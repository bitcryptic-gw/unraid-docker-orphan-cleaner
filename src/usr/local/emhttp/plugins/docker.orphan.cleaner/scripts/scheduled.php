<?php
declare(strict_types=1);

/**
 * Cron entry point.
 *
 * Runs only in notify-only mode by default. Even in delete mode it can only
 * ever delete class 5 (untagged) images: tagged, template referenced and
 * compose referenced images are never removed by a scheduled run, whatever
 * the settings say. Pins and the minimum age are always respected.
 *
 * Notifications are written straight into Unraid's notification store (see
 * include/Notify.php); no shell command is used anywhere in this plugin.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once '/usr/local/emhttp/plugins/docker.orphan.cleaner/include/Config.php';
require_once '/usr/local/emhttp/plugins/docker.orphan.cleaner/include/DockerApi.php';
require_once '/usr/local/emhttp/plugins/docker.orphan.cleaner/include/Orphans.php';
require_once '/usr/local/emhttp/plugins/docker.orphan.cleaner/include/Logger.php';
require_once '/usr/local/emhttp/plugins/docker.orphan.cleaner/include/Notify.php';

/**
 * @param array<int,string> $lines
 */
function doc_syslog(array $lines): void
{
    foreach ($lines as $line) {
        Logger::log((string) $line);
    }
}

function doc_notify(string $subject, string $description, string $importance, string $message = ''): void
{
    if (!Notify::send('Docker Orphan Cleaner', $subject, $description, $importance, $message)) {
        Logger::log('could not write notification; ' . $subject . ' - ' . $description);
    }
}

$cfg = Config::load();

if ($cfg->schedule === 'off') {
    exit(0);
}

$api = new DockerApi();

try {
    $report = (new Orphans($api, $cfg))->compute();
} catch (Throwable $e) {
    Logger::log('scheduled run aborted: ' . $e->getMessage());
    doc_notify('Scheduled run failed', $e->getMessage(), 'warning');
    exit(1);
}

$candidates = [];
$candidateSize = 0;
foreach ($report['orphans'] as $orphan) {
    if ($orphan['class'] !== 'untagged') {
        continue;
    }
    if ($orphan['hasChildren']) {
        continue;
    }
    if ((int) $orphan['ageDays'] < $cfg->minAgeDays) {
        continue;
    }
    $candidates[] = $orphan;
    $candidateSize += (int) $orphan['size'];
}

$count = count($candidates);
$humanSize = Orphans::humanBytes($candidateSize);

if ($cfg->scheduleMode === 'delete-untagged') {
    $deleted = 0;
    $failed = 0;
    $reclaimed = 0;
    foreach ($candidates as $orphan) {
        $id = (string) $orphan['id'];
        try {
            $api->removeImage($id, false);
            $deleted++;
            $reclaimed += (int) $orphan['size'];
            Logger::log('scheduled delete ' . $id . ' tags=' . implode(',', $orphan['tags'])
                . ' size=' . $orphan['size'] . ' result=deleted');
        } catch (DockerApiException $e) {
            $failed++;
            Logger::log('scheduled delete ' . $id . ' result=failed http=' . $e->status()
                . ' message=' . $e->getMessage());
        }
    }

    if ($count > 0) {
        $description = 'Removed ' . $deleted . ' untagged image(s), reclaimed ' . Orphans::humanBytes($reclaimed);
        if ($failed > 0) {
            $description .= ' (' . $failed . ' could not be removed)';
        }
        doc_notify('Scheduled cleanup', $description, $failed > 0 ? 'warning' : 'normal');
        doc_syslog([
            'scheduled delete-untagged run: candidates=' . $count . ' deleted=' . $deleted
                . ' failed=' . $failed . ' reclaimed=' . $reclaimed,
        ]);
    } else {
        Logger::log('scheduled delete-untagged run: nothing eligible');
    }
} else {
    if ($count > 0) {
        doc_notify(
            'Untagged images eligible for cleanup',
            $count . ' untagged image(s), ' . $humanSize . ' reclaimable, all older than '
                . $cfg->minAgeDays . ' day(s). Notify-only mode; nothing deleted.',
            'normal'
        );
        Logger::log('scheduled notify run: eligible=' . $count . ' reclaimable=' . $candidateSize);
    } else {
        Logger::log('scheduled notify run: nothing eligible');
    }
}

if ($cfg->includeBuildCache) {
    try {
        $result = $api->pruneBuildCache();
        $reclaimed = (int) ($result['SpaceReclaimed'] ?? 0);
        Logger::log('scheduled build cache prune reclaimed=' . $reclaimed . ' bytes');
        if ($reclaimed > 0) {
            doc_notify('Build cache pruned', Orphans::humanBytes($reclaimed) . ' reclaimed', 'normal');
        }
    } catch (Throwable $e) {
        Logger::log('scheduled build cache prune failed: ' . $e->getMessage());
    }
}

exit(0);

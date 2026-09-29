<?php
declare(strict_types=1);

/**
 * Detached build-cache prune worker.
 *
 * Started by Action.php via Exec::spawnDetached(), so the web request returns
 * immediately while this runs for as long as the prune takes. It holds an
 * flock so only one prune runs at a time, and records progress in a small
 * status file the UI polls (action=prune-status).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once '/usr/local/emhttp/plugins/docker.orphan.cleaner/include/DockerApi.php';
require_once '/usr/local/emhttp/plugins/docker.orphan.cleaner/include/Pruner.php';
require_once '/usr/local/emhttp/plugins/docker.orphan.cleaner/include/Logger.php';

// A large prune can run for minutes. Keep this process alive regardless of
// the caller (the caller has already detached).
set_time_limit(0);
ignore_user_abort(true);

$lock = Pruner::tryLock();
if ($lock === false) {
    // Another prune is already running; nothing to do.
    Logger::log('prune worker skipped, another prune is running');
    exit(0);
}

$started = time();
Pruner::writeStatus(['state' => 'running', 'started' => $started]);
Logger::log('prune worker started');

try {
    $api = new DockerApi();
    $result = $api->pruneBuildCache(900);
    $reclaimed = (int) ($result['SpaceReclaimed'] ?? 0);
    Pruner::writeStatus([
        'state'     => 'done',
        'started'   => $started,
        'finished'  => time(),
        'reclaimed' => $reclaimed,
    ]);
    Logger::log('prune worker done, reclaimed=' . $reclaimed . ' bytes');
} catch (Throwable $e) {
    Pruner::writeStatus([
        'state'    => 'failed',
        'started'  => $started,
        'finished' => time(),
        'message'  => $e->getMessage(),
    ]);
    Logger::log('prune worker failed: ' . $e->getMessage());
} finally {
    Pruner::unlock($lock);
}

exit(0);

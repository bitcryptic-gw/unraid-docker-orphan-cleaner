<?php
declare(strict_types=1);

/**
 * JSON endpoint for the plugin UI and (indirectly) the scheduler.
 *
 * Security model
 * --------------
 * - Unraid's webGUI auto_prepend (local_prepend.php) already rejects every
 *   POST that does not carry a valid csrf_token (POST field or X-CSRF-Token
 *   header) and consumes that token. Because of that, this endpoint performs
 *   its own hash_equals() check against a *separate* token carried in the
 *   JSON body (`csrf`), so an explicit server side check is still performed.
 * - State changing actions are POST only. GET is refused for them.
 * - Request bodies larger than 64 KiB are refused.
 * - Image IDs must be exact sha256 digests and there can be at most 200.
 * - At delete time the orphan set and its classification are recomputed from
 *   the Docker daemon; client supplied classification is never trusted.
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/DockerApi.php';
require_once __DIR__ . '/Orphans.php';
require_once __DIR__ . '/Logger.php';

const DOC_MAX_BODY_BYTES = 65536;
const DOC_MAX_IDS        = 200;
const DOC_ACTIONS        = ['list', 'delete', 'prune-cache', 'save-settings'];

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

/**
 * @param array<string,mixed> $data
 */
function doc_respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    exit;
}

function doc_csrf_token(): string
{
    foreach (['/var/local/emhttp/var.ini', '/usr/local/emhttp/state/var.ini'] as $path) {
        $ini = @parse_ini_file($path);
        if (is_array($ini) && isset($ini['csrf_token']) && is_string($ini['csrf_token'])) {
            return $ini['csrf_token'];
        }
    }
    return '';
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Reject a declared oversized body before reading any of it.
if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > DOC_MAX_BODY_BYTES) {
    doc_respond(['error' => 'request body too large'], 413);
}

// Read at most one byte past the cap, so a chunked body (or one with no, or an
// untrue, Content-Length) cannot force the endpoint to buffer it all.
$rawBody = file_get_contents('php://input', false, null, 0, DOC_MAX_BODY_BYTES + 1);
if ($rawBody === false) {
    $rawBody = '';
}
if (strlen($rawBody) > DOC_MAX_BODY_BYTES) {
    doc_respond(['error' => 'request body too large'], 413);
}

$body = [];
if ($rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $body = $decoded;
    } elseif ($method === 'POST') {
        doc_respond(['error' => 'invalid JSON body'], 400);
    }
}

$action = '';
if (isset($_GET['action']) && is_string($_GET['action'])) {
    $action = $_GET['action'];
} elseif (isset($body['action']) && is_string($body['action'])) {
    $action = $body['action'];
}
if (!in_array($action, DOC_ACTIONS, true)) {
    doc_respond(['error' => 'unknown action'], 400);
}

$readOnly = ($action === 'list');
if ($readOnly) {
    if ($method !== 'GET' && $method !== 'POST') {
        doc_respond(['error' => 'method not allowed'], 405);
    }
} else {
    if ($method !== 'POST') {
        doc_respond(['error' => 'POST required for this action'], 405);
    }
    $expected = doc_csrf_token();
    $given = '';
    if (isset($body['csrf']) && is_string($body['csrf'])) {
        $given = $body['csrf'];
    } elseif (isset($_POST['csrf']) && is_string($_POST['csrf'])) {
        $given = (string) $_POST['csrf'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $given = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        doc_respond(['error' => 'csrf validation failed'], 403);
    }
}

try {
    if ($action === 'list') {
        $cfg = Config::load();
        $api = new DockerApi();
        $report = (new Orphans($api, $cfg))->compute();
        $report['settings'] = $cfg->toArray();
        $report['dockerOk'] = true;
        doc_respond($report);
    }

    if ($action === 'save-settings') {
        $cfg = Config::fromArray($body);
        $errors = $cfg->validate();
        if (count($errors) > 0) {
            doc_respond(['error' => 'invalid settings', 'details' => $errors], 400);
        }
        if (!$cfg->save()) {
            doc_respond(['error' => 'could not save settings'], 500);
        }
        $cfg->writeCron();
        Logger::log('settings saved (schedule=' . $cfg->schedule . ' mode=' . $cfg->scheduleMode . ' minAgeDays=' . $cfg->minAgeDays . ')');
        doc_respond(['ok' => true, 'settings' => $cfg->toArray()]);
    }

    if ($action === 'prune-cache') {
        $api = new DockerApi();
        $result = $api->pruneBuildCache();
        $reclaimed = (int) ($result['SpaceReclaimed'] ?? 0);
        Logger::log('build cache pruned, reclaimed=' . $reclaimed . ' bytes');
        doc_respond(['ok' => true, 'reclaimed' => $reclaimed, 'humanReclaimed' => Orphans::humanBytes($reclaimed)]);
    }

    if ($action === 'delete') {
        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || count($ids) === 0) {
            doc_respond(['error' => 'no image ids supplied'], 400);
        }
        if (count($ids) > DOC_MAX_IDS) {
            doc_respond(['error' => 'too many image ids (max ' . DOC_MAX_IDS . ')'], 400);
        }
        $ids = array_values(array_unique($ids));
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^sha256:[a-f0-9]{64}$/', $id)) {
                doc_respond(['error' => 'malformed image id'], 400);
            }
        }

        $dryRun = !empty($body['dryRun']);
        $cfg = Config::load();
        $api = new DockerApi();
        $report = (new Orphans($api, $cfg))->compute();

        $orphanMap = [];
        foreach ($report['orphans'] as $orphan) {
            $orphanMap[$orphan['id']] = $orphan;
        }

        $results = [];
        foreach ($ids as $id) {
            if (!isset($orphanMap[$id])) {
                $results[] = [
                    'id' => $id,
                    'status' => 'refused',
                    'message' => 'not an orphan: referenced by a container or no longer present',
                ];
                continue;
            }
            $orphan = $orphanMap[$id];
            if ($orphan['class'] === 'pinned') {
                $results[] = [
                    'id' => $id,
                    'status' => 'refused',
                    'message' => 'pinned',
                    'tags' => $orphan['tags'],
                ];
                continue;
            }
            if ($dryRun) {
                $results[] = [
                    'id' => $id,
                    'status' => 'would-delete',
                    'tags' => $orphan['tags'],
                    'size' => $orphan['size'],
                ];
                continue;
            }
            try {
                $api->removeImage($id, false);
                Logger::log('deleted image ' . $id . ' tags=' . implode(',', $orphan['tags'])
                    . ' size=' . $orphan['size'] . ' result=deleted');
                $results[] = [
                    'id' => $id,
                    'status' => 'deleted',
                    'tags' => $orphan['tags'],
                    'size' => $orphan['size'],
                ];
            } catch (DockerApiException $e) {
                $status = $e->status();
                $mapped = 'refused';
                if ($status === 409) {
                    $mapped = 'conflict';
                } elseif ($status === 404) {
                    $mapped = 'not-found';
                }
                Logger::log('delete image ' . $id . ' result=' . $mapped
                    . ' http=' . $status . ' message=' . $e->getMessage());
                $results[] = [
                    'id' => $id,
                    'status' => $mapped,
                    'message' => $e->getMessage(),
                    'tags' => $orphan['tags'],
                ];
            }
        }

        doc_respond(['ok' => true, 'dryRun' => $dryRun, 'results' => $results]);
    }
} catch (DockerApiException $e) {
    doc_respond(['error' => 'docker error: ' . $e->getMessage()], 502);
} catch (Throwable $e) {
    Logger::log('unhandled error: ' . $e->getMessage());
    doc_respond(['error' => 'internal error'], 500);
}

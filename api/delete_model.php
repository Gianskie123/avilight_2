<?php
/**
 * api/delete_model.php
 *
 * Permanently removes a model version: DB record + stored files.
 * Safety guards:
 *   - IT admin required.
 *   - Active model cannot be deleted; switch to another version first.
 *   - Path is resolved and must sit inside api_models/versions/ to
 *     prevent accidental deletion of the live model root or arbitrary paths.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';

function rrmdir_safe(string $dir): void {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir_safe($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$version = trim($input['version'] ?? '');

if ($version === '') {
    echo json_encode(['success' => false, 'error' => 'Version name is required.']);
    exit;
}

try {
    $db = get_mysql_db();

    $find = $db->prepare('SELECT id, status, file_path FROM models WHERE version_name = :version LIMIT 1');
    $find->execute([':version' => $version]);
    $row = $find->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Model version not found.']);
        exit;
    }

    if (($row['status'] ?? '') === 'Active') {
        echo json_encode(['success' => false, 'error' => 'Cannot delete the Active model. Switch to another version first.']);
        exit;
    }

    // Resolve and validate the stored path — must be inside api_models/versions/
    $versionsRoot = realpath(__DIR__ . '/../api_models/versions');
    $filePath     = (string)($row['file_path'] ?? '');
    $resolved     = $filePath !== '' ? realpath(__DIR__ . '/../' . ltrim($filePath, '/\\')) : false;

    $db->prepare('DELETE FROM models WHERE id = :id')->execute([':id' => (int)$row['id']]);

    // Clean up files only if path resolves and is safely inside versions/
    if ($resolved !== false && $versionsRoot !== false && str_starts_with($resolved, $versionsRoot)) {
        if (is_dir($resolved)) {
            rrmdir_safe($resolved);
        } elseif (is_file($resolved)) {
            @unlink($resolved);
        }
    }

    $actor = $_SESSION['user_email'] ?? 'unknown';
    try {
        _ensure_access_log_table($db);
        $db->prepare(
            'INSERT INTO access_log (user_id, email, action, ip_address) VALUES (:uid, :email, :act, :ip)'
        )->execute([
            ':uid'   => $_SESSION['user_id']    ?? null,
            ':email' => $actor,
            ':act'   => 'delete_model: ' . $version,
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Throwable $_) {}

    echo json_encode(['success' => true, 'message' => "Model version {$version} has been deleted."]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to delete model: ' . $e->getMessage()]);
}

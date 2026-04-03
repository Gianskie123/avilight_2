<?php
/**
 * api/switch_model.php
 *
 * Switches the active ML model version used for predictions.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';

function activate_model_bundle(string $filePath, string $apiModelsDir): ?string {
    $requiredFiles = [
        'xgb_tolerant.json',
        'xgb_sensitive.json',
        'xgb_resident.json',
        'xgb_migrant.json',
        'convlstm_classifier.keras',
        'convlstm_regressor.keras',
        'meta_learner.joblib',
    ];

    $resolved = realpath(__DIR__ . '/../' . ltrim($filePath, '/\\'));
    if ($resolved === false) {
        return 'Stored model path does not exist.';
    }

    if (!is_dir($resolved)) {
        return 'Selected version is not a full model bundle directory. Re-upload this version as a ZIP bundle.';
    }

    foreach ($requiredFiles as $filename) {
        $src = $resolved . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($src)) {
            return "Bundle is missing required file: {$filename}";
        }
    }

    foreach ($requiredFiles as $filename) {
        $src = $resolved . DIRECTORY_SEPARATOR . $filename;
        $dst = $apiModelsDir . DIRECTORY_SEPARATOR . $filename;
        if (!@copy($src, $dst)) {
            return "Failed to activate required file: {$filename}";
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$version = trim($input['version'] ?? '');

if (empty($version)) {
    echo json_encode(['success' => false, 'error' => 'Version is required']);
    exit;
}

try {
    $db = get_mysql_db();
    $api_models_dir = realpath(__DIR__ . '/../api_models');
    if ($api_models_dir === false || !is_dir($api_models_dir) || !is_writable($api_models_dir)) {
        echo json_encode(['success' => false, 'error' => 'Model storage directory is not writable.']);
        exit;
    }

    $db->beginTransaction();

    $find = $db->prepare('SELECT id, status, file_path FROM models WHERE version_name = :version LIMIT 1 FOR UPDATE');
    $find->execute([':version' => $version]);
    $target = $find->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => 'Model version not found.']);
        exit;
    }

    if (($target['status'] ?? '') === 'Active') {
        $db->commit();
        echo json_encode(['success' => true, 'message' => "Model {$version} is already active."]);
        exit;
    }

    $activation_error = activate_model_bundle((string)($target['file_path'] ?? ''), $api_models_dir);
    if ($activation_error !== null) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $activation_error]);
        exit;
    }

    $db->exec("UPDATE models SET status = 'Backup' WHERE status = 'Active'");

    $activate = $db->prepare("UPDATE models SET status = 'Active' WHERE id = :id");
    $activate->execute([':id' => (int)$target['id']]);

    $db->commit();
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Failed to switch model: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => "Switched active model to {$version}. Bundle files are now active in api_models/."
]);

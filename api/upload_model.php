<?php
/**
 * api/upload_model.php
 *
 * Uploads and registers a model version.
 *
 * Preferred input: one .zip bundle containing the full required model set:
 * - xgb_tolerant.json
 * - xgb_sensitive.json
 * - xgb_resident.json
 * - xgb_migrant.json
 * - convlstm_classifier.keras
 * - convlstm_regressor.keras
 * - meta_learner.joblib
 *
 * Legacy input is still accepted for single-file rows (.pkl/.h5/.pth),
 * but those rows are not full-stack bundles.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';

function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function find_file_by_basename(string $root, string $basename): ?string {
    if (!is_dir($root)) {
        return null;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        if (strcasecmp($fileInfo->getFilename(), $basename) === 0) {
            return $fileInfo->getPathname();
        }
    }
    return null;
}

function activate_bundle_to_live_dir(string $bundleDirAbs, string $apiModelsDirAbs, array $requiredFiles): ?string {
    foreach ($requiredFiles as $required) {
        $source = $bundleDirAbs . DIRECTORY_SEPARATOR . $required;
        if (!is_file($source)) {
            return "Bundle is missing required file: {$required}";
        }
        $dest = $apiModelsDirAbs . DIRECTORY_SEPARATOR . $required;
        if (!@copy($source, $dest)) {
            return "Failed to activate bundle file: {$required}";
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

if (empty($_FILES['file']) || empty($_POST['version'])) {
    echo json_encode(['success' => false, 'error' => 'Model file and version name are required']);
    exit;
}

$required_bundle_files = [
    'xgb_tolerant.json',
    'xgb_sensitive.json',
    'xgb_resident.json',
    'xgb_migrant.json',
    'convlstm_classifier.keras',
    'convlstm_regressor.keras',
    'meta_learner.joblib',
];

$file    = $_FILES['file'];
$version = trim($_POST['version']);
$desc    = trim($_POST['description'] ?? '');

if (!preg_match('/^[A-Za-z0-9._-]{1,50}$/', $version)) {
    echo json_encode(['success' => false, 'error' => 'Invalid version name. Use letters, numbers, dot, underscore, or dash (max 50).']);
    exit;
}

if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Model upload failed during transfer.']);
    exit;
}

$valid_extensions = ['zip', 'pkl', 'h5', 'pth'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $valid_extensions, true)) {
    echo json_encode(['success' => false, 'error' => 'Unsupported model format. Use .zip (preferred), .pkl, .h5, or .pth.']);
    exit;
}

$framework_map = [
    'zip' => 'Bundle',
    'pkl' => 'XGBoost',
    'h5'  => 'ConvLSTM',
    'pth' => 'PyTorch',
];
$framework = $framework_map[$ext] ?? 'Unknown';

$api_models_dir = realpath(__DIR__ . '/../api_models');
if ($api_models_dir === false || !is_dir($api_models_dir) || !is_writable($api_models_dir)) {
    echo json_encode(['success' => false, 'error' => 'Model storage directory is not writable.']);
    exit;
}

$safe_version = preg_replace('/[^A-Za-z0-9._-]/', '_', $version);
$stored_name = $safe_version . '_' . date('Ymd_His');

$relative_target = '';
$absolute_target_file = null;
$absolute_target_dir = null;
$tmp_extract_dir = null;

try {
    $db = get_mysql_db();

    $check = $db->prepare('SELECT id FROM models WHERE version_name = :version LIMIT 1');
    $check->execute([':version' => $version]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Version already exists. Choose a unique version name.']);
        exit;
    }

    if ($ext === 'zip') {
        if (!class_exists('ZipArchive')) {
            echo json_encode(['success' => false, 'error' => 'ZIP support is not enabled in PHP (ZipArchive missing).']);
            exit;
        }

        $versions_root = $api_models_dir . DIRECTORY_SEPARATOR . 'versions';
        if (!is_dir($versions_root) && !@mkdir($versions_root, 0775, true)) {
            echo json_encode(['success' => false, 'error' => 'Cannot create versions directory in api_models.']);
            exit;
        }

        $absolute_target_dir = $versions_root . DIRECTORY_SEPARATOR . $stored_name;
        if (!@mkdir($absolute_target_dir, 0775, true)) {
            echo json_encode(['success' => false, 'error' => 'Cannot create model bundle directory.']);
            exit;
        }

        $tmp_extract_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'avilight_bundle_' . uniqid('', true);
        if (!@mkdir($tmp_extract_dir, 0775, true)) {
            rrmdir($absolute_target_dir);
            echo json_encode(['success' => false, 'error' => 'Failed to prepare temporary extraction directory.']);
            exit;
        }

        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            rrmdir($tmp_extract_dir);
            rrmdir($absolute_target_dir);
            echo json_encode(['success' => false, 'error' => 'Invalid ZIP file.']);
            exit;
        }
        if (!$zip->extractTo($tmp_extract_dir)) {
            $zip->close();
            rrmdir($tmp_extract_dir);
            rrmdir($absolute_target_dir);
            echo json_encode(['success' => false, 'error' => 'Failed to extract ZIP archive.']);
            exit;
        }
        $zip->close();

        $missing = [];
        foreach ($required_bundle_files as $required) {
            $found = find_file_by_basename($tmp_extract_dir, $required);
            if ($found === null) {
                $missing[] = $required;
                continue;
            }
            $dest = $absolute_target_dir . DIRECTORY_SEPARATOR . $required;
            if (!@copy($found, $dest)) {
                rrmdir($tmp_extract_dir);
                rrmdir($absolute_target_dir);
                echo json_encode(['success' => false, 'error' => "Failed to copy required file from ZIP: {$required}"]);
                exit;
            }
        }

        if (!empty($missing)) {
            rrmdir($tmp_extract_dir);
            rrmdir($absolute_target_dir);
            echo json_encode([
                'success' => false,
                'error' => 'Bundle is missing required files: ' . implode(', ', $missing),
            ]);
            exit;
        }

        $manifest = [
            'version' => $version,
            'uploaded_at' => gmdate('c'),
            'source_archive' => $file['name'],
            'required_files' => $required_bundle_files,
        ];
        @file_put_contents($absolute_target_dir . DIRECTORY_SEPARATOR . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        rrmdir($tmp_extract_dir);
        $tmp_extract_dir = null;

        $relative_target = 'api_models/versions/' . $stored_name;
    } else {
        $absolute_target_file = $api_models_dir . DIRECTORY_SEPARATOR . $stored_name . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $absolute_target_file)) {
            echo json_encode(['success' => false, 'error' => 'Failed to store uploaded model file.']);
            exit;
        }
        $relative_target = 'api_models/' . basename($absolute_target_file);
    }

    $has_active = (int)$db->query("SELECT COUNT(*) FROM models WHERE status = 'Active'")->fetchColumn() > 0;
    $new_status = $has_active ? 'Backup' : 'Active';
    $uploaded_by = get_logged_user();

    $insert = $db->prepare(
        'INSERT INTO models (version_name, framework, description, file_path, status, uploaded_by)
         VALUES (:version_name, :framework, :description, :file_path, :status, :uploaded_by)'
    );
    $insert->execute([
        ':version_name' => $version,
        ':framework'    => $framework,
        ':description'  => ($desc !== '' ? $desc : null),
        ':file_path'    => $relative_target,
        ':status'       => $new_status,
        ':uploaded_by'  => ($uploaded_by !== null ? $uploaded_by : 'system'),
    ]);

    if ($new_status === 'Active' && $ext === 'zip') {
        $bundle_abs = realpath(__DIR__ . '/../' . $relative_target);
        if ($bundle_abs !== false) {
            $activation_error = activate_bundle_to_live_dir($bundle_abs, $api_models_dir, $required_bundle_files);
            if ($activation_error !== null) {
                echo json_encode([
                    'success' => true,
                    'message' => "Model {$version} uploaded, but activation warning: {$activation_error}",
                ]);
                exit;
            }
        }
    }
} catch (Throwable $e) {
    if ($tmp_extract_dir !== null) {
        rrmdir($tmp_extract_dir);
    }
    if ($absolute_target_file !== null) {
        @unlink($absolute_target_file);
    }
    if ($absolute_target_dir !== null) {
        rrmdir($absolute_target_dir);
    }
    echo json_encode(['success' => false, 'error' => 'Database error while registering model: ' . $e->getMessage()]);
    exit;
}

$msg = ($ext === 'zip')
    ? "Model bundle {$version} uploaded and registered successfully."
    : "Model {$version} uploaded and registered successfully (single-file legacy mode).";

echo json_encode([
    'success' => true,
    'message' => $msg,
]);

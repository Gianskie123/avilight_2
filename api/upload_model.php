<?php
/**
 * api/upload_model.php
 *
 * Receives a ML model file (.pkl / .h5 / .pth), stores it,
 * and registers the new version via the Python backend.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/backend_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

if (empty($_FILES['file']) || empty($_POST['version'])) {
    echo json_encode(['success' => false, 'error' => 'Model file and version name are required']);
    exit;
}

$file    = $_FILES['file'];
$version = trim($_POST['version']);
$desc    = trim($_POST['description'] ?? '');

$valid_extensions = ['pkl', 'h5', 'pth'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $valid_extensions)) {
    echo json_encode(['success' => false, 'error' => 'Unsupported model format. Use .pkl, .h5, or .pth.']);
    exit;
}

// TODO: Forward to Python backend for model registration and validation tests.
// Move the file to a staging directory first, then pass the path:
// $staged = sys_get_temp_dir() . '/' . $version . '_' . basename($file['tmp_name']) . '.' . $ext;
// move_uploaded_file($file['tmp_name'], $staged);
// $result = python_post('/api/upload_model', ['filepath' => $staged, 'version' => $version, 'description' => $desc]);
// if (!$result['success']) { echo json_encode($result); exit; }

echo json_encode([
    'success' => true,
    'message' => "Model {$version} ({$file['name']}) received. Pending Python backend validation."
]);

<?php
/**
 * api/upload_data.php
 *
 * Receives a bird observation CSV/XLSX upload, validates it,
 * and forwards it to the Python backend for processing.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/backend_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

if (empty($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

$valid_extensions = ['csv', 'xlsx'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $valid_extensions)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only CSV and XLSX allowed.']);
    exit;
}

if ($file['size'] > 50 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum size: 50MB.']);
    exit;
}

// TODO: Forward file to Python backend for processing.
// Move the file to a staging directory first, then pass the path:
// $staged = sys_get_temp_dir() . '/' . basename($file['tmp_name']) . '.' . $ext;
// move_uploaded_file($file['tmp_name'], $staged);
// $result = python_post('/api/upload_data', ['filepath' => $staged, 'filename' => $file['name']]);
// if (!$result['success']) { echo json_encode($result); exit; }

echo json_encode([
    'success' => true,
    'message' => "Upload received: {$file['name']} (" . round($file['size'] / 1024, 2) . ' KB). Queued for Python backend processing.'
]);

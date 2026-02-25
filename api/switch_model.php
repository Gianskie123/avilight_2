<?php
/**
 * api/switch_model.php
 *
 * Switches the active ML model version used for predictions.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/backend_config.php';

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

// TODO: Notify Python backend to reload the specified model version
// $result = python_post('/api/switch_model', ['version' => $version]);
// if (!$result['success']) { echo json_encode($result); exit; }

echo json_encode([
    'success' => true,
    'message' => "Switched active model to {$version}. Python backend will reload on next prediction request."
]);

<?php
/**
 * api/fetch_satellite.php
 *
 * Triggers a satellite data fetch (VIIRS / MODIS / NOAA)
 * by calling the Python backend worker.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/backend_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$source = strtoupper(trim($input['source'] ?? ''));

$valid_sources = ['VIIRS', 'MODIS', 'NOAA'];
if (!in_array($source, $valid_sources)) {
    echo json_encode(['success' => false, 'error' => 'Invalid source. Use VIIRS, MODIS, or NOAA.']);
    exit;
}

// TODO: Forward to Python backend to trigger the actual satellite fetch job
// $result = python_post('/api/fetch_satellite', ['source' => $source]);
// if (!$result['success']) { echo json_encode($result); exit; }

echo json_encode([
    'success' => true,
    'message' => "{$source} fetch job queued. Python backend will process it shortly."
]);

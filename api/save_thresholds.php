<?php
/**
 * api/save_thresholds.php
 *
 * Persists risk thresholds and KBA/PA audit weights.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Session expired.']);
    exit;
}
require_once __DIR__ . '/../includes/backend_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true) ?? [];
$required = [
    'high_risk',
    'mod_risk',
    'low_risk',
    'kba_richness_weight',
    'kba_sensitive_weight',
    'kba_ndvi_weight',
    'kba_alan_weight',
    'kba_lst_weight',
    'kba_precip_weight'
];
foreach ($required as $key) {
    if (!isset($input[$key]) || !is_numeric($input[$key])) {
        echo json_encode(['success' => false, 'error' => "Missing or invalid field: {$key}"]);
        exit;
    }
}

$normalized = [];
foreach ($required as $key) {
    $normalized[$key] = (float) $input[$key];
}

$cacheDir = __DIR__ . '/../data/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}

$outPath = $cacheDir . '/thresholds.json';
$writeOk = @file_put_contents(
    $outPath,
    json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

if ($writeOk === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to persist threshold configuration to cache file.',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => "Thresholds saved: Danger Zone color scales and KBA/PA weights updated.",
]);
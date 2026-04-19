<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$defaults = [
    'high_risk' => 60.0,
    'mod_risk' => 40.0,
    'low_risk' => 25.0,
    'kba_richness_weight' => 15.0,
    'kba_sensitive_weight' => 15.0,
    'kba_ndvi_weight' => 15.0,
    'kba_alan_weight' => 15.0,
    'kba_lst_weight' => 15.0,
    'kba_precip_weight' => 10.0,
];

$path = __DIR__ . '/../data/cache/thresholds.json';
if (!is_readable($path)) {
    echo json_encode(['success' => true, 'thresholds' => $defaults]);
    exit;
}

$decoded = json_decode((string) file_get_contents($path), true);
if (!is_array($decoded)) {
    echo json_encode(['success' => true, 'thresholds' => $defaults]);
    exit;
}

foreach ($defaults as $key => $value) {
    if (array_key_exists($key, $decoded) && is_numeric($decoded[$key])) {
        $defaults[$key] = (float) $decoded[$key];
    }
}

echo json_encode(['success' => true, 'thresholds' => $defaults]);

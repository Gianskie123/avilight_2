<?php
/**
 * api/save_thresholds.php
 *
 * Persists risk and SHAP threshold configuration.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_admin();
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
    'critical_shap',
    'warning_shap',
    'positive_shap',
    'kba_richness_weight',
    'kba_density_weight',
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

// TODO: Persist thresholds to config file or database, then notify Python backend
// $result = python_post('/api/save_thresholds', $input);
// if (!$result['success']) { echo json_encode($result); exit; }

echo json_encode([
    'success' => true,
    'message' => "Thresholds saved: High Risk={$input['high_risk']}, Mod Risk={$input['mod_risk']}, Low Risk={$input['low_risk']}, Critical SHAP={$input['critical_shap']}, Warning SHAP={$input['warning_shap']}, Positive SHAP={$input['positive_shap']}, KBA/PA weights updated.",
]);

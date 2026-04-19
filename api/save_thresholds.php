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

$thresholds_file = __DIR__ . '/../data/thresholds.json';
$existing = [];
if (is_readable($thresholds_file)) {
    $existing = json_decode(file_get_contents($thresholds_file), true) ?? [];
}

$existing['high_risk']           = (float) $input['high_risk'];
$existing['mod_risk']            = (float) $input['mod_risk'];
$existing['low_risk']            = (float) $input['low_risk'];
$existing['critical_shap']       = (float) $input['critical_shap'];
$existing['warning_shap']        = (float) $input['warning_shap'];
$existing['positive_shap']       = (float) $input['positive_shap'];
$existing['kba_richness_weight'] = (float) $input['kba_richness_weight'];
$existing['kba_density_weight']  = (float) $input['kba_density_weight'];
$existing['kba_sensitive_weight']= (float) $input['kba_sensitive_weight'];
$existing['kba_ndvi_weight']     = (float) $input['kba_ndvi_weight'];
$existing['kba_alan_weight']     = (float) $input['kba_alan_weight'];
$existing['kba_lst_weight']      = (float) $input['kba_lst_weight'];
$existing['kba_precip_weight']   = (float) $input['kba_precip_weight'];

if (file_put_contents($thresholds_file, json_encode($existing, JSON_PRETTY_PRINT)) === false) {
    echo json_encode(['success' => false, 'error' => 'Could not write thresholds file.']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => "Thresholds saved: High Risk={$input['high_risk']}, Mod Risk={$input['mod_risk']}, Low Risk={$input['low_risk']}, Critical SHAP={$input['critical_shap']}, Warning SHAP={$input['warning_shap']}, Positive SHAP={$input['positive_shap']}, KBA/PA weights updated.",
]);

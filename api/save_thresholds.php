<?php
/**
 * api/save_thresholds.php
 *
 * Persists risk thresholds and KBA/PA audit weights to the system_settings
 * table in MySQL. All validation is enforced here as well as on the client.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Session expired.']);
    exit;
}
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$intFields = ['high_risk', 'mod_risk', 'low_risk'];
$floatFields = [
    'kba_richness_weight',
    'kba_sensitive_weight',
    'kba_ndvi_weight',
    'kba_alan_weight',
    'kba_lst_weight',
    'kba_precip_weight',
];
$allFields = array_merge($intFields, $floatFields);

// 4.1 — all fields required
foreach ($allFields as $key) {
    if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
        echo json_encode(['success' => false, 'error' => "Missing field: {$key}"]);
        exit;
    }
    if (!is_numeric($input[$key])) {
        echo json_encode(['success' => false, 'error' => "Invalid value for {$key}: must be a number."]);
        exit;
    }
}

// 4.2 — risk thresholds must be whole integers
foreach ($intFields as $key) {
    $val = $input[$key];
    if ((string)(int)$val !== (string)((float)$val)) {
        $label = str_replace('_', ' ', $key);
        echo json_encode(['success' => false, 'error' => "Field \"{$label}\" must be a whole number (integer)."]);
        exit;
    }
}

// 4.3 — KBA/PA weights must sum to exactly 100
$weightSum = 0.0;
foreach ($floatFields as $key) {
    $weightSum += (float)$input[$key];
}
if (abs($weightSum - 100.0) > 0.01) {
    echo json_encode([
        'success' => false,
        'error'   => sprintf('KBA/PA weights must total 100%%. Current total: %.2f%%', $weightSum),
    ]);
    exit;
}

$normalized = [];
foreach ($intFields as $key) {
    $normalized[$key] = (int)$input[$key];
}
foreach ($floatFields as $key) {
    $normalized[$key] = round((float)$input[$key], 4);
}

try {
    $db = get_mysql_db();

    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key  VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $db->prepare(
        "INSERT INTO system_settings (setting_key, setting_value)
         VALUES ('thresholds', :val)
         ON DUPLICATE KEY UPDATE setting_value = :val, updated_at = NOW()"
    );
    $stmt->execute([':val' => json_encode($normalized)]);

    // Best-effort: touch audit table so Home tab reflects the config change.
    try {
        $db->exec('UPDATE kba_pa_audit_live SET updated_at = NOW()');
    } catch (Throwable $_) {}

    echo json_encode([
        'success' => true,
        'message' => 'Thresholds saved. Danger Zone scales and KBA/PA weights updated for all users.',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

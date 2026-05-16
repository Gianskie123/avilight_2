<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_login();

$defaults = [
    'high_risk'           => 60.0,
    'mod_risk'            => 40.0,
    'low_risk'            => 25.0,
    'kba_richness_weight' => 15.0,
    'kba_sensitive_weight' => 15.0,
    'kba_ndvi_weight'     => 15.0,
    'kba_alan_weight'     => 15.0,
    'kba_lst_weight'      => 15.0,
    'kba_precip_weight'   => 10.0,
];

// Try MySQL first — this is the authoritative store written by save_thresholds.php.
try {
    require_once __DIR__ . '/../includes/db.php';
    $db  = get_mysql_db();
    $row = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'thresholds' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['setting_value'])) {
        $decoded = json_decode((string)$row['setting_value'], true);
        if (is_array($decoded)) {
            foreach ($defaults as $key => $value) {
                if (array_key_exists($key, $decoded) && is_numeric($decoded[$key])) {
                    $defaults[$key] = (float)$decoded[$key];
                }
            }
            echo json_encode(['success' => true, 'thresholds' => $defaults]);
            exit;
        }
    }
} catch (Throwable $_) {}

// Fallback: JSON cache file.
$path = __DIR__ . '/../data/cache/thresholds.json';
if (is_readable($path)) {
    $decoded = json_decode((string)file_get_contents($path), true);
    if (is_array($decoded)) {
        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $decoded) && is_numeric($decoded[$key])) {
                $defaults[$key] = (float)$decoded[$key];
            }
        }
    }
}

echo json_encode(['success' => true, 'thresholds' => $defaults]);

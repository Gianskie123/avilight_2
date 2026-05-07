<?php
/**
 * api/get_covariate_diagnostics.php
 *
 * Diagnostics for covariate fetching: DB tables, Python workers, GEE config.
 */
header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backend_config.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo "ERROR: Session expired. Please log in again.\n";
    exit;
}

function line($label, $value) {
    echo str_pad($label, 32) . $value . "\n";
}

try {
    $pdo = get_mysql_db();
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: DB connection failed: " . $e->getMessage() . "\n";
    exit;
}

// DB tables
$tables = [
    'raw_bird_observation',
    'aggregated_bird_observation',
    'viirs',
    'ndvi',
    'land_temp',
    'precip',
    'land_cover',
    'species_masterlist',
];
$existing = $pdo->query(
    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
)->fetchAll(PDO::FETCH_COLUMN);
$existingSet = array_flip($existing);

echo "DB Table Checks\n";
echo "================\n";
foreach ($tables as $t) {
    line($t, isset($existingSet[$t]) ? 'OK' : 'MISSING');
}

// Land temp schema check
$landTempOk = false;
if (isset($existingSet['land_temp'])) {
    $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'land_temp'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $colSet = array_flip($cols);
    $landTempOk = isset($colSet['lst_day']);
    line('land_temp.lst_day', $landTempOk ? 'OK' : 'MISSING (needs migration)');
    line('land_temp.lst_night', isset($colSet['lst_night']) ? 'OK' : 'MISSING (optional)');
}

// Observation rows
$obsCount = (int)$pdo->query('SELECT COUNT(*) FROM raw_bird_observation')->fetchColumn();
line('raw_bird_observation rows', number_format($obsCount));

// Python workers
$workers = [
    'extract_viirs.py',
    'extract_ndvi.py',
    'extract_lst.py',
    'extract_precip.py',
    'extract_land_cover.py',
];

echo "\nPython Worker Checks\n";
echo "====================\n";
line('PYTHON_BIN', PYTHON_BIN);
line('PYTHON_SCRIPTS_DIR', PYTHON_SCRIPTS_DIR ?: 'NOT SET');
foreach ($workers as $w) {
    $path = rtrim((string)PYTHON_SCRIPTS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $w;
    line($w, is_file($path) ? 'OK' : 'MISSING');
}

// GEE configuration
$geeKey = GEE_SA_KEY;
if ((!$geeKey || !file_exists($geeKey)) && file_exists('/var/www/html/secrets/gee-service-account.json')) {
    $geeKey = '/var/www/html/secrets/gee-service-account.json';
}

echo "\nGEE Configuration\n";
echo "=================\n";
line('GEE_PROJECT', GEE_PROJECT ?: 'NOT SET');
line('GEE key file', ($geeKey && file_exists($geeKey)) ? $geeKey : 'MISSING');
line('proc_open', function_exists('proc_open') ? 'ENABLED' : 'DISABLED');

if (!function_exists('proc_open')) {
    echo "\nERROR: proc_open is disabled; covariate fetch cannot run Python workers.\n";
}

if ($obsCount === 0) {
    echo "\nERROR: No raw_bird_observation rows found. Upload observations first.\n";
}

if (!$landTempOk) {
    echo "\nWARNING: land_temp schema missing lst_day. Run the migration in fetch_satellite.php comment.\n";
}

?>

<?php
/**
 * api/fetch_satellite.php
 *
 * Smart covariate fetch endpoint.
 *
 * Two-call flow (both calls POST with JSON body):
 *
 *  Call 1 — dry-run  { "source": "viirs" }
 *      Computes which (year, month) periods exist in raw_bird_observation
 *      but are missing from the target covariate table.
 *      Returns the missing list so the frontend can show a confirmation dialog.
 *
 *  Call 2 — execute  { "source": "viirs", "confirmed": true }
 *      Re-computes the missing list (prevents race conditions).
 *      For each missing period, executes the corresponding Python GEE worker
 *      script, captures stdout JSON, and batch-inserts into MariaDB.
 *
 * Python workers (in /python/):
 *   viirs     → extract_viirs.py     → table: viirs      (viirs_avg_rad)
 *   ndvi      → extract_ndvi.py      → table: ndvi       (ndvi)
 *   land_temp → extract_lst.py       → table: land_temp  (lst_day, lst_night)
 *   precip    → extract_precip.py    → table: precip     (precip_mm)
 *
 * ⚠ DB SCHEMA NOTE for land_temp:
 *   The current `land_temp` table has a typo — its value column is named
 *   `viirs_avg_rad` but should be `lst_day`.  Run this migration before
 *   fetching LST data:
 *
 *     ALTER TABLE land_temp
 *         CHANGE COLUMN viirs_avg_rad lst_day   DECIMAL(8,4) NOT NULL,
 *         ADD    COLUMN lst_night               DECIMAL(8,4)     NULL AFTER lst_day;
 */

ob_start();
ini_set('display_errors', '0');
set_time_limit(0);   // Python GEE calls can take several minutes per period

set_exception_handler(function (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Fatal: ' . $err['message']]);
    }
});

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backend_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'POST required.']);
    exit;
}

// ── Covariate map ──────────────────────────────────────────────────────────────

/**
 * source key → [
 *   db_table   : MariaDB target table
 *   script     : Python worker filename
 *   gee_dataset: GEE collection ID (informational / forwarded to Python via env)
 *   inserter   : name of the PHP insert function for this table
 * ]
 */
const COVARIATE_MAP = [
    'viirs'       => [
        'db_table'    => 'viirs',
        'script'      => 'extract_viirs.py',
        'gee_dataset' => 'NOAA/VIIRS/DNB/MONTHLY_V1/VCMCFG',
        'inserter'    => 'insert_viirs',
        'temporal'    => 'monthly',
    ],
    'ndvi'        => [
        'db_table'    => 'ndvi',
        'script'      => 'extract_ndvi.py',
        'gee_dataset' => 'MODIS/061/MOD13A1',
        'inserter'    => 'insert_ndvi',
        'temporal'    => 'monthly',
    ],
    'land_temp'   => [
        'db_table'    => 'land_temp',
        'script'      => 'extract_lst.py',
        'gee_dataset' => 'MODIS/061/MOD11A2',
        'inserter'    => 'insert_land_temp',
        'temporal'    => 'monthly',
    ],
    'precip'      => [
        'db_table'    => 'precip',
        'script'      => 'extract_precip.py',
        'gee_dataset' => 'UCSB-CHG/CHIRPS/DAILY',
        'inserter'    => 'insert_precip',
        'temporal'    => 'monthly',
    ],
    'land_cover'  => [
        'db_table'    => 'land_cover',
        'script'      => 'extract_land_cover.py',
        'gee_dataset' => 'MODIS/061/MCD12Q1',
        'inserter'    => 'insert_land_cover',
        'temporal'    => 'annual',   // year-only — no month argument passed to Python
    ],
];

// ── Request parsing ────────────────────────────────────────────────────────────

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$source    = strtolower(trim($input['source'] ?? ''));
$confirmed = !empty($input['confirmed']);

if (!array_key_exists($source, COVARIATE_MAP)) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid source. Valid values: ' . implode(', ', array_keys(COVARIATE_MAP)),
    ]);
    exit;
}

$cfg       = COVARIATE_MAP[$source];
$db_table  = $cfg['db_table'];
$is_annual = $cfg['temporal'] === 'annual';

// ── Compute missing (year, month) chunks ──────────────────────────────────────

try {
    $pdo = get_mysql_db();

    if ($is_annual) {
        // Land cover and other annual covariates — compare distinct years only.
        $obs_rows = $pdo->query(
            'SELECT DISTINCT year FROM raw_bird_observation ORDER BY year'
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($obs_rows)) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'error'   => 'No bird observation data found. Upload observations before fetching covariates.',
            ]);
            exit;
        }

        $cov_rows = $pdo->query(
            "SELECT DISTINCT year FROM `{$db_table}`"
        )->fetchAll(PDO::FETCH_ASSOC);

        $cov_existing = array_column($cov_rows, null, 'year');

        $missing = [];
        foreach ($obs_rows as $period) {
            if (!isset($cov_existing[$period['year']])) {
                $missing[] = ['year' => (int) $period['year']];
            }
        }
    } else {
        // Monthly covariates — compare (year, month) pairs.
        $obs_rows = $pdo->query(
            'SELECT DISTINCT year, month FROM raw_bird_observation ORDER BY year, month'
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($obs_rows)) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'error'   => 'No bird observation data found. Upload observations before fetching covariates.',
            ]);
            exit;
        }

        $cov_rows = $pdo->query(
            "SELECT DISTINCT year, month FROM `{$db_table}`"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Build lookup: "YYYY-MM" → true
        $cov_existing = [];
        foreach ($cov_rows as $row) {
            $cov_existing[$row['year'] . '-' . str_pad($row['month'], 2, '0', STR_PAD_LEFT)] = true;
        }

        $missing = [];
        foreach ($obs_rows as $period) {
            $key = $period['year'] . '-' . str_pad($period['month'], 2, '0', STR_PAD_LEFT);
            if (!isset($cov_existing[$key])) {
                $missing[] = ['year' => (int) $period['year'], 'month' => (int) $period['month']];
            }
        }
    }

    if (empty($missing)) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => ucwords(str_replace('_', ' ', $source)) . ' is already up to date with all bird observation periods.',
            'missing' => [],
        ]);
        exit;
    }

} catch (PDOException $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// ── Dry-run: return missing list for frontend confirmation ─────────────────────

if (!$confirmed) {
    ob_end_clean();
    echo json_encode([
        'success'       => true,
        'missing_count' => count($missing),
        'missing'       => $missing,
    ]);
    exit;
}

// ── Confirmed: run Python workers and insert results ──────────────────────────

const FETCH_BATCH_SIZE = 12;
$remaining = array_slice($missing, FETCH_BATCH_SIZE);
$missing   = array_slice($missing, 0, FETCH_BATCH_SIZE);

$script_path = PYTHON_SCRIPTS_DIR . DIRECTORY_SEPARATOR . $cfg['script'];

if (!file_exists($script_path)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => "Python script not found: {$script_path}"]);
    exit;
}

$is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

// Use putenv() so child processes inherit the variables without any shell
// syntax differences between Windows and Linux.
putenv('GEE_PROJECT=' . GEE_PROJECT);
$gee_key = GEE_SA_KEY;
if ($gee_key && file_exists($gee_key)) {
    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $gee_key);
}

// Pass MariaDB credentials to Python workers for hierarchical gap-filling.
// Read from env so Railway/hosted DBs work (fallback to local defaults).
$db_host = getenv('DB_HOST') ?: getenv('MYSQL_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: getenv('MYSQL_DATABASE') ?: 'avilight';
$db_user = getenv('DB_USER') ?: getenv('MYSQL_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: getenv('MYSQL_PASSWORD') ?: '';
$db_ssl_ca = getenv('DB_SSL_CA') ?: getenv('MYSQL_SSL_CA') ?: '';

putenv('DB_HOST=' . $db_host);
putenv('DB_PORT=' . $db_port);
putenv('DB_NAME=' . $db_name);
putenv('DB_USER=' . $db_user);
putenv('DB_PASS=' . $db_pass);
if ($db_ssl_ca) {
    putenv('DB_SSL_CA=' . $db_ssl_ca);
}

$total_inserted = 0;
$errors         = [];

foreach ($missing as $period) {
    $year         = (int) $period['year'];
    $month        = $is_annual ? null : (int) $period['month'];
    $period_label = $is_annual ? (string) $year : "{$year}-{$month}";

    // Build the command.
    // stdout  → captured by proc_open pipe (JSON rows from Python)
    // stderr  → captured separately so we can surface Python tracebacks
    $python_bin = PYTHON_BIN;
    $script     = $script_path;
    $yr_arg     = (string) $year;
    $mo_part    = $is_annual ? '' : ' ' . (string) $month;

    if ($is_windows) {
        // On Windows, wrap in cmd /c so the PATH is resolved correctly
        $cmd = 'cmd /c ' . escapeshellarg(
            escapeshellcmd($python_bin)
            . ' ' . escapeshellarg($script)
            . ' ' . $yr_arg
            . $mo_part
        );
    } else {
        $cmd = escapeshellcmd($python_bin)
             . ' ' . escapeshellarg($script)
             . ' ' . escapeshellarg($yr_arg)
             . ($is_annual ? '' : ' ' . escapeshellarg((string) $month));
    }

    // proc_open gives us separate stdout and stderr pipes
    $descriptors = [
        0 => ['pipe', 'r'],   // stdin
        1 => ['pipe', 'w'],   // stdout  ← JSON from Python
        2 => ['pipe', 'w'],   // stderr  ← Python tracebacks / GEE errors
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        $errors[] = "Period {$period_label}: Failed to start Python process.";
        continue;
    }

    fclose($pipes[0]);
    $raw_output = stream_get_contents($pipes[1]);
    $stderr_out = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $return_code = proc_close($process);

    // Surface Python stderr in the error message (trimmed to 300 chars)
    $py_error = trim($stderr_out);
    $py_error_short = $py_error ? ' Python error: ' . substr($py_error, -300) : '';

    if ($return_code !== 0 || empty(trim($raw_output))) {
        $errors[] = "Period {$period_label}: Python exited with code {$return_code}.{$py_error_short}";
        continue;
    }

    $rows = json_decode($raw_output, true);
    if (!is_array($rows)) {
        $errors[] = "Period {$period_label}: Could not parse Python output as JSON.";
        continue;
    }

    // Check if Python returned an error object instead of a row array
    if (isset($rows['error'])) {
        $errors[] = "Period {$period_label}: {$rows['error']}";
        continue;
    }

    if (empty($rows)) {
        $errors[] = "Period {$period_label}: No data returned (possibly outside GEE coverage).";
        continue;
    }

    try {
        $inserted = call_user_func($cfg['inserter'], $pdo, $rows);
        $total_inserted += $inserted;
    } catch (PDOException $e) {
        $errors[] = "Period {$period_label}: DB insert failed — " . $e->getMessage();
    }
}

$batch_count = count($missing);
ob_end_clean();
echo json_encode([
    'success'          => empty($errors),
    'message'          => "Batch complete. {$total_inserted} rows inserted across {$batch_count} period(s).",
    'total_inserted'   => $total_inserted,
    'batch_count'      => $batch_count,
    'remaining_count'  => count($remaining),
    'errors'           => $errors,
]);

// ── Table-specific batch insert functions ──────────────────────────────────────

/**
 * Generic helper: builds and executes a batched multi-row INSERT.
 * Skips rows that already exist (INSERT IGNORE) so re-running is safe.
 *
 * @param PDO    $pdo     Active database connection
 * @param string $table   Target table name
 * @param array  $columns Ordered list of column names
 * @param array  $rows    Array of value arrays (must match $columns order)
 * @return int            Number of rows inserted
 */
function batch_insert(PDO $pdo, string $table, array $columns, array $rows): int {
    if (empty($rows)) return 0;

    $col_list    = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
    $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $batch_size  = 500;
    $inserted    = 0;

    foreach (array_chunk($rows, $batch_size) as $batch) {
        $placeholders = implode(', ', array_fill(0, count($batch), $placeholder));
        $sql  = "INSERT IGNORE INTO `{$table}` ({$col_list}) VALUES {$placeholders}";
        $flat = array_merge(...array_map('array_values', $batch));
        $pdo->prepare($sql)->execute($flat);
        $inserted += count($batch);
    }

    return $inserted;
}

function insert_viirs(PDO $pdo, array $rows): int {
    $cols = ['system_index', 'cell_id', 'latitude', 'longitude', 'month', 'year', 'viirs_avg_rad'];
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            $r['system_index'],
            $r['cell_id'],
            $r['latitude'],
            $r['longitude'],
            $r['month'],
            $r['year'],
            $r['viirs_avg_rad'],
        ];
    }
    return batch_insert($pdo, 'viirs', $cols, $data);
}

function insert_ndvi(PDO $pdo, array $rows): int {
    $cols = ['system_index', 'cell_id', 'latitude', 'longitude', 'month', 'year', 'ndvi'];
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            $r['system_index'],
            $r['cell_id'],
            $r['latitude'],
            $r['longitude'],
            $r['month'],
            $r['year'],
            $r['ndvi'],
        ];
    }
    return batch_insert($pdo, 'ndvi', $cols, $data);
}

/**
 * ⚠ Requires the land_temp schema migration before this function will work:
 *
 *   ALTER TABLE land_temp
 *       CHANGE COLUMN viirs_avg_rad lst_day   DECIMAL(8,4) NOT NULL,
 *       ADD    COLUMN lst_night               DECIMAL(8,4)     NULL AFTER lst_day;
 */
function insert_land_temp(PDO $pdo, array $rows): int {
    $cols = ['system_index', 'cell_id', 'latitude', 'longitude', 'month', 'year', 'lst_day', 'lst_night'];
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            $r['system_index'],
            $r['cell_id'],
            $r['latitude'],
            $r['longitude'],
            $r['month'],
            $r['year'],
            $r['lst_day'],
            $r['lst_night'],
        ];
    }
    return batch_insert($pdo, 'land_temp', $cols, $data);
}

function insert_precip(PDO $pdo, array $rows): int {
    $cols = ['system_index', 'cell_id', 'latitude', 'longitude', 'month', 'year', 'precip_mm'];
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            $r['system_index'],
            $r['cell_id'],
            $r['latitude'],
            $r['longitude'],
            $r['month'],
            $r['year'],
            $r['precip_mm'],
        ];
    }
    return batch_insert($pdo, 'precip', $cols, $data);
}

function insert_land_cover(PDO $pdo, array $rows): int {
    $cols = ['system_index', 'cell_id', 'latitude', 'longitude', 'year', 'land_cover'];
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            $r['system_index'],
            $r['cell_id'],
            $r['latitude'],
            $r['longitude'],
            $r['year'],
            $r['land_cover'],   // NULL allowed — imputed during master grid build
        ];
    }
    return batch_insert($pdo, 'land_cover', $cols, $data);
}
<?php
/**
 * api/build_master_grid.php
 *
 * Triggers a full rebuild of the `final_master_grid` table by running
 * python/build_master_grid.py, which merges all environmental covariates
 * with aggregated bird observations and writes the result back to the DB.
 *
 * Two-call flow (POST with JSON body):
 *
 *   Call 1 — dry-run  {}
 *       Computes which years have COMPLETE covariate coverage against the
 *       (year, month) pairs present in aggregated_bird_observation.
 *       A year is "ready" when land_cover has that year AND ndvi / viirs /
 *       land_temp / precip each have every (year, month) from bird obs.
 *       Returns per-year readiness so the frontend can show a summary.
 *
 *   Call 2 — execute  { "confirmed": true }
 *       Re-computes ready years (prevents race), then calls the Python worker
 *       to INSERT all ready years into final_master_grid.
 *
 * Authentication: requires an active session (is_logged_in()).
 */

ob_start();
ini_set('display_errors', '0');
set_time_limit(0);   // rebuild can take several minutes for all years

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
api_assert_active();

require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'POST required.']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$confirmed = !empty($input['confirmed']);

try {
    $pdo = get_mysql_db();
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]);
    exit;
}

// ── Shared: rebuild aggregated_bird_observation from raw ──────────────────────
/**
 * TRUNCATEs aggregated_bird_observation and repopulates it by aggregating
 * raw_bird_observation joined with species_masterlist.
 * Returns the number of rows inserted.
 */
function rebuild_aggregated_bird_observation(PDO $pdo): int {
    $pdo->exec('SET SESSION group_concat_max_len = 1000000');
    $pdo->exec('TRUNCATE TABLE aggregated_bird_observation');

    $pdo->exec("
        INSERT INTO aggregated_bird_observation
            (site_name, latitude, longitude, month, year,
             total_resident, total_migratory, total_tolerant, total_sensitive,
             bird_count, unique_species_count, species_list,
             grid_lat, grid_lon)
        SELECT
            COALESCE(NULLIF(rbo.site_name, ''), 'Observation Site'),
            rbo.latitude,
            rbo.longitude,
            rbo.month,
            rbo.year,
            COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.migratory_status)) = 'resident'  THEN rbo.species_id END),
            COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.migratory_status)) = 'migratory' THEN rbo.species_id END),
            COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.light_tolerance))  = 'tolerant'  THEN rbo.species_id END),
            COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.light_tolerance))  = 'sensitive' THEN rbo.species_id END),
            SUM(COALESCE(rbo.bird_count, 0)),
            COUNT(DISTINCT rbo.species_id),
            CAST(CONCAT('[', COALESCE(GROUP_CONCAT(DISTINCT JSON_QUOTE(sm.species_name)
                ORDER BY sm.species_name SEPARATOR ','), ''), ']') AS JSON),
            rbo.grid_lat,
            rbo.grid_lon
        FROM raw_bird_observation rbo
        LEFT JOIN species_masterlist sm ON sm.species_id = rbo.species_id
        WHERE rbo.latitude  BETWEEN 14.35 AND 14.82
          AND rbo.longitude BETWEEN 120.90 AND 121.22
          AND rbo.latitude  != 0
          AND rbo.longitude != 0
        GROUP BY rbo.site_name, rbo.latitude, rbo.longitude,
                 rbo.month, rbo.year, rbo.grid_lat, rbo.grid_lon
    ");

    return (int)$pdo->query('SELECT COUNT(*) FROM aggregated_bird_observation')->fetchColumn();
}

// ── Shared: compute per-year readiness ────────────────────────────────────────
/**
 * Returns ['ready' => int[], 'not_ready' => array<int,string[]>, 'year_detail' => array]
 *
 * Hard requirement (blocks merge):
 *   - land_cover must have the year — it defines the grid cells.
 *     Without it there is nothing to merge into.
 *
 * Soft warnings only (merge still runs — Python gap-fills these):
 *   - ndvi / viirs / land_temp / precip may have partial or no monthly rows.
 *     Precip uses nearest-neighbour spatial fill; ndvi/lst use temporal
 *     interpolation → land-cover mean → global mean fallback.
 *
 * A year is therefore "ready" when aggregated_bird_observation has rows
 * for it AND land_cover has that year.
 */
function compute_year_readiness(PDO $pdo): array {
    // Years that have bird observations
    $bird_years = $pdo
        ->query('SELECT DISTINCT year FROM aggregated_bird_observation ORDER BY year')
        ->fetchAll(PDO::FETCH_COLUMN);

    if (empty($bird_years)) {
        return ['ready' => [], 'not_ready' => [], 'year_detail' => []];
    }

    // Hard requirement: land_cover years
    $lc_years = array_flip($pdo
        ->query('SELECT DISTINCT year FROM land_cover')
        ->fetchAll(PDO::FETCH_COLUMN));

    // Soft check: how many months each covariate table has per year (for info only)
    $monthly_tables = ['ndvi', 'viirs', 'land_temp', 'precip'];
    $cov_months = [];   // $cov_months['ndvi'][2024] = 12
    foreach ($monthly_tables as $tbl) {
        $rows = $pdo->query(
            "SELECT year, COUNT(DISTINCT month) AS cnt FROM `{$tbl}` GROUP BY year"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cov_months[$tbl][(int)$r['year']] = (int)$r['cnt'];
        }
    }

    $ready     = [];
    $not_ready = [];
    $detail    = [];

    foreach ($bird_years as $y) {
        $y = (int)$y;

        // Hard block: land_cover missing
        $blocked = !isset($lc_years[$y]);

        // Soft warnings: covariate month counts
        $warnings = [];
        foreach ($monthly_tables as $tbl) {
            $have = $cov_months[$tbl][$y] ?? 0;
            if ($have === 0) {
                $label = str_replace('_', ' ', $tbl);
                $warnings[] = "{$label}: no data (will be gap-filled)";
            } elseif ($have < 12) {
                $label = str_replace('_', ' ', $tbl);
                $warnings[] = "{$label}: {$have}/12 months (missing months will be gap-filled)";
            }
        }

        $detail[$y] = [
            'year'     => $y,
            'ready'    => !$blocked,
            'missing'  => $blocked ? ["land_cover missing for year {$y} — cannot build grid"] : [],
            'warnings' => $warnings,
        ];

        if ($blocked) {
            $not_ready[$y] = $detail[$y]['missing'];
        } else {
            $ready[] = $y;
        }
    }

    sort($ready);

    return ['ready' => $ready, 'not_ready' => $not_ready, 'year_detail' => array_values($detail)];
}

// ── Dry-run: return coverage summary for frontend confirmation dialog ──────────
if (!$confirmed) {
    try {
        $agg_rows     = rebuild_aggregated_bird_observation($pdo);
        if ($agg_rows === 0) {
            ob_end_clean();
            echo json_encode([
                'success' => false,
                'error'   => 'No rows were produced in aggregated_bird_observation. Check raw_bird_observation and species_masterlist.',
            ]);
            exit;
        }
        $readiness    = compute_year_readiness($pdo);
        $current_rows = (int)$pdo->query('SELECT COUNT(*) FROM final_master_grid')->fetchColumn();

        $ready_years = $readiness['ready'];
        $not_ready   = $readiness['not_ready'];

        $msg = empty($ready_years)
            ? 'No years ready. land_cover must exist for a year before it can be merged.'
            : sprintf('%d year(s) ready: %s.', count($ready_years), implode(', ', $ready_years));

        if (!empty($not_ready)) {
            $blocked = implode(', ', array_keys($not_ready));
            $msg .= " Blocked (no land_cover): {$blocked}.";
        }

        ob_end_clean();
        echo json_encode([
            'success'      => true,
            'dry_run'      => true,
            'ready_years'  => $ready_years,
            'year_detail'  => $readiness['year_detail'],
            'current_rows' => $current_rows,
            'message'      => $msg,
            'raw_note'     => number_format($agg_rows) . ' site-month rows aggregated from raw_bird_observation.',
        ]);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Execute rebuild ────────────────────────────────────────────────────────────
// Re-aggregate raw observations then re-compute ready years (prevents races
// since the user may have fetched more covariates between dry-run and confirm).
try {
    $agg_rows    = rebuild_aggregated_bird_observation($pdo);
    if ($agg_rows === 0) {
        ob_end_clean();
        echo json_encode([
            'success' => false,
            'error'   => 'No rows were produced in aggregated_bird_observation. Check raw_bird_observation and species_masterlist.',
        ]);
        exit;
    }
    $readiness   = compute_year_readiness($pdo);
    $ready_years = $readiness['ready'];
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Coverage check failed: ' . $e->getMessage()]);
    exit;
}

if (empty($ready_years)) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'error'   => 'No years have complete covariate coverage. Nothing to rebuild.',
    ]);
    exit;
}

// Full rebuild: clear final_master_grid before inserting fresh rows.
try {
    $pdo->exec('TRUNCATE TABLE final_master_grid');
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Failed to clear final_master_grid: ' . $e->getMessage()]);
    exit;
}


$safe_years = implode(',', array_map('intval', $ready_years));

$python = (PHP_OS_FAMILY === 'Windows') ? 'python' : 'python3';
$script = escapeshellarg(realpath(__DIR__ . '/../python/build_master_grid.py'));
$cmd    = "$python $script --years " . escapeshellarg($safe_years) . " 2>&1";

$log_lines = [];
$success   = false;
$error_msg = null;

$handle = popen($cmd, 'r');
if (!$handle) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Failed to launch Python process.']);
    exit;
}

while (!feof($handle)) {
    $line = fgets($handle);
    if ($line === false) break;
    $line = trim($line);
    if ($line === '') continue;
    $log_lines[] = $line;
    $decoded = json_decode($line, true);
    if (is_array($decoded)) {
        if (($decoded['level'] ?? '') === 'success') {
            $success = true;
        }
        if (($decoded['level'] ?? '') === 'error') {
            $error_msg = $decoded['msg'] ?? 'Unknown error';
        }
    }
}
pclose($handle);

if ($success) {
    try {
        $refreshed = refresh_ecological_monthly_summary($pdo, $ready_years);
        $log_lines[] = json_encode(['level' => 'info', 'msg' => "ecological_monthly_summary refreshed ({$refreshed} rows upserted)."]);
        clear_mysql_bau_baseline_cache($pdo);
        $log_lines[] = json_encode(['level' => 'info', 'msg' => 'BAU baseline cache cleared — prewarming all cities now.']);

        // Prewarm analytics_bau_baselines for every city × every month so the
        // Analytics tab is always a cache hit after a rebuild.
        $cityKeys   = get_bau_prewarm_city_keys($pdo);
        $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host       = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $baseDir    = preg_replace('#/api$#', '', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'));
        $scenarioUrl = $scheme . '://' . $host . $baseDir . '/api/run_scenario.php';

        $prewarmOk = 0;
        $prewarmFail = 0;
        foreach ($cityKeys as $cityKey) {
            for ($m = 1; $m <= 12; $m++) {
                $ch = curl_init($scenarioUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode([
                        'city'         => $cityKey,
                        'month'        => $m,
                        'manual_mode'  => false,
                        'prewarm_only' => true,
                    ]),
                ]);
                $resp = curl_exec($ch);
                $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $decoded = json_decode((string)$resp, true);
                if ($http === 200 && is_array($decoded) && !empty($decoded['success'])) {
                    $prewarmOk++;
                } else {
                    $prewarmFail++;
                }
            }
        }

        $log_lines[] = json_encode(['level' => 'info', 'msg' => "BAU prewarm complete — {$prewarmOk} city-month combinations cached, {$prewarmFail} failed."]);
    } catch (Throwable $e) {
        $log_lines[] = json_encode(['level' => 'warning', 'msg' => 'Post-rebuild hooks failed (non-fatal): ' . $e->getMessage()]);
    }
}

ob_end_clean();
echo json_encode([
    'success'   => $success,
    'log'       => $log_lines,
    'error'     => $success ? null : ($error_msg ?? 'Rebuild did not complete successfully.'),
]);

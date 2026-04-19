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
    // Years that have bird observations — union both tables so the check remains
    // accurate even when aggregated_bird_observation has been cleared or is empty
    // (raw observations will be aggregated before the merge runs).
    $bird_years = $pdo
        ->query('SELECT DISTINCT year FROM aggregated_bird_observation
                 UNION
                 SELECT DISTINCT year FROM raw_bird_observation
                 ORDER BY year')
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

/**
 * Rebuilds aggregated_bird_observation from raw_bird_observation.
 *
 * Strategy: only touch (year, month) pairs that exist in raw_bird_observation.
 *   1. Find distinct raw periods.
 *   2. Snap each unique raw (lat, lon) to the nearest land_cover cell centre
 *      via a temporary table (bounding-box ±0.05° reduces candidates).
 *   3. DELETE existing aggregated rows for those periods then INSERT fresh ones.
 *
 * Returns an array with keys: periods, rows_deleted, rows_inserted.
 */
function aggregate_raw_observations(PDO $pdo, callable $emit): array {
    // Which (year, month) pairs exist in raw data?
    $raw_periods = $pdo->query(
        'SELECT DISTINCT year, month FROM raw_bird_observation ORDER BY year, month'
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($raw_periods)) {
        $emit('No rows in raw_bird_observation — aggregation skipped.', 'info');
        return ['periods' => 0, 'rows_deleted' => 0, 'rows_inserted' => 0];
    }

    $period_count = count($raw_periods);
    $emit("Aggregating {$period_count} period(s) from raw_bird_observation…");

    // Build snap table: unique raw (lat, lon) → nearest land_cover cell centre.
    // Uses a ±0.05° bounding box to keep the correlated subquery fast.
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS _agg_snap');
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS _agg_lc');

    // Deduplicated land_cover cells (one row per cell centre, year-independent)
    $pdo->exec(
        'CREATE TEMPORARY TABLE _agg_lc AS
         SELECT DISTINCT latitude AS cell_lat, longitude AS cell_lon
         FROM land_cover'
    );
    $pdo->exec('CREATE INDEX idx_agg_lc ON _agg_lc(cell_lat, cell_lon)');

    $pdo->exec(
        'CREATE TEMPORARY TABLE _agg_snap AS
         SELECT DISTINCT
             r.latitude  AS raw_lat,
             r.longitude AS raw_lon,
             (SELECT c.cell_lat FROM _agg_lc c
              WHERE c.cell_lat  BETWEEN r.latitude  - 0.05 AND r.latitude  + 0.05
                AND c.cell_lon  BETWEEN r.longitude - 0.05 AND r.longitude + 0.05
              ORDER BY POW(c.cell_lat - r.latitude, 2) + POW(c.cell_lon - r.longitude, 2)
              LIMIT 1) AS grid_lat,
             (SELECT c.cell_lon FROM _agg_lc c
              WHERE c.cell_lat  BETWEEN r.latitude  - 0.05 AND r.latitude  + 0.05
                AND c.cell_lon  BETWEEN r.longitude - 0.05 AND r.longitude + 0.05
              ORDER BY POW(c.cell_lat - r.latitude, 2) + POW(c.cell_lon - r.longitude, 2)
              LIMIT 1) AS grid_lon
         FROM raw_bird_observation r'
    );

    // Only aggregate periods from raw_bird_observation that have no corresponding
    // rows in aggregated_bird_observation yet — never touch pre-existing data.
    $existing_periods = $pdo->query(
        'SELECT DISTINCT year, month FROM aggregated_bird_observation'
    )->fetchAll(PDO::FETCH_ASSOC);
    $existing_set = [];
    foreach ($existing_periods as $ep) {
        $existing_set[$ep['year'] . '-' . $ep['month']] = true;
    }

    $new_periods = array_filter($raw_periods, function ($p) use ($existing_set) {
        return !isset($existing_set[$p['year'] . '-' . $p['month']]);
    });

    if (empty($new_periods)) {
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS _agg_snap, _agg_lc');
        $emit('All raw periods already present in aggregated_bird_observation — nothing to add.', 'info');
        return ['periods' => 0, 'rows_deleted' => 0, 'rows_inserted' => 0];
    }

    $period_where_parts = [];
    foreach ($new_periods as $p) {
        $period_where_parts[] = sprintf('(rbo.year = %d AND rbo.month = %d)', (int)$p['year'], (int)$p['month']);
    }
    $period_where = implode(' OR ', $period_where_parts);
    $rows_deleted = 0;

    // Insert aggregated rows only for new periods
    $rows_inserted = (int) $pdo->exec(
        "INSERT INTO aggregated_bird_observation
             (site_name, latitude, longitude, month, year,
              total_resident, total_migratory, total_tolerant, total_sensitive,
              bird_count, unique_species_count, species_list, grid_lat, grid_lon)
         SELECT
             rbo.site_name,
             rbo.latitude,
             rbo.longitude,
             rbo.month,
             rbo.year,
             SUM(CASE WHEN sm.migratory_status = 'Resident'  THEN 1 ELSE 0 END),
             SUM(CASE WHEN sm.migratory_status = 'Migratory' THEN 1 ELSE 0 END),
             SUM(CASE WHEN sm.light_tolerance  = 'Tolerant'  THEN 1 ELSE 0 END),
             SUM(CASE WHEN sm.light_tolerance  = 'Sensitive' THEN 1 ELSE 0 END),
             SUM(COALESCE(rbo.bird_count, 1)),
             COUNT(DISTINCT rbo.species_id),
             CONCAT('[', GROUP_CONCAT(DISTINCT JSON_QUOTE(sm.species_name) ORDER BY sm.species_name SEPARATOR ','), ']'),
             sn.grid_lat,
             sn.grid_lon
         FROM raw_bird_observation rbo
         JOIN species_masterlist sm  ON sm.species_id  = rbo.species_id
         LEFT JOIN _agg_snap sn      ON sn.raw_lat     = rbo.latitude
                                    AND sn.raw_lon     = rbo.longitude
         WHERE ({$period_where})
         GROUP BY rbo.site_name, rbo.latitude, rbo.longitude, rbo.month, rbo.year,
                  sn.grid_lat, sn.grid_lon"
    );

    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS _agg_snap, _agg_lc');

    $emit("Aggregation complete — {$rows_inserted} rows written to aggregated_bird_observation.", 'info');

    return [
        'periods'       => $period_count,
        'rows_deleted'  => $rows_deleted,
        'rows_inserted' => $rows_inserted,
    ];
}

// ── Dry-run: return coverage summary for frontend confirmation dialog ──────────
if (!$confirmed) {
    try {
        // Count raw observations not yet reflected in aggregated_bird_observation
        $raw_total = (int) $pdo->query('SELECT COUNT(*) FROM raw_bird_observation')->fetchColumn();
        $raw_years = $pdo->query(
            'SELECT DISTINCT year FROM raw_bird_observation ORDER BY year'
        )->fetchAll(PDO::FETCH_COLUMN);

        // Readiness is computed against aggregated_bird_observation (pre-aggregation).
        // After the user confirms, aggregation runs first so these numbers may improve.
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

        // Warn about raw observations that are pending aggregation
        $raw_note = null;
        if ($raw_total > 0) {
            $raw_note = sprintf(
                '%s raw observation row(s) across year(s) %s will be aggregated into aggregated_bird_observation before the merge runs.',
                number_format($raw_total),
                implode(', ', $raw_years)
            );
        }

        ob_end_clean();
        echo json_encode([
            'success'      => true,
            'dry_run'      => true,
            'ready_years'  => $ready_years,
            'year_detail'  => $readiness['year_detail'],
            'current_rows' => $current_rows,
            'message'      => $msg,
            'raw_note'     => $raw_note,
            'raw_total'    => $raw_total,
        ]);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Execute rebuild ────────────────────────────────────────────────────────────

$log_lines = [];

$emit = function (string $msg, string $level = 'info') use (&$log_lines): void {
    $log_lines[] = json_encode(['level' => $level, 'msg' => $msg]);
};

// Step 0: Aggregate raw_bird_observation → aggregated_bird_observation
// This must happen before the readiness check so newly uploaded data is visible.
try {
    aggregate_raw_observations($pdo, $emit);
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Aggregation failed: ' . $e->getMessage(), 'log' => $log_lines]);
    exit;
}

// Re-compute ready years (prevents races since the user may have fetched more
// covariates between the dry-run and the confirmation click, and aggregation
// may have added years that weren't in aggregated_bird_observation before).
try {
    $readiness   = compute_year_readiness($pdo);
    $ready_years = $readiness['ready'];
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Coverage check failed: ' . $e->getMessage(), 'log' => $log_lines]);
    exit;
}

if (empty($ready_years)) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'error'   => 'No years have complete covariate coverage. Nothing to rebuild.',
        'log'     => $log_lines,
    ]);
    exit;
}


$safe_years = implode(',', array_map('intval', $ready_years));

$python    = (PHP_OS_FAMILY === 'Windows') ? 'python' : 'python3';
$script    = escapeshellarg(realpath(__DIR__ . '/../python/build_master_grid.py'));
$cmd       = "$python $script --years " . escapeshellarg($safe_years) . " 2>&1";
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

ob_end_clean();
echo json_encode([
    'success'   => $success,
    'log'       => $log_lines,
    'error'     => $success ? null : ($error_msg ?? 'Rebuild did not complete successfully.'),
]);

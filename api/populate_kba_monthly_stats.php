<?php
/**
 * api/populate_kba_monthly_stats.php
 *
 * Rebuilds kba_pa_monthly_stats from kba_pa_coords + final_master_grid + raw_bird_observation.
 *
 * Authentication: requires an active session (is_logged_in()).
 * Method:        POST only.
 *
 * CLI (bypasses HTTP auth — only for server-side admin use):
 *   php api/populate_kba_monthly_stats.php
 */

define('KPA_CLI', PHP_SAPI === 'cli');

ob_start();
ini_set('display_errors', '0');
set_time_limit(0);  // final_master_grid has 600K+ rows; JOIN may take several seconds.

set_exception_handler(function (Throwable $e) {
    ob_end_clean();
    if (KPA_CLI) {
        fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

if (!KPA_CLI) {
    header('Content-Type: application/json; charset=utf-8');

    require_once __DIR__ . '/../includes/auth.php';

    if (!is_logged_in()) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
        exit;
    }
}

require_once __DIR__ . '/../includes/db.php';

function kpa_log(string $msg): void {
    if (KPA_CLI) {
        echo $msg . PHP_EOL;
    }
    // HTTP callers get the result JSON at the end; no streaming needed.
}

$t0  = microtime(true);
$log = [];

try {
    $pdo = get_mysql_db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── 1. Load sites ──────────────────────────────────────────────────────────
    $sites = $pdo->query(
        "SELECT kba_pa_id, kba_pa_name, grid_cells_json
         FROM kba_pa_coords
         WHERE grid_cells_json IS NOT NULL
           AND grid_cells_json NOT IN ('', '[]')"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sites)) {
        ob_end_clean();
        $msg = 'kba_pa_coords is empty or has no grid_cells_json. Nothing to do.';
        kpa_log('[SKIP] ' . $msg);
        if (!KPA_CLI) echo json_encode(['success' => true, 'rows' => 0, 'message' => $msg]);
        exit(0);
    }

    $log[] = 'Found ' . count($sites) . ' site(s) in kba_pa_coords.';
    kpa_log('[INFO]  ' . end($log));

    // ── 2. Stage grid cells in a temporary table ───────────────────────────────
    // Keyed on (lat, lon) so joins to final_master_grid and raw_bird_observation
    // hit idx_spatial_temporal and idx_bird_grid_time respectively.
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS _kpa_cells");
    $pdo->exec("
        CREATE TEMPORARY TABLE _kpa_cells (
            kba_pa_id BIGINT UNSIGNED NOT NULL,
            lat       DECIMAL(10,8)  NOT NULL,
            lon       DECIMAL(11,8)  NOT NULL,
            PRIMARY KEY (kba_pa_id, lat, lon),
            KEY idx_ll (lat, lon)
        )
    ");

    $insCell    = $pdo->prepare("INSERT INTO _kpa_cells (kba_pa_id, lat, lon) VALUES (?,?,?)");
    $totalCells = 0;

    foreach ($sites as $site) {
        $cells = json_decode((string)($site['grid_cells_json'] ?? '[]'), true);
        if (!is_array($cells)) {
            $log[] = 'WARN: ' . $site['kba_pa_name'] . ' — invalid grid_cells_json, skipped.';
            kpa_log('[WARN]  ' . end($log));
            continue;
        }
        $siteCells = 0;
        foreach ($cells as $cell) {
            if (!isset($cell['lat'], $cell['lon'])) continue;
            $insCell->execute([(int)$site['kba_pa_id'], (float)$cell['lat'], (float)$cell['lon']]);
            $siteCells++;
        }
        $log[] = $site['kba_pa_name'] . ': ' . $siteCells . ' cell(s)';
        kpa_log(sprintf('[CELL]  %-45s — %d cell(s)', $site['kba_pa_name'], $siteCells));
        $totalCells += $siteCells;
    }

    if ($totalCells === 0) {
        ob_end_clean();
        $msg = 'No valid grid cells found. Check grid_cells_json format (expected [{"lat":...,"lon":...},...]).';
        kpa_log('[SKIP]  ' . $msg);
        if (!KPA_CLI) echo json_encode(['success' => false, 'error' => $msg]);
        exit(KPA_CLI ? 1 : 0);
    }

    $log[] = 'Total grid cells staged: ' . $totalCells;
    kpa_log('[INFO]  ' . end($log));

    // ── 3. Full rebuild ────────────────────────────────────────────────────────
    $pdo->exec('TRUNCATE TABLE kba_pa_monthly_stats');
    kpa_log('[TRUNC] kba_pa_monthly_stats cleared.');

    // A) One row per (site, year, month) where VIIRS data exists.
    //    final_master_grid.idx_spatial_temporal (lat, lon, year, month) is used via lat/lon join.
    $pdo->exec("
        INSERT INTO kba_pa_monthly_stats
            (kba_pa_id, kba_pa_name, year, month, viirs_avg, species_count)
        SELECT
            c.kba_pa_id, kc.kba_pa_name, fmg.year, fmg.month,
            AVG(fmg.viirs_avg_rad), 0
        FROM _kpa_cells c
        JOIN kba_pa_coords kc ON kc.kba_pa_id = c.kba_pa_id
        JOIN final_master_grid fmg
            ON  fmg.lat = c.lat
            AND fmg.lon = c.lon
        WHERE fmg.viirs_avg_rad IS NOT NULL
        GROUP BY c.kba_pa_id, kc.kba_pa_name, fmg.year, fmg.month
    ");
    $afterViirs = (int)$pdo->query('SELECT COUNT(*) FROM kba_pa_monthly_stats')->fetchColumn();
    $log[] = 'Inserted ' . $afterViirs . ' site-month row(s) from final_master_grid.';
    kpa_log('[VIIRS] ' . end($log));

    // B) Update species_count on rows already inserted.
    //    raw_bird_observation.idx_bird_grid_time (grid_lat, grid_lon, year, month) used via lat/lon join.
    $pdo->exec("
        UPDATE kba_pa_monthly_stats kms
        JOIN (
            SELECT c.kba_pa_id, rbo.year, rbo.month,
                   COUNT(DISTINCT rbo.species_id) AS cnt
            FROM _kpa_cells c
            JOIN raw_bird_observation rbo
                ON  rbo.grid_lat = c.lat
                AND rbo.grid_lon = c.lon
            WHERE rbo.species_id IS NOT NULL
            GROUP BY c.kba_pa_id, rbo.year, rbo.month
        ) sp
            ON  sp.kba_pa_id = kms.kba_pa_id
            AND sp.year      = kms.year
            AND sp.month     = kms.month
        SET kms.species_count = sp.cnt
    ");
    $log[] = 'Species counts applied to existing rows.';
    kpa_log('[BIRD]  ' . end($log));

    // C) Insert months that have bird data but no VIIRS coverage for that cell.
    $pdo->exec("
        INSERT INTO kba_pa_monthly_stats
            (kba_pa_id, kba_pa_name, year, month, viirs_avg, species_count)
        SELECT sp.kba_pa_id, kc.kba_pa_name, sp.year, sp.month, NULL, sp.cnt
        FROM (
            SELECT c.kba_pa_id, rbo.year, rbo.month,
                   COUNT(DISTINCT rbo.species_id) AS cnt
            FROM _kpa_cells c
            JOIN raw_bird_observation rbo
                ON  rbo.grid_lat = c.lat
                AND rbo.grid_lon = c.lon
            WHERE rbo.species_id IS NOT NULL
            GROUP BY c.kba_pa_id, rbo.year, rbo.month
        ) sp
        JOIN kba_pa_coords kc ON kc.kba_pa_id = sp.kba_pa_id
        LEFT JOIN kba_pa_monthly_stats kms
            ON  kms.kba_pa_id = sp.kba_pa_id
            AND kms.year      = sp.year
            AND kms.month     = sp.month
        WHERE kms.id IS NULL
    ");

    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS _kpa_cells");

    $totalRows = (int)$pdo->query('SELECT COUNT(*) FROM kba_pa_monthly_stats')->fetchColumn();
    $elapsed   = round(microtime(true) - $t0, 2);

    $log[] = 'Done: ' . $totalRows . ' total row(s) in ' . $elapsed . 's.';
    kpa_log('[DONE]  ' . end($log));

    ob_end_clean();
    if (!KPA_CLI) {
        echo json_encode([
            'success' => true,
            'rows'    => $totalRows,
            'elapsed' => $elapsed,
            'log'     => $log,
        ]);
    }
    exit(0);

} catch (Throwable $e) {
    ob_end_clean();
    kpa_log('[ERROR] ' . $e->getMessage());
    if (!KPA_CLI) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(1);
}

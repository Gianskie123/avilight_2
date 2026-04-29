<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'checks' => []]);
    exit;
}
api_assert_active(); // also releases session lock for concurrent requests

$LAT_MIN = 14.3;  $LAT_MAX = 14.8;
$LON_MIN = 120.9; $LON_MAX = 121.15;

try {
    $db = get_mysql_db();

    // ── Ensure indexes exist so these scans are fast on large tables ──────────
    // Wrapped in try-catch: silently ignored if indexes already exist.
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_rbo_latlon  ON raw_bird_observation (latitude, longitude)",
        "CREATE INDEX IF NOT EXISTS idx_rbo_obs_id  ON raw_bird_observation (observation_id)",
        "CREATE INDEX IF NOT EXISTS idx_abo_grid    ON aggregated_bird_observation (grid_lat, grid_lon)",
    ] as $ddl) {
        try { $db->exec($ddl); } catch (Throwable $e) {}
    }

    // ── Query 1: 5 checks in a single pass over raw_bird_observation ──────────
    $raw = $db->query("
        SELECT
            SUM(latitude IS NULL OR longitude IS NULL)                                          AS null_coords,
            SUM(latitude = 0 OR longitude = 0)                                                  AS zero_coords,
            SUM(latitude  IS NOT NULL AND (latitude  < $LAT_MIN OR latitude  > $LAT_MAX))       AS out_lat,
            SUM(longitude IS NOT NULL AND (longitude < $LON_MIN OR longitude > $LON_MAX))       AS out_lon,
            SUM((latitude  IS NOT NULL AND (latitude  < 4.5  OR latitude  > 21.5))
             OR (longitude IS NOT NULL AND (longitude < 116.0 OR longitude > 127.0)))           AS outside_ph
        FROM raw_bird_observation
    ")->fetch(PDO::FETCH_ASSOC);

    // ── Query 2: duplicate observation IDs (needs its own subquery) ───────────
    $dupe_count = (int) $db->query("
        SELECT COUNT(*) FROM (
            SELECT observation_id FROM raw_bird_observation
            GROUP BY observation_id HAVING COUNT(*) > 1
        ) d
    ")->fetchColumn();

    // ── Query 3: 2 checks in a single pass over aggregated_bird_observation ───
    try {
        $agg = $db->query("
            SELECT
                SUM(grid_lat IS NULL OR grid_lon IS NULL)                                           AS null_grid,
                SUM(grid_lat IS NOT NULL AND grid_lon IS NOT NULL AND (
                    grid_lat < $LAT_MIN OR grid_lat > $LAT_MAX OR
                    grid_lon < $LON_MIN OR grid_lon > $LON_MAX
                ))                                                                                  AS out_grid
            FROM aggregated_bird_observation
        ")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $agg = ['null_grid' => 0, 'out_grid' => 0]; // table may not exist yet
    }

    $nc  = (int)($raw['null_coords'] ?? 0);
    $zc  = (int)($raw['zero_coords'] ?? 0);
    $ol  = (int)($raw['out_lat']     ?? 0);
    $oln = (int)($raw['out_lon']     ?? 0);
    $oph = (int)($raw['outside_ph']  ?? 0);
    $ng  = (int)($agg['null_grid']   ?? 0);
    $og  = (int)($agg['out_grid']    ?? 0);

    $checks = [
        ['label' => 'No null coordinates in raw observations',
         'table' => 'raw_bird_observation', 'pass' => $nc === 0,
         'detail' => $nc > 0 ? "{$nc} row(s) have NULL latitude or longitude" : null],

        ['label' => 'No zero-value coordinates in raw observations',
         'table' => 'raw_bird_observation', 'pass' => $zc === 0,
         'detail' => $zc > 0 ? "{$zc} row(s) have lat=0 or lon=0 (likely missing GPS fix)" : null],

        ['label' => "Latitude within {$LAT_MIN}°–{$LAT_MAX}° N (Metro Manila)",
         'table' => 'raw_bird_observation', 'pass' => $ol === 0,
         'detail' => $ol > 0 ? "{$ol} observation(s) outside latitude range" : null],

        ['label' => "Longitude within {$LON_MIN}°–{$LON_MAX}° E (Metro Manila)",
         'table' => 'raw_bird_observation', 'pass' => $oln === 0,
         'detail' => $oln > 0 ? "{$oln} observation(s) outside longitude range" : null],

        ['label' => 'No duplicate observation IDs in raw observations',
         'table' => 'raw_bird_observation', 'pass' => $dupe_count === 0,
         'detail' => $dupe_count > 0 ? "{$dupe_count} observation ID(s) appear more than once" : null],

        ['label' => 'All aggregated observations snapped to a grid cell',
         'table' => 'aggregated_bird_observation', 'pass' => $ng === 0,
         'detail' => $ng > 0 ? "{$ng} aggregated row(s) have no grid_lat/grid_lon — re-run aggregation to fix" : null],

        ['label' => 'All aggregated grid cells within Metro Manila bounds',
         'table' => 'aggregated_bird_observation', 'pass' => $og === 0,
         'detail' => $og > 0 ? "{$og} aggregated row(s) have grid coordinates outside Metro Manila bounding box" : null],

        ['label' => 'All coordinates within Philippines extent (4.5°–21.5° N, 116°–127° E)',
         'table' => 'raw_bird_observation', 'pass' => $oph === 0,
         'detail' => $oph > 0 ? "{$oph} observation(s) fall outside the Philippines entirely" : null],
    ];

    echo json_encode(['success' => true, 'checks' => $checks]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'checks' => [], 'error' => $e->getMessage()]);
}

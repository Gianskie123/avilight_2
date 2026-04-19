<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'checks' => []]);
    exit;
}

$LAT_MIN = 14.3; $LAT_MAX = 14.8;
$LON_MIN = 120.9; $LON_MAX = 121.15;
$checks = [];

try {
    $db = get_mysql_db();

    // 1. Null coordinates
    $v = (int) $db->query('SELECT COUNT(*) FROM raw_bird_observation WHERE latitude IS NULL OR longitude IS NULL')->fetchColumn();
    $checks[] = ['label' => 'No null coordinates in raw observations',      'table' => 'raw_bird_observation',          'pass' => $v === 0, 'detail' => $v > 0 ? "{$v} row(s) have NULL latitude or longitude" : null];

    // 2. Zero coordinates
    $v = (int) $db->query('SELECT COUNT(*) FROM raw_bird_observation WHERE latitude = 0 OR longitude = 0')->fetchColumn();
    $checks[] = ['label' => 'No zero-value coordinates in raw observations', 'table' => 'raw_bird_observation',          'pass' => $v === 0, 'detail' => $v > 0 ? "{$v} row(s) have lat=0 or lon=0 (likely missing GPS fix)" : null];

    // 3. Latitude within Metro Manila range
    $v = (int) $db->query("SELECT COUNT(*) FROM raw_bird_observation WHERE latitude IS NOT NULL AND (latitude < {$LAT_MIN} OR latitude > {$LAT_MAX})")->fetchColumn();
    $checks[] = ['label' => "Latitude within {$LAT_MIN}°–{$LAT_MAX}° N (Metro Manila)",          'table' => 'raw_bird_observation',          'pass' => $v === 0, 'detail' => $v > 0 ? "{$v} observation(s) outside latitude range {$LAT_MIN}°–{$LAT_MAX}° N" : null];

    // 4. Longitude within Metro Manila range
    $v = (int) $db->query("SELECT COUNT(*) FROM raw_bird_observation WHERE longitude IS NOT NULL AND (longitude < {$LON_MIN} OR longitude > {$LON_MAX})")->fetchColumn();
    $checks[] = ['label' => "Longitude within {$LON_MIN}°–{$LON_MAX}° E (Metro Manila)",         'table' => 'raw_bird_observation',          'pass' => $v === 0, 'detail' => $v > 0 ? "{$v} observation(s) outside longitude range {$LON_MIN}°–{$LON_MAX}° E" : null];

    // 5. Duplicate observation IDs
    $v = (int) $db->query('SELECT COUNT(*) FROM (SELECT observation_id FROM raw_bird_observation GROUP BY observation_id HAVING COUNT(*) > 1) AS d')->fetchColumn();
    $checks[] = ['label' => 'No duplicate observation IDs in raw observations',                    'table' => 'raw_bird_observation',          'pass' => $v === 0, 'detail' => $v > 0 ? "{$v} observation ID(s) appear more than once" : null];

    // 6. Aggregated rows snapped to grid
    $v = (int) $db->query('SELECT COUNT(*) FROM aggregated_bird_observation WHERE grid_lat IS NULL OR grid_lon IS NULL')->fetchColumn();
    $checks[] = ['label' => 'All aggregated observations snapped to a grid cell',                  'table' => 'aggregated_bird_observation',    'pass' => $v === 0, 'detail' => $v > 0 ? "{$v} aggregated row(s) have no grid_lat/grid_lon — re-run aggregation to fix" : null];

    // 7. Aggregated grid within Metro Manila
    $v = (int) $db->query("SELECT COUNT(*) FROM aggregated_bird_observation WHERE grid_lat IS NOT NULL AND grid_lon IS NOT NULL AND (grid_lat < {$LAT_MIN} OR grid_lat > {$LAT_MAX} OR grid_lon < {$LON_MIN} OR grid_lon > {$LON_MAX})")->fetchColumn();
    $checks[] = ['label' => 'All aggregated grid cells within Metro Manila bounds',                'table' => 'aggregated_bird_observation',    'pass' => $v === 0, 'detail' => $v > 0 ? "{$v} aggregated row(s) have grid coordinates outside Metro Manila bounding box" : null];

    // 8. Coordinates within Philippines extent
    $v = (int) $db->query('SELECT COUNT(*) FROM raw_bird_observation WHERE latitude IS NOT NULL AND (latitude < 4.5 OR latitude > 21.5) OR longitude IS NOT NULL AND (longitude < 116.0 OR longitude > 127.0)')->fetchColumn();
    $checks[] = ['label' => 'All coordinates within Philippines extent (4.5°–21.5° N, 116°–127° E)', 'table' => 'raw_bird_observation',       'pass' => $v === 0, 'detail' => $v > 0 ? "{$v} observation(s) fall outside the Philippines entirely" : null];

    echo json_encode(['success' => true, 'checks' => $checks]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'checks' => [], 'error' => $e->getMessage()]);
}

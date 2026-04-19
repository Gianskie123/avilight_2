<?php
/**
 * api/get_historical_data.php
 *
 * Returns observation data filtered by year and optional month.
 * Used by the dashboard historical data map layer.
 *
 * Query params:
 *   year  (required) – 4-digit year, e.g. 2014
 *   month (optional) – 1–12; omit to return all months
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : 0;
$month = isset($_GET['month']) ? (int)$_GET['month'] : 0;

if ($year < 2000 || $year > 2100) {
    echo json_encode(['error' => 'Invalid year']);
    exit;
}

try {
    $pdo = get_mysql_db();

    // Metro Manila geographic bounding box
    $mm_lat_min = 14.35;
    $mm_lat_max = 14.82;
    $mm_lng_min = 120.90;
    $mm_lng_max = 121.22;

    if ($month >= 1 && $month <= 12) {
        $stmt = $pdo->prepare(
            'SELECT species_list, site_name, latitude, longitude, month, year,
                unique_species_count AS total_unique, total_tolerant, total_sensitive,
                total_resident, total_migratory AS total_migrant, bird_count AS total_count
             FROM aggregated_bird_observation
             WHERE year = :yr AND month = :mo
               AND latitude  BETWEEN :lat_min AND :lat_max
               AND longitude BETWEEN :lng_min AND :lng_max
               AND latitude != 0 AND longitude != 0
               AND site_name != ""
             ORDER BY total_unique DESC'
        );
        $stmt->execute([
            ':yr'      => $year,
            ':mo'      => $month,
            ':lat_min' => $mm_lat_min,
            ':lat_max' => $mm_lat_max,
            ':lng_min' => $mm_lng_min,
            ':lng_max' => $mm_lng_max,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT species_list, site_name, latitude, longitude, month, year,
                unique_species_count AS total_unique, total_tolerant, total_sensitive,
                total_resident, total_migratory AS total_migrant, bird_count AS total_count
             FROM aggregated_bird_observation
             WHERE year = :yr
               AND latitude  BETWEEN :lat_min AND :lat_max
               AND longitude BETWEEN :lng_min AND :lng_max
               AND latitude != 0 AND longitude != 0
               AND site_name != ""
             ORDER BY total_unique DESC'
        );
        $stmt->execute([
            ':yr'      => $year,
            ':lat_min' => $mm_lat_min,
            ':lat_max' => $mm_lat_max,
            ':lng_min' => $mm_lng_min,
            ':lng_max' => $mm_lng_max,
        ]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>

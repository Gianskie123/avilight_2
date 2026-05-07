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
    echo json_encode(['success' => false, 'error' => 'Invalid year']);
    exit;
}

try {
    $pdo = get_mysql_db();

    // Metro Manila geographic bounding box
    $mm_lat_min = 14.35;
    $mm_lat_max = 14.82;
    $mm_lng_min = 120.90;
    $mm_lng_max = 121.22;

    $columns = $pdo->query('SHOW COLUMNS FROM aggregated_bird_observation')->fetchAll(PDO::FETCH_COLUMN);
    $hasAggregatedColumns = in_array('site_name', $columns, true)
        && in_array('year', $columns, true)
        && in_array('month', $columns, true)
        && in_array('latitude', $columns, true)
        && in_array('longitude', $columns, true);

    if ($hasAggregatedColumns) {
        $selectSpeciesList = in_array('species_list', $columns, true)
            ? 'species_list'
            : "'' AS species_list";
        $selectUnique = in_array('unique_species_count', $columns, true)
            ? 'COALESCE(unique_species_count, 0) AS total_unique'
            : (in_array('total_unique', $columns, true)
                ? 'COALESCE(total_unique, 0) AS total_unique'
                : '0 AS total_unique');
        $selectCount = in_array('bird_count', $columns, true)
            ? 'COALESCE(bird_count, 0) AS total_count'
            : (in_array('total_count', $columns, true)
                ? 'COALESCE(total_count, 0) AS total_count'
                : '0 AS total_count');
        $selectResident = in_array('total_resident', $columns, true)
            ? 'COALESCE(total_resident, 0) AS total_resident'
            : '0 AS total_resident';
        $selectMigrant = in_array('total_migratory', $columns, true)
            ? 'COALESCE(total_migratory, 0) AS total_migrant'
            : (in_array('total_migrant', $columns, true)
                ? 'COALESCE(total_migrant, 0) AS total_migrant'
                : '0 AS total_migrant');
        $selectTolerant = in_array('total_tolerant', $columns, true)
            ? 'COALESCE(total_tolerant, 0) AS total_tolerant'
            : '0 AS total_tolerant';
        $selectSensitive = in_array('total_sensitive', $columns, true)
            ? 'COALESCE(total_sensitive, 0) AS total_sensitive'
            : '0 AS total_sensitive';

        $sql = 'SELECT ' . $selectSpeciesList . ', site_name, latitude, longitude, month, year, '
            . $selectUnique . ', ' . $selectTolerant . ', ' . $selectSensitive . ', '
            . $selectResident . ', ' . $selectMigrant . ', ' . $selectCount . ' '
            . 'FROM aggregated_bird_observation '
            . 'WHERE year = :yr '
            . ($month >= 1 && $month <= 12 ? 'AND month = :mo ' : '')
            . 'AND latitude  BETWEEN :lat_min AND :lat_max '
            . 'AND longitude BETWEEN :lng_min AND :lng_max '
            . 'AND latitude != 0 AND longitude != 0 '
            . "AND site_name != '' "
            . 'ORDER BY total_unique DESC';

        $stmt = $pdo->prepare($sql);
        $params = [
            ':yr'      => $year,
            ':lat_min' => $mm_lat_min,
            ':lat_max' => $mm_lat_max,
            ':lng_min' => $mm_lng_min,
            ':lng_max' => $mm_lng_max,
        ];
        if ($month >= 1 && $month <= 12) {
            $params[':mo'] = $month;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rawColumns = $pdo->query('SHOW COLUMNS FROM raw_bird_observation')->fetchAll(PDO::FETCH_COLUMN);

        $hasModernRaw = in_array('year', $rawColumns, true)
            && in_array('month', $rawColumns, true)
            && in_array('latitude', $rawColumns, true)
            && in_array('longitude', $rawColumns, true)
            && in_array('species_id', $rawColumns, true);

        $hasLegacyRaw = in_array('submission_date', $rawColumns, true)
            && in_array('location_lat', $rawColumns, true)
            && in_array('location_long', $rawColumns, true)
            && in_array('species_name', $rawColumns, true);

        if ($hasModernRaw) {
            // Modern normalized schema.
            $sql = "SELECT
                    GROUP_CONCAT(DISTINCT sm.common_name ORDER BY sm.common_name SEPARATOR ', ') AS species_list,
                    COALESCE(NULLIF(rbo.site_name, ''), 'Observation Site') AS site_name,
                    rbo.latitude AS latitude,
                    rbo.longitude AS longitude,
                    rbo.month AS month,
                    rbo.year AS year,
                    COUNT(DISTINCT rbo.species_id) AS total_unique,
                    COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.light_tolerance)) = 'tolerant' THEN rbo.species_id END) AS total_tolerant,
                    COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.light_tolerance)) = 'sensitive' THEN rbo.species_id END) AS total_sensitive,
                    COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.migratory_status)) = 'resident' THEN rbo.species_id END) AS total_resident,
                    COUNT(DISTINCT CASE WHEN LOWER(TRIM(sm.migratory_status)) = 'migratory' THEN rbo.species_id END) AS total_migrant,
                    SUM(COALESCE(rbo.count, 0)) AS total_count
                FROM raw_bird_observation rbo
                LEFT JOIN species_masterlist sm ON sm.species_id = rbo.species_id
                WHERE rbo.year = :yr "
                . ($month >= 1 && $month <= 12 ? 'AND rbo.month = :mo ' : '')
                . 'AND rbo.latitude  BETWEEN :lat_min AND :lat_max
                AND rbo.longitude BETWEEN :lng_min AND :lng_max
                AND rbo.latitude != 0 AND rbo.longitude != 0
                GROUP BY site_name, rbo.latitude, rbo.longitude, rbo.month, rbo.year
                ORDER BY total_unique DESC';

            $stmt = $pdo->prepare($sql);
            $params = [
                ':yr'      => $year,
                ':lat_min' => $mm_lat_min,
                ':lat_max' => $mm_lat_max,
                ':lng_min' => $mm_lng_min,
                ':lng_max' => $mm_lng_max,
            ];
            if ($month >= 1 && $month <= 12) {
                $params[':mo'] = $month;
            }
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($hasLegacyRaw) {
            // Legacy raw schema found in some deployments.
            $sql = "SELECT
                    GROUP_CONCAT(DISTINCT rbo.species_name ORDER BY rbo.species_name SEPARATOR ', ') AS species_list,
                    COALESCE(NULLIF(rbo.habitat_type, ''), 'Observation Site') AS site_name,
                    ROUND(rbo.location_lat, 4) AS latitude,
                    ROUND(rbo.location_long, 4) AS longitude,
                    MONTH(rbo.submission_date) AS month,
                    YEAR(rbo.submission_date) AS year,
                    COUNT(DISTINCT rbo.species_name) AS total_unique,
                    0 AS total_tolerant,
                    0 AS total_sensitive,
                    0 AS total_resident,
                    0 AS total_migrant,
                    SUM(COALESCE(rbo.count, 0)) AS total_count
                FROM raw_bird_observation rbo
                WHERE YEAR(rbo.submission_date) = :yr "
                . ($month >= 1 && $month <= 12 ? 'AND MONTH(rbo.submission_date) = :mo ' : '')
                . 'AND rbo.location_lat  BETWEEN :lat_min AND :lat_max
                AND rbo.location_long BETWEEN :lng_min AND :lng_max
                AND rbo.location_lat != 0 AND rbo.location_long != 0
                GROUP BY site_name, ROUND(rbo.location_lat, 4), ROUND(rbo.location_long, 4), MONTH(rbo.submission_date), YEAR(rbo.submission_date)
                ORDER BY total_unique DESC';

            $stmt = $pdo->prepare($sql);
            $params = [
                ':yr'      => $year,
                ':lat_min' => $mm_lat_min,
                ':lat_max' => $mm_lat_max,
                ':lng_min' => $mm_lng_min,
                ':lng_max' => $mm_lng_max,
            ];
            if ($month >= 1 && $month <= 12) {
                $params[':mo'] = $month;
            }
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = [];
        }
    }

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

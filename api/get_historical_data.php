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
$selectedBird = isset($_GET['bird']) ? trim((string) $_GET['bird']) : '';
$migrationFilter = isset($_GET['migration']) ? strtolower(trim((string) $_GET['migration'])) : '';
$lightFilter = isset($_GET['light']) ? strtolower(trim((string) $_GET['light'])) : '';
$hasFilters = ($selectedBird !== '' || $migrationFilter !== '' || $lightFilter !== '');

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

    if ($hasAggregatedColumns && !$hasFilters) {
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
        if ($hasModernRaw) {
            $filterSql = 'rbo.year = :yr ';
            $params = [
                ':yr'      => $year,
                ':lat_min' => $mm_lat_min,
                ':lat_max' => $mm_lat_max,
                ':lng_min' => $mm_lng_min,
                ':lng_max' => $mm_lng_max,
            ];
            if ($month >= 1 && $month <= 12) {
                $filterSql .= 'AND rbo.month = :mo ';
                $params[':mo'] = $month;
            }
            $filterSql .= 'AND rbo.latitude BETWEEN :lat_min AND :lat_max '
                . 'AND rbo.longitude BETWEEN :lng_min AND :lng_max '
                . 'AND rbo.latitude != 0 AND rbo.longitude != 0 ';

            if ($selectedBird !== '') {
                $filterSql .= 'AND sm.species_name = :bird ';
                $params[':bird'] = $selectedBird;
            }
            if ($migrationFilter !== '') {
                // Map migration filter to numeric code: 'resident' = 0, 'migratory' = 1
                $migrationCode = ($migrationFilter === 'resident') ? 0 : 1;
                $filterSql .= 'AND sm.migratory_status_code = :migration ';
                $params[':migration'] = $migrationCode;
            }
            if ($lightFilter !== '') {
                // Map light filter to numeric code: 'sensitive' = 0, 'tolerant' = 1
                $lightCode = ($lightFilter === 'sensitive') ? 0 : 1;
                $filterSql .= 'AND sm.light_tolerance_code = :light ';
                $params[':light'] = $lightCode;
            }

            $sql = "SELECT
                    GROUP_CONCAT(DISTINCT sm.species_name ORDER BY sm.species_name SEPARATOR ', ') AS species_list,
                    COALESCE(NULLIF(rbo.site_name, ''), 'Observation Site') AS site_name,
                    rbo.latitude AS latitude,
                    rbo.longitude AS longitude,
                    rbo.month AS month,
                    rbo.year AS year,
                    COUNT(DISTINCT rbo.species_id) AS total_unique,
                    COUNT(DISTINCT CASE WHEN sm.light_tolerance_code = 1 THEN rbo.species_id END) AS total_tolerant,
                    COUNT(DISTINCT CASE WHEN sm.light_tolerance_code = 0 THEN rbo.species_id END) AS total_sensitive,
                    COUNT(DISTINCT CASE WHEN sm.migratory_status_code = 0 THEN rbo.species_id END) AS total_resident,
                    COUNT(DISTINCT CASE WHEN sm.migratory_status_code = 1 THEN rbo.species_id END) AS total_migrant,
                    SUM(COALESCE(rbo.bird_count, 0)) AS total_count
                FROM raw_bird_observation rbo
                LEFT JOIN species_masterlist sm ON sm.species_id = rbo.species_id
                WHERE {$filterSql}
                GROUP BY site_name, rbo.latitude, rbo.longitude, rbo.month, rbo.year
                ORDER BY total_unique DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($hasLegacyRaw) {
            $filterSql = 'YEAR(rbo.submission_date) = :yr ';
            $params = [
                ':yr'      => $year,
                ':lat_min' => $mm_lat_min,
                ':lat_max' => $mm_lat_max,
                ':lng_min' => $mm_lng_min,
                ':lng_max' => $mm_lng_max,
            ];
            if ($month >= 1 && $month <= 12) {
                $filterSql .= 'AND MONTH(rbo.submission_date) = :mo ';
                $params[':mo'] = $month;
            }
            $filterSql .= 'AND rbo.location_lat BETWEEN :lat_min AND :lat_max '
                . 'AND rbo.location_long BETWEEN :lng_min AND :lng_max '
                . 'AND rbo.location_lat != 0 AND rbo.location_long != 0 ';

            if ($selectedBird !== '') {
                $filterSql .= 'AND LOWER(TRIM(rbo.species_name)) = :bird ';
                $params[':bird'] = strtolower($selectedBird);
            }

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
                WHERE {$filterSql}
                GROUP BY site_name, ROUND(rbo.location_lat, 4), ROUND(rbo.location_long, 4), MONTH(rbo.submission_date), YEAR(rbo.submission_date)
                ORDER BY total_unique DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = [];
        }
    }

    $summary = [
        'total_unique' => 0,
        'resident' => 0,
        'migrant' => 0,
        'tolerant' => 0,
        'sensitive' => 0,
    ];
    $citySummary = [];

    if ($hasModernRaw) {
        // Metro Manila-wide total: all obs, no bounding box — matches Reports tab and metro_yearly_richness approach.
        $metroFilterSql = 'rbo.year = :yr ';
        $metroParams    = [':yr' => $year];
        if ($month >= 1 && $month <= 12) {
            $metroFilterSql .= 'AND rbo.month = :mo ';
            $metroParams[':mo'] = $month;
        }
        if ($selectedBird !== '') {
            $metroFilterSql .= 'AND sm.species_name = :bird ';
            $metroParams[':bird'] = $selectedBird;
        }
        if ($migrationFilter !== '') {
            $migrationCode = ($migrationFilter === 'resident') ? 0 : 1;
            $metroFilterSql .= 'AND sm.migratory_status_code = :migration ';
            $metroParams[':migration'] = $migrationCode;
        }
        if ($lightFilter !== '') {
            $lightCode = ($lightFilter === 'sensitive') ? 0 : 1;
            $metroFilterSql .= 'AND sm.light_tolerance_code = :light ';
            $metroParams[':light'] = $lightCode;
        }

        $summarySql = "SELECT
                COUNT(DISTINCT rbo.species_id) AS total_unique,
                COUNT(DISTINCT CASE WHEN sm.migratory_status_code = 0 THEN rbo.species_id END) AS total_resident,
                COUNT(DISTINCT CASE WHEN sm.migratory_status_code = 1 THEN rbo.species_id END) AS total_migrant,
                COUNT(DISTINCT CASE WHEN sm.light_tolerance_code = 1 THEN rbo.species_id END) AS total_tolerant,
                COUNT(DISTINCT CASE WHEN sm.light_tolerance_code = 0 THEN rbo.species_id END) AS total_sensitive
            FROM raw_bird_observation rbo
            LEFT JOIN species_masterlist sm ON sm.species_id = rbo.species_id
            WHERE {$metroFilterSql}";

        $summaryStmt = $pdo->prepare($summarySql);
        $summaryStmt->execute($metroParams);
        $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary = [
            'total_unique' => (int) ($summaryRow['total_unique'] ?? 0),
            'resident' => (int) ($summaryRow['total_resident'] ?? 0),
            'migrant' => (int) ($summaryRow['total_migrant'] ?? 0),
            'tolerant' => (int) ($summaryRow['total_tolerant'] ?? 0),
            'sensitive' => (int) ($summaryRow['total_sensitive'] ?? 0),
        ];

        // City-level breakdown: city_grid_map JOIN constrains to Metro Manila cities.
        $cityFilterSql = 'rbo.year = :yr ';
        $cityParams    = [':yr' => $year];
        if ($month >= 1 && $month <= 12) {
            $cityFilterSql .= 'AND rbo.month = :mo ';
            $cityParams[':mo'] = $month;
        }
        if ($selectedBird !== '') {
            $cityFilterSql .= 'AND sm.species_name = :bird ';
            $cityParams[':bird'] = $selectedBird;
        }
        if ($migrationFilter !== '') {
            $migrationCode = ($migrationFilter === 'resident') ? 0 : 1;
            $cityFilterSql .= 'AND sm.migratory_status_code = :migration ';
            $cityParams[':migration'] = $migrationCode;
        }
        if ($lightFilter !== '') {
            $lightCode = ($lightFilter === 'sensitive') ? 0 : 1;
            $cityFilterSql .= 'AND sm.light_tolerance_code = :light ';
            $cityParams[':light'] = $lightCode;
        }

        $citySql = "SELECT
                cgm.area AS area,
                COUNT(DISTINCT rbo.species_id) AS total_unique,
                COUNT(DISTINCT CASE WHEN sm.migratory_status_code = 0 THEN rbo.species_id END) AS total_resident,
                COUNT(DISTINCT CASE WHEN sm.migratory_status_code = 1 THEN rbo.species_id END) AS total_migrant,
                COUNT(DISTINCT CASE WHEN sm.light_tolerance_code = 1 THEN rbo.species_id END) AS total_tolerant,
                COUNT(DISTINCT CASE WHEN sm.light_tolerance_code = 0 THEN rbo.species_id END) AS total_sensitive
            FROM raw_bird_observation rbo
            INNER JOIN city_grid_map cgm
                ON cgm.lat = rbo.grid_lat
               AND cgm.lon = rbo.grid_lon
            LEFT JOIN species_masterlist sm ON sm.species_id = rbo.species_id
            WHERE {$cityFilterSql}
            GROUP BY cgm.area
            ORDER BY cgm.area";

        $cityStmt = $pdo->prepare($citySql);
        $cityStmt->execute($cityParams);
        $citySummary = $cityStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    echo json_encode([
        'success' => true,
        'data' => $rows,
        'summary' => $summary,
        'city_summary' => $citySummary,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

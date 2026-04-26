<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'locations' => [], 'available_years' => []]);
    exit;
}

$species_id = filter_input(INPUT_GET, 'species_id', FILTER_VALIDATE_INT);
if (!$species_id) {
    echo json_encode(['success' => false, 'locations' => [], 'available_years' => []]);
    exit;
}

$year  = filter_input(INPUT_GET, 'year',  FILTER_VALIDATE_INT);
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
if ($month !== null && ($month < 1 || $month > 12)) {
    $month = null;
}

try {
    $pdo = get_mysql_db();

    $where  = ["species_id = :sid", "site_name IS NOT NULL", "site_name != ''"];
    $params = [':sid' => $species_id];

    if ($year) {
        $where[]         = 'year = :year';
        $params[':year'] = $year;
    }
    if ($month) {
        $where[]          = 'month = :month';
        $params[':month'] = $month;
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT site_name, AVG(latitude) AS latitude, AVG(longitude) AS longitude, COUNT(*) AS sighting_count
           FROM raw_bird_observation
          WHERE $where_sql
          GROUP BY site_name
          ORDER BY sighting_count DESC
          LIMIT 10"
    );
    $stmt->execute($params);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Only fetch available years on the initial (unfiltered) load — the UI
    // populates the dropdown once and ignores it on subsequent filtered calls.
    $available_years = [];
    if (!$year && !$month) {
        $yr_stmt = $pdo->prepare(
            "SELECT DISTINCT year FROM raw_bird_observation
              WHERE species_id = :sid AND year IS NOT NULL
              ORDER BY year DESC"
        );
        $yr_stmt->execute([':sid' => $species_id]);
        $available_years = array_column($yr_stmt->fetchAll(PDO::FETCH_ASSOC), 'year');
    }

    echo json_encode([
        'success'         => true,
        'locations'       => $locations,
        'available_years' => $available_years,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'locations' => [], 'available_years' => [], 'error' => $e->getMessage()]);
}

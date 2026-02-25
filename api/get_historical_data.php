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
    $pdo = get_db();

    if ($month >= 1 && $month <= 12) {
        $stmt = $pdo->prepare(
            'SELECT site_name, latitude, longitude, month, year,
                    total_unique, total_tolerant, total_sensitive,
                    total_resident, total_migrant, total_count
             FROM observations
             WHERE year = :yr AND month = :mo
               AND latitude != 0 AND longitude != 0
               AND site_name != ""
             ORDER BY total_unique DESC'
        );
        $stmt->execute([':yr' => $year, ':mo' => $month]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT site_name, latitude, longitude, month, year,
                    total_unique, total_tolerant, total_sensitive,
                    total_resident, total_migrant, total_count
             FROM observations
             WHERE year = :yr
               AND latitude != 0 AND longitude != 0
               AND site_name != ""
             ORDER BY total_unique DESC'
        );
        $stmt->execute([':yr' => $year]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>

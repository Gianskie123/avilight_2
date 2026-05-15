<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
api_assert_active();

$startYear = 2014;
$endYear = 2025;

try {
    $pdo = get_mysql_db();

        $stmt = $pdo->prepare(
            'SELECT year, month, COUNT(DISTINCT species_id) AS richness
             FROM raw_bird_observation
             WHERE year    BETWEEN :start_year AND :end_year
               AND month   BETWEEN 1 AND 12
               AND latitude  BETWEEN 14.35 AND 14.82
               AND longitude BETWEEN 120.90 AND 121.22
               AND latitude  != 0
               AND longitude != 0
               AND species_id IS NOT NULL
             GROUP BY year, month
             ORDER BY year ASC, month ASC'
        );
        $stmt->execute([
            ':start_year' => $startYear,
            ':end_year'   => $endYear,
        ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $series = [];
    for ($y = $startYear; $y <= $endYear; $y++) {
        $series[$y] = array_fill(0, 12, null);
    }

    foreach ($rows as $row) {
        $year = (int) ($row['year'] ?? 0);
        $month = (int) ($row['month'] ?? 0);
        if ($year < $startYear || $year > $endYear || $month < 1 || $month > 12) {
            continue;
        }
        $series[$year][$month - 1] = round((float) ($row['richness'] ?? 0), 2);
    }

    echo json_encode([
        'success' => true,
        'start_year' => $startYear,
        'end_year' => $endYear,
        'series' => $series,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

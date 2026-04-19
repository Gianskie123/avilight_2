<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$startYear = 2014;
$endYear = 2025;

try {
    $pdo = get_db();

        $stmt = $pdo->prepare(
                'SELECT year, month, AVG(unique_species_count) AS richness
                 FROM aggregated_bird_observation
                 WHERE year BETWEEN :start_year AND :end_year
                     AND month BETWEEN 1 AND 12
                     AND latitude != 0 AND longitude != 0
                 GROUP BY year, month
                 ORDER BY year ASC, month ASC'
        );
    $stmt->execute([
        ':start_year' => $startYear,
        ':end_year' => $endYear,
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

    foreach ($series as $year => $values) {
        $nonNull = array_values(array_filter($values, static fn($v) => $v !== null));
        $fallback = count($nonNull) > 0 ? (array_sum($nonNull) / count($nonNull)) : 0.0;
        foreach ($values as $idx => $v) {
            if ($v === null) {
                $values[$idx] = round($fallback, 2);
            }
        }
        $series[$year] = $values;
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

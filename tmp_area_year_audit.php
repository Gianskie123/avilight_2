<?php
require __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();

$areas = [
    'Caloocan', 'Las Piñas', 'Makati', 'Malabon', 'Mandaluyong', 'Manila',
    'Marikina', 'Muntinlupa', 'Navotas', 'Parañaque', 'Pasay', 'Pasig',
    'Pateros', 'Quezon City', 'San Juan', 'Taguig', 'Valenzuela'
];

$summaryStmt = $pdo->query("SELECT area, year, bird_richness, viirs_avg, ndvi_avg, lst_avg, precipitation_total
    FROM ecological_yearly_summary
    ORDER BY area, year");
$summaryRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$obsStmt = $pdo->query("SELECT m.area, r.year, COUNT(*) AS sightings, COUNT(DISTINCT r.species_id) AS species_count
    FROM raw_bird_observation r
    JOIN observation_city_map m ON m.observation_id = r.observation_id
    GROUP BY m.area, r.year
    ORDER BY m.area, r.year");
$obsRows = $obsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$obsLookup = [];
foreach ($obsRows as $row) {
    $obsLookup[$row['area'] . '|' . $row['year']] = [
        'sightings' => (int) $row['sightings'],
        'species_count' => (int) $row['species_count'],
    ];
}

$byArea = [];
foreach ($areas as $area) {
    $byArea[$area] = [
        'missing_environment' => [],
        'no_bird_sightings' => [],
        'rows' => [],
    ];
}

foreach ($summaryRows as $row) {
    $area = (string) $row['area'];
    $year = (int) $row['year'];
    $envMissing = [];
    foreach (['viirs_avg', 'ndvi_avg', 'lst_avg', 'precipitation_total'] as $field) {
        if (!isset($row[$field]) || $row[$field] === null) {
            $envMissing[] = $field;
        }
    }
    $obs = $obsLookup[$area . '|' . $year] ?? ['sightings' => 0, 'species_count' => 0];
    $noSightings = ($obs['species_count'] === 0);

    $entry = [
        'year' => $year,
        'bird_richness' => $row['bird_richness'],
        'env_missing' => $envMissing,
        'bird_sightings' => $obs['sightings'],
        'species_count' => $obs['species_count'],
    ];
    $byArea[$area]['rows'][] = $entry;

    if (!empty($envMissing)) {
        $byArea[$area]['missing_environment'][] = [
            'year' => $year,
            'missing_fields' => $envMissing,
        ];
    }
    if ($noSightings) {
        $byArea[$area]['no_bird_sightings'][] = $year;
    }
}

echo json_encode($byArea, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

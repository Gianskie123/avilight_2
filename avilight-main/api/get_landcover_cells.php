<?php
/**
 * api/get_landcover_cells.php
 *
 * Returns AviLight_LandCover_GeoJSON.geojson filtered by year.
 * Used by the dashboard historical data map to show the land cover background.
 *
 * Query params:
 *   year (required) – 4-digit year, e.g. 2014  (GeoJSON covers 2014–2020)
 */
header('Content-Type: application/json');

$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
if ($year < 2000 || $year > 2100) {
    echo json_encode(['error' => 'Invalid year']);
    exit;
}

$geojson_path = __DIR__ . '/../AviLight_LandCover_GeoJSON.geojson';
if (!is_readable($geojson_path)) {
    echo json_encode(['error' => 'GeoJSON file not found']);
    exit;
}

$raw  = file_get_contents($geojson_path);
$data = json_decode($raw, true);

$filtered = array_values(array_filter($data['features'], function($f) use ($year) {
    return isset($f['properties']['year']) && (int)$f['properties']['year'] === $year;
}));

echo json_encode([
    'type'     => 'FeatureCollection',
    'features' => $filtered,
]);
?>

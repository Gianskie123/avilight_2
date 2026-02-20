<?php
// API endpoint to export data as GeoJSON
header('Content-Type: application/geo+json');
header('Content-Disposition: attachment; filename="avilight_export_' . date('Y-m-d') . '.geojson"');

// In production, this would:
// 1. Query database for current predictions
// 2. Generate GeoJSON with all cells
// 3. Include predictions, SHAP values, etc.

// For now, return the existing GeoJSON with added prediction data
$geojson_path = __DIR__ . '/../AviLight_LandCover_GeoJSON.geojson';

if (file_exists($geojson_path)) {
    // Stream file directly to output to avoid loading 13MB into PHP memory
    readfile($geojson_path);
} else {
    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => [],
        'error' => 'GeoJSON file not found'
    ]);
}
?>

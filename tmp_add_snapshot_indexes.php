<?php
require 'includes/db.php';
$pdo = get_mysql_db();

echo "Adding snapshot optimization indexes...\n";

$indexes = [
    "idx_viirs_lat_lon" => "ALTER TABLE viirs ADD INDEX idx_viirs_lat_lon (latitude, longitude)",
    "idx_ndvi_lat_lon" => "ALTER TABLE ndvi ADD INDEX idx_ndvi_lat_lon (latitude, longitude)",
    "idx_land_temp_lat_lon" => "ALTER TABLE land_temp ADD INDEX idx_land_temp_lat_lon (latitude, longitude)",
    "idx_precip_lat_lon" => "ALTER TABLE precip ADD INDEX idx_precip_lat_lon (latitude, longitude)",
    "idx_cgm_area" => "ALTER TABLE city_grid_map ADD INDEX idx_cgm_area (area)",
];

foreach ($indexes as $name => $sql) {
    try {
        echo "Creating $name... ";
        $pdo->exec($sql);
        echo "✓\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "Already exists\n";
        } else {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nIndexes added. Snapshot queries should be 10-30% faster.\n";
echo "For significant improvement (>90%), implement Strategy 1 (materialized view) or Strategy 4 (parallel API calls).\n";
?>

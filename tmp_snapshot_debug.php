<?php
require 'includes/db.php';
$pdo = get_mysql_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cities = ['Caloocan','Las Piñas','Makati','Malabon','Mandaluyong','Manila','Marikina','Muntinlupa','Navotas','Parañaque','Pasay','Pasig','Pateros','Quezon City','San Juan','Taguig','Valenzuela'];

// Check raw_bird_observation count
$count = $pdo->query('SELECT COUNT(*) FROM raw_bird_observation WHERE species_id IS NOT NULL')->fetchColumn();
echo "Total observations with species_id: $count\n";

// Test simple migration query
$sql = "SELECT LOWER(TRIM(sm.migratory_status)) AS category, COUNT(DISTINCT r.species_id) AS species_count
    FROM raw_bird_observation r FORCE INDEX (idx_rbo_year_month_species_id)
    JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo) ON m.rbo_id = r.id
    JOIN species_masterlist sm ON sm.species_id = r.species_id
    WHERE r.year >= 2024 AND r.month >= 1 AND r.year <= 2025 AND r.month <= 3
    AND r.species_id IS NOT NULL
    GROUP BY category";

echo "\nTesting migration query...\n";
try {
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Migration query returned " . count($rows) . " rows:\n";
    foreach ($rows as $row) {
        echo "  " . $row['category'] . ": " . $row['species_count'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Test snapshot cache insert
echo "\nTesting snapshot_cache insert...\n";
$insertSql = "INSERT INTO snapshot_cache 
    (area, year_start, month_start, year_end, month_end, migration_migratory, migration_resident, migration_unclassified, 
     light_sensitive, light_tolerant, light_unclassified, richness_count)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP";

try {
    $stmt = $pdo->prepare($insertSql);
    $stmt->execute(['All Areas', 2024, 1, 2025, 3, 5, 10, 2, 3, 12, 2, 25]);
    echo "✓ Insert successful\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check what's in the cache
echo "\nSnapshot cache entries:\n";
$cacheRows = $pdo->query('SELECT * FROM snapshot_cache ORDER BY created_at DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($cacheRows) . " entries\n";
foreach ($cacheRows as $row) {
    echo "  " . $row['area'] . " (" . $row['year_start'] . "-" . $row['month_start'] . " to " . $row['year_end'] . "-" . $row['month_end'] . ")\n";
}

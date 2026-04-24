<?php
require "includes/db.php";
$pdo = get_mysql_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Checking execution plan for the query...\n";
$sql = "EXPLAIN SELECT LOWER(TRIM(sm.migratory_status)) AS category, COUNT(DISTINCT r.species_id) AS species_count
    FROM raw_bird_observation r FORCE INDEX (idx_rbo_year_month_species_id)
    JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo) ON m.rbo_id = r.id
    JOIN species_masterlist sm ON sm.species_id = r.species_id
    WHERE r.year >= 2024 AND r.month >= 1 AND r.year <= 2025 AND r.month <= 3
    AND r.species_id IS NOT NULL
    GROUP BY category";

try {
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        print_r($row);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

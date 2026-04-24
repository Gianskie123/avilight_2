<?php
require "includes/db.php";
$pdo = get_mysql_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "EXPLAIN SELECT LOWER(TRIM(sm.migratory_status)) AS category, COUNT(DISTINCT r.species_id) AS species_count
    FROM raw_bird_observation r
    JOIN species_masterlist sm ON sm.species_id = r.species_id
    WHERE r.year >= 2024 AND r.month >= 1 AND r.year <= 2025 AND r.month <= 3
    AND r.species_id IS NOT NULL
    GROUP BY category";

try {
    echo "Plan WITHOUT city join:\n";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) { print_r($row); }
} catch (Exception $e) { echo "Error: " . $e->getMessage() . "\n"; }

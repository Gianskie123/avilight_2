<?php
/**
 * snapshot_cache_init.php
 * Creates the snapshot_cache table and populates it with pre-aggregated snapshot metrics.
 * Run this once: php snapshot_cache_init.php
 */

require 'includes/db.php';
$pdo = get_mysql_db();

// Create the snapshot_cache table
$createTableSql = "
CREATE TABLE IF NOT EXISTS snapshot_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    area VARCHAR(100) NOT NULL,
    year_start INT NOT NULL,
    month_start INT NOT NULL,
    year_end INT NOT NULL,
    month_end INT NOT NULL,
    
    -- Species distributions
    migration_migratory INT DEFAULT 0,
    migration_resident INT DEFAULT 0,
    migration_unclassified INT DEFAULT 0,
    
    light_sensitive INT DEFAULT 0,
    light_tolerant INT DEFAULT 0,
    light_unclassified INT DEFAULT 0,
    
    -- Richness
    richness_count INT DEFAULT 0,
    
    -- Environmental metrics (aggregated by area)
    viirs_avg FLOAT,
    ndvi_avg FLOAT,
    lst_avg FLOAT,
    precip_avg FLOAT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_area_dates (area, year_start, month_start, year_end, month_end),
    KEY idx_area (area),
    KEY idx_dates (year_start, month_start, year_end, month_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

echo "Creating snapshot_cache table...\n";
try {
    $pdo->exec($createTableSql);
    echo "✓ Table created\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'already exists') === false) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
    echo "✓ Table already exists\n";
}

// Helper function to build snapshot range SQL
function buildSnapshotRangeSql(string $alias = 'r'): string {
    return "((({$alias}.year > :snapshot_start_year) OR ({$alias}.year = :snapshot_start_year AND {$alias}.month >= :snapshot_start_month)) AND (({$alias}.year < :snapshot_end_year) OR ({$alias}.year = :snapshot_end_year AND {$alias}.month <= :snapshot_end_month)))";
}

// Function to populate cache for a specific area and date range
function populateSnapshotCacheEntry(PDO $pdo, string $area, int $yearStart, int $monthStart, int $yearEnd, int $monthEnd, array $cities): void {
    $snapshotRangeSql = buildSnapshotRangeSql('r');
    
    $params = [
        ':snapshot_start_year' => $yearStart,
        ':snapshot_start_month' => $monthStart,
        ':snapshot_end_year' => $yearEnd,
        ':snapshot_end_month' => $monthEnd,
    ];
    
    $areaClause = '';
    if ($area !== 'All Areas') {
        $areaClause = ' AND m.area = :area ';
        $params[':area'] = $area;
    }
    
    // Migration status query
    $migrationSql = "SELECT LOWER(TRIM(sm.migratory_status)) AS category, COUNT(DISTINCT r.species_id) AS species_count
        FROM raw_bird_observation r FORCE INDEX (idx_rbo_year_month_species_id)
        JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo) ON m.rbo_id = r.id
        JOIN species_masterlist sm ON sm.species_id = r.species_id
        WHERE {$snapshotRangeSql} AND r.species_id IS NOT NULL {$areaClause}
        GROUP BY category";
    
    $migStmt = $pdo->prepare($migrationSql);
    $migStmt->execute($params);
    $migrationData = ['migratory' => 0, 'resident' => 0, 'unclassified' => 0];
    foreach ($migStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cat = (string)($row['category'] ?? '');
        $count = (int)($row['species_count'] ?? 0);
        if ($cat === 'migratory') $migrationData['migratory'] += $count;
        elseif ($cat === 'resident') $migrationData['resident'] += $count;
        else $migrationData['unclassified'] += $count;
    }
    
    // Light tolerance query
    $lightSql = "SELECT LOWER(TRIM(sm.light_tolerance)) AS category, COUNT(DISTINCT r.species_id) AS species_count
        FROM raw_bird_observation r FORCE INDEX (idx_rbo_year_month_species_id)
        JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo) ON m.rbo_id = r.id
        JOIN species_masterlist sm ON sm.species_id = r.species_id
        WHERE {$snapshotRangeSql} AND r.species_id IS NOT NULL {$areaClause}
        GROUP BY category";
    
    $lightStmt = $pdo->prepare($lightSql);
    $lightStmt->execute($params);
    $lightData = ['sensitive' => 0, 'tolerant' => 0, 'unclassified' => 0];
    foreach ($lightStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cat = (string)($row['category'] ?? '');
        $count = (int)($row['species_count'] ?? 0);
        if ($cat === 'sensitive') $lightData['sensitive'] += $count;
        elseif ($cat === 'tolerant') $lightData['tolerant'] += $count;
        else $lightData['unclassified'] += $count;
    }
    
    // Richness query
    $areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $cities)) . "'";
    $areaExpr = ($area === 'All Areas') ? "m.area" : "'" . str_replace("'", "''", $area) . "'";
    
    $richnessSql = "SELECT STRAIGHT_JOIN COUNT(DISTINCT r.species_id) AS bird_richness
        FROM raw_bird_observation r FORCE INDEX (idx_rbo_year_month_species_id)
        JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo) ON m.rbo_id = r.id
        WHERE {$snapshotRangeSql} AND r.species_id IS NOT NULL AND m.area IN ({$areaListSql})";
    if ($area !== 'All Areas') {
        $richnessSql .= " AND m.area = :area";
    }
    
    $richStmt = $pdo->prepare($richnessSql);
    $richStmt->execute($params);
    $richness = (int)(($richStmt->fetch(PDO::FETCH_ASSOC) ?? [])['bird_richness'] ?? 0);
    
    // Environmental data query
    $envSql = "SELECT
            AVG(NULLIF(v.viirs_avg_rad, 0)) AS viirs,
            AVG(CASE WHEN n.ndvi = 0 THEN NULL WHEN ABS(n.ndvi) > 1 THEN n.ndvi / 10000 ELSE n.ndvi END) AS ndvi,
            AVG(CASE WHEN lt.lst IS NULL THEN NULL WHEN lt.lst > 0 THEN (lt.lst * 0.02) - 273.15 ELSE NULL END) AS lst,
            AVG(NULLIF(p.monthly_precip_mm, 0)) AS precipitation
        FROM city_grid_map cells
        LEFT JOIN viirs v ON ROUND(v.latitude, 4) = ROUND(cells.lat, 4) AND ROUND(v.longitude, 4) = ROUND(cells.lon, 4)
        LEFT JOIN ndvi n ON ROUND(n.latitude, 4) = ROUND(cells.lat, 4) AND ROUND(n.longitude, 4) = ROUND(cells.lon, 4)
        LEFT JOIN land_temp lt ON ROUND(lt.latitude, 4) = ROUND(cells.lat, 4) AND ROUND(lt.longitude, 4) = ROUND(cells.lon, 4)
        LEFT JOIN precip p ON ROUND(p.latitude, 4) = ROUND(cells.lat, 4) AND ROUND(p.longitude, 4) = ROUND(cells.lon, 4)
        WHERE cells.area" . ($area === 'All Areas' ? " IN ({$areaListSql})" : " = :area");
    
    $envStmt = $pdo->prepare($envSql);
    $envStmt->execute($params);
    $envRow = $envStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
    // Insert or update cache entry
    $insertSql = "INSERT INTO snapshot_cache 
        (area, year_start, month_start, year_end, month_end, migration_migratory, migration_resident, migration_unclassified, 
         light_sensitive, light_tolerant, light_unclassified, richness_count, viirs_avg, ndvi_avg, lst_avg, precip_avg)
        VALUES (:area, :year_start, :month_start, :year_end, :month_end, :mig_mig, :mig_res, :mig_uncl, 
                :light_sens, :light_tol, :light_uncl, :richness, :viirs, :ndvi, :lst, :precip)
        ON DUPLICATE KEY UPDATE
            migration_migratory = :mig_mig, migration_resident = :mig_res, migration_unclassified = :mig_uncl,
            light_sensitive = :light_sens, light_tolerant = :light_tol, light_unclassified = :light_uncl,
            richness_count = :richness, viirs_avg = :viirs, ndvi_avg = :ndvi, lst_avg = :lst, precip_avg = :precip,
            updated_at = CURRENT_TIMESTAMP";
    
    $insertStmt = $pdo->prepare($insertSql);
    $insertStmt->execute([
        ':area' => $area,
        ':year_start' => $yearStart,
        ':month_start' => $monthStart,
        ':year_end' => $yearEnd,
        ':month_end' => $monthEnd,
        ':mig_mig' => $migrationData['migratory'],
        ':mig_res' => $migrationData['resident'],
        ':mig_uncl' => $migrationData['unclassified'],
        ':light_sens' => $lightData['sensitive'],
        ':light_tol' => $lightData['tolerant'],
        ':light_uncl' => $lightData['unclassified'],
        ':richness' => $richness,
        ':viirs' => $envRow['viirs'] ?? null,
        ':ndvi' => $envRow['ndvi'] ?? null,
        ':lst' => $envRow['lst'] ?? null,
        ':precip' => $envRow['precipitation'] ?? null,
    ]);
}

// Get all areas
$cities = ['Caloocan','Las Piñas','Makati','Malabon','Mandaluyong','Manila','Marikina','Muntinlupa','Navotas','Parañaque','Pasay','Pasig','Pateros','Quezon City','San Juan','Taguig','Valenzuela'];
$areas = array_merge(['All Areas'], $cities);

// Common date ranges to pre-populate
$dateRanges = [
    ['2024', '1', '2025', '3'],   // Current default
    ['2024', '1', '2024', '12'],  // Full 2024
    ['2025', '1', '2025', '3'],   // YTD 2025
    ['2023', '1', '2024', '12'],  // Last 2 years
];

echo "\nPopulating snapshot_cache...\n";
$count = 0;
foreach ($areas as $area) {
    foreach ($dateRanges as [$ys, $ms, $ye, $me]) {
        try {
            populateSnapshotCacheEntry($pdo, $area, (int)$ys, (int)$ms, (int)$ye, (int)$me, $cities);
            $count++;
            echo ".";
        } catch (Exception $e) {
            echo "E(" . substr($e->getMessage(), 0, 20) . ")";
        }
    }
}
echo "\n";
echo "\n✓ Populated $count cache entries\n";

echo "\n=== Snapshot Cache Setup Complete ===\n";
echo "Next: Modify api/get_report_data.php to query snapshot_cache instead of computing live.\n";

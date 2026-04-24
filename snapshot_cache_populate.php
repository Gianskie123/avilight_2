<?php
/**
 * snapshot_cache_populate.php - Faster version
 * Populates snapshot_cache by batching simpler queries
 * Run: php snapshot_cache_populate.php
 */

require 'includes/db.php';
$pdo = get_mysql_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cities = ['Caloocan','Las Piñas','Makati','Malabon','Mandaluyong','Manila','Marikina','Muntinlupa','Navotas','Parañaque','Pasay','Pasig','Pateros','Quezon City','San Juan','Taguig','Valenzuela'];
$areas = array_merge(['All Areas'], $cities);

// Just populate "All Areas" for now - it's the most expensive and most used
$areas = ['All Areas'];

// Common date ranges
$dateRanges = [
    [2024, 1, 2025, 3],   // Current default
];

echo "Populating snapshot_cache (simplified)...\n";

$areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $cities)) . "'";

foreach ($areas as $area) {
    foreach ($dateRanges as [$ys, $ms, $ye, $me]) {
        echo "Processing $area ($ys-$ms to $ye-$me)... ";
        
        try {
            // Get migration/light data in one query by joining once
            $dataQuery = "SELECT 
                    sm.migratory_status, sm.light_tolerance,
                    COUNT(DISTINCT r.species_id) as cnt
                FROM raw_bird_observation r 
                FORCE INDEX (idx_rbo_year_month_species_id)
                JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo) ON m.rbo_id = r.id
                JOIN species_masterlist sm ON sm.species_id = r.species_id
                WHERE ((r.year > $ys) OR (r.year = $ys AND r.month >= $ms)) 
                  AND ((r.year < $ye) OR (r.year = $ye AND r.month <= $me))
                  AND r.species_id IS NOT NULL
                  AND m.area IN ($areaListSql)
                GROUP BY sm.migratory_status, sm.light_tolerance";
            
            $stmt = $pdo->query($dataQuery);
            $allData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Aggregate data
            $migData = ['migratory' => 0, 'resident' => 0, 'unclassified' => 0];
            $lightData = ['sensitive' => 0, 'tolerant' => 0, 'unclassified' => 0];
            
            foreach ($allData as $row) {
                $mig = strtolower(trim($row['migratory_status'] ?? ''));
                $light = strtolower(trim($row['light_tolerance'] ?? ''));
                $count = (int)$row['cnt'];
                
                if ($mig === 'migratory') $migData['migratory'] += $count;
                elseif ($mig === 'resident') $migData['resident'] += $count;
                else $migData['unclassified'] += $count;
                
                if ($light === 'sensitive') $lightData['sensitive'] += $count;
                elseif ($light === 'tolerant') $lightData['tolerant'] += $count;
                else $lightData['unclassified'] += $count;
            }
            
            // Get richness
            $richnessQuery = "SELECT COUNT(DISTINCT r.species_id) as cnt
                FROM raw_bird_observation r FORCE INDEX (idx_rbo_year_month_species_id)
                JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo) ON m.rbo_id = r.id
                WHERE ((r.year > $ys) OR (r.year = $ys AND r.month >= $ms))
                  AND ((r.year < $ye) OR (r.year = $ye AND r.month <= $me))
                  AND r.species_id IS NOT NULL
                  AND m.area IN ($areaListSql)";
            
            $richness = (int)($pdo->query($richnessQuery)->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
            
            // Insert/update cache
            $insertSql = "INSERT INTO snapshot_cache 
                (area, year_start, month_start, year_end, month_end, 
                 migration_migratory, migration_resident, migration_unclassified,
                 light_sensitive, light_tolerant, light_unclassified, richness_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    migration_migratory = VALUES(migration_migratory),
                    migration_resident = VALUES(migration_resident),
                    migration_unclassified = VALUES(migration_unclassified),
                    light_sensitive = VALUES(light_sensitive),
                    light_tolerant = VALUES(light_tolerant),
                    light_unclassified = VALUES(light_unclassified),
                    richness_count = VALUES(richness_count),
                    updated_at = CURRENT_TIMESTAMP";
            
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([
                $area, $ys, $ms, $ye, $me,
                $migData['migratory'], $migData['resident'], $migData['unclassified'],
                $lightData['sensitive'], $lightData['tolerant'], $lightData['unclassified'],
                $richness
            ]);
            
            echo "✓\n";
            
        } catch (Exception $e) {
            echo "Error: " . substr($e->getMessage(), 0, 60) . "\n";
        }
    }
}

echo "\nCache population complete!\n";
echo "Next step: Modify api/get_report_data.php to query snapshot_cache.\n";

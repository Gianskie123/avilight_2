<?php
require 'includes/db.php';
$pdo = get_mysql_db();

$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$cities = ['Caloocan','Las Piñas','Makati','Malabon','Mandaluyong','Manila','Marikina','Muntinlupa','Navotas','Parañaque','Pasay','Pasig','Pateros','Quezon City','San Juan','Taguig','Valenzuela'];
$snapshot_start_year = 2024;
$snapshot_start_month = 1;
$snapshot_end_year = 2025;
$snapshot_end_month = 3;
$selectedArea = 'All Areas';

function buildSnapshotRangeSql(string $alias = 'r'): string {
    return "((({$alias}.year > :snapshot_start_year) OR ({$alias}.year = :snapshot_start_year AND {$alias}.month >= :snapshot_start_month)) AND (({$alias}.year < :snapshot_end_year) OR ({$alias}.year = :snapshot_end_year AND {$alias}.month <= :snapshot_end_month)))";
}

// Test 1: fetchSnapshotSpeciesDistributions - 2 queries (migration + light tolerance)
echo "=== QUERY 1: Species Distributions (Migration Status) ===\n";
$areaClause = '';
if ($selectedArea !== 'All Areas') {
    $areaClause = ' AND m.area = :area ';
}
$snapshotRangeSql = buildSnapshotRangeSql('r');

$migrationSql = "SELECT STRAIGHT_JOIN
        LOWER(TRIM(sm.migratory_status)) AS category,
        COUNT(DISTINCT r.species_id) AS species_count
    FROM raw_bird_observation r FORCE INDEX (idx_rbo_year_month_species_id)
    JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo)
        ON m.rbo_id = r.id
    JOIN species_masterlist sm
        ON sm.species_id = r.species_id
    WHERE {$snapshotRangeSql}
      AND r.species_id IS NOT NULL
      {$areaClause}
    GROUP BY category";

$params = [
    ':snapshot_start_year' => $snapshot_start_year,
    ':snapshot_start_month' => $snapshot_start_month,
    ':snapshot_end_year' => $snapshot_end_year,
    ':snapshot_end_month' => $snapshot_end_month,
];

$t0 = microtime(true);
$migStmt = $pdo->prepare($migrationSql);
foreach ($params as $k => $v) {
    $migStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$migStmt->execute();
$rows = $migStmt->fetchAll();
$t1 = microtime(true);
echo "Migration: " . round(($t1 - $t0) * 1000, 2) . " ms (" . count($rows) . " rows)\n";

// Test 2: Light Tolerance (same structure)
echo "\n=== QUERY 2: Species Distributions (Light Tolerance) ===\n";
$lightSql = "SELECT STRAIGHT_JOIN
        LOWER(TRIM(sm.light_tolerance)) AS category,
        COUNT(DISTINCT r.species_id) AS species_count
    FROM raw_bird_observation r FORCE INDEX (idx_rbo_year_month_species_id)
    JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo)
        ON m.rbo_id = r.id
    JOIN species_masterlist sm
        ON sm.species_id = r.species_id
    WHERE {$snapshotRangeSql}
      AND r.species_id IS NOT NULL
      {$areaClause}
    GROUP BY category";

$t0 = microtime(true);
$lightStmt = $pdo->prepare($lightSql);
foreach ($params as $k => $v) {
    $lightStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$lightStmt->execute();
$rows = $lightStmt->fetchAll();
$t1 = microtime(true);
echo "Light Tolerance: " . round(($t1 - $t0) * 1000, 2) . " ms (" . count($rows) . " rows)\n";

// Test 3: Scatter Data - Richness by Area
echo "\n=== QUERY 3: Scatter Data (Richness by Area) ===\n";
$areaListSql = "'" . implode("','", array_map(static fn($c) => str_replace("'", "''", $c), $cities)) . "'";
$areaExpr = "m.area";
$richnessSql = "SELECT STRAIGHT_JOIN
        {$areaExpr} AS area,
        COUNT(DISTINCT r.species_id) AS bird_richness
    FROM raw_bird_observation r FORCE INDEX (idx_rbo_year_month_species_id)
    JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo)
        ON m.rbo_id = r.id
    WHERE {$snapshotRangeSql}
      AND r.species_id IS NOT NULL
      AND {$areaExpr} IN ({$areaListSql})
    GROUP BY {$areaExpr}
    HAVING bird_richness > 0
    ORDER BY {$areaExpr} ASC";

$t0 = microtime(true);
$richStmt = $pdo->prepare($richnessSql);
foreach ($params as $k => $v) {
    $richStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$richStmt->execute();
$rows = $richStmt->fetchAll();
$t1 = microtime(true);
echo "Richness by Area: " . round(($t1 - $t0) * 1000, 2) . " ms (" . count($rows) . " rows)\n";

// Test 4: Environmental Data (VIIRS, NDVI, LST, Precip)
echo "\n=== QUERY 4: Scatter Data (Environmental Metrics) ===\n";
$envSql = "SELECT
        cells.area AS area,
        AVG(NULLIF(v.viirs_avg_rad, 0)) AS viirs,
        AVG(
            CASE
                WHEN n.ndvi = 0 THEN NULL
                WHEN ABS(n.ndvi) > 1 THEN n.ndvi / 10000
                ELSE n.ndvi
            END
        ) AS ndvi,
        AVG(
            CASE
                WHEN lt.lst IS NULL THEN NULL
                WHEN lt.lst > 0 THEN (lt.lst * 0.02) - 273.15
                ELSE NULL
            END
        ) AS lst,
        AVG(NULLIF(p.monthly_precip_mm, 0)) AS precipitation
    FROM city_grid_map cells
    LEFT JOIN viirs v ON ROUND(v.latitude, 4) = ROUND(cells.lat, 4) AND ROUND(v.longitude, 4) = ROUND(cells.lon, 4)
    LEFT JOIN ndvi n ON ROUND(n.latitude, 4) = ROUND(cells.lat, 4) AND ROUND(n.longitude, 4) = ROUND(cells.lon, 4)
    LEFT JOIN land_temp lt ON ROUND(lt.latitude, 4) = ROUND(cells.lat, 4) AND ROUND(lt.longitude, 4) = ROUND(cells.lon, 4)
    LEFT JOIN precip p ON ROUND(p.latitude, 4) = ROUND(cells.lat, 4) AND ROUND(p.longitude, 4) = ROUND(cells.lon, 4)
    GROUP BY cells.area
    ORDER BY cells.area ASC";

$t0 = microtime(true);
$envStmt = $pdo->prepare($envSql);
$envStmt->execute();
$rows = $envStmt->fetchAll();
$t1 = microtime(true);
echo "Environmental Data: " . round(($t1 - $t0) * 1000, 2) . " ms (" . count($rows) . " rows)\n";

// Test 5: Top Sites
echo "\n=== QUERY 5: Top Sites Richness ===\n";
$topSitesSql = "SELECT STRAIGHT_JOIN
        r.site_name,
        COUNT(DISTINCT r.species_id) AS bird_richness
    FROM raw_bird_observation r FORCE INDEX (idx_rbo_year_month_site_species)
    JOIN observation_city_map m FORCE INDEX (idx_ocm_area_rbo)
        ON m.rbo_id = r.id
    WHERE {$snapshotRangeSql}
      AND r.species_id IS NOT NULL
      AND r.site_name IS NOT NULL
      AND m.area IN ({$areaListSql})
    GROUP BY r.site_name
    HAVING bird_richness > 0
    ORDER BY bird_richness DESC
    LIMIT 10";

$t0 = microtime(true);
$topStmt = $pdo->prepare($topSitesSql);
foreach ($params as $k => $v) {
    $topStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$topStmt->execute();
$rows = $topStmt->fetchAll();
$t1 = microtime(true);
echo "Top Sites: " . round(($t1 - $t0) * 1000, 2) . " ms (" . count($rows) . " rows)\n";

echo "\n✓ Individual query profiling complete\n";



<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$years = range(2014, 2025);

echo "year | cells_per_area_avg | avg_per_cell_final_master_sum | sum_across_cells_avg | avg_from_precip_mapping | avg_from_precip_exact\n";
foreach ($years as $y) {
    // average number of grid cells per area
    $cellsPerAreaAvgSql = "SELECT ROUND(AVG(cn),2) AS v FROM (SELECT COUNT(*) AS cn FROM city_grid_map GROUP BY area) t";
    $cellsPerAreaAvg = (float) ($pdo->query($cellsPerAreaAvgSql)->fetchColumn() ?: 0);

    // avg per-cell annual precip from final_master_grid (sum monthly_precip_mm per cell then avg across area)
    $sql1 = "SELECT ROUND(AVG(cell_annual),2) FROM (SELECT cell_id, SUM(CASE WHEN monthly_precip_mm>=0 THEN monthly_precip_mm ELSE 0 END) AS cell_annual FROM final_master_grid WHERE year = {$y} GROUP BY cell_id) t";
    $avgPerCellFinal = (float) ($pdo->query($sql1)->fetchColumn() ?: 0);

    // sum across cells then avg across area (i.e., for each area sum cell_annual then average those sums across areas)
    $sql2 = "SELECT ROUND(AVG(area_sum),2) FROM (SELECT cc.city_key AS area, SUM(fcell.cell_annual) AS area_sum FROM (SELECT cell_id, SUM(CASE WHEN monthly_precip_mm>=0 THEN monthly_precip_mm ELSE 0 END) AS cell_annual FROM final_master_grid WHERE year = {$y} GROUP BY cell_id) fcell JOIN city_cells cc ON fcell.cell_id = cc.cell_id GROUP BY cc.city_key) t";
    $avgAreaSum = (float) ($pdo->query($sql2)->fetchColumn() ?: 0);

    // avg per-area derived from raw precip aggregated by lat/lon then mapped to city_grid_map (round 6)
    $sql3 = "SELECT ROUND(AVG(area_annual),2) FROM (SELECT c.area, AVG(pr.annual_precip) AS area_annual FROM (SELECT latitude AS lat, longitude AS lon, year, SUM(CASE WHEN precip_mm>=0 THEN precip_mm ELSE 0 END) AS annual_precip FROM precip WHERE year = {$y} GROUP BY latitude, longitude, year) pr JOIN city_grid_map c ON ROUND(c.lat,6)=ROUND(pr.lat,6) AND ROUND(c.lon,6)=ROUND(pr.lon,6) GROUP BY c.area) t";
    $avgFromPrecip = (float) ($pdo->query($sql3)->fetchColumn() ?: 0);

    // avg per-area by exact lat/lon join (no rounding)
    $sql4 = "SELECT ROUND(AVG(area_annual),2) FROM (SELECT c.area, AVG(pr.annual_precip) AS area_annual FROM (SELECT latitude AS lat, longitude AS lon, year, SUM(CASE WHEN precip_mm>=0 THEN precip_mm ELSE 0 END) AS annual_precip FROM precip WHERE year = {$y} GROUP BY latitude, longitude, year) pr JOIN city_grid_map c ON c.lat=pr.lat AND c.lon=pr.lon GROUP BY c.area) t";
    $avgFromPrecipExact = (float) ($pdo->query($sql4)->fetchColumn() ?: 0);

    printf("%d | %.2f | %.2f | %.2f | %.2f | %.2f\n", $y, $cellsPerAreaAvg, $avgPerCellFinal, $avgAreaSum, $avgFromPrecip, $avgFromPrecipExact);
}

echo "\nDone.\n";

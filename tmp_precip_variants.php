<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();

function run_variant($pdo, $label, $sql) {
    echo "\n=== $label ===\n";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo ($r['year'] ?? '(null)') . ' ' . ($r['avg_precip'] ?? $r['precip']) . "\n";
    }
}

// Variant A: join precip -> city_grid_map using rounding precision
$precisions = [6,5,4,3];
foreach ($precisions as $p) {
    $sql = "SELECT prec.year AS year, ROUND(AVG(prec.area_annual_precip),2) AS avg_precip
        FROM (
            SELECT cells.area AS area, cell_precip.year AS year, AVG(cell_precip.annual_precip) AS area_annual_precip
            FROM (
                SELECT latitude AS lat, longitude AS lon, year,
                    SUM(CASE WHEN precip_mm >= 0 THEN precip_mm ELSE 0 END) AS annual_precip
                FROM precip
                GROUP BY latitude, longitude, year
            ) cell_precip
            JOIN city_grid_map cells ON ROUND(cells.lat,$p) = ROUND(cell_precip.lat,$p) AND ROUND(cells.lon,$p) = ROUND(cell_precip.lon,$p)
            GROUP BY cells.area, cell_precip.year
        ) prec
        GROUP BY prec.year
        ORDER BY prec.year";
    run_variant($pdo, "rounding={$p}", $sql);
}

// Variant B: aggregate final_master_grid monthly_precip_mm per cell (sum months) and map via city_cells
$sqlB = "SELECT t.year, ROUND(AVG(t.area_annual_precip),2) AS avg_precip
    FROM (
        SELECT cc.city_key AS area, f.year, SUM(CASE WHEN f.monthly_precip_mm >= 0 THEN f.monthly_precip_mm ELSE 0 END) AS area_annual_precip
        FROM final_master_grid f
        JOIN city_cells cc ON f.cell_id = cc.cell_id
        GROUP BY cc.city_key, f.year
    ) t
    GROUP BY t.year
    ORDER BY t.year";
run_variant($pdo, 'city_cells_from_final_master_grid', $sqlB);

// Variant C: aggregate precip by exact lat/lon (no rounding) and attempt join (may miss matches)
$sqlC = "SELECT prec.year AS year, ROUND(AVG(prec.area_annual_precip),2) AS avg_precip
        FROM (
            SELECT cells.area AS area, cell_precip.year AS year, AVG(cell_precip.annual_precip) AS area_annual_precip
            FROM (
                SELECT latitude AS lat, longitude AS lon, year,
                    SUM(CASE WHEN precip_mm >= 0 THEN precip_mm ELSE 0 END) AS annual_precip
                FROM precip
                GROUP BY latitude, longitude, year
            ) cell_precip
            JOIN city_grid_map cells ON cells.lat = cell_precip.lat AND cells.lon = cell_precip.lon
            GROUP BY cells.area, cell_precip.year
        ) prec
        GROUP BY prec.year
        ORDER BY prec.year";
run_variant($pdo, 'exact_latlon_join', $sqlC);

// Variant D: per-cell annual from final_master_grid but map via city_grid_map using rounding 6
$sqlD = "SELECT t.year, ROUND(AVG(t.area_annual_precip),2) AS avg_precip
    FROM (
        SELECT cgm.area AS area, f.year, AVG(SUM_MONTHS) AS area_annual_precip FROM (
            SELECT ROUND(lat,6) AS lat_r, ROUND(lon,6) AS lon_r, year, SUM(CASE WHEN monthly_precip_mm >= 0 THEN monthly_precip_mm ELSE 0 END) AS SUM_MONTHS
            FROM final_master_grid
            GROUP BY ROUND(lat,6), ROUND(lon,6), year
        ) f
        JOIN city_grid_map cgm ON ROUND(cgm.lat,6) = f.lat_r AND ROUND(cgm.lon,6) = f.lon_r
        GROUP BY cgm.area, f.year
    ) t
    GROUP BY t.year
    ORDER BY t.year";
run_variant($pdo, 'final_master_rounded6_to_city_grid_map', $sqlD);

// Variant E: per-cell annual from final_master_grid, map via city_cells, then average across cells
$sqlE = "SELECT t.year, ROUND(AVG(t.area_annual_precip),2) AS avg_precip
    FROM (
        SELECT cc.city_key AS area, ca.year, AVG(ca.cell_annual_precip) AS area_annual_precip
        FROM (
            SELECT cell_id, year, SUM(CASE WHEN monthly_precip_mm >= 0 THEN monthly_precip_mm ELSE 0 END) AS cell_annual_precip
            FROM final_master_grid
            GROUP BY cell_id, year
        ) ca
        JOIN city_cells cc ON ca.cell_id = cc.cell_id
        GROUP BY cc.city_key, ca.year
    ) t
    GROUP BY t.year
    ORDER BY t.year";
run_variant($pdo, 'final_master_per_cell_avg_via_city_cells', $sqlE);

echo "\nDone.\n";

<?php
require __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$start=2014; $end=2024;
$areas=['Caloocan','Las Pi?as','Makati','Malabon','Mandaluyong','Manila','Marikina','Muntinlupa','Navotas','Para?aque','Pasay','Pasig','Pateros','Quezon City','San Juan','Taguig','Valenzuela'];
$esc = array_map(fn($a)=>"'".str_replace("'","''",$a)."'",$areas);
$areaList = implode(',', $esc);
$sql = "WITH cells AS (
    SELECT DISTINCT c.area, CAST(abo.grid_lat AS DECIMAL(10,8)) lat, CAST(abo.grid_lon AS DECIMAL(11,8)) lon
    FROM aggregated_bird_observation abo
    JOIN (SELECT '".implode("' area UNION ALL SELECT '",$areas)."' area) c ON abo.site_name LIKE CONCAT('%', c.area, '%')
    WHERE abo.grid_lat IS NOT NULL AND abo.grid_lon IS NOT NULL
), cell_month AS (
    SELECT cells.area, f.year, f.month, f.lat, f.lon,
      AVG(NULLIF(f.viirs_avg_rad,0)) viirs_m,
      AVG(CASE WHEN ABS(f.ndvi)>1 THEN f.ndvi/10000 WHEN f.ndvi=0 THEN NULL ELSE f.ndvi END) ndvi_m,
      AVG(CASE WHEN f.lst_day>100 THEN (f.lst_day*0.02)-273.15 WHEN f.lst_day<=0 THEN NULL ELSE f.lst_day END) lst_m,
      AVG(CASE WHEN f.monthly_precip_mm<0 THEN NULL ELSE f.monthly_precip_mm END) precip_m
    FROM final_master_grid f
    JOIN cells ON cells.lat=f.lat AND cells.lon=f.lon
    WHERE f.year BETWEEN :start AND :end
      AND NOT (COALESCE(f.viirs_avg_rad,0)=0 AND COALESCE(f.ndvi,0)=0 AND COALESCE(f.lst_day,0)=0 AND COALESCE(f.monthly_precip_mm,0)=0)
    GROUP BY cells.area, f.year, f.month, f.lat, f.lon
), cell_year AS (
    SELECT area, year, lat, lon,
      AVG(viirs_m) viirs_y,
      AVG(ndvi_m) ndvi_y,
      AVG(lst_m) lst_y,
      SUM(precip_m) precip_y,
      COUNT(DISTINCT month) months
    FROM cell_month
    GROUP BY area, year, lat, lon
), balanced_cells AS (
    SELECT area, lat, lon
    FROM cell_year
    GROUP BY area, lat, lon
    HAVING COUNT(DISTINCT year) = (:end - :start + 1)
), area_year AS (
    SELECT cy.area, cy.year,
      AVG(cy.viirs_y) viirs,
      AVG(cy.ndvi_y) ndvi,
      AVG(cy.lst_y) lst,
      AVG(cy.precip_y) precip
    FROM cell_year cy
    JOIN balanced_cells bc ON bc.area=cy.area AND bc.lat=cy.lat AND bc.lon=cy.lon
    GROUP BY cy.area, cy.year
)
SELECT year, AVG(viirs) viirs, AVG(ndvi) ndvi, AVG(lst) lst, AVG(precip) precip
FROM area_year
GROUP BY year
ORDER BY year";
$st=$pdo->prepare($sql);$st->execute([':start'=>$start,':end'=>$end]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
function slope($arr,$k){$n=count($arr);if($n<2)return 0; $sx=0;$sy=0;$sxx=0;$sxy=0;foreach($arr as $i=>$r){$x=$i+1;$y=(float)$r[$k];$sx+=$x;$sy+=$y;$sxx+=$x*$x;$sxy+=$x*$y;} $d=$n*$sxx-$sx*$sx; return $d?($n*$sxy-$sx*$sy)/$d:0;}
echo 'rows='.count($rows)."\n";
echo 'viirs_slope='.round(slope($rows,'viirs'),6)."\n";
echo 'ndvi_slope='.round(slope($rows,'ndvi'),6)."\n";
echo 'lst_slope='.round(slope($rows,'lst'),6)."\n";
echo 'precip_slope='.round(slope($rows,'precip'),6)."\n";
echo json_encode($rows, JSON_UNESCAPED_UNICODE)."\n";

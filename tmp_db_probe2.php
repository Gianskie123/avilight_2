<?php
require __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$sqls = [
  'all_zero_rows' => "SELECT COUNT(*) AS c FROM final_master_grid WHERE COALESCE(viirs_avg_rad,0)=0 AND COALESCE(ndvi,0)=0 AND COALESCE(lst_day,0)=0 AND COALESCE(monthly_precip_mm,0)=0",
  'all_zero_pct' => "SELECT ROUND(100 * SUM(CASE WHEN COALESCE(viirs_avg_rad,0)=0 AND COALESCE(ndvi,0)=0 AND COALESCE(lst_day,0)=0 AND COALESCE(monthly_precip_mm,0)=0 THEN 1 ELSE 0 END)/COUNT(*),2) AS pct FROM final_master_grid",
  'dup_cell_month' => "SELECT COUNT(*) AS groups_with_dups FROM (SELECT lat, lon, year, month, COUNT(*) c FROM final_master_grid GROUP BY lat, lon, year, month HAVING COUNT(*) > 1) t",
  'dup_stats' => "SELECT AVG(c) AS avg_dup, MAX(c) AS max_dup FROM (SELECT lat, lon, year, month, COUNT(*) c FROM final_master_grid GROUP BY lat, lon, year, month HAVING COUNT(*) > 1) t",
  'nonzero_lst_stats' => "SELECT MIN(lst_day) AS min_v, MAX(lst_day) AS max_v, AVG(lst_day) AS avg_v FROM final_master_grid WHERE lst_day > 0",
  'nonzero_prec_stats' => "SELECT MIN(monthly_precip_mm) AS min_v, MAX(monthly_precip_mm) AS max_v, AVG(monthly_precip_mm) AS avg_v FROM final_master_grid WHERE monthly_precip_mm > 0"
];
foreach ($sqls as $name=>$sql){
  echo "=== {$name} ===\n";
  echo json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE) . "\n";
}

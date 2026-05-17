<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$sql = "SELECT year,
  COUNT(*) AS total,
  SUM(CASE WHEN monthly_precip_mm IS NULL OR monthly_precip_mm = 0 THEN 1 ELSE 0 END) AS zero_or_null,
  AVG(NULLIF(monthly_precip_mm,0)) AS avg_nonzero_monthly
FROM final_master_grid
GROUP BY year
ORDER BY year";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo ($r['year'] ?? '(null)') . ' total=' . $r['total'] . ' zero_or_null=' . $r['zero_or_null'] . ' avg_nonzero_monthly=' . round((float)$r['avg_nonzero_monthly'],4) . "\n";
}

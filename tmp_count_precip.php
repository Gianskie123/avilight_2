<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
foreach ([2023,2025] as $y) {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt, SUM(CASE WHEN monthly_precip_mm IS NOT NULL AND monthly_precip_mm >= 0 THEN 1 ELSE 0 END) AS nonneg FROM final_master_grid WHERE year = :y');
    $stmt->execute([':y' => $y]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Year $y: total=" . ($r['cnt'] ?? 0) . ", nonneg_monthly_precip_rows=" . ($r['nonneg'] ?? 0) . "\n";
}

<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$years = [2019,2022,2023,2024,2025];
foreach ($years as $y) {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt_rows, COUNT(DISTINCT CONCAT(lat,",",lon)) AS cells, COUNT(DISTINCT month) AS months FROM final_master_grid WHERE year = :y');
    $stmt->execute([':y'=>$y]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "final_master_grid year $y: rows=" . ($r['cnt_rows']??0) . ", cells=" . ($r['cells']??0) . ", months=" . ($r['months']??0) . "\n";
    $stmt2 = $pdo->prepare('SELECT COUNT(*) AS cnt_rows, COUNT(DISTINCT CONCAT(latitude,",",longitude)) AS cells, COUNT(DISTINCT month) AS months FROM precip WHERE year = :y');
    $stmt2->execute([':y'=>$y]);
    $r2 = $stmt2->fetch(PDO::FETCH_ASSOC);
    echo "precip table year $y: rows=" . ($r2['cnt_rows']??0) . ", cells=" . ($r2['cells']??0) . ", months=" . ($r2['months']??0) . "\n\n";
}


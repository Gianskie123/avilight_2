<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$years = [2023, 2025];
foreach ($years as $y) {
    $start = ($y * 12) + 1;
    $end = ($y * 12) + 12;
    // Method A: per-cell annual sum then average across cells
    $sqlA = "SELECT AVG(cs.annual_precip) AS methodA FROM (SELECT f.lat, f.lon, SUM(CASE WHEN f.monthly_precip_mm >= 0 THEN f.monthly_precip_mm ELSE NULL END) AS annual_precip FROM final_master_grid f WHERE ((f.year * 12) + f.month) BETWEEN (:s) AND (:e) GROUP BY f.lat, f.lon) cs";
    $stmtA = $pdo->prepare($sqlA);
    $stmtA->execute([':s' => $start, ':e' => $end]);
    $a = $stmtA->fetch(PDO::FETCH_ASSOC)['methodA'];

    // Method B: per-month average across cells then sum months
    $sqlB = "SELECT SUM(monthly_avg) AS methodB FROM (SELECT g.month, AVG(CASE WHEN g.monthly_precip_mm >= 0 THEN g.monthly_precip_mm ELSE NULL END) AS monthly_avg FROM final_master_grid g WHERE ((g.year * 12) + g.month) BETWEEN (:s2) AND (:e2) GROUP BY g.month) t";
    $stmtB = $pdo->prepare($sqlB);
    $stmtB->execute([':s2' => $start, ':e2' => $end]);
    $b = $stmtB->fetch(PDO::FETCH_ASSOC)['methodB'];

    echo "Year $y\n";
    echo "  Method A (per-cell sum then avg): " . number_format((float)$a,2) . "\n";
    echo "  Method B (monthly avg then sum):   " . number_format((float)$b,2) . "\n";
    echo "  Diff (A - B): " . number_format(((float)$a - (float)$b),2) . "\n\n";
}


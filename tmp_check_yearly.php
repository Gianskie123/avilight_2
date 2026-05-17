<?php
require 'includes/db.php';
$pdo = get_mysql_db();
$rows = $pdo->query('SELECT year, ROUND(AVG(precipitation_total),2) AS avg_precip FROM ecological_yearly_summary GROUP BY year ORDER BY year')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['year'] . ' ' . $r['avg_precip'] . "\n";
}

<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$sql = "SELECT year, ROUND(AVG(precipitation_total),2) AS avg_precip, ROUND(MIN(precipitation_total),2) AS min_precip, ROUND(MAX(precipitation_total),2) AS max_precip FROM ecological_yearly_summary WHERE year BETWEEN 2014 AND 2025 GROUP BY year ORDER BY year";
foreach ($pdo->query($sql) as $row) {
    echo $row['year'] . ' | ' . $row['avg_precip'] . ' | ' . $row['min_precip'] . ' | ' . $row['max_precip'] . PHP_EOL;
}


<?php
require 'includes/db.php';
$pdo = get_mysql_db();
$rows = $pdo->query('SELECT year, ROUND(precipitation_total,2) AS precip FROM metro_yearly_richness ORDER BY year')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { echo $r['year'] . ' ' . ($r['precip'] ?? '') . "\n"; }

<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$stmt = $pdo->prepare('SELECT area, precipitation_total, updated_at FROM ecological_yearly_summary WHERE year = :y ORDER BY area');
$stmt->execute([':y'=>2023]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo ($r['area']?:'All Areas') . ' | ' . ($r['precipitation_total']===null? 'NULL' : number_format((float)$r['precipitation_total'],2)) . ' | ' . $r['updated_at'] . PHP_EOL;
}


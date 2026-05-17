<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
echo "final_master_grid years:\n";
foreach ($pdo->query('SELECT DISTINCT year FROM final_master_grid ORDER BY year') as $r) {
    echo $r['year'] . "\n";
}
echo "precip years:\n";
foreach ($pdo->query('SELECT DISTINCT year FROM precip ORDER BY year') as $r) {
    echo $r['year'] . "\n";
}

<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
$stmt = $pdo->query('SELECT year, COUNT(*) AS cnt FROM final_master_grid GROUP BY year ORDER BY year');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo ($r['year'] ?? '(null)') . ' ' . $r['cnt'] . "\n";
}

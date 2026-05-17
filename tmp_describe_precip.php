<?php
require_once __DIR__ . '/includes/db.php';
$pdo = get_mysql_db();
try {
    $cols = $pdo->query('SHOW COLUMNS FROM precip')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo $c['Field'] . ' ' . $c['Type'] . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
}

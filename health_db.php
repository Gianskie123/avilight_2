<?php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    $pdo = get_mysql_db();
    $result = $pdo->query('SELECT 1 AS ok')->fetch(PDO::FETCH_ASSOC);
    if ($result && (int)$result['ok'] === 1) {
        echo "OK\n";
        exit;
    }
    echo "ERROR: Unexpected response from database.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

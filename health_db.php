<?php
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    $pdo = get_mysql_db();

    $hostname  = $pdo->query('SELECT @@hostname')->fetchColumn();
    $currentDb = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $currentUser = $pdo->query('SELECT USER()')->fetchColumn();

    $dbHost = getenv('DB_HOST') ?: getenv('MYSQL_HOST') ?: '127.0.0.1';
    $isLocal = in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true);
    $label = $isLocal ? 'LOCAL (Laragon MySQL)' : 'REMOTE (Digital Ocean)';

    echo "STATUS   : OK\n";
    echo "TARGET   : {$label}\n";
    echo "DB_HOST  : {$dbHost}\n";
    echo "HOSTNAME : {$hostname}\n";
    echo "DATABASE : {$currentDb}\n";
    echo "USER     : {$currentUser}\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

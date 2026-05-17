<?php
require_once __DIR__ . '/includes/db.php';
// Recompute environ as get_mysql_db does
$host   = getenv('DB_HOST') ?: getenv('MYSQL_HOST') ?: '127.0.0.1';
$port   = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: getenv('MYSQL_DATABASE') ?: 'avilight';
$user   = getenv('DB_USER') ?: getenv('MYSQL_USER') ?: 'root';
$pass   = getenv('DB_PASS') ?: getenv('MYSQL_PASSWORD') ?: '';
$databaseUrl = getenv('DATABASE_URL') ?: '';
if ($databaseUrl) {
    $parts = parse_url($databaseUrl);
    if ($parts !== false) {
        $host = $parts['host'] ?? $host;
        $port = $parts['port'] ?? $port;
        $user = $parts['user'] ?? $user;
        $pass = $parts['pass'] ?? $pass;
        if (isset($parts['path'])) {
            $dbname = ltrim($parts['path'], '/') ?: $dbname;
        }
    }
}
echo "Connection config used by get_mysql_db():\n";
echo "  host={$host}\n  port={$port}\n  dbname={$dbname}\n  user={$user}\n  (DATABASE_URL present=" . (!empty($databaseUrl) ? 'yes' : 'no') . ")\n\n";
try {
    $pdo = get_mysql_db();
    $serverHost = $pdo->query("SELECT @@hostname")->fetchColumn();
    $currentDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $currentUser = $pdo->query("SELECT USER()")->fetchColumn();
    echo "Connected to MySQL server:\n";
    echo "  server_host=" . ($serverHost ?? '') . "\n";
    echo "  current_db=" . ($currentDb ?? '') . "\n";
    echo "  current_user=" . ($currentUser ?? '') . "\n\n";

    $row = $pdo->query("SELECT MAX(updated_at) AS last_update FROM ecological_yearly_summary")->fetch(PDO::FETCH_ASSOC);
    echo "ecological_yearly_summary last_update: " . ($row['last_update'] ?? '(none)') . "\n";
    $row2 = $pdo->query("SELECT COUNT(*) AS cnt FROM ecological_yearly_summary")->fetch(PDO::FETCH_ASSOC);
    echo "ecological_yearly_summary row_count: " . ($row2['cnt'] ?? 0) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

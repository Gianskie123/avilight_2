<?php
// Explicit remote DB check using .env in project root
function load_dotenv($path) {
    if (!is_readable($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $vars = [];
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (!preg_match('/^\s*([^=]+)=(.*)$/', $line, $m)) continue;
        $k = trim($m[1]);
        $v = trim($m[2]);
        $v = trim($v, "\"'");
        $vars[$k] = $v;
    }
    return $vars;
}
$env = load_dotenv(__DIR__ . '/.env');
$host = $env['DB_HOST'] ?? $env['MYSQL_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$db   = $env['DB_NAME'] ?? $env['MYSQL_DATABASE'] ?? 'defaultdb';
$user = $env['DB_USER'] ?? $env['MYSQL_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? $env['MYSQL_PASSWORD'] ?? '';
$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $serverHost = $pdo->query('SELECT @@hostname')->fetchColumn();
    $currentDb  = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $currentUser= $pdo->query('SELECT USER()')->fetchColumn();
    echo "Connected:\nserver_host={$serverHost}\ncurrent_db={$currentDb}\ncurrent_user={$currentUser}\n\n";

    $row = $pdo->query("SELECT MAX(updated_at) AS last_update FROM ecological_yearly_summary")->fetch(PDO::FETCH_ASSOC);
    $cnt = $pdo->query("SELECT COUNT(*) AS cnt FROM ecological_yearly_summary")->fetchColumn();
    echo "ecological_yearly_summary rows={$cnt} last_update=" . ($row['last_update'] ?? '(none)') . "\n\n";

    $stmt = $pdo->query('SELECT year, ROUND(AVG(precipitation_total),2) AS avg_precip FROM ecological_yearly_summary GROUP BY year ORDER BY year');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "No yearly rows found.\n";
    } else {
        echo "Yearly averages:\n";
        foreach ($rows as $r) echo "{$r['year']} {$r['avg_precip']}\n";
    }

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

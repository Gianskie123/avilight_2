<?php
// Compare local avilight DB (assumed host=127.0.0.1, db=avilight) with remote .env defaultdb
function qconnect($dsn, $user, $pass) {
    try { return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
    catch (Throwable $e) { echo "Connect error ($dsn): " . $e->getMessage() . "\n"; return null; }
}
// Remote from .env
$env = [];
if (is_readable(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if (strpos(trim($l),'#')===0) continue;
        if (!preg_match('/^\s*([^=]+)=(.*)$/', $l, $m)) continue;
        $k = trim($m[1]); $v = trim($m[2]); $v = trim($v, "\"'"); $env[$k]=$v;
    }
}
$rhost = $env['DB_HOST'] ?? '127.0.0.1';
$rport = $env['DB_PORT'] ?? '3306';
$rdb = $env['DB_NAME'] ?? 'defaultdb';
$ruser = $env['DB_USER'] ?? 'root';
$rpass = $env['DB_PASS'] ?? '';
$remoteDsn = "mysql:host={$rhost};port={$rport};dbname={$rdb};charset=utf8mb4";
$remote = qconnect($remoteDsn, $ruser, $rpass);

// Local avilight
$localDsn = "mysql:host=127.0.0.1;port=3306;dbname=avilight;charset=utf8mb4";
$local = qconnect($localDsn, 'root', '');

function print_stats($pdo, $label) {
    if (!$pdo) { echo "$label: no connection\n"; return; }
    try {
        $cnt = $pdo->query('SELECT COUNT(*) FROM ecological_yearly_summary')->fetchColumn();
        $last = $pdo->query('SELECT MAX(updated_at) FROM ecological_yearly_summary')->fetchColumn();
        echo "$label rows=$cnt last_update=$last\n";
        $rows = $pdo->query('SELECT year, ROUND(AVG(precipitation_total),2) AS avg_precip FROM ecological_yearly_summary GROUP BY year ORDER BY year')->fetchAll();
        foreach ($rows as $r) echo "{$label}: {$r['year']} {$r['avg_precip']}\n";
    } catch (Throwable $e) { echo "$label query error: " . $e->getMessage() . "\n"; }
}
print_stats($local, 'LOCAL');
print_stats($remote, 'REMOTE');

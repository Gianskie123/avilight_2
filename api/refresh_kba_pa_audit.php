<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backend_config.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$projectRoot = realpath(__DIR__ . '/..');
$pythonScript = $projectRoot . DIRECTORY_SEPARATOR . 'python' . DIRECTORY_SEPARATOR . 'rebuild_kba_pa_audit.py';

if (!$projectRoot || !is_file($pythonScript)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Rebuild script not found']);
    exit;
}

$pythonBinary = getenv('AVILIGHT_PYTHON') ?: 'python';

// Pass DB credentials via environment for the Python worker.
$dbHost = (string)($GLOBALS['db_host'] ?? '127.0.0.1');
$dbUser = (string)($GLOBALS['db_user'] ?? 'root');
$dbPass = (string)($GLOBALS['db_pass'] ?? '');
$dbName = (string)($GLOBALS['db_name'] ?? 'avilight');
$dbPort = (string)($GLOBALS['db_port'] ?? '3306');

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$env = array_merge($_ENV, [
    'AVILIGHT_DB_HOST' => $dbHost,
    'AVILIGHT_DB_USER' => $dbUser,
    'AVILIGHT_DB_PASS' => $dbPass,
    'AVILIGHT_DB_NAME' => $dbName,
    'AVILIGHT_DB_PORT' => $dbPort,
]);

$process = @proc_open(
    [
        $pythonBinary,
        $pythonScript,
    ],
    $descriptors,
    $pipes,
    $projectRoot,
    $env
);

if (!is_resource($process)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to start KBA/PA rebuild process']);
    exit;
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($process);
$raw = trim((string) $stdout);
if ($raw === '' && $stderr !== '') {
    $raw = trim((string) $stderr);
}
$decoded = null;
if ($raw !== '') {
    $decoded = json_decode($raw, true);
}

if ($exitCode !== 0) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => is_array($decoded) ? ($decoded['error'] ?? 'KBA/PA rebuild failed') : 'KBA/PA rebuild failed',
        'details' => $decoded ?: $raw,
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'KBA/PA audit table rebuilt.',
    'data' => is_array($decoded) ? $decoded : ['output' => $raw],
]);

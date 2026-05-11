<?php
/**
 * api/clear_bau_cache.php
 *
 * Deletes all rows from analytics_bau_baselines.
 * Optional body: { "prewarm": true } — after clearing, precomputes every
 * city × month combination so the Analytics tab is always a cache hit.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?? [];
$prewarm = !empty($input['prewarm']);

try {
    $pdo = get_mysql_db();
    ensure_mysql_bau_baseline_cache_table($pdo);
    $deleted = (int)$pdo->query('SELECT COUNT(*) FROM analytics_bau_baselines')->fetchColumn();
    $pdo->exec('DELETE FROM analytics_bau_baselines');

    if (!$prewarm) {
        echo json_encode(['success' => true, 'deleted_rows' => $deleted, 'prewarmed' => false]);
        exit;
    }

    $cityKeys    = get_bau_prewarm_city_keys($pdo);
    $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host        = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $baseDir     = preg_replace('#/api$#', '', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'));
    $scenarioUrl = $scheme . '://' . $host . $baseDir . '/api/run_scenario.php';

    $ok   = 0;
    $fail = 0;
    foreach ($cityKeys as $cityKey) {
        for ($m = 1; $m <= 12; $m++) {
            $ch = curl_init($scenarioUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'city'         => $cityKey,
                    'month'        => $m,
                    'manual_mode'  => false,
                    'prewarm_only' => true,
                ]),
            ]);
            $resp    = curl_exec($ch);
            $http    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = json_decode((string)$resp, true);
            if ($http === 200 && is_array($decoded) && !empty($decoded['success'])) {
                $ok++;
            } else {
                $fail++;
            }
        }
    }

    echo json_encode([
        'success'      => true,
        'deleted_rows' => $deleted,
        'prewarmed'    => true,
        'prewarm_ok'   => $ok,
        'prewarm_fail' => $fail,
        'total_cities' => count($cityKeys),
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

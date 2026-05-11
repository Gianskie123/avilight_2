<?php
/**
 * api/rebuild_analytics_cache.php
 *
 * Manually rebuilds precomputed analytics cache tables used by geospatial analytics.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

try {
    @set_time_limit(0);

    $pdo = get_db();
    refresh_analytics_latest_sites_cache($pdo);

    $rowCount = (int)$pdo->query('SELECT COUNT(*) FROM analytics_latest_sites')->fetchColumn();
    $refreshedAt = (string)$pdo->query('SELECT MAX(refreshed_at) FROM analytics_latest_sites')->fetchColumn();

    $mysql = get_mysql_db();
    clear_mysql_bau_baseline_cache($mysql);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $baseDir = preg_replace('#/api$#', '', $scriptDir);
    $runScenarioUrl = $scheme . '://' . $host . $baseDir . '/api/run_scenario.php';

    $targetCities = [
        '',             // Metro Manila aggregate
        'Caloocan',
        'Las Piñas',
        'Makati',
        'Malabon',
        'Mandaluyong',
        'Manila',
        'Marikina',
        'Muntinlupa',
        'Navotas',
        'Parañaque',
        'Pasay',
        'Pasig',
        'Pateros',
        'Quezon City',
        'San Juan',
        'Taguig',
        'Valenzuela',
    ];

    $bauPrewarmOk = 0;
    $bauPrewarmFailed = 0;
    foreach ($targetCities as $cityName) {
        for ($m = 1; $m <= 12; $m++) {
            $payload = json_encode([
                'city' => $cityName,
                'month' => $m,
                'manual_mode' => false,
                'prewarm_only' => true,
            ]);

            $ch = curl_init($runScenarioUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
            ]);
            $resp = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err || $http !== 200) {
                $bauPrewarmFailed++;
                continue;
            }

            $decoded = json_decode((string)$resp, true);
            if (is_array($decoded) && !empty($decoded['success'])) {
                $bauPrewarmOk++;
            } else {
                $bauPrewarmFailed++;
            }
        }
    }

    $bauRows = (int)$mysql->query('SELECT COUNT(*) FROM analytics_bau_baselines')->fetchColumn();
    $bauRefreshed = (string)$mysql->query('SELECT MAX(refreshed_at) FROM analytics_bau_baselines')->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => 'Analytics cache rebuilt successfully.',
        'row_count' => $rowCount,
        'refreshed_at' => $refreshedAt,
        'bau_cache' => [
            'scope' => 'all_cities',
            'target_cities' => count($targetCities),
            'rows' => $bauRows,
            'refreshed_at' => $bauRefreshed,
            'prewarm_ok' => $bauPrewarmOk,
            'prewarm_failed' => $bauPrewarmFailed,
        ],
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to rebuild analytics cache: ' . $e->getMessage(),
    ]);
}

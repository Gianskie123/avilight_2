<?php
/**
 * Precompute BAU historical inputs for all cities and all months.
 *
 * Calls run_scenario with prewarm_only=true for every city × month combination
 * (17 cities × 12 months = 204 calls) so each month gets its own correct baseline.
 *
 * Usage:
 *   php api/prewarm_bau_city_months_cli.php
 *   php api/prewarm_bau_city_months_cli.php --url=http://127.0.0.1/avilight_7/avilight_2/api/run_scenario.php
 *   php api/prewarm_bau_city_months_cli.php --no-clear
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run via CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';

$cities = [
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

$clearFirst = true;
$runScenarioUrl = 'http://127.0.0.1/avilight-main/api/run_scenario.php';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-clear') {
        $clearFirst = false;
        continue;
    }
    if (strpos($arg, '--url=') === 0) {
        $runScenarioUrl = substr($arg, 6);
        continue;
    }
}

$mysql = get_mysql_db();
if ($clearFirst) {
    clear_mysql_bau_baseline_cache($mysql);
    fwrite(STDOUT, "Cleared existing analytics_bau_baselines cache.\n");
}

$ok = 0;
$failed = 0;
$failures = [];

foreach ($cities as $city) {
    for ($month = 1; $month <= 12; $month++) {
        $payload = json_encode([
            'city' => $city,
            'month' => $month,
            'manual_mode' => false,
            'prewarm_only' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($runScenarioUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
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

        if ($err !== '' || $http !== 200) {
            $failed++;
            $failures[] = [
                'city' => $city,
                'month' => $month,
                'error' => $err !== '' ? $err : ('HTTP ' . $http),
                'body' => is_string($resp) ? $resp : '',
            ];
            fwrite(STDOUT, "  FAIL {$city} month {$month}\n");
            continue;
        }

        $decoded = json_decode((string)$resp, true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            $failed++;
            $failures[] = [
                'city' => $city,
                'month' => $month,
                'error' => 'Invalid JSON from API',
                'body' => (string)$resp,
            ];
            fwrite(STDOUT, "  FAIL {$city} month {$month}\n");
            continue;
        }

        $ok++;
        fwrite(STDOUT, "  OK   {$city} month {$month}\n");
    }

    fwrite(STDOUT, "Done city: {$city}\n");
}

$rowCount = (int)$mysql->query('SELECT COUNT(*) FROM analytics_bau_baselines')->fetchColumn();
$latest = (string)$mysql->query('SELECT MAX(refreshed_at) FROM analytics_bau_baselines')->fetchColumn();

fwrite(STDOUT, "Done. SeedSuccess={$ok}, SeedFailed={$failed}, Rows={$rowCount}, RefreshedAt={$latest}\n");

if ($failed > 0) {
    fwrite(STDERR, "Failures (first 10):\n");
    $slice = array_slice($failures, 0, 10);
    foreach ($slice as $f) {
        fwrite(STDERR, sprintf("- %s month %d: %s\n", $f['city'], $f['month'], $f['error']));
        $body = trim((string)$f['body']);
        if ($body !== '') {
            fwrite(STDERR, "  body: " . substr($body, 0, 300) . "\n");
        }
    }
    exit(2);
}

exit(0);

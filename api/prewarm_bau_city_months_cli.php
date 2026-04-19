<?php
/**
 * Precompute BAU historical inputs for all cities and all months.
 *
 * Fast strategy:
 * 1) Trigger one prewarm call per city (month 1) to compute authoritative baseline values.
 * 2) Clone that city row to months 2..12 with month metadata adjusted.
 *
 * This keeps values sourced from run_scenario logic while avoiding 17*12 expensive calls.
 *
 * Usage:
 *   php api/prewarm_bau_city_months_cli.php
 *   php api/prewarm_bau_city_months_cli.php --url=http://127.0.0.1/avilight-main/api/run_scenario.php
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

$fetchCityMonthOne = $mysql->prepare('SELECT
        city_key,
        city_label,
        month,
        base_ndvi,
        base_viirs,
        base_lst,
        base_precip,
        lc_dummy_1,
        lc_dummy_2,
        lc_dummy_3,
        lc_dummy_4,
        lc_dummy_5,
        cell_count,
        latest_common_year,
        historical_inputs_json
    FROM analytics_bau_baselines
    WHERE city_key = :city_key AND month = 1
    LIMIT 1');

/**
 * Update month value inside historical_inputs_json payload.
 */
function historical_inputs_with_month(?string $json, int $month): ?string {
    if ($json === null || trim($json) === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $json;
    }
    $decoded['month'] = $month;
    if (isset($decoded['cache']) && is_array($decoded['cache'])) {
        $decoded['cache']['hit'] = false;
        $decoded['cache']['refreshed_at'] = gmdate('Y-m-d H:i:s');
    }
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $json : $encoded;
}

foreach ($cities as $city) {
    $payload = json_encode([
        'city' => $city,
        'month' => 1,
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
            'month' => 1,
            'error' => $err !== '' ? $err : ('HTTP ' . $http),
            'body' => is_string($resp) ? $resp : '',
        ];
        fwrite(STDOUT, "Prewarm failed city: {$city}\n");
        continue;
    }

    $decoded = json_decode((string)$resp, true);
    if (!is_array($decoded) || empty($decoded['success'])) {
        $failed++;
        $failures[] = [
            'city' => $city,
            'month' => 1,
            'error' => 'Invalid JSON payload from API',
            'body' => (string)$resp,
        ];
        fwrite(STDOUT, "Prewarm failed city: {$city}\n");
        continue;
    }

    $cityKey = mb_strtolower(trim($city), 'UTF-8');
    $fetchCityMonthOne->execute([':city_key' => $cityKey]);
    $seed = $fetchCityMonthOne->fetch(PDO::FETCH_ASSOC);
    if (!$seed) {
        $failed++;
        $failures[] = [
            'city' => $city,
            'month' => 1,
            'error' => 'Seed row for month 1 not found after prewarm',
            'body' => '',
        ];
        fwrite(STDOUT, "Prewarm failed city: {$city}\n");
        continue;
    }

    $ok++;

    for ($month = 2; $month <= 12; $month++) {
        upsert_mysql_bau_baseline_cache($mysql, [
            'city_key' => $seed['city_key'],
            'city_label' => $seed['city_label'],
            'month' => $month,
            'base_ndvi' => (float)$seed['base_ndvi'],
            'base_viirs' => (float)$seed['base_viirs'],
            'base_lst' => (float)$seed['base_lst'],
            'base_precip' => (float)$seed['base_precip'],
            'lc_dummy_1' => (float)$seed['lc_dummy_1'],
            'lc_dummy_2' => (float)$seed['lc_dummy_2'],
            'lc_dummy_3' => (float)$seed['lc_dummy_3'],
            'lc_dummy_4' => (float)$seed['lc_dummy_4'],
            'lc_dummy_5' => (float)$seed['lc_dummy_5'],
            'cell_count' => (int)$seed['cell_count'],
            'latest_common_year' => isset($seed['latest_common_year']) ? (int)$seed['latest_common_year'] : null,
            'historical_inputs_json' => historical_inputs_with_month((string)($seed['historical_inputs_json'] ?? ''), $month),
        ]);
    }

    fwrite(STDOUT, "Prewarmed city: {$city}\n");
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

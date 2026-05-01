<?php
header('Content-Type: text/csv; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo "Unauthorized\n";
    exit;
}
_assert_user_active();

function sanitize_filters(array $input): array {
    $defaultYear = (int) gmdate('Y');

    return [
        'selected_area' => trim((string) ($input['selected_area'] ?? 'All Areas')),
        'start_year' => (int) ($input['start_year'] ?? 2014),
        'end_year' => (int) ($input['end_year'] ?? $defaultYear),
        'snapshot_year' => (int) ($input['snapshot_year'] ?? $defaultYear),
        'snapshot_month' => (int) ($input['snapshot_month'] ?? 12),
    ];
}

function fetch_json_with_session(string $url, int $timeoutSeconds = 45): array {
    $cookie = session_name() . '=' . session_id();
    $timeoutSeconds = max(15, $timeoutSeconds);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Cookie: ' . $cookie,
                'Connection: close',
            ],
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (is_string($raw) && $raw !== '' && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $opts = [
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "Accept: application/json\r\nCookie: {$cookie}\r\nConnection: close\r\n",
        ],
    ];
    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function call_report_data_api(array $filters): array {
    $queryFresh = http_build_query(array_merge($filters, [
        'scope' => 'diagnostics',
        'include_diagnostics' => '1',
        'force_refresh' => '0',
    ]));

    $queryCached = http_build_query(array_merge($filters, [
        'scope' => 'diagnostics',
        'include_diagnostics' => '1',
    ]));

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/export_csv.php';
    $appBase = rtrim(dirname(dirname($scriptName)), '/\\');
    if ($appBase === '') {
        $appBase = '';
    }
    $baseUrl = $scheme . '://' . $host . $appBase . '/api/get_report_data.php?';

    $decoded = fetch_json_with_session($baseUrl . $queryFresh, 300);
    if (is_array($decoded) && !empty($decoded['success'])) {
        return $decoded;
    }

    $decoded = fetch_json_with_session($baseUrl . $queryCached, 120);
    return (is_array($decoded) && !empty($decoded['success'])) ? $decoded : [];
}

function csv_row($fh, array $row): void {
    fputcsv($fh, $row);
}

$filters = sanitize_filters($_GET);
$payload = call_report_data_api($filters);

$filename = 'avilight_reports_' . date('Ymd_His') . '.csv';
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

csv_row($out, ['AviLight Statistical Reports Export']);
csv_row($out, ['Generated At', gmdate('Y-m-d H:i:s') . ' UTC']);
csv_row($out, ['Area', $filters['selected_area']]);
csv_row($out, ['Trend Range', $filters['start_year'] . ' - ' . $filters['end_year']]);
csv_row($out, ['Snapshot', $filters['snapshot_year'] . '-' . str_pad((string) $filters['snapshot_month'], 2, '0', STR_PAD_LEFT)]);
csv_row($out, []);

if (empty($payload) || empty($payload['success'])) {
    csv_row($out, ['NOTICE']);
    csv_row($out, ['Report payload could not be loaded at export time.']);
    csv_row($out, ['Try reloading the dashboard filters first, then export again.']);
    fclose($out);
    exit;
}

$trend = $payload['trendHistoricalData'] ?? [];
$labels = is_array($trend['labels'] ?? null) ? $trend['labels'] : [];
$richness = is_array($trend['richness'] ?? null) ? $trend['richness'] : [];
$viirs = is_array($trend['viirs'] ?? null) ? $trend['viirs'] : [];
$ndvi = is_array($trend['ndvi'] ?? null) ? $trend['ndvi'] : [];
$lst = is_array($trend['lst'] ?? null) ? $trend['lst'] : [];
$precip = is_array($trend['precip'] ?? null) ? $trend['precip'] : [];

csv_row($out, ['TREND: HISTORICAL SERIES']);
csv_row($out, ['Year', 'Bird Richness', 'VIIRS', 'NDVI', 'LST', 'Precipitation']);
$maxN = max(count($labels), count($richness), count($viirs), count($ndvi), count($lst), count($precip));
for ($i = 0; $i < $maxN; $i++) {
    csv_row($out, [
        $labels[$i] ?? '',
        $richness[$i] ?? '',
        $viirs[$i] ?? '',
        $ndvi[$i] ?? '',
        $lst[$i] ?? '',
        $precip[$i] ?? '',
    ]);
}
csv_row($out, []);

$corr = $payload['trendCorrelationData'] ?? [];
csv_row($out, ['TREND: CORRELATIONS']);
csv_row($out, ['Metric', 'Value']);
csv_row($out, ['Richness vs VIIRS', $corr['richness_viirs'] ?? '']);
csv_row($out, ['Richness vs NDVI', $corr['richness_ndvi'] ?? '']);
csv_row($out, ['Richness vs LST', $corr['richness_lst'] ?? '']);
csv_row($out, ['Richness vs Precip', $corr['richness_precip'] ?? '']);
csv_row($out, []);

$snapshot = $payload['snapshotDistributions'] ?? [];
$mig = $snapshot['migration_status'] ?? [];
$light = $snapshot['light_tolerance'] ?? [];

csv_row($out, ['SNAPSHOT: MIGRATION STATUS']);
csv_row($out, ['Category', 'Count']);
if (!empty($mig['labels']) && !empty($mig['data'])) {
    foreach ($mig['labels'] as $idx => $label) {
        csv_row($out, [$label, $mig['data'][$idx] ?? 0]);
    }
}
csv_row($out, []);

csv_row($out, ['SNAPSHOT: LIGHT TOLERANCE']);
csv_row($out, ['Category', 'Count']);
if (!empty($light['labels']) && !empty($light['data'])) {
    foreach ($light['labels'] as $idx => $label) {
        csv_row($out, [$label, $light['data'][$idx] ?? 0]);
    }
}
csv_row($out, []);

$scatter = $payload['snapshotScatterData'] ?? [];
$scatterMaps = [
    'light_richness' => 'ALAN vs Richness',
    'ndvi_richness' => 'NDVI vs Richness',
    'lst_richness' => 'LST vs Richness',
    'precipitation_richness' => 'Precipitation vs Richness',
];
foreach ($scatterMaps as $key => $title) {
    csv_row($out, ['SNAPSHOT SCATTER: ' . $title]);
    csv_row($out, ['Area', 'X', 'Richness']);
    $pts = $scatter[$key]['points'] ?? [];
    if (is_array($pts)) {
        foreach ($pts as $pt) {
            csv_row($out, [
                $pt['area'] ?? '',
                $pt['x'] ?? '',
                $pt['y'] ?? '',
            ]);
        }
    }
    csv_row($out, []);
}

$topSites = $payload['topSitesRichnessData']['rows'] ?? [];
csv_row($out, ['SNAPSHOT: TOP SITES BY RICHNESS']);
csv_row($out, ['Rank', 'Area', 'Bird Richness']);
if (is_array($topSites)) {
    foreach ($topSites as $row) {
        csv_row($out, [
            $row['rank'] ?? '',
            $row['area'] ?? '',
            $row['bird_richness'] ?? '',
        ]);
    }
}
csv_row($out, []);

$metrics = $payload['ensembleMetrics']['ensemble_average'] ?? [];
csv_row($out, ['MODEL DIAGNOSTICS: ENSEMBLE METRICS']);
csv_row($out, ['Metric', 'Value']);
csv_row($out, ['RMSE', $metrics['rmse'] ?? '']);
csv_row($out, ['MAE', $metrics['mae'] ?? '']);
csv_row($out, ['R2', $metrics['r2'] ?? '']);
csv_row($out, []);

$importance = $payload['xgboostFeatureImportance'] ?? [];
foreach (['all', 'light_sensitive', 'light_tolerant', 'migratory', 'resident'] as $filter) {
    $block = $importance[$filter] ?? [];
    csv_row($out, ['XGBOOST FEATURE IMPORTANCE: ' . strtoupper($filter)]);
    csv_row($out, ['Feature Group', 'Importance']);
    $labels = $block['labels'] ?? [];
    $values = $block['values'] ?? [];
    if (is_array($labels) && is_array($values)) {
        $n = max(count($labels), count($values));
        for ($i = 0; $i < $n; $i++) {
            csv_row($out, [$labels[$i] ?? '', $values[$i] ?? '']);
        }
    }
    csv_row($out, []);
}

$convlstm = $payload['convlstmPredictions'] ?? [];
foreach (['all', 'light_sensitive', 'light_tolerant', 'migratory', 'resident'] as $filter) {
    $block = $convlstm[$filter] ?? [];
    csv_row($out, ['CONVLSTM ACTUAL VS PREDICTED: ' . strtoupper($filter)]);
    csv_row($out, ['Year', 'Actual', 'Predicted']);
    $years = $block['years'] ?? [];
    $actual = $block['actual'] ?? [];
    $pred = $block['predicted'] ?? [];
    if (is_array($years) && is_array($actual) && is_array($pred)) {
        $n = max(count($years), count($actual), count($pred));
        for ($i = 0; $i < $n; $i++) {
            csv_row($out, [$years[$i] ?? '', $actual[$i] ?? '', $pred[$i] ?? '']);
        }
    }
    csv_row($out, []);
}

$kbaPath = __DIR__ . '/../data/sample_kba.json';
if (is_readable($kbaPath)) {
    $kbaRaw = json_decode((string) file_get_contents($kbaPath), true);
    if (is_array($kbaRaw) && !empty($kbaRaw)) {
        csv_row($out, ['SYSTEMS RECOMMENDATIONS: KBA/PA AUDIT']);
        csv_row($out, ['Site', 'Type', 'Species Count', 'Sensitive %', 'Light Exposure', 'Effectiveness', 'Status']);
        foreach ($kbaRaw as $site) {
            csv_row($out, [
                $site['name'] ?? '',
                $site['type'] ?? '',
                $site['species_count'] ?? '',
                $site['sensitive_species_percent'] ?? '',
                $site['light_exposure'] ?? '',
                $site['effectiveness_score'] ?? '',
                $site['status'] ?? '',
            ]);
        }
        csv_row($out, []);
    }
}

fclose($out);

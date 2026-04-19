<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backend_config.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

function sanitize_filters(array $input): array {
    return [
        'selected_area' => trim((string) ($input['selected_area'] ?? 'All Areas')),
        'start_year' => (int) ($input['start_year'] ?? 2014),
        'end_year' => (int) ($input['end_year'] ?? 2025),
        'snapshot_year' => (int) ($input['snapshot_year'] ?? 2025),
        'snapshot_month' => (int) ($input['snapshot_month'] ?? 12),
    ];
}

function read_request_data(): array {
    $data = [];

    if (!empty($_POST) && is_array($_POST)) {
        $data = $_POST;
    }

    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = array_merge($data, $decoded);
        }
    }

    if (!empty($_GET) && is_array($_GET)) {
        $data = array_merge($data, $_GET);
    }

    return $data;
}

function get_cached_report_payload(array $filters): array {
    $cacheDir = __DIR__ . '/../data/cache/reports';
    if (!is_dir($cacheDir)) {
        return [];
    }

    $reportCacheVersion = 'v3';
    $cacheKey = 'reports:'
        . $reportCacheVersion . ':'
        . $filters['selected_area'] . ':'
        . $filters['start_year'] . ':'
        . $filters['end_year'] . ':'
        . $filters['snapshot_year'] . ':'
        . $filters['snapshot_month'] . ':diag=1';
    $cacheFile = $cacheDir . '/' . sha1($cacheKey) . '.json';
    if (!is_file($cacheFile) || !is_readable($cacheFile)) {
        return [];
    }

    $raw = @file_get_contents($cacheFile);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return (is_array($decoded) && !empty($decoded['success'])) ? $decoded : [];
}

function fetch_json_with_session(string $url, int $timeoutSeconds = 60): array {
    $cookie = session_name() . '=' . session_id();
    $timeoutSeconds = max(30, $timeoutSeconds);

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

function call_report_data_api(array $filters, bool $forceRefresh = false): array {
    $cached = get_cached_report_payload($filters);
    if (!$forceRefresh && !empty($cached)) {
        return $cached;
    }

    $query = http_build_query(array_merge($filters, [
        'scope' => 'diagnostics',
        'include_diagnostics' => '1',
        'force_refresh' => $forceRefresh ? '1' : '0',
    ]));

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/export_handler.php';
    $appBase = rtrim(dirname(dirname($scriptName)), '/\\');
    if ($appBase === '') {
        $appBase = '';
    }
    $url = $scheme . '://' . $host . $appBase . '/api/get_report_data.php?' . $query;

    $decoded = fetch_json_with_session($url, $forceRefresh ? 180 : 90);
    if (is_array($decoded) && !empty($decoded['success'])) {
        return $decoded;
    }

    // If forced refresh fails (timeout/backend transient), retry with normal API read.
    if ($forceRefresh) {
        $fallbackQuery = http_build_query(array_merge($filters, [
            'scope' => 'diagnostics',
            'include_diagnostics' => '1',
            'force_refresh' => '0',
        ]));
        $fallbackUrl = $scheme . '://' . $host . $appBase . '/api/get_report_data.php?' . $fallbackQuery;
        $fallbackDecoded = fetch_json_with_session($fallbackUrl, 90);
        if (is_array($fallbackDecoded) && !empty($fallbackDecoded['success'])) {
            return $fallbackDecoded;
        }
    }

    // Last-resort fallback so export still works even if live refresh path fails.
    return !empty($cached) ? $cached : [];
}

function fetch_kba_pa_audit_rows(): array {
    try {
        $mysql = get_mysql_db();
        $stmt = $mysql->query("SELECT
                area_name,
                area_type,
                species_count,
                sensitive_species_count,
                sensitive_species_percent,
                light_exposure,
                mean_ndvi,
                max_lst,
                precipitation_total,
                grid_cell_count,
                effectiveness_score,
                status,
                snapshot_year,
                snapshot_month
            FROM kba_pa_audit_live
            ORDER BY effectiveness_score ASC, area_name ASC");

        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name' => (string) ($row['area_name'] ?? ''),
                'type' => strtoupper((string) ($row['area_type'] ?? '')) === 'PA' ? 'PA' : 'KBA',
                'species_count' => isset($row['species_count']) ? (int) $row['species_count'] : null,
                'sensitive_species_count' => isset($row['sensitive_species_count']) ? (int) $row['sensitive_species_count'] : null,
                'sensitive_species_percent' => isset($row['sensitive_species_percent']) ? (float) $row['sensitive_species_percent'] : null,
                'light_exposure' => isset($row['light_exposure']) ? (float) $row['light_exposure'] : null,
                'mean_ndvi' => isset($row['mean_ndvi']) ? (float) $row['mean_ndvi'] : null,
                'max_lst' => isset($row['max_lst']) ? (float) $row['max_lst'] : null,
                'precipitation_total' => isset($row['precipitation_total']) ? (float) $row['precipitation_total'] : null,
                'grid_cell_count' => isset($row['grid_cell_count']) ? (int) $row['grid_cell_count'] : null,
                'effectiveness_score' => isset($row['effectiveness_score']) ? (float) $row['effectiveness_score'] : null,
                'status' => (string) ($row['status'] ?? ''),
                'snapshot_year' => isset($row['snapshot_year']) ? (int) $row['snapshot_year'] : null,
                'snapshot_month' => isset($row['snapshot_month']) ? (int) $row['snapshot_month'] : null,
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function run_python_report_engine(array $payload, string $format): array {
    $scriptPath = __DIR__ . '/../python/generate_pdf_report.py';
    if (!is_file($scriptPath)) {
        return ['success' => false, 'error' => 'Python report engine script not found.'];
    }

    $venvPython = __DIR__ . '/../.venv/Scripts/python.exe';
    $pythonBin = is_file($venvPython) ? $venvPython : (defined('PYTHON_BIN') ? PYTHON_BIN : 'python');

    $ext = $format === 'pdf' ? 'pdf' : 'csv';
    $tmpFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'avilight_report_' . uniqid('', true) . '.' . $ext;

    $cmd = escapeshellarg($pythonBin)
        . ' ' . escapeshellarg($scriptPath)
        . ' --format ' . escapeshellarg($format)
        . ' --output ' . escapeshellarg($tmpFile);

    $descriptorSpec = [
        // Child stdin: parent writes JSON payload.
        0 => ['pipe', 'r'],
        // Child stdout/stderr: parent reads execution result/logs.
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return ['success' => false, 'error' => 'Failed to start Python report process.'];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        $jsonPayload = '{}';
    }

    if (!isset($pipes[0]) || !is_resource($pipes[0])) {
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        proc_close($process);
        return ['success' => false, 'error' => 'Failed to open Python stdin pipe.'];
    }

    $bytesToWrite = strlen($jsonPayload);
    $written = 0;
    while ($written < $bytesToWrite) {
        $chunk = substr($jsonPayload, $written, 8192);
        $n = fwrite($pipes[0], $chunk);
        if ($n === false || $n === 0) {
            break;
        }
        $written += $n;
    }
    fclose($pipes[0]);

    $stdout = (isset($pipes[1]) && is_resource($pipes[1])) ? stream_get_contents($pipes[1]) : '';
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        fclose($pipes[1]);
    }

    $stderr = (isset($pipes[2]) && is_resource($pipes[2])) ? stream_get_contents($pipes[2]) : '';
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        fclose($pipes[2]);
    }

    $exitCode = proc_close($process);

    $meta = json_decode((string) $stdout, true);
    if (!is_array($meta)) {
        $meta = [];
    }

    if ($written < $bytesToWrite) {
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
        $pipeErr = 'Failed to stream full payload to Python process (' . $written . '/' . $bytesToWrite . ' bytes).';
        $stderrText = trim((string) $stderr);
        if ($stderrText !== '') {
            $pipeErr .= ' ' . $stderrText;
        }
        return ['success' => false, 'error' => $pipeErr];
    }

    if ($exitCode !== 0 || !is_file($tmpFile) || filesize($tmpFile) <= 0) {
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }
        $err = $meta['error'] ?? trim((string) $stderr);
        if ($err === '') {
            $err = 'Python report generation failed.';
        }
        return ['success' => false, 'error' => $err];
    }

    return [
        'success' => true,
        'file' => $tmpFile,
        'meta' => $meta,
    ];
}

function export_cache_file_path(string $format, array $filters, string $payloadHash = ''): string {
    $cacheDir = __DIR__ . '/../data/cache/reports/exports';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheVersion = 'v5';
    $key = 'export:' . $cacheVersion . ':' . $format . ':'
        . $filters['selected_area'] . ':'
        . $filters['start_year'] . ':'
        . $filters['end_year'] . ':'
        . $filters['snapshot_year'] . ':'
        . $filters['snapshot_month'] . ':' . ($payloadHash !== '' ? $payloadHash : 'nofp');
    $ext = $format === 'pdf' ? 'pdf' : 'csv';
    return rtrim($cacheDir, '/\\') . '/' . sha1($key) . '.' . $ext;
}

function send_export_file(string $path, string $format, string $filename): void {
    header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'text/csv; charset=utf-8'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile($path);
}

function try_send_cached_export(string $format, array $filters, int $ttlSeconds, string $payloadHash = ''): bool {
    if ($ttlSeconds <= 0) {
        return false;
    }
    $cacheFile = export_cache_file_path($format, $filters, $payloadHash);
    if (!is_file($cacheFile) || !is_readable($cacheFile)) {
        return false;
    }
    $mtime = @filemtime($cacheFile);
    if ($mtime === false || (time() - $mtime) > $ttlSeconds) {
        return false;
    }

    $filename = 'avilight_reports_' . date('Ymd_His') . ($format === 'pdf' ? '.pdf' : '.csv');
    send_export_file($cacheFile, $format, $filename);
    return true;
}

$requestData = read_request_data();
$format = strtolower(trim((string) ($requestData['format'] ?? 'pdf')));
if (!in_array($format, ['pdf', 'csv'], true)) {
    http_response_code(400);
    echo 'Invalid export format. Use format=pdf or format=csv.';
    exit;
}

$filters = sanitize_filters($requestData);
$exportLive = ((string) ($requestData['export_live'] ?? '0') === '1');
$clientPayload = (isset($requestData['report_payload']) && is_array($requestData['report_payload'])) ? $requestData['report_payload'] : [];
$clientPayloadHash = '';
if (!empty($clientPayload)) {
    $clientPayloadHash = sha1(json_encode($clientPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}
$exportCacheTtlSeconds = in_array($format, ['pdf', 'csv'], true) ? 1800 : 0;
if (!$exportLive && try_send_cached_export($format, $filters, $exportCacheTtlSeconds, $clientPayloadHash)) {
    exit;
}

if (!empty($clientPayload) && !empty($clientPayload['success'])) {
    $payload = $clientPayload;
    if (!isset($payload['filters']) || !is_array($payload['filters'])) {
        $payload['filters'] = $filters;
    }
    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = [];
    }
    $payload['meta']['export_source'] = 'client_loaded_state';
    $payload['meta']['export_mode'] = 'current_page';
} else {
    $payload = call_report_data_api($filters, $exportLive);
}

if (empty($payload) || empty($payload['success'])) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load report payload for export. Refresh the dashboard and try again.',
    ]);
    exit;
}

$payload['kbaPaAuditRows'] = !empty($payload['kbaPaAuditRows']) ? $payload['kbaPaAuditRows'] : fetch_kba_pa_audit_rows();
$payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
$payload['meta']['kba_pa_audit_source'] = !empty($payload['kbaPaAuditRows']) ? 'kba_pa_audit_live' : 'none';
$payload['meta']['export_payload_hash'] = $clientPayloadHash;

$result = run_python_report_engine($payload, $format);
if (empty($result['success'])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $result['error'] ?? 'Report generation failed.',
    ]);
    exit;
}

$tmpFile = $result['file'];
$timestamp = date('Ymd_His');
$filename = 'avilight_reports_' . $timestamp . ($format === 'pdf' ? '.pdf' : '.csv');

$outputFile = $tmpFile;
if ($exportCacheTtlSeconds > 0) {
    $cacheFile = export_cache_file_path($format, $filters, $clientPayloadHash);
    if (@copy($tmpFile, $cacheFile)) {
        $outputFile = $cacheFile;
    }
}

send_export_file($outputFile, $format, $filename);

if ($tmpFile !== $outputFile && is_file($tmpFile)) {
    @unlink($tmpFile);
} elseif ($tmpFile === $outputFile) {
    @unlink($tmpFile);
}

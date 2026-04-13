<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backend_config.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

function sanitize_filters(array $input): array {
    return [
        'selected_area' => trim((string) ($input['selected_area'] ?? 'All Areas')),
        'start_year' => (int) ($input['start_year'] ?? 2014),
        'end_year' => (int) ($input['end_year'] ?? 2024),
        'snapshot_year' => (int) ($input['snapshot_year'] ?? 2024),
        'snapshot_month' => (int) ($input['snapshot_month'] ?? 12),
    ];
}

function get_cached_report_payload(array $filters): array {
    $cacheDir = __DIR__ . '/../data/cache/reports';
    if (!is_dir($cacheDir)) {
        return [];
    }

    $cacheKey = 'reports:'
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

function fetch_json_with_session(string $url): array {
    $cookie = session_name() . '=' . session_id();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
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
            'timeout' => 60,
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
    $cached = get_cached_report_payload($filters);
    if (!empty($cached)) {
        return $cached;
    }

    $query = http_build_query(array_merge($filters, [
        'scope' => 'diagnostics',
        'include_diagnostics' => '1',
    ]));

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/export_handler.php';
    $appBase = rtrim(dirname(dirname($scriptName)), '/\\');
    if ($appBase === '') {
        $appBase = '';
    }
    $url = $scheme . '://' . $host . $appBase . '/api/get_report_data.php?' . $query;

    $decoded = fetch_json_with_session($url);
    return (is_array($decoded) && !empty($decoded['success'])) ? $decoded : [];
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

function export_cache_file_path(string $format, array $filters): string {
    $cacheDir = __DIR__ . '/../data/cache/reports/exports';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheVersion = 'v4';
    $key = 'export:' . $cacheVersion . ':' . $format . ':'
        . $filters['selected_area'] . ':'
        . $filters['start_year'] . ':'
        . $filters['end_year'] . ':'
        . $filters['snapshot_year'] . ':'
        . $filters['snapshot_month'];
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

function try_send_cached_export(string $format, array $filters, int $ttlSeconds): bool {
    if ($ttlSeconds <= 0) {
        return false;
    }
    $cacheFile = export_cache_file_path($format, $filters);
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

$format = strtolower(trim((string) ($_GET['format'] ?? 'pdf')));
if (!in_array($format, ['pdf', 'csv'], true)) {
    http_response_code(400);
    echo 'Invalid export format. Use format=pdf or format=csv.';
    exit;
}

$filters = sanitize_filters($_GET);
$exportCacheTtlSeconds = ($format === 'pdf') ? 300 : 0;
if (try_send_cached_export($format, $filters, $exportCacheTtlSeconds)) {
    exit;
}

$payload = call_report_data_api($filters);

if (empty($payload) || empty($payload['success'])) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load report payload for export. Refresh the dashboard and try again.',
    ]);
    exit;
}

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
    $cacheFile = export_cache_file_path($format, $filters);
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

<?php
/**
 * api/get_backend_health.php
 *
 * Probes the Python backend health endpoint using PYTHON_BACKEND_URL.
 */
header('Content-Type: text/plain; charset=UTF-8');

require_once __DIR__ . '/../includes/backend_config.php';

try {
    $base = rtrim(PYTHON_BACKEND_URL, '/');
    $url = $base . '/health';

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => "Accept: application/json\r\n",
        ],
    ]);

    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $last = error_get_last();
        echo "FAILED to reach Python backend.\n";
        echo "URL: {$url}\n";
        echo "Error: " . ($last['message'] ?? 'unknown error') . "\n";
        exit;
    }

    echo "Python backend reachable.\n";
    echo "URL: {$url}\n";
    echo "Response: {$resp}\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>

<?php
/**
 * backend_config.php
 *
 * Configuration for the Python backend service.
 * Set the PYTHON_BACKEND_URL environment variable (or update the default below)
 * once the Python service is deployed.
 */

// Base URL of the Python backend (FastAPI / Flask)
define('PYTHON_BACKEND_URL', getenv('PYTHON_BACKEND_URL') ?: 'http://localhost:5000');

/**
 * Send a JSON POST request to the Python backend and return the decoded response.
 *
 * @param string $path    Endpoint path (e.g. '/api/upload_data')
 * @param array  $payload Data to encode as the JSON request body
 * @return array          Decoded JSON response array
 */
function python_post(string $path, array $payload = []): array {
    $url = rtrim(PYTHON_BACKEND_URL, '/') . $path;
    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 30,
        ],
    ]);
    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        $last = error_get_last();
        return ['success' => false, 'error' => 'Python backend unreachable: ' . ($last['message'] ?? 'unknown error')];
    }
    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['success' => false, 'error' => 'Invalid response from Python backend: ' . json_last_error_msg()];
    }
    return $decoded;
}

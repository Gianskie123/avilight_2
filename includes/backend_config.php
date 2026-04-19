<?php
/**
 * backend_config.php
 *
 * Configuration for the Python backend service.
 * Set the PYTHON_BACKEND_URL environment variable (or update the default below)
 * once the Python service is deployed.
 */

// Base URL of the Python backend (FastAPI / Flask)
define('PYTHON_BACKEND_URL', getenv('PYTHON_BACKEND_URL') ?: 'http://127.0.0.1:5000');

// ── Python worker configuration ───────────────────────────────────────────────

// Python executable.
// Priority: PYTHON_BIN env var → .venv/venv (auto-detected) → system python fallback.
// Run setup_env.bat once to create the venv; after that this resolves automatically.
if (!defined('PYTHON_BIN')) {
    $_venv_win  = realpath(__DIR__ . '/../.venv/Scripts/python.exe');  // Windows
    $_venv_unix = realpath(__DIR__ . '/../.venv/bin/python');          // Linux / Mac
    $_venv2_win  = realpath(__DIR__ . '/../venv/Scripts/python.exe');  // Windows fallback
    $_venv2_unix = realpath(__DIR__ . '/../venv/bin/python');          // Linux / Mac fallback
    $_python    = getenv('PYTHON_BIN')
               ?: ($_venv_win  && file_exists($_venv_win)  ? $_venv_win  : null)
               ?: ($_venv_unix && file_exists($_venv_unix) ? $_venv_unix : null)
               ?: ($_venv2_win  && file_exists($_venv2_win) ? $_venv2_win  : null)
               ?: ($_venv2_unix && file_exists($_venv2_unix) ? $_venv2_unix : null)
               ?: 'python';
    define('PYTHON_BIN', $_python);
    unset($_venv_win, $_venv_unix, $_venv2_win, $_venv2_unix, $_python);
}

// Absolute path to the directory containing the extract_*.py worker scripts.
define('PYTHON_SCRIPTS_DIR', realpath(__DIR__ . '/../python'));

// GEE project ID — the Google Cloud project registered with Earth Engine.
// Found in: console.cloud.google.com → select your project → copy the Project ID.
define('GEE_PROJECT', getenv('GEE_PROJECT') ?: 'avilight-483312-492105-492107');

// Absolute path to the GEE service-account JSON key file on THIS machine.
// Steps to get this file:
//   1. Go to console.cloud.google.com → IAM & Admin → Service Accounts
//   2. Click your service account → Keys tab → Add Key → Create new key → JSON
//   3. Save the downloaded file to a safe path (outside the web root)
//   4. Paste the full path below, e.g.: C:\xampp\gee-keys\avilight-key.json
define('GEE_SA_KEY', getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: 'C:\\xampp\\gee-keys\\avilight-key.json');

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

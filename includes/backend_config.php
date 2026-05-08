<?php
/**
 * backend_config.php
 *
 * Configuration for the Python backend service.
 * Set the PYTHON_BACKEND_URL environment variable (or update the default below)
 * once the Python service is deployed.
 */

// Base URL of the Python backend (FastAPI / Flask)
// Railway start.sh launches FastAPI on port 8000 by default.
define('PYTHON_BACKEND_URL', getenv('PYTHON_BACKEND_URL') ?: 'http://127.0.0.1:8000');

// ── Python worker configuration ───────────────────────────────────────────────

// Python executable.
// Priority: PYTHON_BIN env var → .venv/venv (auto-detected) → system python fallback.
// Run setup_env.bat once to create the venv; after that this resolves automatically.
if (!defined('PYTHON_BIN')) {
    $_is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $_venv_win  = realpath(__DIR__ . '/../.venv/Scripts/python.exe');  // Windows
    $_venv_unix = realpath(__DIR__ . '/../.venv/bin/python');          // Linux / Mac
    $_venv2_win  = realpath(__DIR__ . '/../venv/Scripts/python.exe');  // Windows fallback
    $_venv2_unix = realpath(__DIR__ . '/../venv/bin/python');          // Linux / Mac fallback
    $_system_default = $_is_windows ? 'python' : 'python3';
    $_python    = getenv('PYTHON_BIN')
               ?: ($_venv_win  && file_exists($_venv_win)  ? $_venv_win  : null)
               ?: ($_venv_unix && file_exists($_venv_unix) ? $_venv_unix : null)
               ?: ($_venv2_win  && file_exists($_venv2_win) ? $_venv2_win  : null)
               ?: ($_venv2_unix && file_exists($_venv2_unix) ? $_venv2_unix : null)
               ?: $_system_default;
    define('PYTHON_BIN', $_python);
    unset($_is_windows, $_venv_win, $_venv_unix, $_venv2_win, $_venv2_unix, $_system_default, $_python);
}

// Absolute path to the directory containing the extract_*.py worker scripts.
define('PYTHON_SCRIPTS_DIR', realpath(__DIR__ . '/../python'));

// GEE project ID — the Google Cloud project registered with Earth Engine.
// Found in: console.cloud.google.com → select your project → copy the Project ID.
// Accept either GEE_PROJECT or GEE_PROJECT_ID (common naming on hosting providers)
define('GEE_PROJECT', getenv('GEE_PROJECT') ?: getenv('GEE_PROJECT_ID') ?: 'avilight-483312-492105-492107');

/**
 * Resolve GEE service-account credentials path with env-first priority.
 * Supports both path vars and raw JSON vars.
 *
 * Priority order:
 *  1) GOOGLE_APPLICATION_CREDENTIALS (path)
 *  2) GEE_SA_KEY (path)
 *  3) GEE_SA_KEY_JSON / GOOGLE_CREDENTIALS_JSON (raw JSON)
 *  4) legacy local dev default path
 *
 * @return array{0:string,1:string} [resolvedPath, source]
 */
function resolve_gee_sa_key_with_source(): array {
    $pathFromGoogle = trim((string)(getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: ''));
    if ($pathFromGoogle !== '') {
        return [$pathFromGoogle, 'GOOGLE_APPLICATION_CREDENTIALS'];
    }

    $pathFromGee = trim((string)(getenv('GEE_SA_KEY') ?: ''));
    if ($pathFromGee !== '') {
        return [$pathFromGee, 'GEE_SA_KEY'];
    }

    $rawJson = trim((string)(getenv('GEE_SA_KEY_JSON') ?: getenv('GOOGLE_CREDENTIALS_JSON') ?: ''));
    if ($rawJson !== '') {
        $decoded = json_decode($rawJson, true);
        if (is_array($decoded)) {
            $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gee-service-account.json';
            @file_put_contents($tmp, $rawJson);
            @chmod($tmp, 0600);
            return [$tmp, 'GEE_SA_KEY_JSON'];
        }
    }

    return ['C:\\xampp\\gee-keys\\avilight-key.json', 'default'];
}

[$_gee_sa_key, $_gee_sa_key_source] = resolve_gee_sa_key_with_source();
define('GEE_SA_KEY', $_gee_sa_key);
define('GEE_SA_KEY_SOURCE', $_gee_sa_key_source);
unset($_gee_sa_key, $_gee_sa_key_source);

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

<?php
/**
 * Precompute ecological_yearly_summary using the same pipeline as Reports refresh.
 *
 * Usage:
 *   php api/precompute_ecological_yearly_summary_cli.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run via CLI.\n");
    exit(1);
}

$runnerPath = tempnam(sys_get_temp_dir(), 'ecology_precompute_runner_');
if ($runnerPath === false) {
    fwrite(STDERR, "Unable to create temp runner file.\n");
    exit(2);
}

$runnerCode = <<<'PHP'
<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_email'] = $_SESSION['user_email'] ?? 'cli-precompute.local';
$_SESSION['user_role'] = 'admin';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'summary_only' => '1',
];
ob_start();
include '__REFRESH_PATH__';
$output = (string) ob_get_clean();
if ($output === '') {
    fwrite(STDERR, "refresh_report_cache.php returned no output.\n");
    exit(2);
}
fwrite(STDOUT, $output);
exit(0);
PHP;

$runnerCode = str_replace('__REFRESH_PATH__', addslashes(__DIR__ . '/refresh_report_cache.php'), $runnerCode);
file_put_contents($runnerPath, $runnerCode);

$maxAttempts = 5;
$attempt = 0;
$lastOutput = '';
$lastError = '';

while ($attempt < $maxAttempts) {
    $attempt++;

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runnerPath);
    $lines = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $lines, $exitCode);
    $output = trim(implode(PHP_EOL, $lines));
    $lastOutput = $output;

    if ($exitCode !== 0 || $output === '') {
        $lastError = $output !== '' ? $output : 'Runner process failed.';
        break;
    }


        $jsonPayload = $output;
        $jsonStart = strpos($output, '{');
        $jsonEnd = strrpos($output, '}');
        if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd >= $jsonStart) {
            $jsonPayload = substr($output, $jsonStart, $jsonEnd - $jsonStart + 1);
        }

        $decoded = json_decode($jsonPayload, true);
    if (!is_array($decoded)) {
        $lastError = 'Unexpected response payload.';
        break;
    }

    $ok = (bool) ($decoded['success'] ?? false);
    if ($ok) {
        @unlink($runnerPath);
        fwrite(STDOUT, json_encode($decoded, JSON_PRETTY_PRINT) . PHP_EOL);
        exit(0);
    }

    $lastError = (string) ($decoded['error'] ?? 'Unknown refresh error.');
    $isRetryable = strpos($lastError, '1205') !== false
        || strpos($lastError, 'Lock wait timeout exceeded') !== false
        || strpos($lastError, 'SQLSTATE[40001]') !== false
        || strpos($lastError, 'Deadlock found when trying to get lock') !== false
        || strpos($lastError, '1213') !== false;

    if (!$isRetryable || $attempt >= $maxAttempts) {
        break;
    }

    fwrite(STDERR, sprintf("Attempt %d/%d failed (%s). Retrying...\n", $attempt, $maxAttempts, $lastError));
    usleep(300000 * $attempt);
}

@unlink($runnerPath);
fwrite(STDERR, "Precompute failed after retries.\n");
if ($lastError !== '') {
    fwrite(STDERR, $lastError . "\n");
}
if ($lastOutput !== '') {
    fwrite(STDERR, $lastOutput . "\n");
}
exit(4);

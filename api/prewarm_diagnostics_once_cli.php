<?php
/**
 * Prewarm a single default diagnostics cache combination.
 *
 * Usage:
 *   php api/prewarm_diagnostics_once_cli.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run via CLI.\n");
    exit(1);
}

$apiPath = realpath(__DIR__ . '/get_report_data.php');
if ($apiPath === false) {
    fwrite(STDERR, "Cannot find api/get_report_data.php\n");
    exit(1);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['user_email'] = 'cli-cache-warmer.local';
$_GET = [
    'selected_area' => 'All Areas',
    'start_year' => 2014,
    'end_year' => 2024,
    'snapshot_year' => 2024,
    'snapshot_month' => 1,
    'scope' => 'diagnostics',
    'include_diagnostics' => '1',
];

ob_start();
include $apiPath;
$body = (string) ob_get_clean();

$json = json_decode($body, true);
if (!is_array($json) || !($json['success'] ?? false)) {
    fwrite(STDERR, "Prewarm failed: " . ($json['error'] ?? 'invalid JSON response') . "\n");
    exit(2);
}

$cacheKey = (string) (($json['meta']['cache_key'] ?? 'unknown'));
fwrite(STDOUT, "Diagnostics prewarm done for cache_key={$cacheKey}\n");

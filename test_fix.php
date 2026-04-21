<?php
// Test the exact invocation method with the JSON extraction fix

$params = [
    'scope' => 'trend',
    'selected_area' => 'All Areas',
    'start_year' => 2012,
    'end_year' => 2012,
    'snapshot_year' => 2025,
    'snapshot_month' => 12,
    'include_diagnostics' => '0',
];

$apiPath = __DIR__ . '/api/get_report_data.php';
$paramsB64 = base64_encode((string) json_encode($params));
$tmpScript = tempnam(sys_get_temp_dir(), 'report_warm_');

$runner = "<?php\n"
    . '$p=json_decode(base64_decode(' . var_export($paramsB64, true) . '),true);' . "\n"
    . 'if(session_status()===PHP_SESSION_NONE){session_start();}' . "\n"
    . '$_SESSION["user_email"]="cli-cache-warmer.local";' . "\n"
    . '$_GET=$p;' . "\n"
    . 'include ' . var_export($apiPath, true) . ';' . "\n";

file_put_contents($tmpScript, $runner);

$output = [];
$exitCode = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpScript), $output, $exitCode);

echo "Exit code: $exitCode\n";

if ($exitCode !== 0) {
    echo "FAIL: PHP exited with code $exitCode\n";
    @unlink($tmpScript);
    exit(1);
}

$body = trim(implode("\n", $output));

// Apply the JSON extraction fix
$jsonStart = strpos($body, '{');
$jsonEnd = strrpos($body, '}');
if ($jsonStart === false || $jsonEnd === false || $jsonStart > $jsonEnd) {
    echo "FAIL: No JSON found in response\n";
    @unlink($tmpScript);
    exit(1);
}
$jsonStr = substr($body, $jsonStart, $jsonEnd - $jsonStart + 1);

$json = json_decode($jsonStr, true);
if (!is_array($json)) {
    echo "FAIL: Invalid JSON response\n";
    @unlink($tmpScript);
    exit(1);
}

if (!($json['success'] ?? false)) {
    echo "FAIL: " . (string) ($json['error'] ?? 'Unknown error from get_report_data.php') . "\n";
    @unlink($tmpScript);
    exit(1);
}

echo "SUCCESS: Request succeeded!\n";
echo "Cache file would have been written.\n";

@unlink($tmpScript);

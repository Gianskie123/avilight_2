<?php
// Test the exact invocation method used by warm_report_cache_cli.php

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

echo "Temp script: $tmpScript\n";
echo "Running: " . PHP_BINARY . " $tmpScript\n\n";

$output = [];
$exitCode = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpScript), $output, $exitCode);

echo "Exit code: $exitCode\n";
echo "Output lines: " . count($output) . "\n\n";
echo "Raw output:\n";
foreach ($output as $line) {
    echo "> $line\n";
}

$body = trim(implode("\n", $output));
echo "\nTrimmed body:\n$body\n\n";

$json = json_decode($body, true);
echo "JSON decoded: " . ($json ? "OK" : "FAILED") . "\n";
if ($json) {
    echo "success flag: " . ($json['success'] ? "true" : "false") . "\n";
}

@unlink($tmpScript);

<?php
/**
 * Prewarm default trend+snapshot cache for each single area.
 *
 * Usage:
 *   php api/prewarm_default_areas_cli.php
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

$areas = [
    'Caloocan',
    'Las Piñas',
    'Makati',
    'Malabon',
    'Mandaluyong',
    'Manila',
    'Marikina',
    'Muntinlupa',
    'Navotas',
    'Parañaque',
    'Pasay',
    'Pasig',
    'Pateros',
    'Quezon City',
    'San Juan',
    'Taguig',
    'Valenzuela',
];

$runnerPath = tempnam(sys_get_temp_dir(), 'report_default_area_');
if ($runnerPath === false) {
    fwrite(STDERR, "Unable to create temp runner file.\n");
    exit(1);
}

$runnerCode = <<<'PHP'
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$area = $argv[1] ?? 'All Areas';
$scope = $argv[2] ?? 'trend';
$_SESSION['user_email'] = 'cli-cache-warmer.local';
$_GET = [
    'selected_area' => $area,
    'start_year' => 2014,
    'end_year' => 2025,
    'snapshot_year' => 2025,
    'snapshot_month' => 1,
    'scope' => $scope,
    'include_diagnostics' => '0',
];
include '__API_PATH__';
PHP;

$runnerCode = str_replace('__API_PATH__', addslashes($apiPath), $runnerCode);
file_put_contents($runnerPath, $runnerCode);

$ok = 0;
$fail = 0;
foreach ($areas as $area) {
    foreach (['trend', 'snapshot'] as $scope) {
        $cmd = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($runnerPath)
            . ' ' . escapeshellarg($area)
            . ' ' . escapeshellarg($scope);

        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code === 0) {
            $ok++;
        } else {
            $fail++;
        }
    }
    fwrite(STDOUT, "Prewarmed: {$area}\n");
}

@unlink($runnerPath);

fwrite(STDOUT, "Done. Success={$ok}, Failed={$fail}\n");
exit($fail > 0 ? 2 : 0);

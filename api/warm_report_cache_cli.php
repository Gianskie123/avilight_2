<?php
/**
 * CLI report cache warmer.
 *
 * Pre-generates file caches used by api/get_report_data.php by invoking the same
 * endpoint logic in a separate PHP process per filter combination.
 *
 * Usage examples:
 *   php api/warm_report_cache_cli.php
 *   php api/warm_report_cache_cli.php --scopes=trend,snapshot
 *   php api/warm_report_cache_cli.php --scopes=trend,snapshot,diagnostics --max-requests=500
 *   php api/warm_report_cache_cli.php --purge-first=0
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';

$metroManilaCities = [
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

$areas = array_merge(['All Areas'], $metroManilaCities);
$apiPath = realpath(__DIR__ . '/get_report_data.php');
$cacheDir = realpath(__DIR__ . '/../data/cache/reports');

if ($apiPath === false) {
    fwrite(STDERR, "Unable to locate api/get_report_data.php\n");
    exit(1);
}
if ($cacheDir === false) {
    fwrite(STDERR, "Unable to locate data/cache/reports directory\n");
    exit(1);
}

$options = parseArgs($argv);
$scopes = parseScopes((string) ($options['scopes'] ?? 'trend,snapshot'));
$purgeFirst = ((string) ($options['purge-first'] ?? '1')) !== '0';
$maxRequests = isset($options['max-requests']) ? max(0, (int) $options['max-requests']) : 0;

$pdo = get_mysql_db();
[$yearMin, $yearMax] = resolveYearRange($pdo);
$rangeCount = (int) (($yearMax - $yearMin + 1) * ($yearMax - $yearMin + 2) / 2);
$snapshotCountPerArea = ($yearMax - $yearMin + 1) * 12;

$totalPlanned = 0;
if (in_array('trend', $scopes, true)) {
    $totalPlanned += count($areas) * $rangeCount;
}
if (in_array('snapshot', $scopes, true)) {
    $totalPlanned += count($areas) * $snapshotCountPerArea;
}
if (in_array('diagnostics', $scopes, true)) {
    $totalPlanned += count($areas) * $rangeCount * $snapshotCountPerArea;
}
if ($maxRequests > 0 && $maxRequests < $totalPlanned) {
    $totalPlanned = $maxRequests;
}

if ($purgeFirst) {
    $purged = purgeCacheDir($cacheDir);
    fwrite(STDOUT, "Purged cache files: {$purged}\n");
}

fwrite(STDOUT, "Years: {$yearMin}..{$yearMax}\n");
fwrite(STDOUT, "Scopes: " . implode(', ', $scopes) . "\n");
fwrite(STDOUT, "Planned requests: {$totalPlanned}\n");

$started = microtime(true);
$done = 0;
$ok = 0;
$failed = 0;
$failSamples = [];

foreach (buildRequestGenerator($scopes, $areas, $yearMin, $yearMax) as $params) {
    if ($maxRequests > 0 && $done >= $maxRequests) {
        break;
    }

    $done++;
    [$success, $error] = invokeGetReportData($params, $apiPath);
    if ($success) {
        $ok++;
    } else {
        $failed++;
        if (count($failSamples) < 8) {
            $failSamples[] = [
                'params' => $params,
                'error' => $error,
            ];
        }
    }

    if ($done % 100 === 0 || $done === $totalPlanned) {
        $elapsed = microtime(true) - $started;
        $rate = $elapsed > 0 ? ($done / $elapsed) : 0.0;
        $eta = $rate > 0 ? (($totalPlanned - $done) / $rate) : 0.0;
        fwrite(
            STDOUT,
            sprintf(
                "Progress: %d/%d | ok=%d failed=%d | elapsed=%.1fs eta=%.1fs\n",
                $done,
                $totalPlanned,
                $ok,
                $failed,
                $elapsed,
                max(0.0, $eta)
            )
        );
    }
}

$elapsed = microtime(true) - $started;
$filesNow = count(glob($cacheDir . DIRECTORY_SEPARATOR . '*.json') ?: []);

fwrite(STDOUT, "\nCompleted.\n");
fwrite(STDOUT, sprintf("Requests run: %d | ok=%d | failed=%d | elapsed=%.2fs\n", $done, $ok, $failed, $elapsed));
fwrite(STDOUT, "Cache files present: {$filesNow}\n");

if (!empty($failSamples)) {
    fwrite(STDOUT, "\nSample failures:\n");
    foreach ($failSamples as $idx => $sample) {
        fwrite(STDOUT, sprintf("%d) %s | params=%s\n", $idx + 1, (string) $sample['error'], json_encode($sample['params'])));
    }
}

exit($failed > 0 ? 2 : 0);

function parseArgs(array $argv): array {
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (strncmp($arg, '--', 2) !== 0) {
            continue;
        }
        $raw = substr($arg, 2);
        $parts = explode('=', $raw, 2);
        $k = trim((string) ($parts[0] ?? ''));
        $v = trim((string) ($parts[1] ?? '1'));
        if ($k !== '') {
            $out[$k] = $v;
        }
    }
    return $out;
}

function parseScopes(string $raw): array {
    $allowed = ['trend', 'snapshot', 'diagnostics'];
    $scopes = [];
    foreach (explode(',', $raw) as $token) {
        $scope = strtolower(trim($token));
        if ($scope !== '' && in_array($scope, $allowed, true) && !in_array($scope, $scopes, true)) {
            $scopes[] = $scope;
        }
    }
    if (empty($scopes)) {
        $scopes = ['trend', 'snapshot'];
    }
    return $scopes;
}

function resolveYearRange(PDO $pdo): array {
    $row = $pdo->query('SELECT MIN(year) AS min_year, MAX(year) AS max_year FROM ecological_yearly_summary')->fetch(PDO::FETCH_ASSOC) ?: [];
    $min = (int) ($row['min_year'] ?? 0);
    $max = (int) ($row['max_year'] ?? 0);

    if ($min <= 0 || $max <= 0 || $min > $max) {
        $row2 = $pdo->query('SELECT MIN(year) AS min_year, MAX(year) AS max_year FROM raw_bird_observation WHERE year IS NOT NULL')->fetch(PDO::FETCH_ASSOC) ?: [];
        $min = (int) ($row2['min_year'] ?? 2014);
        $max = (int) ($row2['max_year'] ?? 2024);
    }

    if ($min <= 0 || $max <= 0 || $min > $max) {
        $min = 2014;
        $max = 2024;
    }

    return [$min, $max];
}

function purgeCacheDir(string $cacheDir): int {
    $files = glob($cacheDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $count = 0;
    foreach ($files as $file) {
        if (@unlink($file)) {
            $count++;
        }
    }
    return $count;
}

function buildRequestGenerator(array $scopes, array $areas, int $yearMin, int $yearMax): Generator {
    $defaultSnapshotYear = $yearMax;
    $defaultSnapshotMonth = 12;

    if (in_array('trend', $scopes, true)) {
        foreach ($areas as $area) {
            for ($start = $yearMin; $start <= $yearMax; $start++) {
                for ($end = $start; $end <= $yearMax; $end++) {
                    yield [
                        'scope' => 'trend',
                        'selected_area' => $area,
                        'start_year' => $start,
                        'end_year' => $end,
                        'snapshot_year' => $defaultSnapshotYear,
                        'snapshot_month' => $defaultSnapshotMonth,
                        'include_diagnostics' => '0',
                    ];
                }
            }
        }
    }

    if (in_array('snapshot', $scopes, true)) {
        foreach ($areas as $area) {
            for ($y = $yearMin; $y <= $yearMax; $y++) {
                for ($m = 1; $m <= 12; $m++) {
                    yield [
                        'scope' => 'snapshot',
                        'selected_area' => $area,
                        'start_year' => $yearMin,
                        'end_year' => $yearMax,
                        'snapshot_year' => $y,
                        'snapshot_month' => $m,
                        'include_diagnostics' => '0',
                    ];
                }
            }
        }
    }

    if (in_array('diagnostics', $scopes, true)) {
        foreach ($areas as $area) {
            for ($start = $yearMin; $start <= $yearMax; $start++) {
                for ($end = $start; $end <= $yearMax; $end++) {
                    for ($y = $yearMin; $y <= $yearMax; $y++) {
                        for ($m = 1; $m <= 12; $m++) {
                            yield [
                                'scope' => 'diagnostics',
                                'selected_area' => $area,
                                'start_year' => $start,
                                'end_year' => $end,
                                'snapshot_year' => $y,
                                'snapshot_month' => $m,
                                'include_diagnostics' => '1',
                            ];
                        }
                    }
                }
            }
        }
    }
}

function invokeGetReportData(array $params, string $apiPath): array {
    $paramsB64 = base64_encode((string) json_encode($params));
    $tmpScript = tempnam(sys_get_temp_dir(), 'report_warm_');
    if ($tmpScript === false) {
        return [false, 'Unable to create temporary runner script'];
    }

    $runner = "<?php\n"
        . '$p=json_decode(base64_decode(' . var_export($paramsB64, true) . '),true);' . "\n"
        . 'if(session_status()===PHP_SESSION_NONE){session_start();}' . "\n"
        . '$_SESSION["user_email"]="cli-cache-warmer.local";' . "\n"
        . '$_GET=$p;' . "\n"
        . 'include ' . var_export($apiPath, true) . ';' . "\n";

    if (@file_put_contents($tmpScript, $runner) === false) {
        @unlink($tmpScript);
        return [false, 'Unable to write temporary runner script'];
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpScript);
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    @unlink($tmpScript);

    if ($exitCode !== 0) {
        return [false, 'PHP child process exited with code ' . $exitCode];
    }

    $body = trim(implode("\n", $output));
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [false, 'Invalid JSON response from get_report_data.php'];
    }

    if (!($json['success'] ?? false)) {
        return [false, (string) ($json['error'] ?? 'Unknown error from get_report_data.php')];
    }

    return [true, ''];
}

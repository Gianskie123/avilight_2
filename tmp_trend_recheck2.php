<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_SESSION['user_email'] = 'cli-debug.local';
$_GET = [
  'selected_area' => 'All Areas',
  'start_year' => 2016,
  'end_year' => 2025,
  'snapshot_year' => 2025,
  'snapshot_month' => 1,
  'scope' => 'trend',
  'include_diagnostics' => '0',
  'force_refresh' => '1',
];
ob_start();
include __DIR__ . '/api/get_report_data.php';
$body = (string) ob_get_clean();
$j = json_decode($body, true);
if (!is_array($j) || empty($j['success'])) { echo "error\n"; echo $body; exit(1); }
$t = $j['trendHistoricalData'] ?? [];
$labels = is_array($t['labels'] ?? null) ? $t['labels'] : [];
$viirs = is_array($t['viirs'] ?? null) ? $t['viirs'] : [];
$ndvi = is_array($t['ndvi'] ?? null) ? $t['ndvi'] : [];
$lst = is_array($t['lst'] ?? null) ? $t['lst'] : [];
$prec = is_array($t['precip'] ?? null) ? $t['precip'] : [];
$corr = $j['trendCorrelationData'] ?? [];
$nullCounts = [
  'viirs' => count(array_filter($viirs, static fn($v) => $v === null)),
  'ndvi' => count(array_filter($ndvi, static fn($v) => $v === null)),
  'lst' => count(array_filter($lst, static fn($v) => $v === null)),
  'precip' => count(array_filter($prec, static fn($v) => $v === null)),
];
echo 'labels=' . json_encode($labels) . "\n";
echo 'null_counts=' . json_encode($nullCounts) . "\n";
echo 'viirs_first3=' . json_encode(array_slice($viirs, 0, 3)) . "\n";
echo 'ndvi_first3=' . json_encode(array_slice($ndvi, 0, 3)) . "\n";
echo 'lst_first3=' . json_encode(array_slice($lst, 0, 3)) . "\n";
echo 'corr=' . json_encode($corr) . "\n";

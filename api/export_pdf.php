<?php
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

function sanitize_filters(array $input): array {
    return [
        'selected_area' => trim((string) ($input['selected_area'] ?? 'All Areas')),
        'start_year' => (int) ($input['start_year'] ?? 2014),
        'end_year' => (int) ($input['end_year'] ?? 2024),
        'snapshot_year' => (int) ($input['snapshot_year'] ?? 2024),
        'snapshot_month' => (int) ($input['snapshot_month'] ?? 12),
    ];
}

function get_cached_report_payload(array $filters): array {
    $cacheDir = __DIR__ . '/../data/cache/reports';
    if (!is_dir($cacheDir)) {
        return [];
    }

    $cacheKey = 'reports:'
        . $filters['selected_area'] . ':'
        . $filters['start_year'] . ':'
        . $filters['end_year'] . ':'
        . $filters['snapshot_year'] . ':'
        . $filters['snapshot_month'] . ':diag=1';
    $cacheFile = $cacheDir . '/' . sha1($cacheKey) . '.json';
    if (!is_file($cacheFile) || !is_readable($cacheFile)) {
        return [];
    }

    $raw = @file_get_contents($cacheFile);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return (is_array($decoded) && !empty($decoded['success'])) ? $decoded : [];
}

function fetch_json_with_session(string $url): array {
    $cookie = session_name() . '=' . session_id();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Cookie: ' . $cookie,
                'Connection: close',
            ],
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (is_string($raw) && $raw !== '' && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $opts = [
        'http' => [
            'method' => 'GET',
            'timeout' => 45,
            'header' => "Accept: application/json\r\nCookie: {$cookie}\r\nConnection: close\r\n",
        ],
    ];

    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function call_report_data_api(array $filters): array {
    $cached = get_cached_report_payload($filters);
    if (!empty($cached)) {
        return $cached;
    }

    $query = http_build_query(array_merge($filters, [
        'scope' => 'diagnostics',
        'include_diagnostics' => '1',
    ]));

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/export_pdf.php';
    $appBase = rtrim(dirname(dirname($scriptName)), '/\\');
    if ($appBase === '') {
        $appBase = '';
    }
    $url = $scheme . '://' . $host . $appBase . '/api/get_report_data.php?' . $query;

    $decoded = fetch_json_with_session($url);
    return (is_array($decoded) && !empty($decoded['success'])) ? $decoded : [];
}

function wrap_text(string $text, int $maxChars = 108): array {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') {
        return [''];
    }
    $lines = [];
    while (strlen($text) > $maxChars) {
        $chunk = substr($text, 0, $maxChars + 1);
        $breakAt = strrpos($chunk, ' ');
        if ($breakAt === false || $breakAt < 30) {
            $breakAt = $maxChars;
        }
        $lines[] = trim(substr($text, 0, $breakAt));
        $text = trim(substr($text, $breakAt));
    }
    if ($text !== '') {
        $lines[] = $text;
    }
    return $lines;
}

class SimplePdfBuilder {
    private array $pages = [];
    private int $maxLinesPerPage;

    public function __construct(int $maxLinesPerPage = 52) {
        $this->maxLinesPerPage = $maxLinesPerPage;
        $this->pages[] = [];
    }

    public function addLine(string $line = ''): void {
        $current = count($this->pages) - 1;
        if (count($this->pages[$current]) >= $this->maxLinesPerPage) {
            $this->pages[] = [];
            $current++;
        }
        $this->pages[$current][] = $line;
    }

    public function addWrapped(string $text, int $maxChars = 108): void {
        foreach (wrap_text($text, $maxChars) as $line) {
            $this->addLine($line);
        }
    }

    private function esc(string $s): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    public function outputPdf(): string {
        $objects = [];
        $pagesKids = [];

        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';          // 1
        $objects[] = '';                                            // 2 pages placeholder
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'; // 3

        $pageCount = count($this->pages);
        for ($i = 0; $i < $pageCount; $i++) {
            $pageObjNum = 4 + ($i * 2);
            $contentObjNum = $pageObjNum + 1;
            $pagesKids[] = $pageObjNum . ' 0 R';

            $lines = $this->pages[$i];
            $content = "BT\n/F1 10 Tf\n50 790 Td\n12 TL\n";
            foreach ($lines as $line) {
                $content .= '(' . $this->esc($line) . ") Tj\nT*\n";
            }
            $content .= "ET";

            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentObjNum . ' 0 R >>';
            $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }

        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $pagesKids) . '] /Count ' . $pageCount . ' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $idx => $obj) {
            $offsets[] = strlen($pdf);
            $objNum = $idx + 1;
            $pdf .= $objNum . " 0 obj\n" . $obj . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\n";
        $pdf .= 'startxref' . "\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }
}

function fmt_num($v, int $dp = 4): string {
    if ($v === null || $v === '') {
        return '-';
    }
    if (!is_numeric($v)) {
        return (string) $v;
    }
    return number_format((float) $v, $dp, '.', ',');
}

$filters = sanitize_filters($_GET);
$payload = call_report_data_api($filters);

$pdf = new SimplePdfBuilder(52);

$pdf->addLine('AviLight Statistical Reports Dashboard');
$pdf->addLine('Professional Export Report');
$pdf->addLine(str_repeat('=', 108));
$pdf->addLine('Generated (UTC): ' . gmdate('Y-m-d H:i:s'));
$pdf->addLine('Area: ' . $filters['selected_area']);
$pdf->addLine('Trend Years: ' . $filters['start_year'] . ' to ' . $filters['end_year']);
$pdf->addLine('Snapshot: ' . $filters['snapshot_year'] . '-' . str_pad((string) $filters['snapshot_month'], 2, '0', STR_PAD_LEFT));
$pdf->addLine('');

if (empty($payload) || empty($payload['success'])) {
    $pdf->addLine('NOTICE');
    $pdf->addLine(str_repeat('-', 108));
    $pdf->addLine('Report payload could not be loaded at export time.');
    $pdf->addLine('Please refresh dashboard filters and retry export.');

    $filename = 'avilight_reports_' . date('Ymd_His') . '.pdf';
    $pdfBinary = $pdf->outputPdf();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBinary));
    echo $pdfBinary;
    exit;
}

$metrics = $payload['ensembleMetrics']['ensemble_average'] ?? [];
$pdf->addLine('MODEL DIAGNOSTICS SUMMARY');
$pdf->addLine(str_repeat('-', 108));
$pdf->addLine('RMSE: ' . fmt_num($metrics['rmse'] ?? null, 4));
$pdf->addLine('MAE : ' . fmt_num($metrics['mae'] ?? null, 4));
$pdf->addLine('R2  : ' . fmt_num($metrics['r2'] ?? null, 4));
$pdf->addLine('');

$corr = $payload['trendCorrelationData'] ?? [];
$pdf->addLine('ECOLOGICAL CORRELATIONS');
$pdf->addLine(str_repeat('-', 108));
$pdf->addLine('Richness vs VIIRS : ' . fmt_num($corr['richness_viirs'] ?? null, 4));
$pdf->addLine('Richness vs NDVI  : ' . fmt_num($corr['richness_ndvi'] ?? null, 4));
$pdf->addLine('Richness vs LST   : ' . fmt_num($corr['richness_lst'] ?? null, 4));
$pdf->addLine('Richness vs Precip: ' . fmt_num($corr['richness_precip'] ?? null, 4));
$pdf->addLine('');

$trend = $payload['trendHistoricalData'] ?? [];
$labels = is_array($trend['labels'] ?? null) ? $trend['labels'] : [];
$richness = is_array($trend['richness'] ?? null) ? $trend['richness'] : [];
$viirs = is_array($trend['viirs'] ?? null) ? $trend['viirs'] : [];
$ndvi = is_array($trend['ndvi'] ?? null) ? $trend['ndvi'] : [];
$lst = is_array($trend['lst'] ?? null) ? $trend['lst'] : [];
$precip = is_array($trend['precip'] ?? null) ? $trend['precip'] : [];

$pdf->addLine('HISTORICAL TRENDS TABLE');
$pdf->addLine(str_repeat('-', 108));
$pdf->addLine(sprintf('%-6s | %-12s | %-12s | %-10s | %-10s | %-12s', 'Year', 'Richness', 'VIIRS', 'NDVI', 'LST', 'Precip'));
$n = max(count($labels), count($richness), count($viirs), count($ndvi), count($lst), count($precip));
for ($i = 0; $i < $n; $i++) {
    $pdf->addLine(sprintf(
        '%-6s | %-12s | %-12s | %-10s | %-10s | %-12s',
        (string) ($labels[$i] ?? '-'),
        fmt_num($richness[$i] ?? null, 2),
        fmt_num($viirs[$i] ?? null, 2),
        fmt_num($ndvi[$i] ?? null, 4),
        fmt_num($lst[$i] ?? null, 2),
        fmt_num($precip[$i] ?? null, 2)
    ));
}
$pdf->addLine('');

$snapshot = $payload['snapshotDistributions'] ?? [];
$mig = $snapshot['migration_status'] ?? [];
$light = $snapshot['light_tolerance'] ?? [];

$pdf->addLine('SNAPSHOT DISTRIBUTIONS');
$pdf->addLine(str_repeat('-', 108));
$pdf->addLine('Migration Status:');
if (!empty($mig['labels']) && !empty($mig['data'])) {
    foreach ($mig['labels'] as $idx => $label) {
        $pdf->addLine('  - ' . $label . ': ' . fmt_num($mig['data'][$idx] ?? 0, 0));
    }
}
$pdf->addLine('Light Tolerance:');
if (!empty($light['labels']) && !empty($light['data'])) {
    foreach ($light['labels'] as $idx => $label) {
        $pdf->addLine('  - ' . $label . ': ' . fmt_num($light['data'][$idx] ?? 0, 0));
    }
}
$pdf->addLine('');

$topSites = $payload['topSitesRichnessData']['rows'] ?? [];
$pdf->addLine('TOP SITES BY RICHNESS');
$pdf->addLine(str_repeat('-', 108));
$pdf->addLine(sprintf('%-6s | %-40s | %-12s', 'Rank', 'Area', 'Richness'));
if (is_array($topSites)) {
    foreach ($topSites as $row) {
        $pdf->addLine(sprintf(
            '%-6s | %-40s | %-12s',
            (string) ($row['rank'] ?? '-'),
            substr((string) ($row['area'] ?? '-'), 0, 40),
            fmt_num($row['bird_richness'] ?? null, 0)
        ));
    }
}
$pdf->addLine('');

$importance = $payload['xgboostFeatureImportance'] ?? [];
foreach (['all', 'light_sensitive', 'light_tolerant', 'migratory', 'resident'] as $filter) {
    $block = $importance[$filter] ?? [];
    $pdf->addLine('XGBOOST FEATURE IMPORTANCE [' . strtoupper($filter) . ']');
    $pdf->addLine(str_repeat('-', 108));
    $labels = is_array($block['labels'] ?? null) ? $block['labels'] : [];
    $values = is_array($block['values'] ?? null) ? $block['values'] : [];
    $m = max(count($labels), count($values));
    for ($i = 0; $i < $m; $i++) {
        $pdf->addLine(sprintf('%-30s : %s', (string) ($labels[$i] ?? '-'), fmt_num($values[$i] ?? null, 6)));
    }
    $pdf->addLine('');
}

$convlstm = $payload['convlstmPredictions'] ?? [];
foreach (['all', 'light_sensitive', 'light_tolerant', 'migratory', 'resident'] as $filter) {
    $block = $convlstm[$filter] ?? [];
    $pdf->addLine('CONVLSTM ACTUAL VS PREDICTED [' . strtoupper($filter) . ']');
    $pdf->addLine(str_repeat('-', 108));
    $pdf->addLine(sprintf('%-8s | %-14s | %-14s', 'Year', 'Actual', 'Predicted'));
    $years = is_array($block['years'] ?? null) ? $block['years'] : [];
    $actual = is_array($block['actual'] ?? null) ? $block['actual'] : [];
    $pred = is_array($block['predicted'] ?? null) ? $block['predicted'] : [];
    $m = max(count($years), count($actual), count($pred));
    for ($i = 0; $i < $m; $i++) {
        $pdf->addLine(sprintf(
            '%-8s | %-14s | %-14s',
            (string) ($years[$i] ?? '-'),
            fmt_num($actual[$i] ?? null, 4),
            fmt_num($pred[$i] ?? null, 4)
        ));
    }
    $pdf->addLine('');
}

$kbaPath = __DIR__ . '/../data/sample_kba.json';
if (is_readable($kbaPath)) {
    $kba = json_decode((string) file_get_contents($kbaPath), true);
    if (is_array($kba) && !empty($kba)) {
        $pdf->addLine('SYSTEMS RECOMMENDATIONS: KBA/PA AUDIT');
        $pdf->addLine(str_repeat('-', 108));
        $pdf->addLine(sprintf('%-30s | %-5s | %-7s | %-8s | %-8s | %-7s | %-10s', 'Site', 'Type', 'Species', 'Sens %', 'Light', 'Eff', 'Status'));
        foreach ($kba as $site) {
            $pdf->addLine(sprintf(
                '%-30s | %-5s | %-7s | %-8s | %-8s | %-7s | %-10s',
                substr((string) ($site['name'] ?? '-'), 0, 30),
                (string) ($site['type'] ?? '-'),
                fmt_num($site['species_count'] ?? null, 0),
                fmt_num($site['sensitive_species_percent'] ?? null, 1),
                fmt_num($site['light_exposure'] ?? null, 1),
                fmt_num($site['effectiveness_score'] ?? null, 1),
                (string) ($site['status'] ?? '-')
            ));
        }
        $pdf->addLine('');
    }
}

$filename = 'avilight_reports_' . date('Ymd_His') . '.pdf';
$pdfBinary = $pdf->outputPdf();

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfBinary));
echo $pdfBinary;
?>

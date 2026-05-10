<?php
/**
 * api/test_bmb_fetch.php
 *
 * Diagnostic tool for the DENR-BMB announcement fetch pipeline.
 * Admin-only. Returns a JSON report of every stage in the fetch chain.
 *
 * Usage: GET /api/test_bmb_fetch.php
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fetch_bmb_announcements.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
api_assert_active();

$report = [];

// ── 1. Environment ────────────────────────────────────────────────────────────

$report['env'] = [
    'php_version'         => PHP_VERSION,
    'curl_available'      => function_exists('curl_init'),
    'curl_version'        => function_exists('curl_version') ? (curl_version()['version'] ?? 'unknown') : null,
    'fastcgi_finish_req'  => function_exists('fastcgi_finish_request'),
    'allow_url_fopen'     => (bool) ini_get('allow_url_fopen'),
    'data_dir_writable'   => is_writable(dirname(BMB_CACHE_FILE)),
    'cache_file'          => BMB_CACHE_FILE,
    'lock_file'           => BMB_LOCK_FILE,
    'cache_ttl_seconds'   => BMB_CACHE_TTL,
    'base_url'            => BMB_BASE_URL,
];

// ── 2. Cache state ────────────────────────────────────────────────────────────

$cacheResult = bmb_read_cache(10);
$report['cache'] = [
    'file_exists'  => file_exists(BMB_CACHE_FILE),
    'file_size'    => file_exists(BMB_CACHE_FILE) ? filesize(BMB_CACHE_FILE) : null,
    'file_mtime'   => file_exists(BMB_CACHE_FILE) ? date('Y-m-d H:i:s', filemtime(BMB_CACHE_FILE)) : null,
    'empty'        => $cacheResult['empty'],
    'stale'        => $cacheResult['stale'],
    'fetched_at'   => $cacheResult['fetched_at'] ? date('Y-m-d H:i:s', $cacheResult['fetched_at']) : null,
    'age_seconds'  => $cacheResult['fetched_at'] ? (time() - $cacheResult['fetched_at']) : null,
    'item_count'   => count($cacheResult['items']),
    'first_item_title' => $cacheResult['items'][0]['title'] ?? null,
];

// ── 3. Network connectivity to base URL ───────────────────────────────────────

function _diag_http_probe(string $url, int $timeout = 6): array
{
    $t = microtime(true);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_NOBODY         => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; AVILIGHT/1.0; +https://avilight.ph)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Cache-Control: no-cache',
            ],
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        $http   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size   = strlen($body ?: '');
        curl_close($ch);
        return [
            'ok'            => ($errno === 0 && $http >= 200 && $http < 400),
            'http_code'     => $http,
            'curl_errno'    => $errno,
            'curl_error'    => $errmsg ?: null,
            'response_size' => $size,
            'elapsed_ms'    => (int) round((microtime(true) - $t) * 1000),
            'body_snippet'  => $body ? substr(strip_tags($body), 0, 200) : null,
        ];
    }
    // fallback: file_get_contents
    $ctx  = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true,
        'user_agent' => 'Mozilla/5.0 (compatible; AVILIGHT/1.0)']]);
    $body = @file_get_contents($url, false, $ctx);
    return [
        'ok'            => ($body !== false && strlen($body) > 0),
        'http_code'     => null,
        'curl_errno'    => null,
        'curl_error'    => null,
        'response_size' => $body ? strlen($body) : 0,
        'elapsed_ms'    => (int) round((microtime(true) - $t) * 1000),
        'body_snippet'  => $body ? substr(strip_tags($body), 0, 200) : null,
    ];
}

$report['connectivity'] = [
    'base_url' => _diag_http_probe(BMB_BASE_URL),
];

// ── 4. WordPress REST API ─────────────────────────────────────────────────────

$restUrl = rtrim(BMB_BASE_URL, '/') . '/wp-json/wp/v2/posts?per_page=3&_fields=id,date,title,link';
$t = microtime(true);
$restRaw = _diag_http_probe($restUrl);
$restItems = [];
if ($restRaw['ok'] && $restRaw['response_size'] > 0) {
    // Re-fetch to get full body for parsing
    $body = _bmb_http_get($restUrl);
    if ($body !== false) {
        $decoded = json_decode($body, true);
        $restItems = is_array($decoded) ? $decoded : [];
    }
}
$report['fetch_rest'] = array_merge($restRaw, [
    'url'          => $restUrl,
    'parsed_posts' => count($restItems),
    'is_json'      => $restRaw['body_snippet'] ? str_starts_with(ltrim((string)$restRaw['body_snippet']), '[') : false,
]);

// ── 5. RSS Feed ───────────────────────────────────────────────────────────────

$rssUrl = rtrim(BMB_BASE_URL, '/') . '/feed/';
$rssProbe = _diag_http_probe($rssUrl);
$rssItems = [];
$rssParseError = null;
if ($rssProbe['ok'] && $rssProbe['response_size'] > 0) {
    $body = _bmb_http_get($rssUrl);
    if ($body !== false) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        if ($xml instanceof SimpleXMLElement) {
            foreach ($xml->channel->item ?? [] as $item) {
                $rssItems[] = (string)($item->title ?? '');
                if (count($rssItems) >= 3) break;
            }
        } else {
            $rssParseError = empty($xmlErrors) ? 'not valid XML' : $xmlErrors[0]->message;
        }
    }
}
$report['fetch_rss'] = array_merge($rssProbe, [
    'url'         => $rssUrl,
    'parsed_items'=> count($rssItems),
    'item_titles' => $rssItems,
    'parse_error' => $rssParseError,
    'looks_like_rss' => $rssProbe['body_snippet'] ? str_contains((string)$rssProbe['body_snippet'], 'rss') ||
        str_contains((string)$rssProbe['body_snippet'], 'channel') || str_contains((string)$rssProbe['body_snippet'], 'item') : false,
]);

// ── 6. HTML scrape ────────────────────────────────────────────────────────────

$htmlProbe = _diag_http_probe(BMB_BASE_URL);
$htmlArticleCount = 0;
$htmlParseError = null;
if ($htmlProbe['ok'] && $htmlProbe['response_size'] > 0) {
    $body = _bmb_http_get(BMB_BASE_URL);
    if ($body !== false) {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $body);
        libxml_clear_errors();
        $xpath    = new DOMXPath($doc);
        $articles = $xpath->query('//article');
        $htmlArticleCount = $articles ? $articles->length : 0;
    }
}
$report['fetch_html'] = array_merge($htmlProbe, [
    'url'           => BMB_BASE_URL,
    'article_count' => $htmlArticleCount,
]);

// ── 7. Summary ────────────────────────────────────────────────────────────────

$canReach  = $report['connectivity']['base_url']['ok'];
$restWorks = $report['fetch_rest']['parsed_posts'] > 0;
$rssWorks  = $report['fetch_rss']['parsed_items'] > 0;
$htmlWorks = $report['fetch_html']['article_count'] > 0;

$verdict = 'UNKNOWN';
if (!$canReach) {
    $verdict = 'NETWORK_BLOCKED — server cannot reach faps.bmb.gov.ph (firewall, DNS, or site is down)';
} elseif (!$restWorks && !$rssWorks && !$htmlWorks) {
    $verdict = 'REACHABLE_BUT_ALL_PARSERS_FAIL — site responds but REST/RSS/HTML yielded no items (structure mismatch or bot block)';
} elseif ($rssWorks || $restWorks || $htmlWorks) {
    $verdict = 'OK — at least one fetch method works; cache should populate on next refresh';
}

$report['summary'] = [
    'verdict'      => $verdict,
    'can_reach'    => $canReach,
    'rest_ok'      => $restWorks,
    'rss_ok'       => $rssWorks,
    'html_ok'      => $htmlWorks,
    'cache_warm'   => !$cacheResult['empty'] && !$cacheResult['stale'],
    'cache_stale'  => !$cacheResult['empty'] && $cacheResult['stale'],
    'cache_cold'   => $cacheResult['empty'],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

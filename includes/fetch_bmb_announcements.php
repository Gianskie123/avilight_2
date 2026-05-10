<?php
/**
 * DENR-BMB FAPS announcements — cache helpers.
 *
 * Public surface:
 *   fetch_bmb_announcements()   Legacy entry point (server-render, allow_network=false).
 *   bmb_read_cache()            Disk-only read; never touches the network.
 *   bmb_refresh_cache()         Network fetch with exclusive lock; writes cache.
 *   _bmb_flush_and_close()      Flush HTTP response to browser so PHP can keep running.
 */

if (!defined('BMB_BASE_URL'))   define('BMB_BASE_URL',   'https://faps.bmb.gov.ph/faps/');
if (!defined('BMB_CACHE_FILE')) define('BMB_CACHE_FILE', __DIR__ . '/../data/bmb_announcements_cache.json');
if (!defined('BMB_LOCK_FILE'))  define('BMB_LOCK_FILE',  __DIR__ . '/../data/bmb_announcements.lock');
if (!defined('BMB_CACHE_TTL'))  define('BMB_CACHE_TTL',  3600); // 1 hour

// ── Public: legacy entry point ────────────────────────────────────────────────

/**
 * Return announcements from cache (or network if allowed and cache is empty/stale).
 * The $allow_network = false path used by home.php server-render is always instant.
 */
function fetch_bmb_announcements(int $limit = 5, int $cache_ttl = BMB_CACHE_TTL, bool $allow_network = true): array
{
    $result = bmb_read_cache($limit, $cache_ttl);

    if (!$result['empty']) {
        if ($allow_network && $result['stale']) {
            $fresh = bmb_refresh_cache($limit);
            if (!empty($fresh)) {
                return array_slice($fresh, 0, $limit);
            }
        }
        return $result['items'];
    }

    if (!$allow_network) {
        return _bmb_fallback_data();
    }

    $items = bmb_refresh_cache($limit);
    return empty($items) ? _bmb_fallback_data() : array_slice($items, 0, $limit);
}

// ── Public: disk-only cache read ─────────────────────────────────────────────

/**
 * Read the on-disk cache and report its state. No network calls, always fast.
 *
 * @return array{items: list<array>, stale: bool, empty: bool, fetched_at: int|null}
 */
function bmb_read_cache(int $limit = 5, int $cache_ttl = BMB_CACHE_TTL): array
{
    $result = ['items' => [], 'stale' => true, 'empty' => true, 'fetched_at' => null];

    $raw = @file_get_contents(BMB_CACHE_FILE);
    if ($raw === false) {
        return $result;
    }

    $cached = json_decode($raw, true);
    if (!is_array($cached) || !isset($cached['fetched_at'], $cached['items']) || !is_array($cached['items'])) {
        return $result;
    }

    $age = time() - (int) $cached['fetched_at'];

    $result['items']      = array_slice($cached['items'], 0, $limit);
    $result['stale']      = ($age >= $cache_ttl);
    $result['empty']      = empty($cached['items']);
    $result['fetched_at'] = (int) $cached['fetched_at'];

    return $result;
}

// ── Public: network refresh with stampede protection ─────────────────────────

/**
 * Fetch fresh data from DENR-BMB and persist to cache.
 *
 * Uses a non-blocking exclusive file lock so only ONE concurrent process
 * performs the network call. All others return [] immediately and the
 * caller falls back to stale cache — no thundering herd.
 *
 * @return list<array> Fresh items, or [] on failure / lock contention.
 */
function bmb_refresh_cache(int $limit = 10): array
{
    $lock = @fopen(BMB_LOCK_FILE, 'c');
    if (!$lock) {
        return [];
    }

    // LOCK_NB: if another process is already refreshing, give up immediately.
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return [];
    }

    try {
        // Double-check: cache may have been refreshed while we waited for the lock.
        $raw = @file_get_contents(BMB_CACHE_FILE);
        if ($raw !== false) {
            $cached = json_decode($raw, true);
            if (
                is_array($cached) &&
                isset($cached['fetched_at'], $cached['items']) &&
                (time() - (int) $cached['fetched_at']) < BMB_CACHE_TTL
            ) {
                return is_array($cached['items']) ? $cached['items'] : [];
            }
        }

        // WordPress REST API first, HTML scrape as fallback.
        $items = _bmb_fetch_via_rest(BMB_BASE_URL, $limit);
        if (empty($items)) {
            $items = _bmb_fetch_via_html(BMB_BASE_URL, $limit);
        }

        if (!empty($items)) {
            @file_put_contents(
                BMB_CACHE_FILE,
                json_encode(['fetched_at' => time(), 'items' => $items], JSON_UNESCAPED_UNICODE)
            );
        }

        return $items;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ── Public: flush HTTP response to browser ────────────────────────────────────

/**
 * Deliver the already-buffered HTTP response to the browser now, while keeping
 * the PHP process alive for background work (cache refresh, etc.).
 *
 * On PHP-FPM (Railway and most PaaS) fastcgi_finish_request() does this
 * atomically and reliably. The generic fallback covers Apache mod_php.
 */
function _bmb_flush_and_close(): void
{
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    // Generic fallback: set Content-Length so the browser knows the response
    // is complete even after the connection is marked for close.
    ignore_user_abort(true);
    $len = ob_get_length();
    if ($len !== false) {
        header('Content-Length: ' . $len);
        header('Connection: close');
        ob_end_flush();
    }
    flush();
}

// ── Internal: WordPress REST API fetch ───────────────────────────────────────

function _bmb_fetch_via_rest(string $base_url, int $limit): array
{
    $api_url = rtrim($base_url, '/') . '/wp-json/wp/v2/posts?per_page=' . $limit
             . '&_fields=id,date,title,excerpt,link';

    $response = _bmb_http_get($api_url);
    if ($response === false) {
        return [];
    }

    $posts = json_decode($response, true);
    if (!is_array($posts) || empty($posts)) {
        return [];
    }

    $items = [];
    foreach ($posts as $post) {
        $title   = isset($post['title']['rendered'])
                    ? strip_tags(html_entity_decode($post['title']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                    : '';
        $excerpt = isset($post['excerpt']['rendered'])
                    ? strip_tags(html_entity_decode($post['excerpt']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                    : '';
        $date    = isset($post['date'])
                    ? date('M j, Y', strtotime($post['date']))
                    : '';
        $link    = $post['link'] ?? $base_url;

        if ($title === '') {
            continue;
        }

        $items[] = [
            'date'      => $date,
            'title'     => $title,
            'summary'   => mb_strimwidth($excerpt, 0, 200, '…'),
            'tag'       => 'News',
            'tag_class' => 'info',
            'link'      => $link,
        ];
    }

    return $items;
}

// ── Internal: HTML scrape fallback ───────────────────────────────────────────

function _bmb_fetch_via_html(string $base_url, int $limit): array
{
    $html = _bmb_http_get($base_url);
    if ($html === false) {
        return [];
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $items = [];

    $articles = $xpath->query('//article');
    foreach ($articles as $article) {
        if (count($items) >= $limit) {
            break;
        }

        $title_node = $xpath->query('.//h1/a|.//h2/a|.//h3/a', $article)->item(0);
        if (!($title_node instanceof DOMElement)) {
            continue;
        }
        $title = trim($title_node->textContent);
        $link  = $title_node->getAttribute('href') ?: $base_url;

        $date_node = $xpath->query('.//*[@class and contains(@class,"entry-date")]|.//time', $article)->item(0);
        $date = $date_node ? trim($date_node->textContent) : '';

        $excerpt_node = $xpath->query('.//*[contains(@class,"entry-summary")]|.//*[contains(@class,"entry-content")]', $article)->item(0);
        $excerpt = $excerpt_node ? mb_strimwidth(trim(strip_tags($excerpt_node->textContent)), 0, 200, '…') : '';

        if ($title === '') {
            continue;
        }

        $items[] = [
            'date'      => $date,
            'title'     => $title,
            'summary'   => $excerpt,
            'tag'       => 'News',
            'tag_class' => 'info',
            'link'      => $link,
        ];
    }

    return $items;
}

// ── Internal: HTTP GET ────────────────────────────────────────────────────────

function _bmb_http_get(string $url)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'AVILIGHT/1.0 (DENR-BMB feed reader)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);
        return ($err === 0 && $body !== false) ? $body : false;
    }

    $ctx = stream_context_create(['http' => [
        'timeout'       => 8,
        'user_agent'    => 'AVILIGHT/1.0 (DENR-BMB feed reader)',
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || strlen($body) === 0) {
        return false;
    }
    if (isset($http_response_header[0]) && !preg_match('#HTTP/\S+ 2\d\d#', $http_response_header[0])) {
        return false;
    }
    return $body;
}

// ── Internal: hardcoded fallback ─────────────────────────────────────────────

function _bmb_fallback_data(): array
{
    return [
        [
            'date'      => '',
            'title'     => 'DENR-BMB FAPS – Recent Announcements',
            'summary'   => 'Live announcements could not be loaded at this time. Visit the DENR-BMB FAPS portal for the latest updates.',
            'tag'       => 'Info',
            'tag_class' => 'info',
            'link'      => 'https://faps.bmb.gov.ph/faps/',
        ],
    ];
}

<?php
/**
 * Fetch recent announcements from the DENR-BMB FAPS site.
 *
 * Strategy:
 *  1. Return cached data if it is less than 1 hour old.
 *  2. Try the WordPress REST API  (/wp-json/wp/v2/posts).
 *  3. Fall back to lightweight HTML scraping.
 *  4. Fall back to hardcoded placeholder data so the page never breaks.
 *
 * @param  int   $limit         Maximum number of announcements to return.
 * @param  int   $cache_ttl     Cache lifetime in seconds (default 3600 = 1 h).
 * @param  bool  $allow_network When false, do not make live network calls.
 * @return array            Array of announcement arrays with keys:
 *                          date, title, summary, tag, tag_class, link.
 */
function fetch_bmb_announcements(int $limit = 5, int $cache_ttl = 3600, bool $allow_network = true): array
{
    $base_url   = 'https://faps.bmb.gov.ph/faps/';
    $cache_file = __DIR__ . '/../data/bmb_announcements_cache.json';

    // ── 1. Serve from cache if still fresh ────────────────────────────────
    if (file_exists($cache_file)) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if (
            is_array($cached)
            && isset($cached['fetched_at'], $cached['items'])
            && (time() - (int) $cached['fetched_at']) < $cache_ttl
        ) {
            return array_slice($cached['items'], 0, $limit);
        }
    }

    if (!$allow_network) {
        return _bmb_fallback_data();
    }

    // ── 2. Try WordPress REST API ──────────────────────────────────────────
    $items = _bmb_fetch_via_rest($base_url, $limit);

    // ── 3. Fall back to HTML scraping ──────────────────────────────────────
    if (empty($items)) {
        $items = _bmb_fetch_via_html($base_url, $limit);
    }

    // ── 4. Hardcoded fallback so the page never shows nothing ──────────────
    if (empty($items)) {
        return _bmb_fallback_data();
    }

    // Persist to cache
    $payload = ['fetched_at' => time(), 'items' => $items];
    @file_put_contents($cache_file, json_encode($payload, JSON_UNESCAPED_UNICODE));

    return array_slice($items, 0, $limit);
}

// ── Internal helpers ──────────────────────────────────────────────────────────

/**
 * Fetch posts via the WordPress REST API.
 */
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

/**
 * Fetch posts by scraping the HTML home page.
 */
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

    // WordPress themes typically wrap posts in <article> elements.
    $articles = $xpath->query('//article');
    foreach ($articles as $article) {
        if (count($items) >= $limit) {
            break;
        }

        // Title – look for the first heading link
        $title_node = $xpath->query('.//h1/a|.//h2/a|.//h3/a', $article)->item(0);
        if (!($title_node instanceof DOMElement)) {
            continue;
        }
        $title = trim($title_node->textContent);
        $link  = $title_node->getAttribute('href') ?: $base_url;

        // Date
        $date_node = $xpath->query('.//*[@class and contains(@class,"entry-date")]|.//time', $article)->item(0);
        $date = $date_node ? trim($date_node->textContent) : '';

        // Excerpt / entry content
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

/**
 * Minimal cURL / file_get_contents wrapper.
 * Returns the response body string or false on failure.
 */
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

    // Fallback: file_get_contents with a short timeout
    $ctx = stream_context_create(['http' => [
        'timeout'       => 8,
        'user_agent'    => 'AVILIGHT/1.0 (DENR-BMB feed reader)',
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || strlen($body) === 0) {
        return false;
    }
    // Reject non-2xx HTTP responses
    if (isset($http_response_header[0]) && !preg_match('#HTTP/\S+ 2\d\d#', $http_response_header[0])) {
        return false;
    }
    return $body;
}

/**
 * Static fallback data shown when the live feed cannot be reached.
 */
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

<?php
/**
 * api/get_bmb_announcements.php
 *
 * Stale-while-revalidate: always respond from cache instantly, then refresh
 * the cache in the background after the response is already sent to the browser.
 *
 * Flow:
 *   Cache fresh  → respond in < 5 ms, done.
 *   Cache stale  → respond from stale cache in < 5 ms, then background refresh.
 *   No cache     → block once to populate (first run / cache deleted), then respond.
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

$limit  = max(1, min(20, (int) ($_GET['limit'] ?? 5)));
$result = bmb_read_cache($limit);

if (!$result['empty']) {
    // Cache hit (fresh or stale) — respond to the browser immediately.
    $age    = $result['fetched_at'] !== null ? (time() - $result['fetched_at']) : BMB_CACHE_TTL;
    $maxAge = max(0, BMB_CACHE_TTL - $age);
    header('Cache-Control: private, max-age=' . $maxAge . ', stale-while-revalidate=' . BMB_CACHE_TTL);

    echo json_encode(['success' => true, 'items' => $result['items']]);

    if ($result['stale']) {
        // Flush HTTP response to browser first, then refresh cache in background.
        // fastcgi_finish_request() on Railway/PHP-FPM makes this instant and safe.
        _bmb_flush_and_close();
        bmb_refresh_cache();
    }
    exit;
}

// No cache at all — must fetch synchronously (first run / cache wiped).
// This is the only case where the user waits; it happens at most once.
header('Cache-Control: private, no-store');
$items = bmb_refresh_cache($limit);
if (empty($items)) {
    $items = _bmb_fallback_data();
}
echo json_encode(['success' => true, 'items' => array_slice($items, 0, $limit)]);

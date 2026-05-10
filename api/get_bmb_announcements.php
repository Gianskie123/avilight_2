<?php
/**
 * api/get_bmb_announcements.php
 *
 * This endpoint NEVER blocks the browser, regardless of cache state.
 *
 * Three cases:
 *   Cache fresh  → respond instantly (<5 ms). Done.
 *   Cache stale  → respond with stale data instantly, background-refresh after sending.
 *   No cache     → respond with fallback + {pending:true} instantly,
 *                  background-refresh after sending so the NEXT request hits cache.
 *
 * The JS caller checks {pending:true} and retries after a short delay.
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
    // ── Cache hit (fresh or stale) ────────────────────────────────────────
    $age    = $result['fetched_at'] !== null ? (time() - $result['fetched_at']) : BMB_CACHE_TTL;
    $maxAge = max(0, BMB_CACHE_TTL - $age);
    header('Cache-Control: private, max-age=' . $maxAge . ', stale-while-revalidate=' . BMB_CACHE_TTL);

    echo json_encode(['success' => true, 'items' => $result['items']]);

    if ($result['stale']) {
        _bmb_flush_and_close();
        bmb_refresh_cache();
    }
    exit;
}

// ── No cache at all (first run / cache wiped) ─────────────────────────────
// Return fallback immediately so the browser is never blocked,
// then warm the cache in the background.
// The JS caller detects {pending:true} and retries automatically.
header('Cache-Control: private, no-store');
echo json_encode(['success' => true, 'items' => _bmb_fallback_data(), 'pending' => true]);
_bmb_flush_and_close();
bmb_refresh_cache();

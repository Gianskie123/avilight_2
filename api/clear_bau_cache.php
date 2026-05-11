<?php
/**
 * api/clear_bau_cache.php
 *
 * Deletes all rows from analytics_bau_baselines so the BAU baseline
 * is recomputed fresh on the next forecast request.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

try {
    $pdo = get_mysql_db();
    ensure_mysql_bau_baseline_cache_table($pdo);
    $deleted = (int)$pdo->query('SELECT COUNT(*) FROM analytics_bau_baselines')->fetchColumn();
    $pdo->exec('DELETE FROM analytics_bau_baselines');
    echo json_encode(['success' => true, 'deleted_rows' => $deleted]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

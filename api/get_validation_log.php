<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'rows' => []]);
    exit;
}

try {
    $pdo  = get_mysql_db();
    $rows = $pdo->query(
        "SELECT ul.uploaded_at, ul.uploaded_by, ul.filename,
                url.reason,
                COUNT(*) AS cnt
           FROM upload_rejection_log url
           JOIN upload_log ul ON ul.id = url.upload_log_id
          WHERE ul.uploaded_at >= NOW() - INTERVAL 1 HOUR
          GROUP BY url.upload_log_id, url.reason
          ORDER BY ul.uploaded_at DESC, url.reason
          LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'rows' => $rows]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'rows' => [], 'error' => $e->getMessage()]);
}

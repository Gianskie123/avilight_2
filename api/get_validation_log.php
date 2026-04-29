<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'uploads' => []]);
    exit;
}
api_assert_active();

try {
    $pdo = get_mysql_db();

    // All uploads from the last 24 hours — includes clean uploads with zero rejections
    $uploads = $pdo->query(
        "SELECT id, uploaded_at, uploaded_by, filename, status,
                rows_total, rows_inserted, rows_skipped, new_species, fail_reason
         FROM upload_log
         WHERE uploaded_at >= NOW() - INTERVAL 24 HOUR
         ORDER BY uploaded_at DESC
         LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (empty($uploads)) {
        echo json_encode(['success' => true, 'uploads' => []]);
        exit;
    }

    // Rejection-reason counts grouped per upload and reason
    $upload_ids   = array_column($uploads, 'id');
    $placeholders = implode(',', array_fill(0, count($upload_ids), '?'));
    $rejStmt      = $pdo->prepare(
        "SELECT upload_log_id, reason, COUNT(*) AS cnt
         FROM upload_rejection_log
         WHERE upload_log_id IN ($placeholders)
         GROUP BY upload_log_id, reason
         ORDER BY upload_log_id, reason"
    );
    $rejStmt->execute($upload_ids);

    $rejection_map = [];
    foreach ($rejStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rejection_map[(int)$r['upload_log_id']][$r['reason']] = (int)$r['cnt'];
    }

    foreach ($uploads as &$u) {
        $u['rejections'] = $rejection_map[(int)$u['id']] ?? [];
    }
    unset($u);

    echo json_encode(['success' => true, 'uploads' => $uploads]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'uploads' => [], 'error' => $e->getMessage()]);
}

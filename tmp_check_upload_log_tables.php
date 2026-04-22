<?php
echo "<h3>This file is in: <strong>avilight</strong></h3>";
require_once __DIR__ . '/includes/db.php';

try {
    $pdo = get_mysql_db();

    // Row counts
    $ul_count  = $pdo->query("SELECT COUNT(*) FROM upload_log")->fetchColumn();
    $url_count = $pdo->query("SELECT COUNT(*) FROM upload_rejection_log")->fetchColumn();
    echo "<p>upload_log rows: <strong>{$ul_count}</strong></p>";
    echo "<p>upload_rejection_log rows: <strong>{$url_count}</strong></p>";

    // Latest 5 upload_log entries regardless of time
    echo "<h3>Latest upload_log entries:</h3>";
    $rows = $pdo->query("SELECT id, uploaded_by, filename, status, rows_total, rows_inserted, rows_skipped, uploaded_at FROM upload_log ORDER BY uploaded_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($rows, true) . "</pre>";

    // Latest 5 rejection log entries
    echo "<h3>Latest upload_rejection_log entries:</h3>";
    $rows = $pdo->query("SELECT * FROM upload_rejection_log ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($rows, true) . "</pre>";

    // Exact query used by get_validation_log.php WITH 1 DAY filter
    echo "<h3>Validation log query (WITH 1 DAY filter):</h3>";
    $rows = $pdo->query(
        "SELECT ul.uploaded_at, ul.uploaded_by, ul.filename, url.reason, COUNT(*) AS cnt
           FROM upload_rejection_log url
           JOIN upload_log ul ON ul.id = url.upload_log_id
          WHERE ul.uploaded_at >= NOW() - INTERVAL 1 DAY
          GROUP BY url.upload_log_id, url.reason
          ORDER BY ul.uploaded_at DESC, url.reason
          LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo count($rows) ? "<pre>" . print_r($rows, true) . "</pre>" : "<p style='color:red'>No rows returned — all data is older than 1 day.</p>";

    // Same query WITHOUT time filter
    echo "<h3>Validation log query (NO time filter):</h3>";
    $rows = $pdo->query(
        "SELECT ul.uploaded_at, ul.uploaded_by, ul.filename, url.reason, COUNT(*) AS cnt
           FROM upload_rejection_log url
           JOIN upload_log ul ON ul.id = url.upload_log_id
          GROUP BY url.upload_log_id, url.reason
          ORDER BY ul.uploaded_at DESC, url.reason
          LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($rows, true) . "</pre>";

} catch (Throwable $e) {
    echo "<p style='color:red'>DB error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

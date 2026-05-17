<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = get_mysql_db();
    // Check models table existence
    $stmt = $pdo->query("SHOW TABLES LIKE 'models'");
    $has = $stmt && $stmt->fetchColumn();
    if (!$has) {
        echo json_encode(['success' => true, 'active' => null, 'note' => 'models table not present']);
        exit;
    }

    $row = $pdo->query("SELECT id, version_name, file_path, status, created_at FROM models WHERE status = 'Active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => true, 'active' => null, 'note' => 'no active model']);
        exit;
    }

    echo json_encode(['success' => true, 'active' => [
        'id' => (int)$row['id'],
        'version' => $row['version_name'] ?? null,
        'file_path' => $row['file_path'] ?? null,
        'status' => $row['status'] ?? null,
        'created_at' => $row['created_at'] ?? null,
    ]]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

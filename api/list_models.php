<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/db.php';

try {
    $db   = get_mysql_db();
    $stmt = $db->query(
        "SELECT version_name, status, created_at
         FROM models
         ORDER BY created_at DESC, id DESC"
    );
    echo json_encode(['success' => true, 'models' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'models' => []]);
}

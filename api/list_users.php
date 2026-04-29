<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'users' => []]);
    exit;
}

try {
    $pdo   = get_mysql_db();
    $users = $pdo->query(
        'SELECT user_id, email, full_name, user_type, is_active, last_login_at
         FROM users ORDER BY user_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'users' => $users]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'users' => [], 'error' => $e->getMessage()]);
}

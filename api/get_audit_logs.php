<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in() || !is_it_admin()) {
    echo json_encode(['success' => false, 'rows' => []]);
    exit;
}

$type     = $_GET['type']      ?? 'activity';
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 10;
$offset    = ($page - 1) * $per_page;

$date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) ? $date_from : '';
$date_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)   ? $date_to   : '';

try {
    $pdo = get_mysql_db();

    if ($type === 'failures') {
        _ensure_login_attempts_table($pdo);
        $where  = "WHERE success = 0";
        $params = [];
        if ($date_from) { $where .= " AND DATE(attempted_at) >= :df"; $params[':df'] = $date_from; }
        if ($date_to)   { $where .= " AND DATE(attempted_at) <= :dt"; $params[':dt'] = $date_to;   }

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT email, ip_address, attempted_at FROM login_attempts $where
             ORDER BY attempted_at DESC LIMIT $per_page OFFSET $offset"
        );
        $stmt->execute($params);

    } else {
        $allowed = ['login','logout','add_user','activate_user','deactivate_user','reset_password','delete_user'];
        $action  = trim($_GET['action'] ?? '');
        $action  = in_array($action, $allowed) ? $action : '';

        _ensure_access_log_table($pdo);
        $where  = "WHERE 1=1";
        $params = [];
        if ($action)    { $where .= " AND action LIKE :act";       $params[':act'] = $action . '%'; }
        if ($date_from) { $where .= " AND DATE(logged_at) >= :df"; $params[':df']  = $date_from; }
        if ($date_to)   { $where .= " AND DATE(logged_at) <= :dt"; $params[':dt']  = $date_to;   }

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM access_log $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT email, action, ip_address, logged_at FROM access_log $where
             ORDER BY logged_at DESC LIMIT $per_page OFFSET $offset"
        );
        $stmt->execute($params);
    }

    echo json_encode([
        'success'      => true,
        'rows'         => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total'        => $total,
        'page'         => $page,
        'per_page'     => $per_page,
        'total_pages'  => max(1, (int)ceil($total / $per_page)),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'rows' => [], 'error' => $e->getMessage()]);
}

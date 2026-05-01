<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in() || !is_it_admin()) {
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required.']);
    exit;
}

$action  = $_POST['action']  ?? '';
$user_id = (int)($_POST['user_id'] ?? 0);

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid user ID.']);
    exit;
}

// Prevent acting on own account
if ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
    echo json_encode(['success' => false, 'error' => 'You cannot modify your own account.']);
    exit;
}

try {
    $pdo = get_mysql_db();

    // Fetch target user
    $target = $pdo->prepare('SELECT user_id, email, is_active FROM users WHERE user_id = :id LIMIT 1');
    $target->execute([':id' => $user_id]);
    $user = $target->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }

    switch ($action) {

        case 'activate':
            $pdo->prepare('UPDATE users SET is_active = 1 WHERE user_id = :id')
                ->execute([':id' => $user_id]);
            _log($pdo, 'activate_user: ' . $user['email']);
            echo json_encode(['success' => true, 'message' => 'Account activated.']);
            break;

        case 'deactivate':
            $pdo->prepare('UPDATE users SET is_active = 0 WHERE user_id = :id')
                ->execute([':id' => $user_id]);
            _log($pdo, 'deactivate_user: ' . $user['email']);
            echo json_encode(['success' => true, 'message' => 'Account deactivated.']);
            break;

        case 'change_password':
            $new_password = $_POST['new_password'] ?? '';
            if (strlen($new_password) < 8) {
                echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters.']);
                exit;
            }
            $pdo->prepare('UPDATE users SET password_hash = :hash, password_changed_at = NULL WHERE user_id = :id')
                ->execute([':hash' => password_hash($new_password, PASSWORD_BCRYPT), ':id' => $user_id]);
            _log($pdo, 'change_password: ' . $user['email']);
            echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
            break;

        case 'delete':
            $pdo->prepare('DELETE FROM users WHERE user_id = :id')
                ->execute([':id' => $user_id]);
            _log($pdo, 'delete_user: ' . $user['email']);
            echo json_encode(['success' => true, 'message' => 'Account deleted.']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function _log(PDO $pdo, string $action): void {
    try {
        _ensure_access_log_table($pdo);
        $pdo->prepare(
            'INSERT INTO access_log (user_id, email, action, ip_address) VALUES (:uid, :email, :act, :ip)'
        )->execute([
            ':uid'   => $_SESSION['user_id']    ?? null,
            ':email' => $_SESSION['user_email'] ?? '',
            ':act'   => $action,
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Exception $e) { /* non-fatal */ }
}

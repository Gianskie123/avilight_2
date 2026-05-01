<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$full_name = trim($_POST['full_name'] ?? '');
$email     = trim($_POST['email']     ?? '');
$password  = trim($_POST['password']  ?? '');
$user_type = in_array($_POST['user_type'] ?? '', ['IT_admin', 'EMS']) ? $_POST['user_type'] : 'EMS';

if ($full_name === '') {
    echo json_encode(['success' => false, 'error' => 'Full name is required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}
if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters.']);
    exit;
}

try {
    $pdo = get_mysql_db();

    $pdo->prepare('INSERT INTO users (email, password_hash, full_name, user_type, created_by) VALUES (:email, :hash, :full_name, :user_type, :created_by)')
        ->execute([
            ':email'      => $email,
            ':hash'       => password_hash($password, PASSWORD_BCRYPT),
            ':full_name'  => $full_name,
            ':user_type'  => $user_type,
            ':created_by' => $_SESSION['user_id'] ?? null,
        ]);

    _ensure_access_log_table($pdo);
    $pdo->prepare(
        'INSERT INTO access_log (user_id, email, action, ip_address) VALUES (:uid, :email, :act, :ip)'
    )->execute([
        ':uid'   => $_SESSION['user_id']    ?? null,
        ':email' => $_SESSION['user_email'] ?? '',
        ':act'   => 'add_user: ' . $email,
        ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    echo json_encode(['success' => true, 'message' => 'User ' . $email . ' added successfully.']);
} catch (Exception $e) {
    $msg = $e->getMessage();
    $error = str_contains($msg, 'UNIQUE')
        ? 'That email is already registered.'
        : $msg;
    echo json_encode(['success' => false, 'error' => $error]);
}

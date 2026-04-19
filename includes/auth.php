<?php
// Authentication and session management for AVILIGHT Dashboard

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in() {
    return isset($_SESSION['user_email']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    if (($_SESSION['user_role'] ?? 'user') !== 'admin') {
        header('Location: home.php');
        exit;
    }
}

function get_logged_user() {
    return $_SESSION['user_email'] ?? null;
}

function get_logged_role() {
    return $_SESSION['user_role'] ?? 'user';
}

function logout() {
    if (is_logged_in()) {
        try {
            require_once __DIR__ . '/db.php';
            $pdo = get_mysql_db();
            _ensure_access_log_table($pdo);
            $pdo->prepare(
                'INSERT INTO access_log (user_id, email, action, ip_address) VALUES (:uid, :email, :act, :ip)'
            )->execute([
                ':uid'   => $_SESSION['user_id'] ?? null,
                ':email' => $_SESSION['user_email'] ?? '',
                ':act'   => 'logout',
                ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        } catch (Exception $e) {
            error_log('[AVILIGHT] logout log error: ' . $e->getMessage());
        }
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

/**
 * Validate credentials against the MySQL users table.
 * Records the attempt in login_attempts and logs a successful access event.
 * Returns the user row on success or false on failure.
 */
function authenticate_user(string $email, string $password) {
    require_once __DIR__ . '/db.php';
    try {
        $pdo  = get_mysql_db();
        _ensure_users_table($pdo);
        _ensure_login_attempts_table($pdo);
        _ensure_access_log_table($pdo);

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => trim($email)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $success = $user && password_verify($password, $user['password_hash']);

        // Always record the attempt
        $pdo->prepare(
            'INSERT INTO login_attempts (email, ip_address, success) VALUES (:email, :ip, :ok)'
        )->execute([
            ':email' => trim($email),
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ok'    => $success ? 1 : 0,
        ]);

        if ($success) {
            $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = :id')
                ->execute([':id' => $user['user_id']]);
            $pdo->prepare(
                'INSERT INTO access_log (user_id, email, action, ip_address) VALUES (:uid, :email, :act, :ip)'
            )->execute([
                ':uid'   => $user['user_id'],
                ':email' => $user['email'],
                ':act'   => 'login',
                ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            return $user;
        }
    } catch (Exception $e) {
        error_log('[AVILIGHT] authenticate_user error: ' . $e->getMessage());
    }
    return false;
}

/**
 * Change the password for the currently logged-in user.
 * Returns true on success, or a string error message on failure.
 */
function change_password(string $current_password, string $new_password) {
    if (!is_logged_in()) {
        return 'Not logged in.';
    }
    if (strlen($new_password) < 8) {
        return 'New password must be at least 8 characters.';
    }
    require_once __DIR__ . '/db.php';
    try {
        $pdo  = get_mysql_db();
        _ensure_users_table($pdo);
        $stmt = $pdo->prepare('SELECT user_id, password_hash FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $_SESSION['user_email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($current_password, $user['password_hash'])) {
            return 'Current password is incorrect.';
        }
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :id')
            ->execute([':hash' => $new_hash, ':id' => $user['user_id']]);
        return true;
    } catch (Exception $e) {
        error_log('[AVILIGHT] change_password error: ' . $e->getMessage());
        return 'An unexpected error occurred. Please try again.';
    }
}

/**
 * List all users (admin only).
 */
function list_users(): array {
    require_once __DIR__ . '/db.php';
    try {
        $pdo = get_mysql_db();
        _ensure_users_table($pdo);
        return $pdo->query('SELECT user_id, email, role, created_at, last_login_at FROM users ORDER BY user_id')
                   ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Ensure the MySQL login_attempts table exists.
 * Columns: id, email, ip_address, success, attempted_at
 */
function _ensure_login_attempts_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        email        VARCHAR(255)    NOT NULL,
        ip_address   VARCHAR(45)     NOT NULL DEFAULT '',
        success      TINYINT(1)      NOT NULL DEFAULT 0,
        attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_la_email (email),
        KEY idx_la_time  (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Ensure the MySQL access_log table exists.
 * Columns: id, user_id, email, action, ip_address, logged_at
 */
function _ensure_access_log_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS access_log (
        id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        user_id    INT UNSIGNED    NULL,
        email      VARCHAR(255)    NOT NULL DEFAULT '',
        action     VARCHAR(255)    NOT NULL,
        ip_address VARCHAR(45)     NOT NULL DEFAULT '',
        logged_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_al_email (email),
        KEY idx_al_time  (logged_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Ensure the MySQL users table exists (created on first use).
 */
function _ensure_users_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        email         VARCHAR(255) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('admin','user') NOT NULL DEFAULT 'user',
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login    DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uidx_users_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default admin if table is empty
    $count = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ((int)$count === 0) {
        $default_pass = getenv('AVILIGHT_ADMIN_PASS') ?: 'avilight2024!';
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, :role)'
        )->execute([
            ':email' => 'admin@avilight.ph',
            ':hash'  => password_hash($default_pass, PASSWORD_BCRYPT),
            ':role'  => 'admin',
        ]);
    }
}


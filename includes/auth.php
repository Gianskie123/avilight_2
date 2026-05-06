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
    _assert_user_active();
}

function require_admin() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    _assert_user_active();
    if (($_SESSION['user_role'] ?? 'user') !== 'IT_admin') {
        header('Location: home.php');
        exit;
    }
}

/**
 * Kill the current session if the user's account has been deactivated.
 * Called on every authenticated request so deactivation takes effect immediately.
 * Uses email (always present in session) so the check works even when user_id is unset.
 */
function _assert_user_active(): void {
    $email = $_SESSION['user_email'] ?? null;
    if (!$email) {
        return;
    }
    try {
        require_once __DIR__ . '/db.php';
        $pdo  = get_mysql_db();
        $stmt = $pdo->prepare('SELECT is_active FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['is_active'] === 0) {
            session_destroy();
            header('Location: login.php');
            exit;
        }
    } catch (Exception $e) {
        error_log('[AVILIGHT] _assert_user_active error: ' . $e->getMessage());
    }
}

/**
 * Single call for JSON API endpoints: checks login + active status.
 * Replaces the manual is_logged_in() block in API files.
 */
function require_api_auth(): void {
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
        exit;
    }
    api_assert_active();
}

/**
 * For JSON API endpoints: return 401 and exit if the user is deactivated.
 * Releases the PHP session file lock before doing any DB work so concurrent
 * AJAX requests are not serialized waiting for the session lock.
 */
function api_assert_active(): void {
    $email = $_SESSION['user_email'] ?? null;
    // Release the session lock immediately — we only needed to read the email.
    // This allows other concurrent requests to proceed without queuing.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (!$email) {
        return;
    }
    try {
        require_once __DIR__ . '/db.php';
        $pdo  = get_mysql_db();
        $stmt = $pdo->prepare('SELECT is_active FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['is_active'] === 0) {
            session_start();
            session_destroy();
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Account deactivated.', 'redirect' => 'login.php']);
            exit;
        }
    } catch (Exception $e) {
        error_log('[AVILIGHT] api_assert_active error: ' . $e->getMessage());
    }
}

function get_logged_user() {
    return $_SESSION['user_email'] ?? null;
}

function get_logged_role() {
    return $_SESSION['user_role'] ?? 'user';
}

function get_logged_user_type(): string {
    return $_SESSION['user_type'] ?? 'EMS';
}

function is_it_admin(): bool {
    return get_logged_user_type() === 'IT_admin';
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
 * Check whether an email is temporarily locked out due to repeated failures.
 * 5 failed attempts within 15 minutes blocks further attempts for that window.
 * Also logs the blocked attempt so the count keeps rising during the lockout.
 * Returns an error string if locked, null if the account can proceed.
 */
function check_login_lockout(string $email): ?string {
    try {
        require_once __DIR__ . '/db.php';
        $pdo = get_mysql_db();
        _ensure_login_attempts_table($pdo);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND success = 0
             AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $stmt->execute([':email' => trim($email)]);
        if ((int)$stmt->fetchColumn() >= 5) {
            // Log the blocked attempt so the window keeps extending
            $pdo->prepare(
                "INSERT INTO login_attempts (email, ip_address, success) VALUES (:email, :ip, 0)"
            )->execute([':email' => trim($email), ':ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            return 'Too many failed login attempts. Please wait 15 minutes and try again.';
        }
    } catch (Exception $e) {
        error_log('[AVILIGHT] check_login_lockout error: ' . $e->getMessage());
    }
    return null;
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

        // Reject deactivated accounts before even checking the password
        if ($user && empty($user['is_active'])) {
            return false;
        }

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
            $_SESSION['user_type'] = $user['user_type'] ?? 'EMS';
            $user_id = $user['user_id'] ?? $user['id'] ?? null;

            if ($user_id !== null) {
                try {
                    $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
                        ->execute([':id' => $user_id]);
                } catch (Exception $e) {
                    error_log('[AVILIGHT] last_login update skipped: ' . $e->getMessage());
                }
            }

            try {
                $pdo->prepare(
                    'INSERT INTO access_log (user_id, email, action, ip_address) VALUES (:uid, :email, :act, :ip)'
                )->execute([
                    ':uid'   => $user_id,
                    ':email' => $user['email'],
                    ':act'   => 'login',
                    ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
                ]);
            } catch (Exception $e) {
                error_log('[AVILIGHT] login access_log insert skipped: ' . $e->getMessage());
            }

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
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $_SESSION['user_email']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($current_password, $user['password_hash'])) {
            return 'Current password is incorrect.';
        }
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute([':hash' => $new_hash, ':id' => $user['id']]);
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
        return $pdo->query('SELECT id, email, role, created_at, last_login FROM users ORDER BY id')
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
 * Check whether the currently logged-in user has accepted the User Responsibility Agreement.
 * Session flag is checked first for performance; falls back to the database.
 */
function has_accepted_agreement(): bool {
    if (!is_logged_in()) {
        return false;
    }
    // Fast path: already recorded in session
    if (!empty($_SESSION['agreement_accepted'])) {
        return true;
    }
    // Slow path: query the database
    try {
        require_once __DIR__ . '/db.php';
        $pdo = get_mysql_db();
        _ensure_user_agreements_table($pdo);
        $stmt = $pdo->prepare('SELECT id FROM user_agreements WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $_SESSION['user_email']]);
        if ($stmt->fetch()) {
            $_SESSION['agreement_accepted'] = true;
            return true;
        }
    } catch (Exception $e) {
        error_log('[AVILIGHT] has_accepted_agreement error: ' . $e->getMessage());
    }
    return false;
}

/**
 * Record that the currently logged-in user has accepted the User Responsibility Agreement.
 */
function record_agreement_acceptance(): void {
    if (!is_logged_in()) {
        return;
    }
    try {
        require_once __DIR__ . '/db.php';
        $pdo = get_mysql_db();
        _ensure_user_agreements_table($pdo);
        $pdo->prepare(
            'INSERT INTO user_agreements (user_id, email, ip_address)
             VALUES (:uid, :email, :ip)
             ON DUPLICATE KEY UPDATE accepted_at = NOW(), ip_address = :ip2'
        )->execute([
            ':uid'   => $_SESSION['user_id'] ?? null,
            ':email' => $_SESSION['user_email'],
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ip2'   => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        $_SESSION['agreement_accepted'] = true;
    } catch (Exception $e) {
        error_log('[AVILIGHT] record_agreement_acceptance error: ' . $e->getMessage());
    }
}

/**
 * Ensure the MySQL users table exists (created on first use).
 */
function _ensure_users_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        email         VARCHAR(255) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('IT_admin','EMS') NOT NULL DEFAULT 'EMS',
        is_active     TINYINT(1) NOT NULL DEFAULT 1,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login    DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uidx_users_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add is_active column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    } catch (Exception $e) {
        // Column already exists, ignore the error
    }

    // Seed default admin if table is empty
    $count = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ((int)$count === 0) {
        $default_pass = getenv('AVILIGHT_ADMIN_PASS') ?: 'avilight2024!';
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, role, is_active) VALUES (:email, :hash, :role, 1)'
        )->execute([
            ':email' => 'admin@avilight.ph',
            ':hash'  => password_hash($default_pass, PASSWORD_BCRYPT),
            ':role'  => 'IT_admin',
        ]);
    }
}

/**
 * Ensure the MySQL user_agreements table exists.
 * Columns: id, user_id, email, accepted_at, ip_address
 */
function _ensure_user_agreements_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_agreements (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     INT UNSIGNED NULL,
        email       VARCHAR(255) NOT NULL,
        accepted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address  VARCHAR(45)  NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        UNIQUE KEY uidx_ua_email (email),
        KEY idx_ua_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Two-Factor Authentication (Email OTP) ─────────────────────────────────────

/** How long (seconds) an OTP code remains valid. */
define('AVILIGHT_OTP_EXPIRY_SECONDS', 600);

/** Sender address used in OTP emails; override via AVILIGHT_OTP_FROM env var. */
define('AVILIGHT_OTP_FROM', getenv('AVILIGHT_OTP_FROM') ?: 'noreply@avilight.ph');

/** Resend API key — set via RESEND_API_KEY environment variable. */
define('AVILIGHT_RESEND_API_KEY', getenv('RESEND_API_KEY') ?: '');

/**
 * Generate a 6-digit OTP, store it in the session (with expiry), and email it.
 * The OTP is delivered to $delivery_email, which may differ from the account
 * email stored in the session for identity purposes.
 *
 * @param array  $user           The authenticated user row from the database.
 * @param string $delivery_email The address the OTP code is sent to.
 * @return bool  True if the email was dispatched; false on failure.
 */
function generate_and_send_otp(array $user, string $delivery_email = ''): bool {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Use account email as delivery address when none is specified
    if ($delivery_email === '') {
        $delivery_email = $user['email'];
    }

    // Store in session so login_otp.php can verify it
    $_SESSION['otp_code']           = $code;
    $_SESSION['otp_email']          = $user['email'];       // account identity
    $_SESSION['otp_delivery_email'] = $delivery_email;      // where the code was sent
    $_SESSION['otp_user_id']        = $user['user_id'] ?? $user['id'] ?? null;
    $_SESSION['otp_role']           = $user['role']     ?? 'user';
    $_SESSION['otp_type']           = $user['user_type'] ?? 'EMS';
    $_SESSION['otp_expires']        = time() + AVILIGHT_OTP_EXPIRY_SECONDS;
    $_SESSION['otp_attempts']       = 0;

    return _send_otp_email($delivery_email, $code);
}

/**
 * Verify the OTP entered by the user.
 * Returns true on success, or an error string on failure.
 *
 * @param string $submitted  The code typed by the user.
 * @return true|string
 */
function verify_otp(string $submitted) {
    // Guard: session must have a pending OTP
    if (empty($_SESSION['otp_code']) || empty($_SESSION['otp_email'])) {
        return 'No pending verification. Please log in again.';
    }

    // Expiry check
    if (time() > ($_SESSION['otp_expires'] ?? 0)) {
        _clear_otp_session();
        return 'The verification code has expired. Please log in again.';
    }

    // Rate-limit: max 5 wrong guesses per OTP session
    $_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;
    if ($_SESSION['otp_attempts'] > 5) {
        _clear_otp_session();
        return 'Too many incorrect attempts. Please log in again.';
    }

    if (!hash_equals($_SESSION['otp_code'], trim($submitted))) {
        return 'Incorrect verification code. Please try again.';
    }

    // ── Success: promote pending OTP data into a full session ──
    $email   = $_SESSION['otp_email'];
    $user_id = $_SESSION['otp_user_id'];
    $role    = $_SESSION['otp_role'];
    $type    = $_SESSION['otp_type'];

    _clear_otp_session();

    $_SESSION['user_email'] = $email;
    $_SESSION['user_role']  = $role;
    $_SESSION['user_id']    = $user_id;
    $_SESSION['user_type']  = $type;

    // Log successful login
    try {
        require_once __DIR__ . '/db.php';
        $pdo = get_mysql_db();
        if ($user_id !== null) {
            $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
                ->execute([':id' => $user_id]);
        }
        $pdo->prepare(
            'INSERT INTO access_log (user_id, email, action, ip_address) VALUES (:uid, :email, :act, :ip)'
        )->execute([
            ':uid'   => $user_id,
            ':email' => $email,
            ':act'   => 'login',
            ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Exception $e) {
        error_log('[AVILIGHT] verify_otp login log error: ' . $e->getMessage());
    }

    return true;
}

/**
 * Resend the OTP to the same delivery email (regenerate code, reset expiry).
 * Returns true on success, or false on failure.
 */
function resend_otp(): bool {
    if (empty($_SESSION['otp_delivery_email'])) {
        return false;
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['otp_code']     = $code;
    $_SESSION['otp_expires']  = time() + AVILIGHT_OTP_EXPIRY_SECONDS;
    $_SESSION['otp_attempts'] = 0;
    return _send_otp_email($_SESSION['otp_delivery_email'], $code);
}

/**
 * Remove all OTP-related keys from the session.
 */
function _clear_otp_session(): void {
    foreach (['otp_code', 'otp_email', 'otp_delivery_email', 'otp_user_id', 'otp_role', 'otp_type', 'otp_expires', 'otp_attempts'] as $k) {
        unset($_SESSION[$k]);
    }
}

/**
 * Send the OTP code to the given email address via Resend HTTP API.
 * Uses HTTPS (port 443) which works on all Railway plans.
 */
function _send_otp_email(string $to, string $code): bool {
    $subject = 'Your AVILIGHT login code';
    $body    = "Your AVILIGHT verification code is:\n\n    {$code}\n\nThis code expires in 10 minutes. Do not share it with anyone.\n";

    return _resend_send(AVILIGHT_OTP_FROM, $to, $subject, $body);
}

/**
 * Return the last mail error string (if any).
 */
function smtp_last_error(): string {
    return $GLOBALS['AVILIGHT_SMTP_LAST_ERROR'] ?? '';
}

/**
 * Send a plain-text email via the Resend HTTP API.
 * Uses HTTPS (port 443) — works on all Railway plans, no SMTP ports needed.
 *
 * @param string $from    Sender address (must be verified in Resend).
 * @param string $to      Recipient address.
 * @param string $subject Message subject.
 * @param string $body    Plain-text message body.
 * @return bool  True if Resend accepted the message; false on any error.
 */
function _resend_send(
    string $from,
    string $to,
    string $subject,
    string $body
): bool {
    $GLOBALS['AVILIGHT_SMTP_LAST_ERROR'] = '';

    $api_key = AVILIGHT_RESEND_API_KEY;
    if ($api_key === '') {
        error_log('[AVILIGHT] Resend API key is not set (RESEND_API_KEY).');
        $GLOBALS['AVILIGHT_SMTP_LAST_ERROR'] = 'RESEND_API_KEY env var is missing.';
        return false;
    }

    $payload = json_encode([
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'text'    => $body,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err !== '') {
        error_log("[AVILIGHT] Resend curl error: {$curl_err}");
        $GLOBALS['AVILIGHT_SMTP_LAST_ERROR'] = "curl error: {$curl_err}";
        return false;
    }

    if ($status === 200 || $status === 201) {
        return true;
    }

    error_log("[AVILIGHT] Resend API error (HTTP {$status}): {$response}");
    $GLOBALS['AVILIGHT_SMTP_LAST_ERROR'] = "Resend API HTTP {$status}: {$response}";
    return false;
}

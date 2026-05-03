<?php
// Authentication and session management for AVILIGHT Dashboard

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Load a simple root .env file into the process environment if present.
 * Existing environment variables are left untouched.
 */
function _load_avilight_env_file(): void {
    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envFile) || !is_readable($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

_load_avilight_env_file();

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
    if (($_SESSION['user_role'] ?? 'user') !== 'admin') {
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
                    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = :id')
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

/**
 * Mail driver: 'smtp' uses a direct SMTP connection (compatible with Mailpit).
 *              'mail' falls back to PHP's built-in mail() function.
 * Override via the MAIL_DRIVER environment variable.
 */
define('AVILIGHT_MAIL_DRIVER', getenv('MAIL_DRIVER') ?: 'smtp');

/** SMTP host (default: 127.0.0.1 for Mailpit / Laragon). */
define('AVILIGHT_SMTP_HOST', getenv('MAIL_HOST') ?: '127.0.0.1');

/** SMTP port (default: 1025, Mailpit's default SMTP port). */
define('AVILIGHT_SMTP_PORT', (int)(getenv('MAIL_PORT') ?: 1025));

/** Optional SMTP auth username (empty = no auth). */
define('AVILIGHT_SMTP_USER', getenv('MAIL_USER') ?: getenv('MAIL_USERNAME') ?: '');

/** Optional SMTP auth password. */
define('AVILIGHT_SMTP_PASS', getenv('MAIL_PASS') ?: getenv('MAIL_PASSWORD') ?: '');

/** Optional SMTP encryption: 'tls' or 'ssl' (default: none). */
define('AVILIGHT_SMTP_ENCRYPTION', strtolower(getenv('MAIL_ENCRYPTION') ?: '') ?: '');

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
            $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = :id')
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
 * Build a styled HTML email body for OTP delivery.
 */
function _build_otp_email_html(string $code): string {
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $issuedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A') . ' PHT';
    return '<!doctype html>'
        . '<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
        . '<body style="margin:0;padding:0;background:#eaf1f9;font-family:Segoe UI,Arial,sans-serif;color:#0f172a;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Your AVILIGHT verification code is ' . $safeCode . '. It expires in 10 minutes.</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eaf1f9;padding:30px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #d4e2f1;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">'
        . '<tr><td style="padding:24px 26px;background:#0f172a;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>'
        . '<td style="font-size:11px;letter-spacing:1.4px;color:#93c5fd;font-weight:700;">AVILIGHT SECURITY</td>'
        . '<td align="right" style="font-size:11px;color:#cbd5e1;">' . $issuedAt . '</td>'
        . '</tr></table>'
        . '<h1 style="margin:10px 0 0 0;font-size:24px;line-height:1.2;color:#ffffff;font-weight:700;">Verify your sign in</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:26px;">'
        . '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.65;color:#1e293b;">Enter this one-time code in AviLight to complete login.</p>'
        . '<div style="margin:0 0 18px 0;padding:16px;background:linear-gradient(180deg,#eff6ff 0%,#e0edff 100%);border:1px solid #93c5fd;border-radius:14px;text-align:center;">'
        . '<span style="display:block;font-size:36px;line-height:1;font-weight:800;letter-spacing:8px;color:#1e40af;">' . $safeCode . '</span>'
        . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0 8px;">'
        . '<tr><td style="font-size:13px;color:#334155;">1. This code expires in <strong>10 minutes</strong>.</td></tr>'
        . '<tr><td style="font-size:13px;color:#334155;">2. Use it only on the AviLight verification page.</td></tr>'
        . '<tr><td style="font-size:13px;color:#334155;">3. Never share this code with anyone.</td></tr>'
        . '</table>'
        . '<div style="margin-top:18px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;font-size:12px;color:#475569;line-height:1.55;">If this request was not made by you, ignore this message and consider changing your password.</div>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 26px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;color:#64748b;">AviLight automated message. Please do not reply to this email.</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

/**
 * Send the OTP code to the given email address.
 * Uses SMTP when MAIL_DRIVER=smtp (default, compatible with Mailpit / Laragon),
 * or falls back to PHP's mail() when MAIL_DRIVER=mail.
 */
function _send_otp_email(string $to, string $code): bool {
    $subject = 'Your AVILIGHT login code';
    $textBody = "Your AVILIGHT verification code is:\n\n    {$code}\n\nThis code expires in 10 minutes. Do not share it with anyone.\n";
    $htmlBody = _build_otp_email_html($code);

    // If configured, use PHPMailer wrapper which supports authenticated SMTP and Gmail easily.
    if (AVILIGHT_MAIL_DRIVER === 'phpmailer') {
        return _phpmailer_send($to, $subject, $textBody, $htmlBody);
    }

    if (AVILIGHT_MAIL_DRIVER === 'smtp') {
        return _smtp_send(AVILIGHT_SMTP_HOST, AVILIGHT_SMTP_PORT, AVILIGHT_OTP_FROM, $to, $subject, $textBody, $htmlBody);
    }

    $boundary = '=_avl_' . bin2hex(random_bytes(8));
    $headers  = "From: " . AVILIGHT_OTP_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    $mailBody  = "--{$boundary}\r\n";
    $mailBody .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $mailBody .= $textBody . "\r\n";
    $mailBody .= "--{$boundary}\r\n";
    $mailBody .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $mailBody .= $htmlBody . "\r\n";
    $mailBody .= "--{$boundary}--\r\n";

    $sent = mail($to, $subject, $mailBody, $headers);
    if (!$sent) {
        error_log("[AVILIGHT] OTP email (mail()) failed to send to {$to}");
    }
    return (bool)$sent;
}

/**
 * Send using PHPMailer. Requires composer install of phpmailer/phpmailer.
 * Honor AVILIGHT_SMTP_* env vars for SMTP mode; falls back to PHP mailer if no SMTP user.
 */
function _phpmailer_send(string $to, string $subject, string $textBody, string $htmlBody): bool {
    // Attempt to load composer autoloader if present
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    } else {
        error_log('[AVILIGHT] PHPMailer autoload not found; please run composer require phpmailer/phpmailer');
        return false;
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        // Use SMTP if configured
        $useSmtp = (AVILIGHT_SMTP_HOST !== '' && AVILIGHT_SMTP_PORT > 0);
        if ($useSmtp) {
            $mail->isSMTP();
            $mail->Host       = AVILIGHT_SMTP_HOST;
            $mail->Port       = AVILIGHT_SMTP_PORT;
            $mail->SMTPAuth   = (AVILIGHT_SMTP_USER !== '');
            if ($mail->SMTPAuth) {
                $mail->Username = AVILIGHT_SMTP_USER;
                $mail->Password = AVILIGHT_SMTP_PASS;
            }
            if (AVILIGHT_SMTP_ENCRYPTION === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif (AVILIGHT_SMTP_ENCRYPTION === 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
        }

        $mail->setFrom(AVILIGHT_OTP_FROM, 'AviLight');
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;
        $mail->isHTML(true);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[AVILIGHT] PHPMailer send failed: ' . ($e->getMessage() ?? $mail->ErrorInfo ?? 'unknown'));
        return false;
    }
}

/**
 * Send a plain-text email via a direct SMTP connection.
 * Compatible with Mailpit (localhost:1025) and any unauthenticated SMTP relay.
 *
 * @param string $host    SMTP server hostname or IP.
 * @param int    $port    SMTP server port.
 * @param string $from    Envelope sender address.
 * @param string $to      Envelope recipient address.
 * @param string $subject Message subject.
 * @param string $textBody Plain-text message body.
 * @param string $htmlBody HTML message body.
 * @return bool  True if the server accepted the message; false on any error.
 */
function _smtp_send(string $host, int $port, string $from, string $to, string $subject, string $textBody, string $htmlBody): bool {
    // Support optional SSL wrapper
    $use_ssl = (AVILIGHT_SMTP_ENCRYPTION === 'ssl');
    $host_to_connect = $use_ssl ? 'ssl://' . $host : $host;

    $fp = @fsockopen($host_to_connect, $port, $errno, $errstr, 10);
    if (!$fp) {
        error_log("[AVILIGHT] SMTP connect to {$host}:{$port} failed: {$errstr} ({$errno})");
        return false;
    }

    /** Read one complete SMTP response (handles multi-line replies). */
    $read_response = static function () use ($fp): string {
        $response = '';
        while (!feof($fp)) {
            $line = fgets($fp, 512);
            if ($line === false) break;
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $response;
    };

    /** Send one SMTP command and verify the expected response code. */
    $cmd = static function (string $command, int $expected) use ($fp, $read_response): bool {
        fwrite($fp, $command . "\r\n");
        $response = $read_response();
        return (int)substr($response, 0, 3) === $expected;
    };

    $ok = true;
    $read_response(); // consume server greeting (220 ...)

    // EHLO
    $ok = $ok && $cmd('EHLO localhost', 250);

    // If TLS requested (STARTTLS) and not using implicit SSL, attempt STARTTLS
    if (AVILIGHT_SMTP_ENCRYPTION === 'tls' && !$use_ssl) {
        if ($cmd('STARTTLS', 220)) {
            // enable crypto on the socket
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('[AVILIGHT] STARTTLS negotiation failed.');
                @fwrite($fp, "QUIT\r\n");
                fclose($fp);
                return false;
            }
            // EHLO again after STARTTLS
            $ok = $ok && $cmd('EHLO localhost', 250);
        } else {
            error_log('[AVILIGHT] STARTTLS not supported by server.');
        }
    }

    // Authenticate if credentials provided
    if (AVILIGHT_SMTP_USER !== '') {
        $ok = $ok && $cmd('AUTH LOGIN', 334);
        $ok = $ok && $cmd(base64_encode(AVILIGHT_SMTP_USER), 334);
        $ok = $ok && $cmd(base64_encode(AVILIGHT_SMTP_PASS), 235);
    }

    $ok = $ok && $cmd("MAIL FROM:<{$from}>", 250);
    $ok = $ok && $cmd("RCPT TO:<{$to}>", 250);
    $ok = $ok && $cmd('DATA', 354);

    if ($ok) {
        $boundary = '=_avl_' . bin2hex(random_bytes(8));
        $mimeBody  = "--{$boundary}\r\n";
        $mimeBody .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $mimeBody .= $textBody . "\r\n";
        $mimeBody .= "--{$boundary}\r\n";
        $mimeBody .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $mimeBody .= $htmlBody . "\r\n";
        $mimeBody .= "--{$boundary}--\r\n";

        $date    = date('r');
        $message = "Date: {$date}\r\n"
                 . "From: {$from}\r\n"
                 . "To: {$to}\r\n"
                 . "Subject: {$subject}\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
                 . "\r\n"
                 . str_replace("\r\n.", "\r\n..", $mimeBody)
                 . "\r\n.\r\n";
        fwrite($fp, $message);
        $response = $read_response();
        $ok       = (int)substr($response, 0, 3) === 250;
    }

    @fwrite($fp, "QUIT\r\n");
    fclose($fp);

    if (!$ok) {
        error_log("[AVILIGHT] SMTP send to {$to} via {$host}:{$port} failed.");
    }
    return $ok;
}

<?php
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: text/plain; charset=UTF-8');

$to = getenv('MAIL_TEST_TO') ?: AVILIGHT_OTP_FROM;
if (!$to) {
    echo "ERROR: MAIL_TEST_TO or AVILIGHT_OTP_FROM is required.\n";
    exit;
}

$driver = defined('AVILIGHT_MAIL_DRIVER') ? AVILIGHT_MAIL_DRIVER : (getenv('MAIL_DRIVER') ?: 'smtp');
$host = defined('AVILIGHT_SMTP_HOST') ? AVILIGHT_SMTP_HOST : (getenv('MAIL_HOST') ?: '');
$port = defined('AVILIGHT_SMTP_PORT') ? AVILIGHT_SMTP_PORT : (int)(getenv('MAIL_PORT') ?: 0);
$enc = defined('AVILIGHT_SMTP_ENCRYPTION') ? (AVILIGHT_SMTP_ENCRYPTION ?: 'none') : (getenv('MAIL_ENCRYPTION') ?: 'none');

$sent = _send_otp_email($to, '123456');

if ($sent) {
    echo "OK: Test email sent to {$to}.\n";
    echo "Driver={$driver}; Host={$host}; Port={$port}; Encryption={$enc}\n";
} else {
    echo "ERROR: Mail send failed. Check logs for details.\n";
    echo "Driver={$driver}; Host={$host}; Port={$port}; Encryption={$enc}\n";
}

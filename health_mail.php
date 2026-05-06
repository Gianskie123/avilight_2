<?php
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: text/plain; charset=UTF-8');

$to = getenv('MAIL_TEST_TO') ?: AVILIGHT_OTP_FROM;
if (!$to) {
    echo "ERROR: MAIL_TEST_TO or AVILIGHT_OTP_FROM is required.\n";
    exit;
}

$driver = AVILIGHT_MAIL_DRIVER;
$host = AVILIGHT_SMTP_HOST;
$port = AVILIGHT_SMTP_PORT;
$enc = AVILIGHT_SMTP_ENCRYPTION ?: 'none';

$sent = _send_otp_email($to, '123456');

if ($sent) {
    echo "OK: Test email sent to {$to}.\n";
    echo "Driver={$driver}; Host={$host}; Port={$port}; Encryption={$enc}\n";
} else {
    echo "ERROR: Mail send failed. Check logs for details.\n";
    echo "Driver={$driver}; Host={$host}; Port={$port}; Encryption={$enc}\n";
}

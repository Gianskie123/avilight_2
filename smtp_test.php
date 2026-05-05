<?php
require_once __DIR__ . '/includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
// Diagnostic info
echo "MAIL_DRIVER=" . AVILIGHT_MAIL_DRIVER . "\n";
echo "SMTP_HOST=" . AVILIGHT_SMTP_HOST . "\n";
echo "SMTP_PORT=" . AVILIGHT_SMTP_PORT . "\n";
echo "OTP_FROM=" . AVILIGHT_OTP_FROM . "\n";

// Replace with an address you control for testing
$test_to = 'test@example.com';
$user = ['email' => 'test-account@example.com', 'user_id' => 123, 'role' => 'user', 'user_type' => 'EMS'];

echo "\n--- generate_and_send_otp() ---\n";
$sent = generate_and_send_otp($user, $test_to);
echo $sent ? "generate_and_send_otp: SUCCESS\n" : "generate_and_send_otp: FAILURE\n";

// Also attempt raw SMTP send for direct diagnostic
if (function_exists('_smtp_send')) {
    echo "\n--- _smtp_send() direct test ---\n";
    $ok = _smtp_send(AVILIGHT_SMTP_HOST, AVILIGHT_SMTP_PORT, AVILIGHT_OTP_FROM, $test_to, 'SMTP Test', "This is a test message from smtp_test.php\n");
    echo $ok ? "_smtp_send: SUCCESS\n" : "_smtp_send: FAILURE\n";
} else {
    echo "_smtp_send not available\n";
}

// Show session keys for OTP if set
echo "\nSession OTP keys:\n";
foreach (['otp_code','otp_email','otp_delivery_email','otp_expires','otp_attempts'] as $k) {
    echo "$k => " . (isset($_SESSION[$k]) ? (string)$_SESSION[$k] : '(not set)') . "\n";
}

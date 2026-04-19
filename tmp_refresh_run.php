<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_SESSION['user_email'] = 'admin@avilight.ph';
$_SESSION['user_role'] = 'admin';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['force' => '1'];
ob_start();
include __DIR__ . '/api/refresh_report_cache.php';
$body = (string) ob_get_clean();
$j = json_decode($body, true);
if (!is_array($j)) {
    echo "invalid_json\n";
    echo $body;
    exit(1);
}
echo json_encode($j, JSON_PRETTY_PRINT), "\n";

<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_email'] = 'cli-precompute.local';
$_SESSION['user_role'] = 'admin';
$_SERVER['REQUEST_METHOD'] = 'POST';
include 'C:\laragon\www\avilightv5\api\refresh_report_cache.php';

<?php
session_start();
$_SESSION['user_email'] = 'admin@example.com';
$_SESSION['user_role'] = 'admin';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['force'] = '1';
require __DIR__ . '/api/refresh_report_cache.php';

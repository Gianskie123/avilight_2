<?php
// Authentication and session management for AVILIGHT Dashboard
// Replace with proper database authentication in production

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
    
    // TODO: Replace with proper role check from database
    // For now, all logged-in users have admin access
    // In production, check: if ($_SESSION['user_role'] !== 'admin')
}

function get_logged_user() {
    return isset($_SESSION['user_email']) ? $_SESSION['user_email'] : null;
}

function logout() {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle logout request
if (isset($_GET['logout'])) {
    logout();
}
?>

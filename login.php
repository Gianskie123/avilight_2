<?php
require_once 'includes/auth.php';

// Redirect already-authenticated users
if (is_logged_in()) {
    header('Location: home.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $user = authenticate_user($email, $password);
        if ($user) {
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_id']    = $user['id'];
            header('Location: loading.php?next=home.php');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Apply saved theme before first paint -->
    <script>
        (function(){
            if(localStorage.getItem('avilight-theme')==='dark'){
                document.documentElement.setAttribute('data-theme','dark');
            }
        })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avilight | Login</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: var(--bg-main, #f8fafc);
        }
        .login-card {
            width: 360px;
            background: var(--bg-card, #fff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 14px;
            padding: 40px 36px;
            text-align: center;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        }
        .login-card img { width: 72px; margin-bottom: 12px; }
        .login-card h2 {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text-primary, #1e293b);
            margin: 0 0 4px;
        }
        .login-card p {
            font-size: 0.88rem;
            color: var(--text-secondary, #64748b);
            margin: 0 0 24px;
        }
        .login-card input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 14px;
            margin: 6px 0;
            border-radius: 8px;
            border: 1px solid var(--border-color, #cbd5e1);
            background: var(--bg-input, #f1f5f9);
            color: var(--text-primary, #1e293b);
            font-size: 0.93rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .login-card input:focus { border-color: var(--accent-blue, #3b82f6); }
        .login-btn {
            width: 100%;
            padding: 11px;
            margin-top: 14px;
            border-radius: 8px;
            border: none;
            background: var(--accent-blue, #3b82f6);
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .login-btn:hover { opacity: 0.88; }
        .login-error {
            margin: 10px 0 0;
            padding: 9px 12px;
            border-radius: 8px;
            background: rgba(239,68,68,0.12);
            color: #ef4444;
            font-size: 0.85rem;
        }
        .login-hint {
            margin-top: 18px;
            font-size: 0.78rem;
            color: var(--text-muted, #94a3b8);
        }
    </style>
</head>
<body>
<div class="login-card">
    <img src="AviLight_Logo.png" alt="Avilight Logo">
    <h2>Welcome back</h2>
    <p>Sign in to your AVILIGHT account</p>

    <form method="POST" autocomplete="on">
        <input type="email"    name="email"    placeholder="email@domain.com" required autofocus>
        <input type="password" name="password" placeholder="Password"         required>
        <button type="submit" class="login-btn">Sign in</button>
    </form>

    <?php if ($error): ?>
        <div class="login-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="login-hint">
        First-time setup? Use your administrator credentials to sign in.
    </div>
</div>
</body>
</html>

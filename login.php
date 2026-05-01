<?php
require_once 'includes/auth.php';

// Redirect already-authenticated users
if (is_logged_in()) {
    header('Location: home.php');
    exit;
}

$error = '';

/** How long (seconds) a CAPTCHA challenge stays valid after it was generated. */
const CAPTCHA_EXPIRY_SECONDS = 300;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $captcha  = strtoupper(trim($_POST['captcha'] ?? ''));

    // ── CAPTCHA check ──────────────────────────────────────────────────────
    $captcha_ok      = false;
    $captcha_expired = (time() - ($_SESSION['captcha_time'] ?? 0)) > CAPTCHA_EXPIRY_SECONDS;
    if (!empty($_SESSION['captcha_text']) && !$captcha_expired) {
        $captcha_ok = hash_equals($_SESSION['captcha_text'], $captcha);
    }
    // Always invalidate after one attempt (success or failure)
    unset($_SESSION['captcha_text'], $_SESSION['captcha_time']);

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } elseif (!$captcha_ok) {
        $error = 'Incorrect CAPTCHA. Please try again.';
    } else {
        $lockout = check_login_lockout($email);
        if ($lockout) {
            $error = $lockout;
        } else {
            $user = authenticate_user($email, $password);
            if ($user) {
                // Credentials correct — start 2FA OTP flow
                generate_and_send_otp($user);
                header('Location: login_otp.php');
                exit;
            } else {
                $error = 'Invalid email or password.';
            }
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
            max-width: calc(100% - 32px);
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
        .captcha-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 8px 0 4px;
        }
        .captcha-row img {
            width: auto;
            height: 70px;
            border-radius: 6px;
            border: 1px solid var(--border-color, #cbd5e1);
            cursor: pointer;
            flex: 1;
        }
        .captcha-refresh {
            background: var(--bg-input, #f1f5f9);
            border: 1px solid var(--border-color, #cbd5e1);
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 1.1rem;
            cursor: pointer;
            color: var(--text-secondary, #64748b);
            transition: background 0.2s;
            line-height: 1;
        }
        .captcha-refresh:hover { background: var(--border-color, #e2e8f0); }
        @media (max-width: 400px) {
            .login-card {
                max-width: 100%;
                border-radius: 0;
                border-left: none;
                border-right: none;
                padding: 36px 20px;
            }
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

        <div class="captcha-row">
            <img id="captcha-img" src="captcha.php" alt="CAPTCHA" title="Click to refresh">
            <button type="button" class="captcha-refresh" onclick="refreshCaptcha()" title="Refresh CAPTCHA">&#x21bb;</button>
        </div>
        <input type="text" name="captcha" placeholder="Enter the characters above" required autocomplete="off" spellcheck="false">

        <button type="submit" class="login-btn">Sign in</button>
    </form>

    <?php if ($error): ?>
        <div class="login-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="login-hint">
        First-time setup? Use your administrator credentials to sign in.
    </div>
</div>
<script>
function refreshCaptcha() {
    var img = document.getElementById('captcha-img');
    img.src = 'captcha.php?' + Date.now();
}
// Also refresh when clicking the image directly
document.getElementById('captcha-img').addEventListener('click', refreshCaptcha);
</script>
</body>
</html>
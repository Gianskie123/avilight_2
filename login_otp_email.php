<?php
require_once 'includes/auth.php';

// Redirect fully-authenticated users away
if (is_logged_in()) {
    header('Location: home.php');
    exit;
}

// Must have a pending authenticated user in session; otherwise back to login
if (empty($_SESSION['otp_pending_user'])) {
    header('Location: login.php');
    exit;
}

$pending_user  = $_SESSION['otp_pending_user'];
$account_email = $pending_user['email'] ?? '';
$error         = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $delivery_email = trim($_POST['delivery_email'] ?? '');

    if (!$delivery_email) {
        $error = 'Please enter an email address.';
    } elseif (!filter_var($delivery_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Generate and dispatch the OTP to the chosen delivery address
        $sent = generate_and_send_otp($pending_user, $delivery_email);
        // Pending user data is now encoded in the OTP session; remove the staging key
        unset($_SESSION['otp_pending_user']);

        if ($sent) {
            header('Location: login_otp.php');
            exit;
        }

        // Email send failed — keep the pending user so they can try again
        $_SESSION['otp_pending_user'] = $pending_user;
        _clear_otp_session(); // roll back partially-set OTP session
        $error = 'Failed to send the code. Please check the address and try again.';
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
    <title>AVILIGHT | Send Verification Code</title>
    <link rel="icon" type="image/png" href="AviLight_Logo.png">
    <link rel="stylesheet" href="assets/css/main.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: var(--bg-main, #f8fafc);
        }
        .otp-card {
            width: 400px;
            max-width: calc(100% - 32px);
            background: var(--bg-card, #fff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 14px;
            padding: 40px 36px;
            text-align: center;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        }
        .otp-card img { width: 72px; margin-bottom: 12px; }
        .otp-card h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary, #1e293b);
            margin: 0 0 6px;
        }
        .otp-card p {
            font-size: 0.87rem;
            color: var(--text-secondary, #64748b);
            margin: 0 0 22px;
            line-height: 1.5;
        }
        .otp-card label {
            display: block;
            text-align: left;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary, #64748b);
            margin-bottom: 4px;
        }
        .otp-card input[type="email"] {
            width: 100%;
            box-sizing: border-box;
            padding: 11px 14px;
            margin: 0 0 10px;
            border-radius: 8px;
            border: 1px solid var(--border-color, #cbd5e1);
            background: var(--bg-input, #f1f5f9);
            color: var(--text-primary, #1e293b);
            font-size: 0.93rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .otp-card input[type="email"]:focus { border-color: var(--accent-blue, #3b82f6); }
        .otp-btn {
            width: 100%;
            padding: 11px;
            margin-top: 4px;
            border-radius: 8px;
            border: none;
            background: var(--accent-blue, #3b82f6);
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .otp-btn:hover { opacity: 0.88; }
        .otp-error {
            margin: 10px 0 0;
            padding: 9px 12px;
            border-radius: 8px;
            background: rgba(239,68,68,0.12);
            color: #ef4444;
            font-size: 0.85rem;
        }
        .otp-footer {
            margin-top: 20px;
            font-size: 0.80rem;
        }
        .otp-footer a {
            color: var(--accent-blue, #3b82f6);
            text-decoration: none;
        }
        .otp-footer a:hover { text-decoration: underline; }
        @media (max-width: 440px) {
            .otp-card {
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
<div class="otp-card">
    <img src="AviLight_Logo.png" alt="Avilight Logo">
    <h2>Where should we send the code?</h2>
    <p>
        Enter the email address where you want to receive<br>
        the one-time verification code.
    </p>

    <form method="POST">
        <label for="delivery_email">Email address</label>
        <input
            type="email"
            id="delivery_email"
            name="delivery_email"
            placeholder="you@example.com"
            value="<?= htmlspecialchars($_POST['delivery_email'] ?? $account_email) ?>"
            required
            autofocus>
        <button type="submit" class="otp-btn">Send code</button>
    </form>

    <?php if ($error): ?>
        <div class="otp-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="otp-footer">
        <a href="login.php">&#8592; Back to login</a>
    </div>
</div>
</body>
</html>

<?php
require_once 'includes/auth.php';

// Redirect fully-authenticated users away
if (is_logged_in()) {
    header('Location: home.php');
    exit;
}

// If there is no pending OTP session, send back to login
if (empty($_SESSION['otp_code']) || empty($_SESSION['otp_email'])) {
    header('Location: login.php');
    exit;
}

$error   = '';
$success = '';
$masked  = ''; // e.g. "a***@example.com" to hint the user

// Mask the email for display
$raw_email = $_SESSION['otp_email'] ?? '';
if ($raw_email) {
    [$local, $domain] = array_pad(explode('@', $raw_email, 2), 2, '');
    $masked = (strlen($local) > 2 ? substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2)) : $local)
            . '@' . $domain;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resend'])) {
        // Resend OTP
        $sent = resend_otp();
        $success = $sent
            ? 'A new code has been sent to your email.'
            : 'Email delivery failed. Please try again in a moment, or contact your system administrator if the problem persists.';
    } else {
        // Verify submitted code
        $submitted = trim($_POST['otp_code'] ?? '');
        $result    = verify_otp($submitted);
        if ($result === true) {
            header('Location: loading.php?next=home.php');
            exit;
        }
        $error = $result;
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
    <title>Avilight | Verify Your Identity</title>
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
            width: 380px;
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
        .otp-card p strong {
            color: var(--text-primary, #1e293b);
        }
        .otp-input {
            width: 100%;
            box-sizing: border-box;
            padding: 13px 14px;
            margin: 4px 0 10px;
            border-radius: 8px;
            border: 1px solid var(--border-color, #cbd5e1);
            background: var(--bg-input, #f1f5f9);
            color: var(--text-primary, #1e293b);
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 0.35em;
            text-align: center;
            outline: none;
            transition: border-color 0.2s;
        }
        .otp-input:focus { border-color: var(--accent-blue, #3b82f6); }
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
        .otp-success {
            margin: 10px 0 0;
            padding: 9px 12px;
            border-radius: 8px;
            background: rgba(34,197,94,0.12);
            color: #16a34a;
            font-size: 0.85rem;
        }
        .otp-footer {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            font-size: 0.80rem;
        }
        .otp-footer a,
        .otp-footer button {
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            color: var(--accent-blue, #3b82f6);
            font-size: 0.80rem;
            text-decoration: none;
        }
        .otp-footer a:hover,
        .otp-footer button:hover { text-decoration: underline; }
        @media (max-width: 420px) {
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
    <h2>Check your email</h2>
    <p>
        We sent a 6-digit verification code to<br>
        <strong><?= htmlspecialchars($masked) ?></strong>.<br>
        Enter it below to complete sign-in.
    </p>

    <form method="POST">
        <input
            type="text"
            name="otp_code"
            class="otp-input"
            placeholder="000000"
            maxlength="6"
            pattern="\d{6}"
            inputmode="numeric"
            autocomplete="one-time-code"
            required
            autofocus>
        <button type="submit" class="otp-btn">Verify</button>
    </form>

    <?php if ($error): ?>
        <div class="otp-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="otp-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="otp-footer">
        <a href="login.php">&#8592; Back to login</a>
        <form method="POST" style="display:inline">
            <button type="submit" name="resend" value="1">Resend code</button>
        </form>
    </div>
</div>
</body>
</html>

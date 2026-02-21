<?php
// From Figma design lang siya since draft pa lang just so we can show progress !!
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['user_email'] = $_POST['email'];
    header('Location: home.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Avilight | Login</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .card {
            width: 360px;
            text-align: center;
        }
        input {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border-radius: 6px;
            border: 1px solid #ccc;
        }
        button {
            width: 100%;
            padding: 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
        }
        .email-btn {
            background: #000;
            color: #fff;
        }
        .google-btn {
            margin-top: 10px;
            background: #f1f1f1;
        }
        .terms {
            font-size: 12px;
            color: #777;
            margin-top: 15px;
        }
    </style>
</head>
<body>
<div class="card">
    <img src="AviLight_Logo.png" width="80" alt="Avilight Logo">
    <h2>Create an account</h2>
    <p>Enter your email to sign up for this app</p>

    <form method="POST">
        <input type="email" name="email" placeholder="email@domain.com" required>
        <button class="email-btn" type="submit">Sign up with email</button>
    </form>

    <p class="terms">
        By clicking sign up, you agree to our
        <b>Terms of Service</b> and <b>Privacy Policy</b>
    </p>
</div>
</body>
</html>

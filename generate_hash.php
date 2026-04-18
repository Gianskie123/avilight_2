<?php
// ONE-TIME USE ONLY — DELETE THIS FILE AFTER USE

$password = 'admin123'; // <-- change this
$hash = password_hash($password, PASSWORD_BCRYPT);

echo "<p><strong>Password:</strong> $password</p>";
echo "<p><strong>Hash:</strong> $hash</p>";
echo "<hr>";
echo "<p>Use this in your INSERT statement:</p>";
echo "<pre>INSERT INTO users (email, password_hash, full_name, role, is_active)
VALUES (
    'admin123@gmail.com',
    '$hash',
    'Gian Carlo Miguel Q. Regalado',
    'admin',
    1
);</pre>";

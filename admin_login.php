<?php
require __DIR__.'/db.php';
require __DIR__.'/auth.php';

$email = $_GET['email'] ?? '';
$pass  = $_GET['pass']  ?? '';
if (!$email || !$pass) { echo 'Use ?email=you@example.com&pass=YourPassword'; exit; }

$stmt = pdo()->prepare('SELECT id, password_hash FROM admins WHERE email=?');
$stmt->execute([$email]);
$row = $stmt->fetch();

if (!$row || !password_verify($pass, $row['password_hash'])) { echo 'Login failed'; exit; }

$_SESSION['admin_id'] = (int)$row['id'];
echo 'OK - admin logged in';

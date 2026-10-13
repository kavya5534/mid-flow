<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
$message = ''; $error = '';
$count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $count === 0) {
  $name = trim((string)($_POST['full_name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) $error = 'Enter a name, valid email, and password with at least 8 characters.';
  else { $stmt=$pdo->prepare('INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,?)'); $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),'admin']); $message='Admin account created. Delete setup_admin.php now, then sign in.'; $count=1; }
}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin setup</title><style>body{font:16px Segoe UI,Arial;background:#f1f5fa;display:grid;place-items:center;min-height:100vh}.box{background:white;padding:28px;border-radius:16px;width:min(420px,92vw)}input{width:100%;padding:12px;margin:6px 0 14px;box-sizing:border-box}button{padding:12px 18px;background:#176b87;color:white;border:0;border-radius:8px}.msg{color:#12643c}.err{color:#a32929}</style><div class="box"><h2>MediFlow AI · First admin setup</h2><?php if($message): ?><p class="msg"><?= htmlspecialchars($message,ENT_QUOTES,'UTF-8') ?></p><?php elseif($count>0): ?><p>An account already exists. For security, setup is disabled. Delete this file and use <a href="login.php">login</a>.</p><?php else: ?><?php if($error): ?><p class="err"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?><form method="post"><label>Full name</label><input name="full_name" required><label>Email</label><input name="email" type="email" required><label>Password (minimum 8 characters)</label><input name="password" type="password" minlength="8" required><button>Create admin</button></form><?php endif; ?></div></html>
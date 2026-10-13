<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

require_once __DIR__ . '/db.php';

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid name and email address.';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match.';
    } else {
        try {
            $check = $pdo->prepare(
                'SELECT id FROM users WHERE email = ? LIMIT 1'
            );
            $check->execute([$email]);

            if ($check->fetch()) {
                $message = 'This email is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $insert = $pdo->prepare(
                    "INSERT INTO users (full_name, email, password_hash, role)
                     VALUES (?, ?, ?, 'staff')"
                );
                $insert->execute([$name, $email, $hash]);

                $success = true;
                $message = 'Registration successful! You can now log in.';
            }
        } catch (PDOException $e) {
            $message = 'Registration failed. Check your database setup.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MediFlow AI - Register</title>
<style>
* { box-sizing: border-box; }
body {
    margin: 0; min-height: 100vh; display: grid; place-items: center;
    padding: 20px; font-family: Arial, sans-serif;
    background: #eef3f9; color: #17233c;
}
.card {
    width: 100%; max-width: 440px; padding: 32px;
    background: white; border-radius: 18px;
    box-shadow: 0 12px 40px #17233c12;
}
h1 { margin-top: 0; font-size: 27px; }
p { line-height: 1.5; color: #64748b; }
label { display: block; margin: 16px 0 7px; font-weight: bold; }
input {
    width: 100%; padding: 12px; border: 1px solid #cbd5e1;
    border-radius: 8px; font-size: 15px;
}
button {
    width: 100%; padding: 13px; margin-top: 22px;
    border: 0; border-radius: 8px; background: #1769aa;
    color: white; font-size: 16px; font-weight: bold; cursor: pointer;
}
.message { padding: 12px; border-radius: 8px; background: #eef2ff; }
a { color: #1769aa; }
</style>
</head>
<body>
<main class="card">
    <h1>Create your account</h1>
    <p>Join MediFlow AI hospital operations.</p>

    <?php if ($message !== ''): ?>
        <p class="message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="post">
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" required maxlength="120"
               autocomplete="name">

        <label for="email">Email address</label>
        <input id="email" name="email" type="email" required maxlength="190"
               autocomplete="email">

        <label for="password">Password (minimum 8 characters)</label>
        <input id="password" name="password" type="password" required
               minlength="8" autocomplete="new-password">

        <label for="confirm_password">Confirm password</label>
        <input id="confirm_password" name="confirm_password" type="password"
               required minlength="8" autocomplete="new-password">

        <button type="submit">Register</button>
    </form>
    <?php endif; ?>

    <p>Already have an account? <a href="login.php">Log in</a></p>
</main>
</body>
</html>
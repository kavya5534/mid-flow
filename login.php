<?php
session_start();
require_once __DIR__ . '/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email ID and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, full_name, email, password_hash, role
                 FROM users
                 WHERE email = ?
                 LIMIT 1'
            );

            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_role'] = $user['role'];

                header('Location: index.php');
                exit;
            } else {
                $error = 'Invalid email ID or password. Please try again.';
            }
        } catch (PDOException $e) {
            $error = 'Unable to log in. Please check your database connection.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MediFlow AI | Login</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 20px;
    font-family: Arial, sans-serif;
    background: linear-gradient(135deg, #e8f3ff, #f5f7fb);
    color: #17233c;
}

.card {
    width: 100%;
    max-width: 400px;
    padding: 32px;
    background: white;
    border-radius: 18px;
    box-shadow: 0 15px 45px #17345b18;
}

.logo {
    font-size: 28px;
    font-weight: bold;
    color: #1769aa;
}

.subtitle {
    color: #64748b;
    margin-bottom: 24px;
    line-height: 1.5;
}

label {
    display: block;
    font-weight: bold;
    margin: 16px 0 7px;
}

input {
    display: block;
    width: 100%;
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 15px;
    background: #fff;
    color: #17233c;
}

input:focus {
    outline: none;
    border-color: #1769aa;
    box-shadow: 0 0 0 3px #1769aa20;
}

button {
    width: 100%;
    margin-top: 22px;
    padding: 13px;
    border: 0;
    border-radius: 8px;
    background: #1769aa;
    color: white;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
}

button:hover {
    background: #12558b;
}

.error {
    color: #b42318;
    background: #fef3f2;
    padding: 10px;
    border-radius: 8px;
    font-size: 14px;
}

.footer {
    margin-top: 22px;
    text-align: center;
    color: #64748b;
}

a {
    color: #1769aa;
    text-decoration: none;
    font-weight: bold;
}

a:hover {
    text-decoration: underline;
}
</style>
</head>

<body>
<main class="card">
    <div class="logo">MediFlow AI</div>

    <h2>Welcome back</h2>

    <p class="subtitle">
        Sign in to your hospital operations dashboard.
    </p>

    <?php if ($error !== ''): ?>
        <p class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <form method="post" action="login.php">
        <label for="email">Email ID</label>
        <input
            id="email"
            name="email"
            type="email"
            required
            autocomplete="email"
            placeholder="Enter your email ID"
            value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        >

        <label for="password">Password</label>
        <input
            id="password"
            name="password"
            type="password"
            required
            autocomplete="current-password"
            placeholder="Enter your password"
        >

        <button type="submit">Login</button>
    </form>

    <div class="footer">
        New member? <a href="register.php">Register here</a>
    </div>
</main>
</body>
</html>


<?php
// login.php
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, username, password, FirstName, LastName FROM admin WHERE username = :username');
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            $ok = false;
            $needsUpgrade = false;
            if ($user) {
                $stored   = (string)$user['password'];
                $isHashed = !empty(password_get_info($stored)['algo']);
                if ($isHashed) {
                    $ok = password_verify($password, $stored);
                    $needsUpgrade = $ok && password_needs_rehash($stored, PASSWORD_DEFAULT);
                } else {
                    // Legacy plain-text password: accept it once, then store a hash.
                    $ok = hash_equals($stored, $password);
                    $needsUpgrade = $ok;
                }
            }

            if ($ok) {
                if ($needsUpgrade) {
                    try {
                        $up = $pdo->prepare('UPDATE admin SET password = ? WHERE id = ?');
                        $up->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                    } catch (PDOException $e) {
                        error_log('Password upgrade skipped: ' . $e->getMessage()); // column too short, etc.
                    }
                }
                session_regenerate_id(true);
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['username']   = $user['username'];
                $_SESSION['first_name'] = $user['FirstName'];
                $_SESSION['last_name']  = $user['LastName'];
                redirect('index.php');
            }
            $error = 'Wrong username or password.';
        } catch (PDOException $e) {
            error_log('Login error: ' . $e->getMessage());
            $error = 'Could not reach the database. Try again in a moment.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in | <?= e(HOSPITAL_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login">
<main class="login-card">
    <span class="brand-mark" aria-hidden="true"></span>
    <h1><?= e(HOSPITAL_NAME) ?></h1>
    <p class="sub">Staff sign in</p>

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <?= csrf_field() ?>
        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>"
                   autocomplete="username" required autofocus>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="pw-wrap">
                <input type="password" id="password" name="password" autocomplete="current-password" required>
                <button type="button" class="pw-toggle" id="pwToggle" aria-controls="password" aria-pressed="false">Show</button>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Log in</button>
    </form>
</main>
<script>
const pw = document.getElementById('password');
const tg = document.getElementById('pwToggle');
tg.addEventListener('click', () => {
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    tg.textContent = show ? 'Hide' : 'Show';
    tg.setAttribute('aria-pressed', show);
});
</script>
</body>
</html>

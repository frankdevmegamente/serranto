<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// If no .env exists yet, redirect to setup
if (empty(ADMIN_PASS_HASH)) {
    header('Location: setup.php');
    exit;
}

$error = '';

// Rate limiting: 5 intentos cada 15 minutos
$maxAttempts = 5;
$lockoutTime = 900;
$now = time();

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_attempts_reset'] = $now;
}

if ($now - $_SESSION['login_attempts_reset'] > $lockoutTime) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_attempts_reset'] = $now;
}

if ($_SESSION['login_attempts'] >= $maxAttempts) {
    $remaining = $lockoutTime - ($now - $_SESSION['login_attempts_reset']);
    $error = 'Demasiados intentos. Espera ' . ceil($remaining / 60) . ' minutos.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Token de seguridad inválido. Recarga la página.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $valid = false;
        $role = '';

        // Check against .env admin
        if ($username === ADMIN_USER && !empty(ADMIN_PASS_HASH)) {
            $valid = password_verify($password, ADMIN_PASS_HASH);
            $role = 'admin';
        }

        // Check against SQLite users
        if (!$valid) {
            $user = getUser($username);
            if ($user) {
                $valid = password_verify($password, $user['password_hash']);
                $role = $user['role'];
            }
        }

        if ($valid) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['usuario'] = $username;
            $_SESSION['role'] = $role;
            $_SESSION['login_time'] = $now;
            $_SESSION['last_activity'] = $now;
            $_SESSION['login_attempts'] = 0;

            updateUserLogin($username);

            header('Location: dashboard.php');
            exit;
        } else {
            $_SESSION['login_attempts']++;
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}

$csrfToken = generateCSRFToken();
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>Login — Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <h1>Dashboard</h1>
            <p class="login-subtitle">Ingresa para ver tus pagos</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="form-group">
                    <label for="username">Usuario</label>
                    <input type="text" id="username" name="username" required autocomplete="username" autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
            </form>
        </div>
    </div>
</body>
</html>

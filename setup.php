<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// If .env already has a password hash, setup is done
if (!empty(ADMIN_PASS_HASH)) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = false;

$oldAdminUser = 'admin';
$oldAdminPass = '';
$oldPaypalClient = '';
$oldPaypalSecret = '';
$oldPaypalUrl = 'https://api-m.paypal.com';
$oldStripeKey = '';

// Try to recover old values from the old config.php format
$oldConfig = __DIR__ . '/config.old.php';
if (file_exists($oldConfig)) {
    $oldVars = get_defined_vars();
    // We won't use this, just read the file directly
}

// Read old config values from the current config.php backup or defaults
if (defined('ADMIN_USER')) { $oldAdminUser = ADMIN_USER; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPass = $_POST['admin_password'] ?? '';
    $newPassConfirm = $_POST['admin_password_confirm'] ?? '';
    $adminUser = trim($_POST['admin_user'] ?? $oldAdminUser);
    $paypalClient = trim($_POST['paypal_client_id'] ?? '');
    $paypalSecret = trim($_POST['paypal_secret'] ?? '');
    $paypalUrl = trim($_POST['paypal_api_url'] ?? 'https://api-m.paypal.com');
    $stripeKey = trim($_POST['stripe_secret_key'] ?? '');

    if (empty($adminUser)) {
        $error = 'El nombre de usuario admin es requerido.';
    } elseif (empty($newPass)) {
        $error = 'La contraseña es requerida.';
    } elseif ($newPass !== $newPassConfirm) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (strlen($newPass) < 4) {
        $error = 'La contraseña debe tener al menos 4 caracteres.';
    } else {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);

        $envContent = "# ============================================================\n";
        $envContent .= "# CONFIGURACIÓN GENERADA POR SETUP\n";
        $envContent .= "# ============================================================\n\n";
        $envContent .= "# --- Admin Credentials ---\n";
        $envContent .= "ADMIN_USER={$adminUser}\n";
        $envContent .= "ADMIN_PASS_HASH={$hash}\n\n";
        $envContent .= "# --- PayPal API Keys ---\n";
        $envContent .= "PAYPAL_CLIENT_ID={$paypalClient}\n";
        $envContent .= "PAYPAL_SECRET={$paypalSecret}\n";
        $envContent .= "PAYPAL_API_URL={$paypalUrl}\n\n";
        $envContent .= "# --- Stripe API Keys ---\n";
        $envContent .= "STRIPE_SECRET_KEY={$stripeKey}\n";

        if (@file_put_contents(__DIR__ . '/.env', $envContent) === false) {
            $error = 'No se pudo escribir el archivo .env. Verifica permisos.';
        } else {
            // Initialize DB with admin user
            try {
                $db = getDB();
                $stmt = $db->prepare('INSERT OR IGNORE INTO users (username, password_hash, role) VALUES (?, ?, ?)');
                $stmt->execute([$adminUser, $hash, 'admin']);
                $success = true;
            } catch (Exception $e) {
                $error = 'Error al inicializar la base de datos: ' . $e->getMessage();
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalación — Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .setup-body { background: var(--bg-body); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem; }
        .setup-card { background: var(--bg-card); border-radius: var(--radius); box-shadow: var(--shadow); padding: 2rem; max-width: 560px; width: 100%; border: 1px solid var(--border); }
        .setup-card h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
        .setup-card .subtitle { color: var(--text-muted); margin-bottom: 1.5rem; font-size: 0.85rem; }
        .setup-card .form-group { margin-bottom: 1rem; }
        .setup-card label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.25rem; color: var(--text); }
        .setup-card input { width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg-card); color: var(--text); }
        .setup-card .section-title { font-size: 0.85rem; font-weight: 700; margin: 1.25rem 0 0.75rem; padding-top: 1rem; border-top: 1px solid var(--border); }
        .setup-card .success-msg { text-align: center; padding: 2rem; }
        .setup-card .success-msg h2 { color: var(--color-success-text); margin-bottom: 0.5rem; }
        .setup-card .success-msg p { color: var(--text-muted); margin-bottom: 1.5rem; }
    </style>
</head>
<body class="setup-body">
    <div class="setup-card">
        <?php if ($success): ?>
            <div class="success-msg">
                <h2>✓ Instalación completada</h2>
                <p>El archivo .env y la base de datos se han creado correctamente.</p>
                <a href="index.php" class="btn btn-primary">Ir al inicio de sesión</a>
            </div>
        <?php else: ?>
            <h1>Configuración inicial</h1>
            <p class="subtitle">Crea tu cuenta de administrador y configura las API keys.</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="section-title">🔐 Administrador</div>

                <div class="form-group">
                    <label for="admin_user">Usuario admin</label>
                    <input type="text" id="admin_user" name="admin_user" value="<?= htmlspecialchars($oldAdminUser) ?>" required>
                </div>

                <div class="form-group">
                    <label for="admin_password">Contraseña</label>
                    <input type="password" id="admin_password" name="admin_password" required minlength="4">
                </div>

                <div class="form-group">
                    <label for="admin_password_confirm">Confirmar contraseña</label>
                    <input type="password" id="admin_password_confirm" name="admin_password_confirm" required minlength="4">
                </div>

                <div class="section-title" style="opacity:0.7;font-size:0.75rem;">💳 PayPal <span style="font-weight:400;">(opcional — solo si usas PayPal)</span></div>

                <div class="form-group">
                    <label for="paypal_client_id">Client ID</label>
                    <input type="text" id="paypal_client_id" name="paypal_client_id" value="<?= htmlspecialchars($oldPaypalClient) ?>" placeholder="ARhX...">
                </div>

                <div class="form-group">
                    <label for="paypal_secret">Secret</label>
                    <input type="text" id="paypal_secret" name="paypal_secret" value="<?= htmlspecialchars($oldPaypalSecret) ?>" placeholder="EBCw...">
                </div>

                <div class="form-group">
                    <label for="paypal_api_url">API URL</label>
                    <input type="text" id="paypal_api_url" name="paypal_api_url" value="<?= htmlspecialchars($oldPaypalUrl) ?>">
                </div>

                <div class="section-title" style="opacity:0.7;font-size:0.75rem;">⚡ Stripe <span style="font-weight:400;">(opcional — solo si usas Stripe)</span></div>

                <div class="form-group">
                    <label for="stripe_secret_key">Secret Key</label>
                    <input type="text" id="stripe_secret_key" name="stripe_secret_key" value="<?= htmlspecialchars($oldStripeKey) ?>" placeholder="sk_live_...">
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top:1.5rem;">Guardar configuración</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>

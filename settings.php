<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stripe_helper.php';
requireAdmin();

$error = '';
$success = '';

$tokenCachePath = __DIR__ . '/requests/token_cache.json';
$envPath = __DIR__ . '/.env';

// Load current values from .env
function loadEnv(string $path): array
{
    $vars = [];
    if (!file_exists($path)) return $vars;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $vars[$parts[0]] = $parts[1];
        }
    }
    return $vars;
}

function writeEnv(string $path, array $vars): bool
{
    $out = "# ============================================================\n";
    $out .= "# CONFIGURACIÓN\n";
    $out .= "# ============================================================\n\n";

    $out .= "# --- Admin Credentials ---\n";
    $out .= "ADMIN_USER=" . ($vars['ADMIN_USER'] ?? 'admin') . "\n";
    $out .= "ADMIN_PASS_HASH=" . ($vars['ADMIN_PASS_HASH'] ?? '') . "\n\n";

    $out .= "# --- PayPal API Keys ---\n";
    $out .= "PAYPAL_CLIENT_ID=" . ($vars['PAYPAL_CLIENT_ID'] ?? '') . "\n";
    $out .= "PAYPAL_SECRET=" . ($vars['PAYPAL_SECRET'] ?? '') . "\n";
    $out .= "PAYPAL_API_URL=" . ($vars['PAYPAL_API_URL'] ?? 'https://api-m.paypal.com') . "\n\n";

    $out .= "# --- Stripe API Keys ---\n";
    $out .= "STRIPE_SECRET_KEY=" . ($vars['STRIPE_SECRET_KEY'] ?? '') . "\n\n";

    $out .= "# --- Tipo de cambio USD -> MXN ---\n";
    $out .= "TIPO_CAMBIO=" . ($vars['TIPO_CAMBIO'] ?? '20.00') . "\n";

    return file_put_contents($path, $out, LOCK_EX) !== false;
}

$envVars = loadEnv($envPath);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_apis') {
        $envVars['PAYPAL_CLIENT_ID'] = trim($_POST['paypal_client_id'] ?? '');
        $envVars['PAYPAL_SECRET'] = trim($_POST['paypal_secret'] ?? '');
        $envVars['PAYPAL_API_URL'] = trim($_POST['paypal_api_url'] ?? 'https://api-m.paypal.com');
        $envVars['STRIPE_SECRET_KEY'] = trim($_POST['stripe_secret_key'] ?? '');

        if (writeEnv($envPath, $envVars)) {
            $success = 'API keys actualizadas correctamente.';
        } else {
            $error = 'Error al escribir el archivo .env. Verifica permisos.';
        }

    } elseif ($action === 'update_exchange_rate') {
        $rate = str_replace(',', '.', trim($_POST['tipo_cambio'] ?? '20.00'));
        if (!is_numeric($rate) || floatval($rate) <= 0) {
            $error = 'El tipo de cambio debe ser un número positivo.';
        } else {
            $envVars['TIPO_CAMBIO'] = $rate;
            if (writeEnv($envPath, $envVars)) {
                $success = 'Tipo de cambio actualizado a $1 USD = $' . number_format(floatval($rate), 2) . ' MXN.';
            } else {
                $error = 'Error al escribir el archivo .env.';
            }
        }

    } elseif ($action === 'clear_cache') {
        if (file_exists($tokenCachePath)) {
            if (@unlink($tokenCachePath)) {
                $success = 'Caché de token PayPal eliminado.';
            } else {
                $error = 'Error al eliminar el caché.';
            }
        } else {
            $success = 'No hay caché que eliminar.';
        }

        } elseif ($action === 'change_password') {
            $newPass = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            if (strlen($newPass) < 4) {
                $error = 'La contraseña debe tener al menos 4 caracteres.';
            } elseif ($newPass !== $confirm) {
                $error = 'Las contraseñas no coinciden.';
            } else {
                $envVars['ADMIN_PASS_HASH'] = password_hash($newPass, PASSWORD_BCRYPT);
                if (writeEnv($envPath, $envVars)) {
                    $success = 'Contraseña de admin actualizada.';
                } else {
                    $error = 'Error al escribir el archivo .env.';
                }
            }

        } elseif ($action === 'refresh_exchange_rate') {
            $tcCacheFile = __DIR__ . '/requests/tipo_cambio_cache.json';
            if (file_exists($tcCacheFile)) {
                @unlink($tcCacheFile);
            }
            $tcInfo = obtenerInfoTipoCambio();
            $success = 'Tasa actualizada: 1 USD = $' . number_format($tcInfo['rate'], 2) . ' MXN (fuente: ' . htmlspecialchars($tcInfo['source']) . ').';
        }
}

// Reload after potential update
$envVars = loadEnv($envPath);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración — Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .settings-body { background: var(--bg-body); padding: 2rem; max-width: 700px; margin: 0 auto; }
        .settings-body h1 { font-size: 1.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .settings-body h1 a { font-size: 0.85rem; font-weight: 400; text-decoration: none; color: var(--color-primary); }
        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.5rem; }
        .card h3 { font-size: 0.95rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .card .form-group { margin-bottom: 0.75rem; }
        .card label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.25rem; }
        .card input { width: 100%; padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--bg-card); color: var(--text); font-family: monospace; font-size: 0.8rem; }
        .card .hint { font-size: 0.7rem; color: var(--text-muted); margin-top: 0.15rem; }
        .btn-danger { background: #dc2626; color: #fff; border: none; padding: 0.5rem 1rem; border-radius: var(--radius-sm); cursor: pointer; }
        .btn-danger:hover { background: #b91c1c; }
        .inline-group { display: flex; gap: 0.5rem; align-items: flex-end; }
        .inline-group input { flex: 1; }
        .back-link { display: inline-block; margin-bottom: 1rem; color: var(--color-primary); text-decoration: none; font-size: 0.85rem; }
    </style>
</head>
<body class="settings-body">
    <a href="dashboard.php" class="back-link">← Volver al Dashboard</a>
    <h1>⚙️ Configuración</h1>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-info"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="card">
        <h3>💳 PayPal API</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_apis">
            <div class="form-group">
                <label for="paypal_client_id">Client ID</label>
                <input type="text" id="paypal_client_id" name="paypal_client_id" value="<?= htmlspecialchars($envVars['PAYPAL_CLIENT_ID'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="paypal_secret">Secret</label>
                <input type="text" id="paypal_secret" name="paypal_secret" value="<?= htmlspecialchars($envVars['PAYPAL_SECRET'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="paypal_api_url">API URL</label>
                <input type="text" id="paypal_api_url" name="paypal_api_url" value="<?= htmlspecialchars($envVars['PAYPAL_API_URL'] ?? 'https://api-m.paypal.com') ?>">
                <div class="hint">Producción: https://api-m.paypal.com · Sandbox: https://api-m.sandbox.paypal.com</div>
            </div>
            <button type="submit" class="btn btn-primary">Guardar PayPal</button>
        </form>
    </div>

    <div class="card">
        <h3>⚡ Stripe API</h3>
        <form method="POST">
            <input type="hidden" name="action" value="update_apis">
            <div class="form-group">
                <label for="stripe_secret_key">Secret Key</label>
                <input type="text" id="stripe_secret_key" name="stripe_secret_key" value="<?= htmlspecialchars($envVars['STRIPE_SECRET_KEY'] ?? '') ?>">
                <div class="hint">Empieza con sk_live_ (producción) o sk_test_ (pruebas)</div>
            </div>
            <button type="submit" class="btn btn-primary">Guardar Stripe</button>
        </form>
    </div>

    <div class="card">
        <h3>💱 Tipo de cambio USD → MXN</h3>

        <?php
        $tcCachePath = __DIR__ . '/requests/tipo_cambio_cache.json';
        $tcInfo = null;
        if (file_exists($tcCachePath)) {
            $tcRaw = @file_get_contents($tcCachePath);
            if ($tcRaw !== false) {
                $tcInfo = json_decode($tcRaw, true);
            }
        }
        $tcActual = null;
        $tcSource = '—';
        $tcUpdated = '—';
        if ($tcInfo && isset($tcInfo['rate'])) {
            $tcActual = $tcInfo['rate'];
            $tcUpdated = $tcInfo['last_updated'] ?? '—';
            $tcAge = $tcInfo['updated_at'] ?? 0;
            $tcAgeHours = $tcAge ? round((time() - $tcAge) / 3600, 1) : '—';
            $tcSource = 'open.er-api.com (automático)';
        } else {
            $tcActual = $envVars['TIPO_CAMBIO'] ?? '20.00';
            $tcSource = 'valor fijo (fallback)';
        }
        ?>

        <div style="background:var(--bg-table-header);border-radius:var(--radius-sm);padding:0.75rem;margin-bottom:0.75rem;font-size:0.85rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">
                <span><strong>Tasa actual:</strong> 1 USD = <strong style="font-size:1.1rem;color:var(--color-primary);">$<?= htmlspecialchars(number_format((float)$tcActual, 2)) ?></strong> MXN</span>
                <span class="text-muted" style="font-size:0.75rem;">Origen: <?= htmlspecialchars($tcSource) ?></span>
            </div>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:0.35rem;font-size:0.75rem;color:var(--text-muted);">
                <span>📅 Última actualización: <?= htmlspecialchars($tcUpdated) ?></span>
                <?php if ($tcInfo && isset($tcAgeHours) && $tcAgeHours !== '—'): ?>
                    <span>⏱ Hace <?= $tcAgeHours ?> hora(s)</span>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <input type="hidden" name="action" value="refresh_exchange_rate">
            <button type="submit" class="btn btn-primary">🔄 Actualizar tasa ahora</button>
            <span class="text-muted" style="font-size:0.75rem;align-self:center;">La tasa se actualiza automáticamente cada 6 horas vía open.er-api.com</span>
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:0.75rem 0;">

        <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:0.5rem;">
            ⚠️ Valor de respaldo manual (se usa si la API no está disponible):
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_exchange_rate">
            <div class="form-group" style="display:flex;gap:0.5rem;align-items:flex-end;">
                <div style="flex:1;">
                    <label for="tipo_cambio">1 USD = ? MXN</label>
                    <input type="text" id="tipo_cambio" name="tipo_cambio" value="<?= htmlspecialchars($envVars['TIPO_CAMBIO'] ?? '20.00') ?>" placeholder="20.00">
                </div>
                <button type="submit" class="btn btn-primary">Guardar respaldo</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>🗑️ Caché de PayPal</h3>
        <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:0.75rem;">
            El token de acceso a PayPal se guarda en <code>requests/token_cache.json</code>.
            Si cambias las credenciales, limpia el caché para que se genere un token nuevo.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="clear_cache">
            <button type="submit" class="btn-danger">Limpiar caché</button>
        </form>
    </div>

    <div class="card">
        <h3>🔐 Contraseña de administrador</h3>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label for="new_password">Nueva contraseña</label>
                <input type="password" id="new_password" name="new_password" required minlength="4">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirmar</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="4">
            </div>
            <button type="submit" class="btn btn-primary">Cambiar contraseña</button>
        </form>
    </div>
</body>
</html>

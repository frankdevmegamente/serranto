<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Session timeout (30 minutos)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();
$csrfToken = generateCSRFToken();
setSecurityHeaders();
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Seleccionar plataforma</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .select-body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
        }
        .select-container {
            width: 100%;
            max-width: 600px;
            padding: 1rem;
        }
        .select-card {
            background: #fff;
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow-lg);
            text-align: center;
        }
        .platform-grid.single {
            grid-template-columns: 1fr;
            max-width: 320px;
            margin-left: auto;
            margin-right: auto;
        }
        .select-card h1 {
            font-size: 1.5rem;
            color: #0f172a;
            margin-bottom: 0.25rem;
        }
        .select-card .subtitle {
            color: #64748b;
            margin-bottom: 2rem;
            font-size: 0.875rem;
        }
        .platform-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .platform-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            border-radius: var(--radius-lg);
            border: 2px solid var(--border);
            background: #f8fafc;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.25s;
            gap: 0.75rem;
        }
        .platform-card:hover {
            border-color: var(--color-primary);
            background: #f0f4ff;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .platform-card .icon {
            font-size: 2.5rem;
        }
        .platform-card .name {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
        }
        .platform-card .desc {
            font-size: 0.8rem;
            color: #64748b;
        }
        .select-card .user-info {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 1.5rem;
            padding: 0.75rem;
            background: #f1f5f9;
            border-radius: var(--radius-md);
        }
        .select-card .user-info strong {
            color: #1e293b;
        }
        .select-card .back-link {
            display: inline-block;
            color: #dc2626;
            text-decoration: none;
            font-size: 0.8rem;
            padding: 0.5rem 1rem;
            border: 1px solid rgba(220,38,38,0.3);
            border-radius: var(--radius-sm);
            transition: all 0.2s;
        }
        .select-card .back-link:hover {
            background: #fef2f2;
            border-color: #dc2626;
            color: #b91c1c;
        }
    </style>
</head>
<body class="select-body">
    <div class="select-container">
        <div class="select-card">
            <h1>🔐 Bienvenido</h1>
            <p class="subtitle">Selecciona una plataforma para continuar</p>

            <div class="user-info">
                👤 Conectado como <strong><?= htmlspecialchars($_SESSION['usuario'] ?? 'Administrador') ?></strong>
            </div>

            <?php
            $hasPP = hasPayPal();
            $hasSt = hasStripe();
            $totalPlataformas = ($hasPP ? 1 : 0) + ($hasSt ? 1 : 0);
            ?>

            <div class="platform-grid<?= $totalPlataformas === 1 ? ' single' : '' ?>">
                <?php if ($hasPP): ?>
                    <a href="paypal_dashboard.php" class="platform-card">
                        <div class="icon">🅿️</div>
                        <div class="name">PayPal</div>
                        <div class="desc">Ver pagos, reembolsos y rastreo</div>
                    </a>
                <?php endif; ?>
                <?php if ($hasSt): ?>
                    <a href="stripe_dashboard.php" class="platform-card">
                        <div class="icon">💳</div>
                        <div class="name">Stripe</div>
                        <div class="desc">Ver pagos y reportes</div>
                    </a>
                <?php endif; ?>
                <?php if ($totalPlataformas === 0): ?>
                    <div class="platform-card disabled" style="cursor:default;opacity:0.6;">
                        <div class="icon">⚙️</div>
                        <div class="name">Sin plataformas</div>
                        <div class="desc">Configura las APIs en <a href="settings.php" style="color:var(--color-primary);">Configuración</a></div>
                    </div>
                <?php endif; ?>
            </div>

            <a href="logout.php" class="back-link">✕ Cerrar sesión</a>
        </div>
    </div>
</body>
</html>

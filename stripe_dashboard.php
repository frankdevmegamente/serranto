<?php
session_start();

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

require_once __DIR__ . '/stripe_helper.php';

if (!hasStripe()): ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Stripe — Dashboard</title><link rel="stylesheet" href="assets/style.css"></head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg-body);"><div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:2rem;max-width:420px;width:100%;text-align:center;"><div style="font-size:3rem;margin-bottom:1rem;">💳</div><h2 style="margin-bottom:0.5rem;">Stripe no configurado</h2><p style="color:var(--text-muted);margin-bottom:1.5rem;">Este cliente no tiene llaves API de Stripe. Puedes agregarlas desde Configuración si se requieren más adelante.</p><a href="dashboard.php" class="btn">⬅ Volver al inicio</a>&nbsp;<a href="settings.php" class="btn btn-primary">⚙️ Configuración</a></div></body></html>
<?php exit; endif;

checkRateLimit(30, 60);

// --- Export CSV (authenticated) ---
if (isset($_GET['export']) && $_GET['export'] === 'csv' && !empty($_GET['ids'])) {
    $exportIds = explode(',', $_GET['ids']);
    $resultado = obtenerPaymentIntents(100);
    $intents = $resultado['data'];
    $clasificados = clasificarPagos($intents);
    $todosExport = array_merge($clasificados['exitosos'], $clasificados['fallidos']);
    $todosExport = array_filter($todosExport, fn($p) => in_array($p['id'], $exportIds));
    exportarCSV(array_values($todosExport));
    exit;
}

// --- Cargar datos del Dashboard Inicio (semana seleccionada o personalizado) ---
$dashModo = $_GET['modo'] ?? 'semanal';
$dashSemanaRef = $_GET['semana'] ?? null;
$dashCustomStart = $_GET['start'] ?? null;
$dashCustomEnd = $_GET['end'] ?? null;
$dashError = null;
$dashStats = ['total_exitosos' => 0, 'total_fallidos' => 0, 'monto_total_formateado' => '$ 0.00', 'moneda' => 'MXN'];
$dashDailyLabels = [];
$dashDailyAmounts = [];
$dashDailyCounts = [];
$dashRecentTransactions = [];
$dashMoneda = 'MXN';

if ($dashModo === 'personalizado' && $dashCustomStart && $dashCustomEnd) {
    $dashInicioTS = (new DateTime($dashCustomStart . ' 00:00:00'))->getTimestamp();
    $dashFinTS = (new DateTime($dashCustomEnd . ' 23:59:59'))->getTimestamp();
    $dashInicioDisplay = (new DateTime($dashCustomStart))->format('d/m/Y');
    $dashFinDisplay = (new DateTime($dashCustomEnd))->format('d/m/Y');
    $dashInicioStr = $dashCustomStart;
    $dashFinStr = $dashCustomEnd;
    $dashRangoDias = (new DateTime($dashCustomStart))->diff(new DateTime($dashCustomEnd))->days + 1;
} else {
    $dashModo = 'semanal';
    $dashWeek = obtenerSemana($dashSemanaRef);
    $dashInicioTS = $dashWeek['inicio_ts'];
    $dashFinTS = $dashWeek['fin_ts'];
    $dashInicioDisplay = $dashWeek['inicio_display'];
    $dashFinDisplay = $dashWeek['fin_display'];
    $dashInicioStr = $dashWeek['inicio_str'];
    $dashFinStr = $dashWeek['fin_str'];
    $semanaActual = obtenerSemana();
    $dashEsActual = $dashWeek['inicio_str'] === $semanaActual['inicio_str'];
    $dashRangoDias = 7;
}

// Nav URLs
$dashNavPrev = '';
$dashNavNext = '';
if ($dashModo === 'semanal') {
    $ant = (new DateTime($dashInicioStr))->modify('-7 days')->format('Y-m-d');
    $sig = (new DateTime($dashInicioStr))->modify('+7 days')->format('Y-m-d');
    $dashNavPrev = "?modo=semanal&semana=$ant";
    $dashNavNext = $dashEsActual ? '' : "?modo=semanal&semana=$sig";
} else {
    $dias = $dashRangoDias;
    $antInicio = (new DateTime($dashInicioStr))->modify("-{$dias} days")->format('Y-m-d');
    $antFin = (new DateTime($dashFinStr))->modify("-{$dias} days")->format('Y-m-d');
    $sigInicio = (new DateTime($dashInicioStr))->modify("+{$dias} days")->format('Y-m-d');
    $sigFin = (new DateTime($dashFinStr))->modify("+{$dias} days")->format('Y-m-d');
    $dashNavPrev = "?modo=personalizado&start=$antInicio&end=$antFin";
    $dashNavNext = "?modo=personalizado&start=$sigInicio&end=$sigFin";
}
$dashCustomStartVal = $dashCustomStart ?? date('Y-m-d', strtotime('-7 days'));
$dashCustomEndVal = $dashCustomEnd ?? date('Y-m-d');

try {
    $dashResult = obtenerPagosPorRango($dashInicioTS, $dashFinTS);
    if ($dashResult['error']) {
        $dashError = $dashResult['error'];
    } else {
        $dashClasificados = clasificarPagos($dashResult['data']);
        $dashExitosos = $dashClasificados['exitosos'];
        $dashFallidos = $dashClasificados['fallidos'];
        $dashStats = resumenPagos($dashExitosos, $dashFallidos);
        $dashMoneda = $dashStats['moneda'] ?: 'MXN';
        $dashTodos = array_merge($dashExitosos, $dashFallidos);
        usort($dashTodos, fn($a, $b) => $b['created_ts'] - $a['created_ts']);
        $dashRecentTransactions = array_slice($dashTodos, 0, 8);
        $dashChartData = datosGrafica($dashExitosos);
        $dashDailyLabels = json_encode(array_column($dashChartData, 'fecha'));
        $dashDailyAmounts = json_encode(array_column($dashChartData, 'total'));
        $dashDailyCounts = json_encode(array_column($dashChartData, 'conteo'));
    }
} catch (Exception $e) {
    $dashError = $e->getMessage();
}
// --- Fin carga dashboard ---

$limite  = 50;
$cursor  = $_GET['cursor'] ?? null;

try {
    $resultado = obtenerPaymentIntents($limite, $cursor);
    $intents   = $resultado['data'];
    $has_more  = $resultado['has_more'];

    if (isset($resultado['error'])) {
        $error_api = $resultado['error'];
        $intents   = [];
    }

    $clasificados = clasificarPagos($intents);
    $exitosos     = $clasificados['exitosos'];
    $fallidos     = $clasificados['fallidos'];
    $resumen      = resumenPagos($exitosos, $fallidos);

    $ultimo_id = !empty($intents) ? end($intents)->id : null;
    $primer_id = !empty($intents) ? reset($intents)->id : null;

} catch (Exception $e) {
    $error_api = $e->getMessage();
    $exitosos  = [];
    $fallidos  = [];
    $resumen   = ['total_exitosos' => 0, 'total_fallidos' => 0, 'monto_total_formateado' => '$ 0.00', 'moneda' => 'MXN'];
    $ultimo_id = null;
    $primer_id = null;
    $has_more  = false;
}

$total_pagos = $resumen['total_exitosos'] + $resumen['total_fallidos'];
$todos       = array_merge($exitosos, $fallidos);
usort($todos, fn($a, $b) => $b['created_ts'] - $a['created_ts']);

$chart_data = datosGrafica($exitosos);
$chart_labels = json_encode(array_column($chart_data, 'fecha'));
$chart_values = json_encode(array_column($chart_data, 'total'));

$moneda_simbolo = $resumen['moneda'] ?? 'MXN';
$all_ids = implode(',', array_column($todos, 'id'));

$csrfToken = generateCSRFToken();
setSecurityHeaders();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>Stripe — Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/reporte-semanal.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <style>
        :root { --sidebar-width: 230px; }
        body { display: flex; min-height: 100vh; flex-direction: column; }
        .app-layout { display: flex; flex: 1; overflow: hidden; }
        .sidebar {
            width: var(--sidebar-width);
            background: var(--bg-header);
            color: var(--text-on-header);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 200;
            border-right: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar-header { padding: 1rem 1.25rem; display: flex; align-items: center; gap: 0.6rem; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-logo { font-size: 1.4rem; }
        .sidebar-title { font-size: 1rem; font-weight: 700; }
        .sidebar-nav { list-style: none; padding: 0.75rem 0.5rem; flex: 1; overflow-y: auto; }
        .nav-item {
            display: flex; align-items: center; gap: 0.65rem; padding: 0.65rem 0.9rem;
            border-radius: var(--radius-md); cursor: pointer; font-size: 0.875rem;
            color: rgba(255,255,255,0.7); transition: all 0.2s; margin-bottom: 0.15rem; user-select: none;
        }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-item.active { background: rgba(255,255,255,0.12); color: #fff; font-weight: 600; }
        .nav-item .nav-icon { font-size: 1rem; width: 22px; text-align: center; flex-shrink: 0; }
        .nav-item .nav-text { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-footer { padding: 0.75rem 1rem; border-top: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; font-size: 0.8rem; }
        .sidebar-footer .user-badge { background: rgba(255,255,255,0.1); padding: 0.25rem 0.5rem; border-radius: var(--radius-sm); color: rgba(255,255,255,0.8); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sidebar-footer .logout-link { color: rgba(255,255,255,0.5); text-decoration: none; padding: 0.25rem 0.5rem; border-radius: var(--radius-sm); transition: all 0.2s; }
        .sidebar-footer .logout-link:hover { color: #fff; background: rgba(255,255,255,0.1); }
        .main-content { flex: 1; display: flex; flex-direction: column; min-width: 0; overflow-y: auto; max-height: 100vh; }
        .main-header {
            background: var(--bg-card); border-bottom: 1px solid var(--border);
            padding: 0.75rem 1.5rem; display: flex; align-items: center;
            justify-content: space-between; flex-shrink: 0; position: sticky; top: 0; z-index: 50;
        }
        .main-header .page-title { font-size: 1.1rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
        .main-header .header-right { display: flex; align-items: center; gap: 0.75rem; }
        .section-panel { display: none; padding: 1.5rem; flex: 1; }
        .section-panel.active { display: block; }
        .section-panel .container { max-width: 1400px; margin: 0 auto; }
        .sidebar-toggle-btn { display: none; background: none; border: none; color: var(--text-primary); font-size: 1.25rem; cursor: pointer; padding: 0.25rem; }
        .text-muted { color: var(--text-muted); }
        .font-mono { font-family: var(--font-mono); }
        .text-sm { font-size: 0.75rem; }
        .status-badge { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 99px; font-size: 0.7rem; font-weight: 600; white-space: nowrap; }
        .status-badge.success { background: var(--color-success-bg); color: var(--color-success-text); }
        .status-badge.failed { background: var(--color-danger-bg); color: var(--color-danger-text); }
        .module-card { background: var(--bg-card); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); padding: 1.5rem; margin-bottom: 1.5rem; }
        .module-card h3 { font-size: 1rem; font-weight: 600; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; }
        .filters-bar { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem; }
        .filter-group { display: flex; flex-direction: column; gap: 0.25rem; }
        .filter-group label { font-size: 0.7rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; }
        .filter-group input, .filter-group select { background: var(--bg-input); border: 1px solid var(--border-input); border-radius: var(--radius-sm); padding: 0.4rem 0.6rem; font-size: 0.825rem; color: var(--text-primary); outline: none; }
        .filter-group input:focus, .filter-group select:focus { border-color: var(--color-primary); }
        .search-wrapper { position: relative; }
        .search-wrapper input { background: var(--bg-input); border: 1px solid var(--border-input); border-radius: var(--radius-md); padding: 0.5rem 0.75rem 0.5rem 2rem; font-size: 0.85rem; color: var(--text-primary); outline: none; width: 220px; }
        .search-wrapper input:focus { border-color: var(--color-primary); }
        .search-icon { position: absolute; left: 0.6rem; top: 50%; transform: translateY(-50%); font-size: 0.9rem; }
        .btn-icon { background: none; border: 1px solid var(--border); padding: 0.4rem 0.6rem; border-radius: var(--radius-sm); cursor: pointer; color: var(--text-primary); font-size: 0.85rem; }
        .btn-icon:hover { background: var(--bg-table-hover); }
        .refresh-indicator { font-size: 0.7rem; display: inline-flex; align-items: center; gap: 4px; }
        .dot { width: 6px; height: 6px; border-radius: 50%; background: #22c55e; display: inline-block; }
        .dot.paused { background: #ef4444; }
        .chart-section { background: var(--bg-card); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); margin-bottom: 1rem; overflow: hidden; }
        .chart-header { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1.25rem; cursor: pointer; user-select: none; }
        .chart-header h3 { font-size: 0.9375rem; font-weight: 600; }
        .chart-toggle { background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 0.9rem; padding: 0.25rem; }
        .chart-body { padding: 0 1.25rem 1.25rem; }
        .chart-body.hidden { display: none; }
        .chart-body canvas { max-height: 280px; width: 100% !important; }
        .chart-type-selector { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
        .chart-type-btn { padding: 0.3rem 0.75rem; border: 1px solid var(--border); background: var(--bg-card); border-radius: var(--radius-sm); cursor: pointer; font-size: 0.75rem; color: var(--text-secondary); transition: all 0.2s; }
        .chart-type-btn:hover { border-color: var(--color-primary); color: var(--color-primary); }
        .chart-type-btn.active { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }
        .loading { text-align: center; padding: 2rem; color: var(--text-secondary); }
        .no-results { display: none; text-align: center; padding: 1rem; color: var(--text-secondary); font-style: italic; }
        .card-badge { background: var(--bg-badge); padding: 0.15rem 0.5rem; border-radius: var(--radius-sm); font-family: var(--font-mono); font-size: 0.7rem; }
        .code-sm { font-size: 0.7rem; padding: 0.15rem 0.4rem; background: var(--bg-code); border-radius: var(--radius-sm); }
        .meta-toggle { background: none; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.2rem 0.5rem; font-size: 0.7rem; cursor: pointer; color: var(--text-secondary); }
        .meta-toggle:hover { color: var(--color-primary); border-color: var(--color-primary); }
        .meta-content { font-size: 0.65rem; background: var(--bg-code); padding: 0.5rem; border-radius: var(--radius-sm); margin-top: 0.3rem; max-height: 100px; overflow-y: auto; }
        .pagination { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; gap: 0.5rem; }
        .pagination-info { font-size: 0.8rem; color: var(--text-secondary); }
        .pagination-buttons { display: flex; gap: 0.5rem; }
        .dark-toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; background: var(--bg-table-header); border-radius: var(--radius-sm); }
        .dark-toggle-row span { font-weight: 500; }
        .toggle-switch { position: relative; width: 44px; height: 24px; background: var(--border-input); border-radius: 12px; cursor: pointer; transition: background 0.2s; }
        .toggle-switch.active { background: var(--color-primary); }
        .toggle-switch::after { content: ''; position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; background: #fff; border-radius: 50%; transition: transform 0.2s; }
        .toggle-switch.active::after { transform: translateX(20px); }
        .config-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .config-item { padding: 0.75rem; background: var(--bg-table-header); border-radius: var(--radius-sm); }
        .config-item .config-label { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 600; }
        .config-item .config-value { font-size: 0.875rem; font-family: var(--font-mono); color: var(--text-primary); margin-top: 0.2rem; word-break: break-all; }
        .toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 0.5rem; }
        .toast { padding: 0.75rem 1rem; border-radius: var(--radius-md); font-size: 0.875rem; box-shadow: var(--shadow-md); animation: toastIn 0.3s ease-out; color: #fff; min-width: 280px; }
        .toast.success { background: #16a34a; }
        .toast.error { background: #dc2626; }
        .toast.info { background: #2563eb; }
        @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
        @media (max-width: 900px) {
            .sidebar { position: fixed; left: -100%; transition: left 0.3s ease; z-index: 300; }
            .sidebar.open { left: 0; }
            .sidebar-toggle-btn { display: block; }
            .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 250; }
            .sidebar-overlay.open { display: block; }
            .config-grid { grid-template-columns: 1fr; }
            .filters-bar { flex-direction: column; }
            .search-wrapper input { width: 100%; }
        }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: var(--bg-modal-overlay); z-index: 1000; align-items: center; justify-content: center; padding: 1rem; }
        .modal-overlay.active { display: flex; }
        .modal { background: var(--bg-modal); border-radius: var(--radius-lg); box-shadow: var(--shadow-md); max-width: 550px; width: 100%; max-height: 85vh; overflow-y: auto; animation: modalIn 0.2s ease-out; }
        @keyframes modalIn { from { opacity: 0; transform: translateY(20px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); position: sticky; top: 0; background: var(--bg-modal); z-index: 1; }
        .modal-header h3 { font-size: 1.1rem; font-weight: 600; }
        .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-secondary); padding: 0.25rem; line-height: 1; }
        .modal-close:hover { color: var(--text-primary); }
        .modal-body { padding: 1.25rem 1.5rem; }
        .detail-grid { display: grid; gap: 0.5rem; }
        .detail-item { display: flex; gap: 0.75rem; padding: 0.4rem 0; border-bottom: 1px solid var(--border); }
        .detail-item.full { grid-column: 1 / -1; flex-direction: column; }
        .detail-label { font-size: 0.7rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; min-width: 100px; flex-shrink: 0; }
        .detail-value { font-size: 0.85rem; color: var(--text-primary); word-break: break-word; }

        .header-user-info {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.78rem;
            color: var(--text-secondary);
            padding: 0.25rem 0.6rem;
            background: var(--bg-table-header);
            border-radius: var(--radius-sm);
            white-space: nowrap;
        }
        .header-user-name {
            font-weight: 600;
            color: var(--text-primary);
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .header-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-primary);
            padding: 0.3rem 0.6rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.78rem;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .header-btn:hover {
            background: var(--bg-table-header);
            border-color: var(--text-secondary);
        }
        .header-btn-logout {
            color: #dc2626;
            border-color: rgba(220,38,38,0.3);
        }
        .header-btn-logout:hover {
            background: #fef2f2;
            border-color: #dc2626;
            color: #b91c1c;
        }
    </style>
</head>
<body>
    <div id="toastContainer" class="toast-container"></div>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <div class="app-layout">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <span class="sidebar-logo">💳</span>
                <span class="sidebar-title">Stripe Dashboard</span>
            </div>
            <ul class="sidebar-nav">
                <li class="nav-item active" data-section="inicio" onclick="navigateTo('inicio')">
                    <span class="nav-icon">📊</span>
                    <span class="nav-text">Dashboard</span>
                </li>
                <li class="nav-item" data-section="rastreo" onclick="navigateTo('rastreo')">
                    <span class="nav-icon">📋</span>
                    <span class="nav-text">Transacciones</span>
                </li>
                <li class="nav-item" data-section="reporte" onclick="navigateTo('reporte')">
                    <span class="nav-icon">📅</span>
                    <span class="nav-text">Reporte Semanal</span>
                </li>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <li class="nav-item" onclick="location.href='users.php'">
                        <span class="nav-icon">👥</span>
                        <span class="nav-text">Usuarios</span>
                    </li>
                    <li class="nav-item" onclick="location.href='settings.php'">
                        <span class="nav-icon">⚙️</span>
                        <span class="nav-text">Configuración</span>
                    </li>
                <?php else: ?>
                    <li class="nav-item" data-section="config" onclick="navigateTo('config')">
                        <span class="nav-icon">⚙️</span>
                        <span class="nav-text">Configuración</span>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="sidebar-footer">
                <span class="user-badge"><?= htmlspecialchars($_SESSION['usuario'] ?? ADMIN_USER) ?></span>
                <div style="display:flex;gap:0.25rem;">
                    <a href="dashboard.php" class="logout-link" title="Cambiar plataforma">🔄</a>
                    <a href="logout.php" class="logout-link">Salir</a>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <header class="main-header">
                <div class="page-title">
                    <button class="sidebar-toggle-btn" id="sidebarToggle" onclick="toggleSidebar()">☰</button>
                    <span id="pageTitleText">📊 Dashboard</span>
                </div>
                <div class="header-right">
                    <div class="header-user-info">
                        <span>👤</span>
                        <span class="header-user-name"><?= htmlspecialchars($_SESSION['usuario'] ?? ADMIN_USER) ?></span>
                    </div>
                    <a href="dashboard.php" class="header-btn" title="Cambiar plataforma">🔄 Plataformas</a>
                    <a href="logout.php" class="header-btn header-btn-logout">Salir</a>
                    <button class="btn-icon" id="darkToggle" onclick="toggleDark()" title="Modo oscuro">🌙</button>
                </div>
            </header>

            <!-- ============ INICIO / DASHBOARD ============ -->
            <div class="section-panel active" id="section-inicio">
                <div class="container">
                    <?php if ($dashError): ?>
                        <div class="alert alert-error">
                            <strong>Error al cargar dashboard:</strong> <?= htmlspecialchars($dashError) ?>
                        </div>
                    <?php endif; ?>

                    <div class="semana-navegacion" style="margin-bottom:1.25rem;">
                        <div class="modo-toggle">
                            <a href="?modo=semanal" class="modo-btn <?= $dashModo === 'semanal' ? 'active' : '' ?>">📅 Semanal</a>
                            <a href="?modo=personalizado&start=<?= date('Y-m-d', strtotime('-7 days')) ?>&end=<?= date('Y-m-d') ?>" class="modo-btn <?= $dashModo === 'personalizado' ? 'active' : '' ?>">📅 Personalizado</a>
                        </div>

                        <div class="nav-mode mode-semanal" style="display:<?= $dashModo === 'semanal' ? 'flex' : 'none' ?>;">
                            <a href="<?= $dashNavPrev ?>" class="btn">← Anterior</a>
                            <div class="semana-selector">
                                <span class="semana-rango" style="font-size:1rem;font-weight:600;">📊 Dashboard</span>
                                <span class="text-muted"><?= $dashInicioDisplay ?> — <?= $dashFinDisplay ?></span>
                                <input type="date" id="dashSemanaPicker" value="<?= $dashInicioStr ?>" onchange="irASemana(this.value)">
                            </div>
                            <?php if ($dashNavNext): ?>
                                <a href="<?= $dashNavNext ?>" class="btn">Siguiente →</a>
                            <?php else: ?>
                                <span class="btn btn-disabled" style="opacity:0.5;cursor:not-allowed;">Siguiente →</span>
                            <?php endif; ?>
                            <a href="?" class="btn btn-sm">Semana actual</a>
                        </div>

                        <div class="nav-mode mode-personalizado" style="display:<?= $dashModo === 'personalizado' ? 'flex' : 'none' ?>;">
                            <a href="<?= $dashNavPrev ?>" class="btn">← Anterior</a>
                            <div class="custom-range-inputs">
                                <label>Desde:</label>
                                <input type="date" id="dashCustomStart" value="<?= $dashCustomStartVal ?>">
                                <label>Hasta:</label>
                                <input type="date" id="dashCustomEnd" value="<?= $dashCustomEndVal ?>">
                                <button class="btn btn-primary" onclick="aplicarCustomDash()">🔍 Generar</button>
                            </div>
                            <a href="<?= $dashNavNext ?>" class="btn">Siguiente →</a>
                            <a href="?" class="btn btn-sm">Hoy</a>
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card success">
                            <div class="stat-label">Pagos Exitosos</div>
                            <div class="stat-value"><?= $dashStats['total_exitosos'] ?></div>
                        </div>
                        <div class="stat-card failed">
                            <div class="stat-label">Pagos Fallidos</div>
                            <div class="stat-value"><?= $dashStats['total_fallidos'] ?></div>
                        </div>
                        <div class="stat-card total">
                            <div class="stat-label">Total Recaudado</div>
                            <div class="stat-value"><?= $dashStats['monto_total_formateado'] ?></div>
                        </div>
                        <div class="stat-card currency">
                            <div class="stat-label">Total MXN</div>
                            <div class="stat-value" style="font-size:1.1rem;word-break:break-word;"><?= htmlspecialchars($dashStats['monto_total_mxn_formateado'] ?? '$ 0.00') ?></div>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
                        <div class="chart-section">
                            <div class="chart-header" onclick="toggleDashChart('chartBody1','chartToggle1')">
                                <h3>📊 Ingresos por día</h3>
                                <span class="chart-toggle" id="chartToggle1">▼</span>
                            </div>
                            <div class="chart-body" id="chartBody1">
                                <div class="chart-type-selector">
                                    <button class="chart-type-btn active" data-type="ingresos" onclick="switchDashChartType('ingresos')">Monto</button>
                                    <button class="chart-type-btn" data-type="conteo" onclick="switchDashChartType('conteo')">Cantidad</button>
                                </div>
                                <canvas id="dashBarChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-section">
                            <div class="chart-header" onclick="toggleDashChart('chartBody2','chartToggle2')">
                                <h3>🍩 Distribución</h3>
                                <span class="chart-toggle" id="chartToggle2">▼</span>
                            </div>
                            <div class="chart-body" id="chartBody2">
                                <canvas id="dashDonutChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($dashRecentTransactions)): ?>
                    <div class="module-card">
                        <h3>🕐 Transacciones recientes</h3>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Fecha</th>
                                        <th>Cliente</th>
                                        <th>Monto</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dashRecentTransactions as $i => $p): ?>
                                        <tr>
                                            <td class="text-muted"><?= $i + 1 ?></td>
                                            <td class="text-nowrap"><?= htmlspecialchars($p['fecha_actualizacion']) ?></td>
                                            <td><?= htmlspecialchars($p['nombre']) ?></td>
                                            <td class="text-right"><?= $p['monto_formateado'] ?></td>
                                            <td>
                                                <span class="status-badge <?= $p['estado'] === 'Exitoso' ? 'success' : 'failed' ?>">
                                                    <?= $p['estado'] ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============ RASTREO / TRANSACCIONES ============ -->
            <div class="section-panel" id="section-rastreo">
                <div class="container">
                    <?php if (isset($error_api)): ?>
                        <div class="alert alert-error">
                            <strong>Error de conexión con Stripe:</strong>
                            <?= htmlspecialchars($error_api) ?>
                            <br><small>Verifica tu <code>STRIPE_SECRET_KEY</code> en <code>config.php</code>.</small>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;margin-bottom:1rem;">
                        <div class="search-wrapper">
                            <span class="search-icon">🔍</span>
                            <input type="text" id="searchInput" placeholder="Buscar por ID, email, cliente..." oninput="filtrarTabla()">
                        </div>
                        <button class="btn-icon" id="refreshToggle" onclick="toggleRefresh()" title="Auto-refresh">
                            🔄 <span id="refreshStatus" class="refresh-indicator"><span class="dot"></span> 30s</span>
                        </button>
                        <button class="btn-icon" onclick="exportCSV()" title="Exportar CSV">⬇ CSV</button>
                        <span class="text-muted text-sm" style="margin-left:auto;"><?= count($todos) ?> pago(s) cargados</span>
                    </div>

                    <div class="filters-bar">
                        <div class="filter-group">
                            <label>Desde</label>
                            <input type="date" id="filterDateFrom" onchange="filtrarTabla()">
                        </div>
                        <div class="filter-group">
                            <label>Hasta</label>
                            <input type="date" id="filterDateTo" onchange="filtrarTabla()">
                        </div>
                        <div class="filter-group">
                            <label>Estado</label>
                            <select id="filterStatus" onchange="filtrarTabla()">
                                <option value="">Todos</option>
                                <option value="Exitoso">Exitosos</option>
                                <option value="Fallido">Fallidos</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Monto min</label>
                            <input type="number" id="filterAmountMin" placeholder="0" min="0" step="0.01" oninput="filtrarTabla()" style="width:80px;">
                        </div>
                        <div class="filter-group">
                            <label>Monto max</label>
                            <input type="number" id="filterAmountMax" placeholder="99999" min="0" step="0.01" oninput="filtrarTabla()" style="width:80px;">
                        </div>
                        <button class="btn" onclick="limpiarFiltros()" style="margin-left:auto;">Limpiar</button>
                    </div>

                    <div class="tabs">
                        <button class="tab-btn active" data-tab="todos">Todos <span class="badge"><?= $total_pagos ?></span></button>
                        <button class="tab-btn" data-tab="exitosos">Exitosos <span class="badge"><?= $resumen['total_exitosos'] ?></span></button>
                        <button class="tab-btn" data-tab="fallidos">Fallidos <span class="badge"><?= $resumen['total_fallidos'] ?></span></button>
                    </div>

                    <div class="tab-content active" id="tab-todos">
                        <?php renderTabla($todos, 'todos'); ?>
                    </div>
                    <div class="tab-content" id="tab-exitosos">
                        <?php renderTabla($exitosos, 'exitosos'); ?>
                    </div>
                    <div class="tab-content" id="tab-fallidos">
                        <?php renderTabla($fallidos, 'fallidos'); ?>
                    </div>
                </div>
            </div>

            <!-- ============ REPORTE SEMANAL ============ -->
            <div class="section-panel" id="section-reporte">
                <div class="container">
                    <div id="reporteStripeContent">
                        <div class="empty-state">
                            <div class="empty-icon">📅</div>
                            <p>Selecciona una semana para ver el reporte</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ CONFIGURACIÓN ============ -->
            <div class="section-panel" id="section-config">
                <div class="container">
                    <div class="module-card">
                        <h3>⚙️ Configuración</h3>
                        <div class="config-grid">
                            <div class="config-item">
                                <div class="config-label">Usuario</div>
                                <div class="config-value"><?= htmlspecialchars(ADMIN_USER) ?></div>
                            </div>
                            <div class="config-item">
                                <div class="config-label">API Stripe</div>
                                <div class="config-value" style="color:var(--color-success-text);">✓ Configurada</div>
                            </div>
                            <div class="config-item">
                                <div class="config-label">Últimos pagos</div>
                                <div class="config-value"><?= count($todos) ?> mostrados</div>
                            </div>
                            <div class="config-item">
                                <div class="config-label">Moneda</div>
                                <div class="config-value"><?= htmlspecialchars($resumen['moneda'] ?? 'MXN') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="module-card">
                        <h3>🎨 Apariencia</h3>
                        <div class="dark-toggle-row">
                            <span>Modo oscuro</span>
                            <div class="toggle-switch" id="darkToggleSwitch" onclick="toggleDark()"></div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="modal-overlay" id="modalOverlay" onclick="cerrarModalOutside(event)">
        <div class="modal">
            <div class="modal-header">
                <h2 id="modalTitle">Pago</h2>
                <button class="modal-close" onclick="cerrarModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>

    <div class="modal-overlay" id="refundModal" onclick="cerrarModalRefundOutside(event)">
        <div class="modal" style="max-width:420px;">
            <div class="modal-header">
                <h2>Reembolsar Pago</h2>
                <button class="modal-close" onclick="cerrarModalRefund()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="refundForm" onsubmit="return processRefund(event)">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="payment_id" id="refundPaymentId">
                    <input type="hidden" name="charge_id" id="refundChargeId">
                    <div style="margin-bottom:0.75rem;">
                        <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.25rem;">Monto a reembolsar</label>
                        <select id="refundType" onchange="toggleRefundAmount()" style="width:100%;padding:0.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);color:var(--text);">
                            <option value="full">Reembolso total</option>
                            <option value="partial">Reembolso parcial</option>
                        </select>
                    </div>
                    <div id="refundAmountGroup" style="display:none;margin-bottom:0.75rem;">
                        <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.25rem;">Monto</label>
                        <input type="number" name="amount" id="refundAmount" step="0.01" min="0.01" placeholder="0.00" style="width:100%;padding:0.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);color:var(--text);">
                    </div>
                    <div style="margin-bottom:0.75rem;">
                        <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:0.25rem;">Motivo</label>
                        <select name="reason" style="width:100%;padding:0.5rem;border:1px solid var(--border);border-radius:var(--radius-sm);background:var(--bg-card);color:var(--text);">
                            <option value="requested_by_customer">Solicitado por el cliente</option>
                            <option value="duplicate">Duplicado</option>
                            <option value="fraudulent">Fraudulento</option>
                            <option value="expired_uncaptured_charge">Cargo expirado</option>
                        </select>
                    </div>
                    <div style="margin-bottom:0.75rem;font-size:0.75rem;color:var(--text-muted);" id="refundPaymentInfo"></div>
                    <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
                        <button type="button" class="btn" onclick="cerrarModalRefund()">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="refundSubmitBtn">Procesar reembolso</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    let chartInstance = null;
    let refreshInterval = null;
    let refreshSeconds = 30;
    let dashBarInstance = null;
    let dashDonutInstance = null;

    const pageTitles = {
        inicio: '📊 Dashboard',
        rastreo: '📋 Transacciones',
        reporte: '📅 Reporte Semanal',
        config: '⚙️ Configuración'
    };

    // === DASHBOARD CHART DATA ===
    const dashLabels = <?= $dashDailyLabels ?: '[]' ?>;
    const dashAmounts = <?= $dashDailyAmounts ?: '[]' ?>;
    const dashCounts = <?= $dashDailyCounts ?: '[]' ?>;

    // === TRANSACTION DATA ===
    const pagosData = <?= json_encode($todos, JSON_UNESCAPED_UNICODE) ?>;
    const chartLabels = <?= $chart_labels ?>;
    const chartValues = <?= $chart_values ?>;

    // === SIDEBAR ===
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('open');
    }

    function navigateTo(section) {
        document.querySelectorAll('.section-panel').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
        document.getElementById('section-' + section).classList.add('active');
        document.querySelector('.nav-item[data-section="' + section + '"]').classList.add('active');
        document.getElementById('pageTitleText').textContent = pageTitles[section] || section;
        if (section === 'inicio') initDashCharts();
        if (section === 'reporte' && !document.querySelector('#reporteStripeContent .table-wrapper')) {
            loadStripeReporte();
        }
        if (window.innerWidth <= 900) {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('open');
        }
    }

    // === DARK MODE ===
    function toggleDark() {
        document.body.classList.toggle('dark');
        localStorage.setItem('darkMode', document.body.classList.contains('dark'));
        const btn = document.getElementById('darkToggle');
        const sw = document.getElementById('darkToggleSwitch');
        if (document.body.classList.contains('dark')) {
            btn.textContent = '☀️';
            if (sw) sw.classList.add('active');
        } else {
            btn.textContent = '🌙';
            if (sw) sw.classList.remove('active');
        }
    }

    if (localStorage.getItem('darkMode') === 'true') {
        document.body.classList.add('dark');
        document.getElementById('darkToggle').textContent = '☀️';
        const sw = document.getElementById('darkToggleSwitch');
        if (sw) sw.classList.add('active');
    }

    // === TOAST ===
    function toast(msg, type) {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true,
            didOpen: (toast) => { toast.addEventListener('mouseenter', Swal.stopTimer); toast.addEventListener('mouseleave', Swal.resumeTimer); }
        });
        Toast.fire({
            icon: type === 'error' ? 'error' : type === 'success' ? 'success' : 'info',
            title: msg
        });
    }

    // === DASHBOARD CHARTS ===
    function initDashCharts() {
        if (!document.getElementById('dashBarChart')) return;
        if (dashLabels.length === 0) {
            const charts = document.querySelectorAll('#section-inicio .chart-section');
            charts.forEach(function(c) { c.style.display = 'none'; });
            return;
        }
        if (dashBarInstance) { dashBarInstance.destroy(); dashBarInstance = null; }
        if (dashDonutInstance) { dashDonutInstance.destroy(); dashDonutInstance = null; }

        const barCtx = document.getElementById('dashBarChart').getContext('2d');
        dashBarInstance = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: dashLabels,
                datasets: [{
                    label: 'Monto ($)',
                    data: dashAmounts,
                    backgroundColor: 'rgba(99, 102, 241, 0.6)',
                    borderColor: 'rgba(99, 102, 241, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(ctx) { return '$ ' + (ctx.parsed.y / 100).toFixed(2); } } }
                },
                scales: {
                    x: { ticks: { font: { size: 10 } }, grid: { display: false } },
                    y: { ticks: { font: { size: 10 }, callback: function(v) { return '$' + (v / 100).toFixed(0); } } }
                }
            }
        });

        const totalExitosos = <?= $dashStats['total_exitosos'] ?: 0 ?>;
        const totalFallidos = <?= $dashStats['total_fallidos'] ?: 0 ?>;
        const donutCtx = document.getElementById('dashDonutChart').getContext('2d');
        dashDonutInstance = new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: ['Exitosos', 'Fallidos'],
                datasets: [{
                    data: [totalExitosos, totalFallidos],
                    backgroundColor: ['rgba(22, 163, 74, 0.8)', 'rgba(220, 38, 38, 0.8)'],
                    borderColor: ['#16a34a', '#dc2626'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 10 }, padding: 12 } },
                    tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ' + ctx.parsed; } } }
                }
            }
        });
    }

    function irASemana(val) {
        if (val) window.location.href = '?modo=semanal&semana=' + encodeURIComponent(val);
    }

    function aplicarCustomDash() {
        var s = document.getElementById('dashCustomStart');
        var e = document.getElementById('dashCustomEnd');
        if (s && e && s.value && e.value) {
            window.location.href = '?modo=personalizado&start=' + encodeURIComponent(s.value) + '&end=' + encodeURIComponent(e.value);
        }
    }

    function toggleDashChart(bodyId, toggleId) {
        const body = document.getElementById(bodyId);
        const toggle = document.getElementById(toggleId);
        if (body && toggle) {
            body.classList.toggle('hidden');
            toggle.textContent = body.classList.contains('hidden') ? '\u25b6' : '\u25bc';
        }
    }

    function switchDashChartType(type) {
        document.querySelectorAll('#section-inicio .chart-type-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelector('#section-inicio .chart-type-btn[data-type="' + type + '"]')?.classList.add('active');
        if (!dashBarInstance) return;
        if (type === 'ingresos') {
            dashBarInstance.data.datasets[0].label = 'Monto ($)';
            dashBarInstance.data.datasets[0].data = dashAmounts;
        } else {
            dashBarInstance.data.datasets[0].label = 'Cantidad';
            dashBarInstance.data.datasets[0].data = dashCounts;
        }
        dashBarInstance.update();
    }

    // === AUTO-REFRESH ===
    function toggleRefresh() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
            document.getElementById('refreshToggle').classList.remove('active');
            document.querySelector('.refresh-indicator .dot').classList.add('paused');
        } else {
            refreshSeconds = 30;
            refreshInterval = setInterval(function() {
                refreshSeconds--;
                document.querySelector('.refresh-indicator .dot').classList.remove('paused');
                document.getElementById('refreshStatus').innerHTML = '<span class="dot"></span> ' + refreshSeconds + 's';
                if (refreshSeconds <= 0) { location.reload(); }
            }, 1000);
            document.getElementById('refreshToggle').classList.add('active');
        }
    }

    // === SEARCH & FILTER ===
    function filtrarTabla() {
        const q = document.getElementById('searchInput').value.toLowerCase().trim();
        const dateFrom = document.getElementById('filterDateFrom').value;
        const dateTo = document.getElementById('filterDateTo').value;
        const status = document.getElementById('filterStatus').value;
        const amtMin = parseFloat(document.getElementById('filterAmountMin').value) || 0;
        const amtMax = parseFloat(document.getElementById('filterAmountMax').value) || Infinity;

        document.querySelectorAll('.tab-content.active tbody tr').forEach(function(row) {
            const id = row.dataset.id || '';
            const email = row.dataset.email || '';
            const nombre = row.dataset.nombre || '';
            const fecha = row.dataset.fecha || '';
            const estado = row.dataset.estado || '';
            const monto = parseFloat(row.dataset.monto) || 0;
            let show = true;
            if (q && !id.includes(q) && !email.includes(q) && !nombre.includes(q)) show = false;
            if (status && estado !== status) show = false;
            if (monto < amtMin || monto > amtMax) show = false;
            if (dateFrom && fecha < dateFrom) show = false;
            if (dateTo && fecha > dateTo) show = false;
            row.classList.toggle('hidden', !show);
        });

        document.querySelectorAll('.tab-content.active').forEach(function(tab) {
            const visible = tab.querySelector('tbody tr:not(.hidden)');
            const noResults = tab.querySelector('.no-results');
            if (noResults) noResults.style.display = visible ? 'none' : 'block';
        });
    }

    function limpiarFiltros() {
        document.getElementById('searchInput').value = '';
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterAmountMin').value = '';
        document.getElementById('filterAmountMax').value = '';
        filtrarTabla();
    }

    // === COLUMN SORTING ===
    let sortState = { col: null, asc: true };
    function ordenarTabla(col) {
        const tab = document.querySelector('.tab-content.active');
        if (!tab) return;
        const tbody = tab.querySelector('tbody');
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('tr:not(.hidden)'));
        if (rows.length === 0) return;
        if (sortState.col === col) { sortState.asc = !sortState.asc; }
        else { sortState.col = col; sortState.asc = true; }
        document.querySelectorAll('.sort-icon').forEach(function(i) { i.textContent = ''; });
        const icon = document.querySelector('.sort-icon[data-col="' + col + '"]');
        if (icon) icon.textContent = sortState.asc ? '\u25b2' : '\u25bc';
        rows.sort(function(a, b) {
            var va, vb;
            switch (col) {
                case 'fecha': va = a.dataset.fecha || ''; vb = b.dataset.fecha || ''; return sortState.asc ? va.localeCompare(vb) : vb.localeCompare(va);
                case 'cliente': va = a.dataset.nombre || ''; vb = b.dataset.nombre || ''; return sortState.asc ? va.localeCompare(vb) : vb.localeCompare(va);
                case 'monto': va = parseFloat(a.dataset.monto) || 0; vb = parseFloat(b.dataset.monto) || 0; return sortState.asc ? va - vb : vb - va;
                case 'monto_mxn': va = parseFloat(a.dataset.monto_mxn) || 0; vb = parseFloat(b.dataset.monto_mxn) || 0; return sortState.asc ? va - vb : vb - va;
                case 'recibido': va = parseFloat(a.dataset.recibido) || 0; vb = parseFloat(b.dataset.recibido) || 0; return sortState.asc ? va - vb : vb - va;
                case 'comision': va = parseFloat(a.dataset.comision) || 0; vb = parseFloat(b.dataset.comision) || 0; return sortState.asc ? va - vb : vb - va;
                case 'estado': va = a.dataset.estado || ''; vb = b.dataset.estado || ''; return sortState.asc ? va.localeCompare(vb) : vb.localeCompare(va);
                default: return 0;
            }
        });
        rows.forEach(function(r) { tbody.appendChild(r); });
    }

    // === TABS ===
    document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
            document.querySelectorAll('.tab-content').forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
            document.getElementById('tab-' + this.dataset.tab).classList.add('active');
            setTimeout(filtrarTabla, 50);
        });
    });

    // === MODAL ===
    function abrirModal(id) {
        const p = pagosData.find(function(d) { return d.id === id; });
        if (!p) return;
        document.getElementById('modalTitle').textContent = p.id;
        let html = '<div class="detail-grid">';
        const fields = [
            ['ID', p.id],
            ['Estado', '<span class="status-badge ' + (p.estado === 'Exitoso' ? 'success' : 'failed') + '">' + p.estado + '</span>'],
            ['Cliente', p.nombre],
            ['Email', p.email],
            ['Tel\u00e9fono', p.telefono],
            ['Fecha', p.fecha_actualizacion],
            ['Monto', p.monto_formateado],
            ['MXN', p.monto_mxn_formateado || '\u2014'],
            ['Recibido', p.monto_recibido_formateado || '\u2014'],
            ['Comisi\u00f3n', p.comision_formateado || '\u2014'],
            ['Moneda', p.moneda],
            ['Tarjeta', p.card_brand !== '\u2014' ? p.card_brand + ' ****' + p.card_last4 + ' (' + p.card_funding + ')' : (p.metodo_pago || '\u2014')],
            ['Descripci\u00f3n', p.descripcion || '\u2014'],
            ['Cliente Stripe ID', p.cliente_id || '\u2014'],
        ];
        if (p.estado === 'Fallido') {
            fields.push(['Motivo', p.motivo || '\u2014']);
            fields.push(['C\u00f3digo Error', p.error_code || '\u2014']);
            fields.push(['C\u00f3digo Declinaci\u00f3n', p.decline_code || '\u2014']);
            fields.push(['Tipo Error', p.error_tipo || '\u2014']);
        }
        if (p.receipt_url) {
            fields.push(['Recibo', '<a href="' + p.receipt_url + '" target="_blank" class="btn btn-sm">Ver recibo en Stripe</a>']);
        }
        fields.forEach(function(f) {
            html += '<div class="detail-item"><div class="detail-label">' + f[0] + '</div><div class="detail-value">' + f[1] + '</div></div>';
        });
        if (p.metadata && Object.keys(p.metadata).length) {
            html += '<div class="detail-item full"><div class="detail-label">Metadata</div><div class="detail-value"><pre style="font-size:0.75rem;background:var(--bg-code);padding:0.5rem;border-radius:var(--radius-sm);overflow-x:auto;">' + JSON.stringify(p.metadata, null, 2) + '</pre></div></div>';
        }
        html += '</div>';
        if (p.estado === 'Exitoso' && p.charge_id) {
            html += '<div style="margin-top:1rem;text-align:center;"><button class="btn btn-sm" onclick="event.stopPropagation();abrirModalRefund(\'' + p.id + '\')">\u24D8 Reembolsar</button></div>';
        }
        document.getElementById('modalBody').innerHTML = html;
        document.getElementById('modalOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function cerrarModal() {
        document.getElementById('modalOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }

    function cerrarModalOutside(e) {
        if (e.target === e.currentTarget) cerrarModal();
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') cerrarModal();
    });

    document.getElementById('modalOverlay').addEventListener('click', function(e) {
        if (e.target === this) cerrarModal();
    });

    // === STRIPE REFUND ===
    function abrirModalRefund(paymentId) {
        const p = pagosData.find(function(d) { return d.id === paymentId; });
        if (!p || !p.charge_id) { toast('No se puede reembolsar este pago', 'error'); return; }
        document.getElementById('refundPaymentId').value = p.id;
        document.getElementById('refundChargeId').value = p.charge_id;
        document.getElementById('refundPaymentInfo').textContent = 'Pago: ' + p.id + ' | Monto: ' + p.monto_formateado;
        document.getElementById('refundSubmitBtn').disabled = false;
        document.getElementById('refundSubmitBtn').textContent = 'Procesar reembolso';
        document.getElementById('refundForm').reset();
        document.getElementById('refundType').value = 'full';
        document.getElementById('refundAmountGroup').style.display = 'none';
        document.getElementById('refundModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function toggleRefundAmount() {
        const type = document.getElementById('refundType').value;
        document.getElementById('refundAmountGroup').style.display = type === 'partial' ? 'block' : 'none';
    }

    function cerrarModalRefund() {
        document.getElementById('refundModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    function cerrarModalRefundOutside(e) {
        if (e.target === e.currentTarget) cerrarModalRefund();
    }

    function processRefund(e) {
        e.preventDefault();
        const chargeId = document.getElementById('refundChargeId').value;
        const amount = document.getElementById('refundAmount').value;
        const isPartial = document.getElementById('refundType').value === 'partial';
        if (isPartial && (!amount || parseFloat(amount) <= 0)) {
            toast('Ingresa un monto v\u00e1lido para el reembolso parcial', 'error');
            return;
        }
        const submitBtn = document.getElementById('refundSubmitBtn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Procesando...';
        const formData = new FormData(document.getElementById('refundForm'));
        formData.append('charge_id', chargeId);
        formData.append('amount', isPartial ? amount : '0');
        Swal.fire({
            title: '\u00bfReembolsar este pago?',
            text: isPartial ? 'Monto: $' + parseFloat(amount).toFixed(2) : 'Reembolso total',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'S\u00ed, reembolsar',
            cancelButtonText: 'Cancelar'
        }).then(function(result) {
            if (!result.isConfirmed) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Procesar reembolso';
                return;
            }
            fetch('requests/refund_stripe.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': document.querySelector('input[name="csrf_token"]')?.value || '' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status) {
                    toast('Reembolso procesado: $' + data.data.amount + ' ' + data.data.currency, 'success');
                    cerrarModalRefund();
                } else {
                    toast(data.message || 'Error al procesar reembolso', 'error');
                }
            })
            .catch(function() {
                toast('Error de conexi\u00f3n al procesar reembolso', 'error');
            })
            .finally(function() {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Procesar reembolso';
            });
        }).catch(function() {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Procesar reembolso';
        });
        return false;
    }

    // === EXPORT CSV ===
    function toggleMeta(btn) {
        const pre = btn.nextElementSibling;
        if (pre.style.display === 'none') { pre.style.display = 'block'; btn.textContent = 'Ocultar'; }
        else { pre.style.display = 'none'; btn.textContent = 'Ver datos'; }
    }

    function exportCSV() {
        const visible = document.querySelectorAll('.tab-content.active tbody tr:not(.hidden)');
        const ids = [];
        visible.forEach(function(row) {
            if (row.dataset.id) ids.push(row.dataset.id);
        });
        if (ids.length === 0) { Swal.fire({ icon: 'warning', title: 'No hay pagos para exportar.', showConfirmButton: false, timer: 2000 }); return; }
        window.location.href = '?export=csv&ids=' + ids.join(',');
    }

    // === STRIPE REPORTE AJAX ===
    let currentStripeSemana = null;
    let currentStripeModo = 'semanal';
    let currentStripeStart = null;
    let currentStripeEnd = null;

    function loadStripeReporte(semana, modo, start, end) {
        if (semana === 'today') {
            semana = new Date().toISOString().slice(0, 10);
        }
        var params = 'embed=1';
        if (modo === 'personalizado' && start && end) {
            params += '&modo=personalizado&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
            currentStripeModo = 'personalizado';
            currentStripeStart = start;
            currentStripeEnd = end;
        } else {
            params += '&modo=semanal' + (semana ? '&semana=' + encodeURIComponent(semana) : '');
            currentStripeModo = 'semanal';
            currentStripeSemana = semana || new Date().toISOString().slice(0, 10);
        }

        const content = document.getElementById('reporteStripeContent');
        content.innerHTML = '<div class="loading-spinner">⏳ Cargando reporte...</div>';

        fetch('stripe_reporte.php?' + params)
            .then(function(r) { return r.text(); })
            .then(function(html) {
                content.innerHTML = html;

                const dataScript = document.getElementById('reporteStripeData');
                if (dataScript) {
                    try {
                        var parsed = JSON.parse(dataScript.textContent);
                        window._reporteStripeData = parsed.data || parsed;
                        if (parsed.modo) currentStripeModo = parsed.modo;
                        if (parsed.start) currentStripeStart = parsed.start;
                        if (parsed.end) currentStripeEnd = parsed.end;
                    } catch(e) {
                        window._reporteStripeData = [];
                    }
                }

                content.querySelectorAll('.tab-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        content.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
                        content.querySelectorAll('.tab-content').forEach(function(t) { t.classList.remove('active'); });
                        this.classList.add('active');
                        var tab = content.querySelector('#tab-' + this.dataset.tab);
                        if (tab) tab.classList.add('active');
                    });
                });

                content.querySelectorAll('tr[data-id]').forEach(function(row) {
                    row.addEventListener('click', function() {
                        var id = this.dataset.id;
                        var p = window._reporteStripeData ? window._reporteStripeData.find(function(d) { return d.id === id; }) : null;
                        if (p) abrirDetalleModalReporteStripe(p);
                    });
                });

                var picker = content.querySelector('#semanaDatePickerEmbed');
                if (picker) {
                    picker.addEventListener('change', function() { loadStripeReporte(this.value); });
                }
            })
            .catch(function() {
                content.innerHTML = '<div class="alert alert-error">Error al cargar el reporte. Verifica la conexión.</div>';
            });
    }

    function navReporteStripe(dir) {
        if (!currentStripeSemana) {
            loadStripeReporte(new Date().toISOString().slice(0, 10));
            return;
        }
        var d = new Date(currentStripeSemana);
        d.setDate(d.getDate() + (dir * 7));
        loadStripeReporte(d.toISOString().slice(0, 10));
    }

    function toggleModoReporteStripe(modo) {
        if (modo === currentStripeModo) return;
        if (modo === 'personalizado') {
            var end = new Date().toISOString().slice(0, 10);
            var start = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
            loadStripeReporte(null, 'personalizado', start, end);
        } else {
            loadStripeReporte('today');
        }
    }

    function applyCustomRangeStripe() {
        var s, e;
        var content = document.getElementById('reporteStripeContent');
        s = content.querySelector('#customStartEmbed');
        e = content.querySelector('#customEndEmbed');
        if (s && e && s.value && e.value) {
            loadStripeReporte(null, 'personalizado', s.value, e.value);
        }
    }

    function abrirDetalleModalReporteStripe(p) {
        abrirModalStripeReporte(p);
    }

    function abrirModalStripeReporte(p) {
        document.getElementById('modalTitle').textContent = p.id;
        let html = '<div class="detail-grid">';
        const fields = [
            ['ID', p.id],
            ['Estado', '<span class="status-badge ' + (p.estado === 'Exitoso' ? 'success' : 'failed') + '">' + p.estado + '</span>'],
            ['Cliente', p.nombre],
            ['Email', p.email],
            ['Tel\u00e9fono', p.telefono],
            ['Fecha', p.fecha_actualizacion],
            ['Monto', p.monto_formateado],
            ['MXN', p.monto_mxn_formateado || '\u2014'],
            ['Recibido', p.monto_recibido_formateado || '\u2014'],
            ['Comisi\u00f3n', p.comision_formateado || '\u2014'],
            ['Moneda', p.moneda],
            ['Tarjeta', p.card_brand !== '\u2014' ? p.card_brand + ' ****' + p.card_last4 + ' (' + p.card_funding + ')' : (p.metodo_pago || '\u2014')],
            ['Descripci\u00f3n', p.descripcion || '\u2014'],
            ['Cliente Stripe ID', p.cliente_id || '\u2014'],
        ];
        if (p.estado === 'Fallido') {
            fields.push(['Motivo', p.motivo || '\u2014']);
            fields.push(['C\u00f3digo Error', p.error_code || '\u2014']);
            fields.push(['C\u00f3digo Declinaci\u00f3n', p.decline_code || '\u2014']);
            fields.push(['Tipo Error', p.error_tipo || '\u2014']);
        }
        if (p.receipt_url) {
            fields.push(['Recibo', '<a href="' + p.receipt_url + '" target="_blank" class="btn btn-sm">Ver recibo en Stripe</a>']);
        }
        fields.forEach(function(f) {
            html += '<div class="detail-item"><div class="detail-label">' + f[0] + '</div><div class="detail-value">' + f[1] + '</div></div>';
        });
        if (p.metadata && Object.keys(p.metadata).length) {
            html += '<div class="detail-item full"><div class="detail-label">Metadata</div><div class="detail-value"><pre style="font-size:0.75rem;background:var(--bg-code);padding:0.5rem;border-radius:var(--radius-sm);overflow-x:auto;">' + JSON.stringify(p.metadata, null, 2) + '</pre></div></div>';
        }
        html += '</div>';
        if (p.estado === 'Exitoso' && p.charge_id) {
            html += '<div style="margin-top:1rem;text-align:center;"><button class="btn btn-sm" onclick="event.stopPropagation();abrirModalRefund(\'' + p.id + '\')">\u24D8 Reembolsar</button></div>';
        }
        document.getElementById('modalBody').innerHTML = html;
        document.getElementById('modalOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    // === DOM READY ===
    document.addEventListener('DOMContentLoaded', function() {
        initDashCharts();
        setTimeout(filtrarTabla, 100);
    });
    </script>
</body>
</html>

<?php
function renderTabla(array $pagos, string $tab): void
{
    global $has_more, $ultimo_id, $primer_id, $cursor;
    ?>
    <div class="table-wrapper">
        <?php if (empty($pagos)): ?>
            <div class="loading">No hay pagos para mostrar.</div>
        <?php else: ?>
            <div class="no-results">No se encontraron pagos con los filtros actuales.</div>
            <table>
                <thead>
                    <tr>
                        <th onclick="ordenarTabla('fecha')" style="cursor:pointer;">Fecha <span class="sort-icon" data-col="fecha"></span></th>
                        <th onclick="ordenarTabla('cliente')" style="cursor:pointer;">Cliente <span class="sort-icon" data-col="cliente"></span></th>
                        <th>Email / Tel.</th>
                        <?php if ($tab !== 'fallidos'): ?>
                            <th>Tarjeta</th>
                        <?php endif; ?>
                        <th onclick="ordenarTabla('monto')" style="cursor:pointer;">Monto <span class="sort-icon" data-col="monto"></span></th>
                        <th onclick="ordenarTabla('monto_mxn')" style="cursor:pointer;">MXN <span class="sort-icon" data-col="monto_mxn"></span></th>
                        <?php if ($tab !== 'fallidos'): ?>
                            <th onclick="ordenarTabla('recibido')" style="cursor:pointer;">Recibido <span class="sort-icon" data-col="recibido"></span></th>
                            <th onclick="ordenarTabla('comision')" style="cursor:pointer;">Comisión <span class="sort-icon" data-col="comision"></span></th>
                        <?php endif; ?>
                        <th onclick="ordenarTabla('estado')" style="cursor:pointer;">Estado <span class="sort-icon" data-col="estado"></span></th>
                        <?php if ($tab === 'fallidos'): ?>
                            <th>Error</th>
                            <th>Código</th>
                        <?php endif; ?>
                        <th>Datos</th>
                        <th>ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagos as $p): ?>
                        <tr onclick="abrirModal('<?= htmlspecialchars($p['id'], ENT_QUOTES) ?>')"
                            data-id="<?= htmlspecialchars($p['id']) ?>"
                            data-email="<?= htmlspecialchars(strtolower($p['email'])) ?>"
                            data-nombre="<?= htmlspecialchars(strtolower($p['nombre'])) ?>"
                            data-fecha="<?= date('Y-m-d', $p['created_ts']) ?>"
                            data-estado="<?= $p['estado'] ?>"
                             data-monto="<?= $p['monto_decimal'] ?>"
                             data-monto_mxn="<?= $p['monto_mxn'] ?? 0 ?>"
                             data-recibido="<?= $p['monto_recibido_decimal'] ?? 0 ?>"
                             data-comision="<?= $p['comision_decimal'] ?? 0 ?>">
                            <td class="text-nowrap"><?= htmlspecialchars($p['fecha_actualizacion']) ?></td>
                            <td>
                                <?= htmlspecialchars($p['nombre']) ?>
                                <?php if (!empty($p['cliente_id'])): ?>
                                    <br><span class="text-muted" style="font-size:0.65rem;">ID: <?= htmlspecialchars($p['cliente_id']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($p['email']) ?>
                                <?php if ($p['telefono'] !== '—'): ?>
                                    <br><span class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($p['telefono']) ?></span>
                                <?php endif; ?>
                            </td>
                            <?php if ($tab !== 'fallidos'): ?>
                                <td>
                                    <?php if ($p['card_brand'] !== '—'): ?>
                                        <span class="card-badge"><?= htmlspecialchars($p['card_brand']) ?> ****<?= htmlspecialchars($p['card_last4']) ?></span>
                                        <span class="text-muted" style="font-size:0.65rem;">(<?= htmlspecialchars($p['card_funding']) ?>)</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($p['metodo_pago']) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td class="text-right"><?= htmlspecialchars($p['monto_formateado']) ?></td>
                            <td class="text-right"><?= htmlspecialchars($p['monto_mxn_formateado'] ?? '—') ?></td>
                            <?php if ($tab !== 'fallidos'): ?>
                                <td class="text-right"><?= htmlspecialchars($p['monto_recibido_formateado']) ?></td>
                                <td class="text-right text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($p['comision_formateado'] ?? '—') ?></td>
                            <?php endif; ?>
                            <td>
                                <span class="status-badge <?= $p['estado'] === 'Exitoso' ? 'success' : 'failed' ?>">
                                    <?= $p['estado'] ?>
                                </span>
                            </td>
                            <?php if ($tab === 'fallidos'): ?>
                                <td class="text-muted" style="max-width:160px;word-break:break-word;"><?= htmlspecialchars($p['motivo'] ?? '—') ?></td>
                                <td><code class="code-sm"><?= htmlspecialchars($p['error_code'] ?? '—') ?></code></td>
                            <?php endif; ?>
                            <td style="max-width:140px;">
                                <?php if (!empty($p['metadata'])): ?>
                                    <button class="meta-toggle" onclick="event.stopPropagation();toggleMeta(this)">Ver datos</button>
                                    <pre class="meta-content" style="display:none;"><?= htmlspecialchars($p['metadata_txt']) ?></pre>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="payment-id"><?= htmlspecialchars($p['id']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="pagination">
                <div class="pagination-info">
                    Mostrando <?= count($pagos) ?> pago(s)
                </div>
                <div class="pagination-buttons">
                    <?php if ($cursor): ?>
                        <a href="?cursor=<?= htmlspecialchars($primer_id) ?>" class="btn">← Anterior</a>
                    <?php endif; ?>
                    <?php if ($has_more): ?>
                        <a href="?cursor=<?= htmlspecialchars($ultimo_id) ?>" class="btn btn-primary">Siguiente →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

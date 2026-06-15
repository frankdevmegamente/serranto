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

require_once __DIR__ . '/paypal_helper.php';

if (!hasPayPal()): ?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>PayPal — Dashboard</title><link rel="stylesheet" href="assets/style.css"></head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg-body);"><div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:2rem;max-width:420px;width:100%;text-align:center;"><div style="font-size:3rem;margin-bottom:1rem;">🅿️</div><h2 style="margin-bottom:0.5rem;">PayPal no configurado</h2><p style="color:var(--text-muted);margin-bottom:1.5rem;">Este cliente no tiene llaves API de PayPal. Puedes agregarlas desde Configuración si se requieren más adelante.</p><a href="dashboard.php" class="btn">⬅ Volver al inicio</a>&nbsp;<a href="settings.php" class="btn btn-primary">⚙️ Configuración</a></div></body></html>
<?php exit; endif;

// --- Cargar datos del Dashboard Inicio (semana seleccionada o personalizado) ---
$tzMx = new DateTimeZone('America/Mexico_City');
$dashModo = $_GET['modo'] ?? 'semanal';
$dashSemanaRef = $_GET['semana'] ?? null;
$dashCustomStart = $_GET['start'] ?? null;
$dashCustomEnd = $_GET['end'] ?? null;
$dashEsActual = false;

if ($dashModo === 'personalizado' && $dashCustomStart && $dashCustomEnd) {
    $ds = new DateTime($dashCustomStart . ' 00:00:00', $tzMx);
    $ds->setTimezone(new DateTimeZone('UTC'));
    $dashInicioUTC = $ds->format('Y-m-d\TH:i:s\Z');
    $de = new DateTime($dashCustomEnd . ' 23:59:59', $tzMx);
    $de->setTimezone(new DateTimeZone('UTC'));
    $dashFinUTC = $de->format('Y-m-d\TH:i:s\Z');
    $dashInicioDisplay = $ds->format('d/m/Y');
    $dashFinDisplay = $de->format('d/m/Y');
    $dashInicioStr = $dashCustomStart;
    $dashFinStr = $dashCustomEnd;
    $dashRangoDias = (new DateTime($dashCustomStart, $tzMx))->diff(new DateTime($dashCustomEnd, $tzMx))->days + 1;
} else {
    $dashModo = 'semanal';
    $dashWeek = obtenerSemanaPaypal($dashSemanaRef, $tzMx);
    $dashInicioUTC = $dashWeek['inicio_utc'];
    $dashFinUTC = $dashWeek['fin_utc'];
    $dashInicioDisplay = $dashWeek['inicio_display'];
    $dashFinDisplay = $dashWeek['fin_display'];
    $dashInicioStr = $dashWeek['inicio_str'];
    $dashFinStr = $dashWeek['fin_str'];
    $semanaActual = obtenerSemanaPaypal(null, $tzMx);
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
$dashError = null;
$dashStats = [
    'total_completadas' => 0, 'total_reembolsadas' => 0, 'total_pendientes' => 0,
    'total_chargebacks' => 0, 'total_general' => 0, 'monto_total_formateado' => '$ 0.00',
    'monto_reembolsado_formateado' => '$ 0.00', 'monto_chargebacks_formateado' => '$ 0.00',
    'moneda' => 'MXN'
];
$dashDailyLabels = [];
$dashDailyAmounts = [];
$dashDailyCounts = [];
$dashRecentTransactions = [];

try {
    $dashResult = obtenerPagosPaypalPorRango($dashInicioUTC, $dashFinUTC, $dashInicioUTC, $dashFinUTC);
    if ($dashResult['error']) {
        $dashError = $dashResult['error'];
    } else {
        $dashProcesadas = procesarTransaccionesPaypal($dashResult['data']);
        $dashStats = resumenPagosPaypal(
            $dashProcesadas['completadas'],
            $dashProcesadas['reembolsadas'],
            $dashProcesadas['pendientes'],
            $dashProcesadas['chargebacks'],
            $dashProcesadas['conversiones']
        );
        $dashTodos = array_merge(
            $dashProcesadas['completadas'],
            $dashProcesadas['reembolsadas'],
            $dashProcesadas['pendientes'],
            $dashProcesadas['chargebacks'],
            $dashProcesadas['conversiones']
        );
        usort($dashTodos, fn($a, $b) => ($b['initiation_date'] ?? '') <=> ($a['initiation_date'] ?? ''));
        $dashRecentTransactions = array_slice($dashTodos, 0, 8);
        $dashGrouped = agruparPaypalPorDia($dashTodos);
        $dashDailyLabels = json_encode(array_column($dashGrouped, 'fecha'));
        $dashDailyAmounts = json_encode(array_column($dashGrouped, 'total'));
        $dashDailyCounts = json_encode(array_column($dashGrouped, 'conteo'));
    }
} catch (Exception $e) {
    $dashError = $e->getMessage();
}
// --- Fin carga dashboard ---

$pendingTracking = getPendingTracking();
$pendingCount = count(array_filter($pendingTracking, fn($i) => ($i['status'] ?? '') === 'pending'));

$csrfToken = generateCSRFToken();
setSecurityHeaders();
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>PayPal — Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/reporte-semanal.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        :root {
            --sidebar-width: 230px;
        }

        body {
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }

        .app-layout {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

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

        .sidebar-header {
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .sidebar-logo {
            font-size: 1.4rem;
        }

        .sidebar-title {
            font-size: 1rem;
            font-weight: 700;
        }

        .sidebar-nav {
            list-style: none;
            padding: 0.75rem 0.5rem;
            flex: 1;
            overflow-y: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.65rem 0.9rem;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 0.875rem;
            color: rgba(255,255,255,0.7);
            transition: all 0.2s;
            margin-bottom: 0.15rem;
            user-select: none;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }

        .nav-item.active {
            background: rgba(255,255,255,0.12);
            color: #fff;
            font-weight: 600;
        }

        .nav-item .nav-icon {
            font-size: 1rem;
            width: 22px;
            text-align: center;
            flex-shrink: 0;
        }

        .nav-item .nav-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-footer {
            padding: 0.75rem 1rem;
            border-top: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            font-size: 0.8rem;
        }

        .sidebar-footer .user-badge {
            background: rgba(255,255,255,0.1);
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            color: rgba(255,255,255,0.8);
            max-width: 110px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sidebar-footer .logout-link {
            color: rgba(255,255,255,0.5);
            text-decoration: none;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            transition: all 0.2s;
        }

        .sidebar-footer .logout-link:hover {
            color: #fff;
            background: rgba(255,255,255,0.1);
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow-y: auto;
            max-height: 100vh;
        }

        .main-header {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .main-header .page-title {
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .main-header .header-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-panel {
            display: none;
            padding: 1.5rem;
            flex: 1;
        }

        .section-panel.active {
            display: block;
        }

        .section-panel .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .sidebar-toggle-btn {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 1.25rem;
            cursor: pointer;
            padding: 0.25rem;
        }

        .search-box {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1.5rem;
        }

        .search-inputs {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .search-inputs .input-group {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            flex: 1;
            min-width: 150px;
        }

        .search-inputs .input-group label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .search-inputs .input-group input,
        .search-inputs .input-group select {
            background: var(--bg-input);
            border: 1px solid var(--border-input);
            border-radius: var(--radius-sm);
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.2s;
        }

        .search-inputs .input-group input:focus {
            border-color: var(--color-primary);
        }

        .table-actions {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .text-muted { color: var(--text-muted); }
        .font-mono { font-family: var(--font-mono); }
        .text-sm { font-size: 0.75rem; }

        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 99px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-badge.success {
            background: var(--color-success-bg);
            color: var(--color-success-text);
        }

        .status-badge.warning {
            background: #fef3c7;
            color: #d97706;
        }
        body.dark .status-badge.warning {
            background: #78350f;
            color: #fbbf24;
        }

        .status-badge.danger {
            background: var(--color-danger-bg);
            color: var(--color-danger-text);
        }

        .status-badge.info {
            background: #dbeafe;
            color: #2563eb;
        }
        body.dark .status-badge.info {
            background: #1e3a5f;
            color: #93c5fd;
        }

        .currency-badge {
            display: inline-block;
            padding: 0.15rem 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--bg-table-header);
            color: var(--text-primary);
        }

        .refunded-row td {
            background-color: rgba(220, 38, 38, 0.06) !important;
        }
        body.dark .refunded-row td {
            background-color: rgba(220, 38, 38, 0.12) !important;
        }
        .refund-event-row td {
            background-color: rgba(220, 38, 38, 0.03) !important;
        }
        body.dark .refund-event-row td {
            background-color: rgba(220, 38, 38, 0.06) !important;
        }

        .tracking-badge {
            display: inline-block;
            background: var(--bg-badge);
            padding: 0.15rem 0.5rem;
            border-radius: var(--radius-sm);
            font-family: var(--font-mono);
            font-size: 0.7rem;
            color: #0369a1;
        }
        body.dark .tracking-badge {
            color: #7dd3fc;
        }

        .btn-tracking {
            cursor: pointer;
            color: var(--color-primary);
            font-size: 0.8rem;
            text-decoration: none;
        }
        .btn-tracking:hover {
            text-decoration: underline;
        }

        .table-container {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            padding: 1rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }
        .empty-state .empty-icon {
            font-size: 3rem;
            margin-bottom: 0.75rem;
        }
        .empty-state p {
            font-size: 0.9rem;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: var(--bg-modal-overlay);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal {
            background: var(--bg-modal);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            max-width: 500px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            animation: modalIn 0.2s ease-out;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            background: var(--bg-modal);
            z-index: 1;
        }
        .modal-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
            padding: 0.25rem;
            line-height: 1;
        }
        .modal-close:hover {
            color: var(--text-primary);
        }
        .modal-body {
            padding: 1.25rem 1.5rem;
        }
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        .modal-footer .modal-close {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
        }

        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.375rem;
        }
        .form-control {
            width: 100%;
            background: var(--bg-input);
            border: 1px solid var(--border-input);
            border-radius: var(--radius-sm);
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            border-color: var(--color-primary);
        }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.25rem;
        }

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.825rem;
        }
        body.dark .alert-warning {
            background: #78350f;
            color: #fbbf24;
            border-color: #92400e;
        }

        .toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .toast {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            box-shadow: var(--shadow-md);
            animation: toastIn 0.3s ease-out;
            color: #fff;
            min-width: 280px;
        }
        .toast.success { background: #16a34a; }
        .toast.error { background: #dc2626; }
        .toast.info { background: #2563eb; }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .dataTables_wrapper .dataTables_filter input {
            background: var(--bg-input) !important;
            border: 1px solid var(--border-input) !important;
            border-radius: var(--radius-sm) !important;
            padding: 0.4rem 0.6rem !important;
            color: var(--text-primary) !important;
        }

        .dataTables_wrapper .dataTables_length select {
            background: var(--bg-input) !important;
            border: 1px solid var(--border-input) !important;
            border-radius: var(--radius-sm) !important;
            color: var(--text-primary) !important;
            padding: 0.3rem !important;
        }

        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            color: var(--text-secondary) !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            color: var(--text-primary) !important;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--color-primary) !important;
            border-color: var(--color-primary) !important;
            color: #fff !important;
        }
        .dataTables_wrapper table.dataTable thead th {
            background: var(--bg-table-header) !important;
            color: var(--text-secondary) !important;
        }
        table.dataTable tbody td {
            color: var(--text-primary) !important;
        }
        table.dataTable.display tbody tr:hover {
            background: var(--bg-table-hover) !important;
        }
        table.dataTable.display tbody tr:nth-child(even) {
            background: var(--bg-card) !important;
        }
        table.dataTable.display tbody tr:nth-child(odd) {
            background: var(--bg-body) !important;
        }
        table.dataTable.display tbody tr:nth-child(even):hover,
        table.dataTable.display tbody tr:nth-child(odd):hover {
            background: var(--bg-table-hover) !important;
        }

        .loading-spinner {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
        }

        .chart-section {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
            overflow: hidden;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1.25rem;
            cursor: pointer;
            user-select: none;
        }

        .chart-header h3 {
            font-size: 0.9375rem;
            font-weight: 600;
        }

        .chart-toggle {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 0.9rem;
            padding: 0.25rem;
        }

        .chart-body {
            padding: 0 1.25rem 1.25rem;
        }

        .chart-body.hidden {
            display: none;
        }

        .chart-body canvas {
            max-height: 280px;
            width: 100% !important;
        }

        .chart-type-selector {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .chart-type-btn {
            padding: 0.3rem 0.75rem;
            border: 1px solid var(--border);
            background: var(--bg-card);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.75rem;
            color: var(--text-secondary);
            transition: all 0.2s;
        }

        .chart-type-btn:hover {
            border-color: var(--color-primary);
            color: var(--color-primary);
        }

        .chart-type-btn.active {
            background: var(--color-primary);
            color: #fff;
            border-color: var(--color-primary);
        }

        .module-card {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .module-card h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .module-card p {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .config-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .config-item {
            padding: 0.75rem;
            background: var(--bg-table-header);
            border-radius: var(--radius-sm);
        }

        .config-item .config-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .config-item .config-value {
            font-size: 0.875rem;
            font-family: var(--font-mono);
            color: var(--text-primary);
            margin-top: 0.2rem;
            word-break: break-all;
        }

        .dark-toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            background: var(--bg-table-header);
            border-radius: var(--radius-sm);
        }

        .dark-toggle-row span {
            font-weight: 500;
        }

        .toggle-switch {
            position: relative;
            width: 44px;
            height: 24px;
            background: var(--border-input);
            border-radius: 12px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .toggle-switch.active {
            background: var(--color-primary);
        }

        .toggle-switch::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            transition: transform 0.2s;
        }

        .toggle-switch.active::after {
            transform: translateX(20px);
        }

        .tracking-results {
            margin-top: 1rem;
        }

        .tracking-result-card {
            background: var(--bg-table-header);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-bottom: 0.75rem;
        }

        .tracking-result-card .tracking-header {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .tracking-result-card .tracking-detail {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        @media (max-width: 900px) {
            .sidebar {
                position: fixed;
                left: -100%;
                transition: left 0.3s ease;
                z-index: 300;
            }
            .sidebar.open {
                left: 0;
            }
            .sidebar-toggle-btn {
                display: block;
            }
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 250;
            }
            .sidebar-overlay.open {
                display: block;
            }
            .config-grid {
                grid-template-columns: 1fr;
            }
        }

        .modo-toggle {
            display: flex;
            gap: 0.25rem;
            background: var(--bg-body);
            border-radius: var(--radius-md);
            padding: 0.2rem;
            flex: 0 0 auto;
        }
        .modo-btn {
            padding: 0.35rem 0.75rem;
            border: none;
            background: transparent;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.75rem;
            color: var(--text-secondary);
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }
        .modo-btn:hover {
            color: var(--text-primary);
        }
        .modo-btn.active {
            background: var(--bg-card);
            color: var(--text-primary);
            font-weight: 600;
            box-shadow: var(--shadow-sm);
        }
        .nav-mode {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
            flex-wrap: wrap;
        }
        .custom-range-inputs {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex: 1;
            flex-wrap: wrap;
        }
        .custom-range-inputs label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
        }
        .custom-range-inputs input[type="date"] {
            background: var(--bg-input);
            border: 1px solid var(--border-input);
            border-radius: var(--radius-sm);
            padding: 0.35rem 0.5rem;
            font-size: 0.8125rem;
            color: var(--text-primary);
            outline: none;
            max-width: 150px;
        }
        .custom-range-inputs input[type="date"]:focus {
            border-color: var(--color-primary);
        }
        @media (max-width: 768px) {
            .section-panel { padding: 1rem; }
            .search-inputs { flex-direction: column; }
            .search-inputs .input-group { min-width: auto; }
        }

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
                <span class="sidebar-logo">🅿️</span>
                <span class="sidebar-title">PayPal Dashboard</span>
            </div>
            <ul class="sidebar-nav">
                <li class="nav-item active" data-section="inicio" onclick="navigateTo('inicio')">
                    <span class="nav-icon">🏠</span>
                    <span class="nav-text">Inicio</span>
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
                    <span id="pageTitleText">🏠 Inicio</span>
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

            <div class="section-panel active" id="section-inicio">
                <div class="container">

                    <?php if ($dashError): ?>
                        <div class="alert alert-error" style="position:relative;padding-right:2.5rem;">
                            <strong>⚠️ PayPal está teniendo problemas</strong>
                            <br>No se pudieron cargar los datos de PayPal en este momento. Esto es un problema temporal de PayPal, no del sistema.
                            <br>Puedes seguir usando las demás secciones con normalidad.
                            <?php if (str_contains($dashError, 'cURL errno 7') || str_contains($dashError, 'Could not connect')): ?>
                                <br><small style="opacity:0.6;">PayPal rechazó la conexión — intenta más tarde.</small>
                            <?php elseif (str_contains($dashError, '401') || str_contains($dashError, 'Unauthorized')): ?>
                                <br><small style="opacity:0.6;">Las llaves de acceso a PayPal necesitan actualizarse — contacta al administrador.</small>
                            <?php else: ?>
                                <br><small style="opacity:0.6;">Error temporal de PayPal. Intenta de nuevo en unos minutos.</small>
                            <?php endif; ?>
                            <button onclick="this.parentElement.remove()" style="position:absolute;top:0.5rem;right:0.5rem;background:none;border:none;font-size:1.2rem;cursor:pointer;color:inherit;opacity:0.7;">&times;</button>
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
                        <div class="stat-card total">
                            <div class="stat-label">Total Movimientos</div>
                            <div class="stat-value"><?= $dashStats['total_general'] ?></div>
                        </div>
                        <div class="stat-card success">
                            <div class="stat-label">Completadas</div>
                            <div class="stat-value"><?= $dashStats['total_completadas'] ?></div>
                        </div>
                        <div class="stat-card failed">
                            <div class="stat-label">Reembolsadas</div>
                            <div class="stat-value"><?= $dashStats['total_reembolsadas'] ?></div>
                        </div>
                        <div class="stat-card warning">
                            <div class="stat-label">Retiros</div>
                            <div class="stat-value"><?= $dashStats['total_chargebacks'] ?></div>
                        </div>
                        <div class="stat-card currency">
                            <div class="stat-label">Total Recaudado</div>
                            <div class="stat-value" style="font-size:1.25rem;"><?= $dashStats['monto_total_formateado'] ?></div>
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
                                            <td class="text-nowrap"><?= $p['initiation_date'] ? date('d/m/Y H:i', strtotime($p['initiation_date'])) : '—' ?></td>
                                            <td><?= htmlspecialchars($p['payer_name']) ?></td>
                                            <td class="text-right"><span class="currency-badge"><?= htmlspecialchars($p['currency']) ?></span> <?= $p['amount_formatted'] ?></td>
                                            <td>
                                                <span class="status-badge <?= $p['status_label'] === 'Completado' ? 'success' : ($p['is_refunded'] ? 'failed' : 'warning') ?>">
                                                    <?= htmlspecialchars($p['status_label']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($pendingCount > 0): ?>
                    <div class="module-card" id="pendingSection">
                        <h3>📦 Guías pendientes (<span id="pendingCount"><?= $pendingCount ?></span>)</h3>
                        <p style="color:var(--text-muted);">PayPal no está disponible. Estas guías se subirán automáticamente cuando PayPal vuelva.</p>
                        <div style="display:flex;flex-direction:column;gap:0.5rem;">
                            <?php foreach ($pendingTracking as $p): ?>
                                <?php if (($p['status'] ?? '') === 'pending'): ?>
                                <div class="tracking-result-card" style="display:flex;justify-content:space-between;align-items:center;">
                                    <div>
                                        <div class="tracking-header"><?= htmlspecialchars($p['transaction_id']) ?></div>
                                        <div class="tracking-detail"><?= htmlspecialchars($p['carrier']) ?>: <?= htmlspecialchars($p['tracking_number']) ?></div>
                                        <div class="text-muted text-sm">Creado: <?= htmlspecialchars($p['created_at']) ?> | Intentos: <?= (int)($p['attempts'] ?? 0) ?></div>
                                    </div>
                                    <span class="status-badge warning">⏳ Pendiente</span>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <div class="section-panel" id="section-rastreo">
                <div class="container" style="position:relative;">

                    <div class="search-box">
                        <form id="transactionsForm">
                            <div class="search-inputs">
                                <div class="input-group">
                                    <label for="buyer">Comprador</label>
                                    <input type="text" id="buyer" name="buyer" placeholder="Nombre o email..." autocomplete="off">
                                </div>
                                <div class="input-group">
                                    <label for="start">Fecha inicio</label>
                                    <input type="date" id="start" name="start" value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
                                </div>
                                <div class="input-group">
                                    <label for="end">Fecha final</label>
                                    <input type="date" id="end" name="end" value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="input-group" style="flex: 0 0 auto; justify-content: flex-end;">
                                    <button type="submit" class="btn btn-primary" id="searchBtn">🔍 Buscar</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="stats-grid" id="statsGrid" style="display:none;">
                        <div class="stat-card total">
                            <div class="stat-label">Total Transacciones</div>
                            <div class="stat-value" id="txTotalCount">0</div>
                            <div class="stat-sub" id="txTotalBruto" style="font-size:0.7rem;color:var(--text-muted);margin-top:0.2rem;"></div>
                        </div>
                        <div class="stat-card success">
                            <div class="stat-label">Completadas</div>
                            <div class="stat-value" id="txCompletadasCount">0</div>
                            <div class="stat-sub" id="txCompletadasBruto" style="font-size:0.65rem;color:var(--text-muted);margin-top:0.15rem;line-height:1.3;"></div>
                        </div>
                        <div class="stat-card failed">
                            <div class="stat-label">Reembolsadas</div>
                            <div class="stat-value" id="txReembolsadasCount">0</div>
                            <div class="stat-sub" id="txReembolsadasMonto" style="font-size:0.7rem;color:var(--text-muted);margin-top:0.2rem;"></div>
                        </div>
                        <div class="stat-card warning">
                            <div class="stat-label">Pendientes</div>
                            <div class="stat-value" id="txPendientesCount">0</div>
                            <div class="stat-sub" id="txPendientesMonto" style="font-size:0.7rem;color:var(--text-muted);margin-top:0.2rem;"></div>
                        </div>
                        <div class="stat-card" style="background:#dbeafe;color:#1e40af;">
                            <style>
                                body.dark #statsGrid .stat-card:has(#txRetirosCount) {
                                    background: #1e3a5f !important;
                                    color: #93c5fd !important;
                                }
                            </style>
                            <div class="stat-label">Retiros</div>
                            <div class="stat-value" id="txRetirosCount">0</div>
                            <div class="stat-sub" id="txRetirosMonto" style="font-size:0.7rem;color:var(--text-muted);margin-top:0.2rem;"></div>
                        </div>
                        <div class="stat-card currency">
                            <div class="stat-label">Total Neto (Recaudado)</div>
                            <div class="stat-value" id="txNetoAmount" style="font-size:1.1rem;">$0.00</div>
                            <div class="stat-sub" style="font-size:0.65rem;color:var(--text-muted);margin-top:0.2rem;">Completadas - Comisiones = Neto</div>
                        </div>
                    </div>

                    <div class="chart-section">
                        <div class="chart-header" onclick="toggleChart()">
                            <h3>📊 Transacciones por día</h3>
                            <span class="chart-toggle" id="chartToggle">▼</span>
                        </div>
                        <div class="chart-body" id="chartBody">
                            <div class="chart-type-selector">
                                <button class="chart-type-btn active" data-type="ingresos" onclick="switchChartType('ingresos')">Monto</button>
                                <button class="chart-type-btn" data-type="conteo" onclick="switchChartType('conteo')">Cantidad</button>
                            </div>
                            <canvas id="chartCanvas"></canvas>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                        <!-- <div class="module-card" style="margin-bottom:0;">
                            <h3>📦 Agregar rastreo</h3>
                            <form id="trackingFormSection">
                                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end;">
                                    <div style="flex:1;min-width:150px;">
                                        <label for="trackTxnId" style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:0.25rem;">ID Transacción</label>
                                        <input type="text" id="trackTxnId" class="form-control" placeholder="Ej: 3VW12345..." required>
                                    </div>
                                    <div style="flex:1;min-width:120px;">
                                        <label for="trackCarrier" style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:0.25rem;">Paquetería</label>
                                        <select id="trackCarrier" class="form-control" required>
                                            <option value="">Selecciona</option>
                                            <option value="USPS">US Postal Service</option>
                                            <option value="DHL">DHL Express</option>
                                            <option value="FEDEX">FedEx</option>
                                            <option value="UPS">UPS</option>
                                            <option value="ONTRAC">OnTrac</option>
                                            <option value="SEUR">SEUR</option>
                                            <option value="GLS">GLS</option>
                                            <option value="OTHER">Otro</option>
                                        </select>
                                    </div>
                                    <div style="flex:2;min-width:180px;">
                                        <label for="trackNumberSection" style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:0.25rem;">Número de rastreo</label>
                                        <input type="text" id="trackNumberSection" class="form-control" placeholder="Ej: 1Z999AA10123456784" required>
                                    </div>
                                    <div style="flex:0 0 auto;">
                                        <button type="submit" class="btn btn-primary" id="trackSectionBtn">📦 Guardar</button>
                                    </div>
                                </div>
                            </form>
                        </div> -->
                        <!-- <div class="module-card" style="margin-bottom:0;">
                            <h3>🔍 Consultar rastreo</h3>
                            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:flex-end;">
                                <div style="flex:1;min-width:250px;">
                                    <label for="checkTxnId" style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:0.25rem;">ID Transacción o Número de guía</label>
                                    <input type="text" id="checkTxnId" class="form-control" placeholder="Ingresa ID de transacción o número de guía">
                                </div>
                                <div style="display:flex;gap:0.5rem;">
                                    <button class="btn btn-primary" id="checkTrackingBtn">🔍 Consultar</button>
                                </div>
                            </div>
                            <div class="tracking-results" id="trackingResults"></div>
                        </div> -->
                    </div>

                    <div class="table-actions">
                        <button class="btn btn-primary" id="exportCsvBtn" disabled>📥 Exportar CSV</button>
                        <button class="btn" id="exportTrackingReportBtn" disabled>📦 Reporte de Guías</button>
                        <span class="text-muted text-sm" id="resultInfo"></span>
                    </div>

                    <!-- Loading overlay -->
                    <div id="rastreoLoadingOverlay" style="display:none;position:absolute;inset:0;z-index:100;align-items:center;justify-content:center;border-radius:var(--radius-md);pointer-events:none;">
                        <div style="background:var(--bg-card);padding:1.5rem 2.5rem;border-radius:var(--radius-lg);box-shadow:0 20px 60px rgba(0,0,0,0.3);text-align:center;pointer-events:auto;animation:modalIn 0.25s ease-out;">
                            <div style="font-size:2.5rem;margin-bottom:0.5rem;">⏳</div>
                            <div id="overlayTitle" style="font-weight:700;font-size:1rem;color:var(--text-primary);">Cargando transacciones...</div>
                            <div id="overlaySubtext" style="font-size:0.8rem;color:var(--text-muted);margin-top:0.3rem;">Consultando PayPal API</div>
                            <div style="margin-top:0.75rem;width:120px;height:3px;background:var(--border);border-radius:2px;overflow:hidden;margin-left:auto;margin-right:auto;">
                                <div style="width:30%;height:100%;background:var(--color-primary);border-radius:2px;animation:loadingBar 1.2s ease-in-out infinite;"></div>
                            </div>
                        </div>
                    </div>
                    <style>
                        @keyframes loadingBar {
                            0% { transform: translateX(-100%); }
                            100% { transform: translateX(400%); }
                        }
                    </style>

                    <div class="table-container" style="margin-bottom:1.5rem;">
                        <table id="transactionsTable" class="display" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Transacción</th>
                                    <th>Cliente / Monto</th>
                                    <th>Moneda</th>
                                    <th>Fecha</th>
                                    <th>Estado</th>
                                    <th>Rastreo</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="transactionsBody">
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <div class="empty-icon">📋</div>
                                            <p>Realiza una búsqueda para ver transacciones</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>

            <div class="section-panel" id="section-reporte">
                <div class="container">
                    <div id="reporteContent">
                        <div class="empty-state">
                            <div class="empty-icon">📅</div>
                            <p>Selecciona una semana para ver el reporte</p>
                        </div>
                    </div>
                </div>
            </div>

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
                                <div class="config-label">Client ID</div>
                                <div class="config-value" id="clientIdDisplay">••••••••</div>
                            </div>
                            <div class="config-item">
                                <div class="config-label">API URL</div>
                                <div class="config-value"><?= htmlspecialchars(PAYPAL_API_URL) ?></div>
                            </div>
                            <div class="config-item">
                                <div class="config-label">Token caché</div>
                                <div class="config-value" id="tokenCacheStatus">Verificando...</div>
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
                    <div class="module-card">
                        <h3>🔄 Mantenimiento</h3>
                        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                            <button class="btn" id="regenerateTokenBtn">🔄 Regenerar token API</button>
                            <button class="btn" style="color:var(--color-danger);border-color:var(--color-danger);" id="showClientIdBtn">👁️ Mostrar Client ID</button>
                        </div>
                        <div id="tokenResult" style="margin-top:0.75rem;"></div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="modal-overlay" id="trackingModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="trackingModalTitle">Agregar Rastreo</h3>
                <button class="modal-close" onclick="cerrarModal('trackingModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="trackingForm">
                    <input type="hidden" name="transaction_id" id="trackingTransactionId">
                    <div id="trackingTxnInfo" style="background:var(--bg-table-hover);padding:0.75rem;border-radius:var(--radius-md);margin-bottom:1rem;display:none;"></div>
                    <div class="form-group">
                        <label for="carrier">Paquetería</label>
                        <select id="carrier" class="form-control" required>
                            <option value="">Selecciona una opción</option>
                            <option value="USPS">US Postal Service</option>
                            <option value="DHL">DHL Express</option>
                            <option value="FEDEX">FedEx</option>
                            <option value="UPS">UPS</option>
                            <option value="ONTRAC">OnTrac</option>
                            <option value="CANADA_POST">Canada Post</option>
                            <option value="ROYAL_MAIL">Royal Mail</option>
                            <option value="AUSTRALIA_POST">Australia Post</option>
                            <option value="SEUR">SEUR</option>
                            <option value="GLS">General Logistics Systems</option>
                            <option value="CHRONOPOST">Chronopost</option>
                            <option value="LA_POSTE">La Poste</option>
                            <option value="TNT">TNT</option>
                            <option value="ARAMEX">Aramex</option>
                            <option value="HERMES_LOGISTICS">Hermes</option>
                            <option value="OTHER">Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="trackNumber">Número de rastreo</label>
                        <input type="text" id="trackNumber" class="form-control" placeholder="Ej: 1Z999AA10123456784" required>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn" onclick="cerrarModal('trackingModal')">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="refundModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="refundModalTitle">Procesar Reembolso</h3>
                <button class="modal-close" onclick="cerrarModal('refundModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="refundForm">
                    <input type="hidden" name="capture_id" id="refundCaptureId">
                    <input type="hidden" name="currency" id="refundCurrency">
                    <input type="hidden" name="max_amount" id="refundMaxAmount">
                    <div class="form-group">
                        <label>Cliente</label>
                        <p id="refundPayerName" style="font-size: 1.1rem; font-weight: 600;">—</p>
                    </div>
                    <div class="form-group">
                        <label>Monto original</label>
                        <p id="refundOriginalAmount" style="font-size: 1.25rem; font-weight: 600;">$0.00</p>
                    </div>
                    <div id="refundExistingInfo" style="display:none;"></div>
                    <div class="form-group">
                        <label for="refundType">Tipo de reembolso</label>
                        <select id="refundType" class="form-control" required>
                            <option value="full">Reembolso total</option>
                            <option value="partial">Reembolso parcial</option>
                        </select>
                    </div>
                    <div class="form-group" id="partialAmountGroup" style="display:none;">
                        <label for="refundAmount">Monto a reembolsar</label>
                        <input type="number" id="refundAmount" class="form-control" step="0.01" min="0.01" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label for="refundReason">Motivo</label>
                        <input type="text" id="refundReason" class="form-control" value="Solicitud del cliente" maxlength="255">
                    </div>
                    <div class="alert-warning">⚠️ Esta acción no se puede deshacer. El reembolso se procesará inmediatamente.</div>
                    <div class="form-actions">
                        <button type="button" class="btn" onclick="cerrarModal('refundModal')">Cancelar</button>
                        <button type="submit" class="btn" style="background:var(--color-danger);color:#fff;border-color:var(--color-danger);">💸 Procesar reembolso</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="refundDetailsModal">
        <div class="modal">
            <div class="modal-header">
                <h3>📄 Detalles del reembolso</h3>
                <button class="modal-close" onclick="cerrarModal('refundDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="refundDetailsBody">
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-close btn" onclick="cerrarModal('refundDetailsModal')">Cerrar</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="detailModal">
        <div class="modal" style="max-width:650px;">
            <div class="modal-header">
                <h3 id="detailModalTitle">Detalle de transacción</h3>
                <button class="modal-close" onclick="cerrarModal('detailModal')">&times;</button>
            </div>
            <div class="modal-body" id="detailModalBody">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="cerrarModal('detailModal')">Cerrar</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="viewTrackingModal">
        <div class="modal" style="max-width:550px;">
            <div class="modal-header">
                <h3 id="viewTrackingTitle">Rastreo</h3>
                <button class="modal-close" onclick="cerrarModal('viewTrackingModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewTrackingBody">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="cerrarModal('viewTrackingModal')">Cerrar</button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    $.ajaxPrefilter(function(options, originalOptions, jqXHR) {
        if (options.type === 'POST' || options.method === 'POST') {
            if (options.data instanceof FormData) {
                options.data.append('csrf_token', csrfToken);
            } else if (typeof options.data === 'string') {
                options.data += '&csrf_token=' + encodeURIComponent(csrfToken);
            } else if (options.data) {
                options.data.csrf_token = csrfToken;
            } else {
                options.data = { csrf_token: csrfToken };
            }
        }
    });

    let transactionsData = [];
    let dataTable = null;
    let chartInstance = null;
    let chartLabels = [];
    let chartAmounts = [];
    let chartCounts = [];
    let dashBarInstance = null;
    let dashDonutInstance = null;
    let rastreoLoaded = false;
    let rastreoLoading = false;
    let trackingLoading = false;
    let pendingCount = <?= $pendingCount ?>;
    let heartbeatInterval = null;

    const pageTitles = {
        inicio: '📊 Dashboard',
        rastreo: '📋 Transacciones',
        reporte: '📅 Reporte Semanal',
        config: '⚙️ Configuración'
    };

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

        if (section === 'reporte' && !document.querySelector('#reporteContent .table-wrapper')) {
            loadReporte();
        }
        if (section === 'inicio') {
            initDashCharts();
        }
        if (section === 'rastreo' && !rastreoLoaded && !rastreoLoading) {
            rastreoLoading = true;
            submitTransactionsForm();
        }

        if (window.innerWidth <= 900) {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('open');
        }
    }

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

    function abrirModal(id) {
        document.getElementById(id).classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function cerrarModal(id) {
        document.getElementById(id).classList.remove('active');
        document.body.style.overflow = '';
    }

    function submitTransactionsForm() {
        var form = document.getElementById('transactionsForm');
        if (form.requestSubmit) {
            form.requestSubmit();
        } else {
            var ev = new Event('submit', { cancelable: true, bubbles: true });
            form.dispatchEvent(ev);
        }
    }

    document.getElementById('trackingModal').addEventListener('click', function(e) {
        if (e.target === this) cerrarModal('trackingModal');
    });
    document.getElementById('refundModal').addEventListener('click', function(e) {
        if (e.target === this) cerrarModal('refundModal');
    });
    document.getElementById('refundDetailsModal').addEventListener('click', function(e) {
        if (e.target === this) cerrarModal('refundDetailsModal');
    });
    document.getElementById('detailModal').addEventListener('click', function(e) {
        if (e.target === this) cerrarModal('detailModal');
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(function(m) {
                m.classList.remove('active');
            });
            document.body.style.overflow = '';
        }
    });

    document.getElementById('refundType').addEventListener('change', function() {
        document.getElementById('partialAmountGroup').style.display = this.value === 'partial' ? 'block' : 'none';
    });

    function openTrackingModal(txnId) {
        document.getElementById('trackingTransactionId').value = txnId;
        document.getElementById('trackingModalTitle').textContent = 'Agregar Rastreo - ' + txnId;
        document.getElementById('carrier').value = '';
        document.getElementById('trackNumber').value = '';
        var infoDiv = document.getElementById('trackingTxnInfo');
        var txn = transactionsData.find(function(t) {
            return t.transaction_info && t.transaction_info.transaction_id === txnId;
        });
        if (txn) {
            var payer = txn.payer_info || {};
            var name = (payer.payer_name && (payer.payer_name.alternate_full_name || (payer.payer_name.given_name && payer.payer_name.surname ? payer.payer_name.given_name + ' ' + payer.payer_name.surname : ''))) || payer.email_address || '\u2014';
            var info = txn.transaction_info || {};
            var amount = info.transaction_amount && info.transaction_amount.value ? info.transaction_amount.value : '0';
            var currency = info.transaction_amount && info.transaction_amount.currency_code ? info.transaction_amount.currency_code : 'MXN';
            infoDiv.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;"><div><strong style="font-size:0.9rem;">\ud83d\udc64 ' + name + '</strong></div><div><span style="font-weight:700;font-size:1rem;color:var(--color-primary);">' + formatoMoneda(amount, currency) + '</span></div></div>';
            infoDiv.style.display = 'block';
        } else {
            infoDiv.style.display = 'none';
        }
        abrirModal('trackingModal');
    }

    function viewTracking(txnId) {
        const body = document.getElementById('viewTrackingBody');
        body.innerHTML = '<div style="text-align:center;padding:2rem;"><div class="loading-spinner">\u23f3 Consultando PayPal...</div></div>';
        document.getElementById('viewTrackingTitle').textContent = 'Rastreo - ' + txnId;
        abrirModal('viewTrackingModal');
        $.ajax({
            url: 'requests/check_tracking.php',
            method: 'POST',
            data: { value: txnId },
            dataType: 'json',
            success: function(res) {
                if (res.status && Array.isArray(res.tracking) && res.tracking.length) {
                    let html = '';
                    res.tracking.forEach(function(t) {
                        html += '<div class="tracking-result-card" style="margin-bottom:0.75rem;">';
                        html += '<div class="tracking-header" style="background:var(--color-success-bg, #dcfce7);color:var(--color-success, #16a34a);">\u2705 En PayPal</div>';
                        html += '<div class="tracking-detail">';
                        html += '<div class="detail-grid">';
                        html += '<div class="detail-item"><div class="detail-label">Paqueter\u00eda</div><div class="detail-value">' + (t.carrier || '\u2014') + '</div></div>';
                        html += '<div class="detail-item"><div class="detail-label">N\u00famero de gu\u00eda</div><div class="detail-value font-mono text-sm">' + (t.tracking_number || '\u2014') + '</div></div>';
                        html += '<div class="detail-item"><div class="detail-label">Estado</div><div class="detail-value">' + (t.status || 'Desconocido') + '</div></div>';
                        html += '<div class="detail-item"><div class="detail-label">ID Transacci\u00f3n</div><div class="detail-value font-mono text-sm">' + txnId + '</div></div>';
                        if (t.shipment_direction) html += '<div class="detail-item"><div class="detail-label">Direcci\u00f3n</div><div class="detail-value">' + t.shipment_direction + '</div></div>';
                        if (t.shipment_create_date) html += '<div class="detail-item"><div class="detail-label">Fecha de creaci\u00f3n</div><div class="detail-value">' + t.shipment_create_date + '</div></div>';
                        if (t.shipment_update_date) html += '<div class="detail-item"><div class="detail-label">\u00daltima actualizaci\u00f3n</div><div class="detail-value">' + t.shipment_update_date + '</div></div>';
                        if (t.notification_payer_email) html += '<div class="detail-item"><div class="detail-label">Notificar a</div><div class="detail-value">' + t.notification_payer_email + '</div></div>';
                        html += '</div></div></div>';

                    });
                    body.innerHTML = html;
                } else if (res.message) {
                    body.innerHTML = '<div class="tracking-result-card"><div class="tracking-header" style="background:var(--color-danger-bg, #fee2e2);color:var(--color-danger, #dc2626);">\u274c Error</div><div class="tracking-detail">' + res.message + '</div></div>';
                } else {
                    body.innerHTML = '<div class="tracking-result-card"><div class="tracking-header" style="background:var(--color-warning-bg, #fef3c7);color:var(--color-warning, #d97706);">\u26a0\ufe0f Sin rastreo en PayPal</div><div class="tracking-detail">No hay informaci\u00f3n de rastreo asociada a esta transacci\u00f3n en PayPal.</div></div>';
                }
            },
            error: function(xhr) {
                let msg = 'Error de conexi\u00f3n';
                try { var r = JSON.parse(xhr.responseText); msg = r.message || r.error || msg; } catch(e) {}
                body.innerHTML = '<div class="tracking-result-card"><div class="tracking-header" style="background:var(--color-danger-bg, #fee2e2);color:var(--color-danger, #dc2626);">\u274c ' + msg + '</div></div>';
            }
        });
    }

    function openRefundModal(txn) {
        document.getElementById('refundCaptureId').value = txn.capture_id;
        document.getElementById('refundCurrency').value = txn.currency;
        document.getElementById('refundMaxAmount').value = txn.amount;
        document.getElementById('refundOriginalAmount').textContent = txn.amount_formatted;
        document.getElementById('refundPayerName').textContent = txn.payer_name || '—';
        document.getElementById('refundModalTitle').textContent = 'Reembolsar - ' + (txn.transaction_id || txn.id);
        document.getElementById('refundType').value = 'full';
        document.getElementById('partialAmountGroup').style.display = 'none';
        document.getElementById('refundAmount').value = '';
        document.getElementById('refundReason').value = 'Solicitud del cliente';
        // Mostrar info de reembolso existente si aplica
        var existingInfo = document.getElementById('refundExistingInfo');
        var ri = txn.refund_info || {};
        if (ri.is_refunded && ri.amount_refunded) {
            var reembolsado = formatoMoneda(ri.amount_refunded, txn.currency || 'MXN');
            var restante = parseFloat(txn.amount) - parseFloat(ri.amount_refunded);
            existingInfo.innerHTML = '<div style="background:var(--color-warning-bg, #fef3c7);padding:0.65rem 0.75rem;border-radius:var(--radius-md);margin-bottom:0.75rem;font-size:0.85rem;"><strong> Ya reembolsado anteriormente:</strong> ' + reembolsado + '<br><strong> Restante:</strong> ' + formatoMoneda(restante, txn.currency || 'MXN') + '</div>';
            existingInfo.style.display = 'block';
        } else {
            existingInfo.style.display = 'none';
        }
        abrirModal('refundModal');
    }

    function openRefundDetails(txn) {
        const ri = txn.refund_info || {};
        const body = document.getElementById('refundDetailsBody');
        let html = '<div class="detail-grid">';

        // Seccion: pago original
        html += '<div class="detail-section-label" style="grid-column:1/-1;font-weight:700;font-size:0.85rem;color:var(--text-primary);border-bottom:1px solid var(--border);padding-bottom:0.25rem;margin-bottom:0.25rem;">Pago original</div>';
        html += '<div class="detail-item"><div class="detail-label">Transacci\u00f3n</div><div class="detail-value font-mono text-sm">' + (txn.transaction_id || '\u2014') + '</div></div>';
        html += '<div class="detail-item"><div class="detail-label">Cliente</div><div class="detail-value">' + (txn.payer_name || '\u2014') + '</div></div>';
        html += '<div class="detail-item"><div class="detail-label">Monto</div><div class="detail-value">' + (txn.amount_formatted || '\u2014') + '</div></div>';
        html += '<div class="detail-item"><div class="detail-label">Moneda</div><div class="detail-value">' + (txn.currency || '\u2014') + '</div></div>';
        html += '<div class="detail-item"><div class="detail-label">Fecha</div><div class="detail-value">' + (txn.initiation_date ? new Date(txn.initiation_date).toLocaleString('es-MX', { timeZone: 'Etc/GMT+7' }) : '\u2014') + '</div></div>';

        // Seccion: reembolso
        html += '<div class="detail-section-label" style="grid-column:1/-1;font-weight:700;font-size:0.85rem;color:var(--color-danger);border-bottom:1px solid var(--border);padding-bottom:0.25rem;margin-bottom:0.25rem;margin-top:0.5rem;">Reembolso</div>';
        if (ri.refund_id) {
            html += '<div class="detail-item"><div class="detail-label">ID Reembolso</div><div class="detail-value font-mono text-sm">' + ri.refund_id + '</div></div>';
            html += '<div class="detail-item"><div class="detail-label">Tipo</div><div class="detail-value">' + (ri.refund_type === 'full' ? 'Total' : 'Parcial') + '</div></div>';
            html += '<div class="detail-item"><div class="detail-label">Monto reembolsado</div><div class="detail-value">' + (ri.amount_refunded ? formatoMoneda(ri.amount_refunded, txn.currency || 'MXN') : '\u2014') + '</div></div>';
            var resto = null;
            if (txn.amount && ri.amount_refunded) {
                resto = parseFloat(txn.amount) - parseFloat(ri.amount_refunded);
            }
            if (resto !== null && resto > 0) {
                html += '<div class="detail-item" style="border-top:1px dashed var(--border);padding-top:0.5rem;"><div class="detail-label" style="font-weight:700;">Saldo restante</div><div class="detail-value" style="font-weight:700;color:var(--color-success-text);">' + formatoMoneda(resto, txn.currency || 'MXN') + '</div></div>';
            }
            html += '<div class="detail-item"><div class="detail-label">Fecha reembolso</div><div class="detail-value">' + (ri.refund_date ? new Date(ri.refund_date).toLocaleString('es-MX', { timeZone: 'Etc/GMT+7' }) : '\u2014') + '</div></div>';
        } else {
            html += '<div class="detail-item" style="grid-column:1/-1;"><p class="text-muted">No hay informaci\u00f3n detallada del reembolso disponible.</p></div>';
        }

        html += '</div>';
        body.innerHTML = html;
        abrirModal('refundDetailsModal');
    }

    function renderActions(txn) {
        const info = txn.transaction_info || {};
        const id = info.transaction_id || '';
        const capId = txn.capture_id || '';
        const isRefunded = txn.refund_info && txn.refund_info.is_refunded;
        const amt = info.transaction_amount && info.transaction_amount.value || 0;
        const cur = info.transaction_amount && info.transaction_amount.currency_code || 'MXN';
        const amtFormatted = formatoMoneda(amt, cur);
        const payer = txn.payer_info || {};
        const nameFromPayer = payer.payer_name
            ? (payer.payer_name.alternate_full_name || (payer.payer_name.given_name && payer.payer_name.surname ? payer.payer_name.given_name + ' ' + payer.payer_name.surname : '') || '')
            : '';
        const payerName = nameFromPayer || payer.email_address || '';
        const initDate = info.transaction_initiation_date || '';
        var trackers = txn.trackers || [];
        let btns = '<div style="display:flex;gap:4px;flex-wrap:wrap;">';
        if (trackers.length === 0) {
            btns += '<button class="btn-icon" style="font-size:0.7rem;padding:0.25rem 0.5rem;color:var(--color-primary);border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;" onclick="event.stopPropagation();openTrackingModal(\'' + id + '\')" title="Agregar rastreo">📦 Rastreo</button>';
        } else {
            btns += '<button class="btn-icon" style="font-size:0.7rem;padding:0.25rem 0.5rem;color:var(--color-success);border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;" onclick="event.stopPropagation();viewTracking(\'' + id + '\')" title="Ver rastreo en PayPal">🔍 Ver rastreo</button>';
        }
        if (capId && (!isRefunded || (isRefunded && txn.refund_info && txn.refund_info.refund_type === 'partial'))) {
            btns += '<button class="btn-icon" style="font-size:0.7rem;padding:0.25rem 0.5rem;color:var(--color-danger);border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;" onclick=\'event.stopPropagation();openRefundModal(' + JSON.stringify({transaction_id: id, capture_id: capId, currency: cur, amount: amt, amount_formatted: amtFormatted, payer_name: payerName, refund_info: txn.refund_info}) + ')\' title="Reembolsar">💸 Reembolso</button>';
        }
        if (isRefunded && !isRefundEvent(txn)) {
            btns += '<button class="btn-icon" style="font-size:0.7rem;padding:0.25rem 0.5rem;color:var(--text-secondary);border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;" onclick=\'event.stopPropagation();openRefundDetails(' + JSON.stringify({transaction_id: id, payer_name: payerName, amount: amt, amount_formatted: amtFormatted, currency: cur, initiation_date: initDate, refund_info: txn.refund_info}) + ')\' title="Ver reembolso">📋 Reembolso</button>';
        }
        btns += '</div>';
        return btns;
    }

    function renderTracking(txn) {
        const trackers = txn.trackers || [];
        if (trackingLoading && trackers.length === 0) return '<span class="text-muted text-sm">\u23f3 Cargando gu\u00edas...</span>';
        if (trackers.length === 0) return '<span class="text-muted text-sm">\u2014</span>';
        return trackers.map(function(t) {
            return '<span class="tracking-badge">' + (t.carrier || '') + ': ' + (t.tracking_number || '') + '</span>';
        }).join(' ');
    }

    function isRefundEvent(txn) {
        const ec = (txn.transaction_info && txn.transaction_info.transaction_event_code) || '';
        return ec === 'T110' || ec === 'T120';
    }

    function renderStatus(txn) {
        const info = txn.transaction_info || {};
        const eventCode = info.transaction_event_code || '';
        if (eventCode === 'T0403') return '<span class="status-badge warning">Retiro</span>';
        if (isRefundEvent(txn)) return '<span class="status-badge danger">Reembolso</span>';
        const status = info.transaction_status || '';
        const isRefunded = txn.refund_info && txn.refund_info.is_refunded;
        if (isRefunded) {
            const type = txn.refund_info.refund_type;
            return '<span class="status-badge danger">' + (type === 'partial' ? 'Parcialmente reembolsado' : 'Reembolsado') + '</span>';
        }
        switch (status) {
            case 'S': return '<span class="status-badge success">Completado</span>';
            case 'P': return '<span class="status-badge warning">Pendiente</span>';
            case 'H': return '<span class="status-badge info">Retenido</span>';
            case 'V': return '<span class="status-badge success">Revisado</span>';
            default: return '<span class="status-badge" style="background:var(--border);color:var(--text-secondary)">' + (status || eventCode || '\u2014') + '</span>';
        }
    }

    function renderClienteMonto(txn) {
        const payer = txn.payer_info || {};
        const nameFromPayer = payer.payer_name
            ? (payer.payer_name.alternate_full_name || (payer.payer_name.given_name && payer.payer_name.surname ? payer.payer_name.given_name + ' ' + payer.payer_name.surname : '') || '')
            : '';
        const name = nameFromPayer || payer.email_address || '\u2014';
        const info = txn.transaction_info || {};
        const amount = info.transaction_amount && info.transaction_amount.value ? info.transaction_amount.value : '0';
        const currency = info.transaction_amount && info.transaction_amount.currency_code ? info.transaction_amount.currency_code : 'MXN';
        return '<div><strong>' + name + '</strong><br><span class="text-muted text-sm">' + formatoMoneda(amount, currency) + '</span></div>';
    }

    function formatoMoneda(amount, currency) {
        const simb = { MXN: '$', USD: '$', EUR: '\u20ac', GBP: '\u00a3', CAD: 'C$', AUD: 'A$' };
        return (simb[currency] || '$') + ' ' + parseFloat(amount).toFixed(2);
    }

    function loadTrackingBatch() {
        const ids = transactionsData.map(function(t) {
            return t.transaction_info ? t.transaction_info.transaction_id : '';
        }).filter(Boolean);
        if (ids.length === 0) {
            var ov = document.getElementById('rastreoLoadingOverlay');
            if (ov) ov.style.display = 'none';
            return;
        }
        trackingLoading = true;
        var overlayTitle = document.getElementById('overlayTitle');
        var overlaySubtext = document.getElementById('overlaySubtext');
        if (overlayTitle) overlayTitle.textContent = 'Cargando gu\u00edas de rastreo...';
        if (overlaySubtext) overlaySubtext.textContent = 'Consultando tracking en PayPal';
        if (dataTable) {
            dataTable.destroy();
            dataTable = null;
            initTable(transactionsData);
        }
        var chunkSize = 25;
        var chunks = [];
        for (var i = 0; i < ids.length; i += chunkSize) {
            chunks.push(ids.slice(i, i + chunkSize));
        }
        var totalChunks = chunks.length;
        var processedChunks = 0;
        var allTracking = {};
        function processNextChunk() {
            if (processedChunks >= totalChunks) {
                transactionsData.forEach(function(t) {
                    var tid = t.transaction_info ? t.transaction_info.transaction_id : '';
                    t.trackers = allTracking[tid] ? allTracking[tid].trackers : [];
                });
                trackingLoading = false;
                if (dataTable) {
                    dataTable.destroy();
                    dataTable = null;
                    initTable(transactionsData);
                }
                var ov = document.getElementById('rastreoLoadingOverlay');
                if (ov) ov.style.display = 'none';
                return;
            }
            var chunk = chunks[processedChunks];
            if (overlaySubtext) overlaySubtext.textContent = 'Consultando gu\u00edas... ' + (processedChunks * chunkSize) + ' de ' + ids.length;
            $.ajax({
                url: 'requests/check_tracking_batch.php',
                method: 'POST',
                data: { transaction_ids: chunk.join(',') },
                dataType: 'json',
                success: function(trackRes) {
                    if (trackRes.notice) {
                        toast(trackRes.notice, 'info');
                    }
                    if (trackRes.status && trackRes.tracking) {
                        Object.keys(trackRes.tracking).forEach(function(key) {
                            allTracking[key] = trackRes.tracking[key];
                        });
                    }
                },
                error: function() {
                    console.warn('Error consultando lote de gu\u00edas');
                },
                complete: function() {
                    processedChunks++;
                    processNextChunk();
                }
            });
        }
        processNextChunk();
    }

    function formatDate(isoStr) {
        if (!isoStr) return '\u2014';
        const d = new Date(isoStr);
        return d.toLocaleDateString('es-MX', { timeZone: 'Etc/GMT+7', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function getEventCodeLabel(code) {
        const labels = {
            'T0000': 'Pago completado', 'T0001': 'Pago de art\u00edculo', 'T0002': 'Pago de servicio',
            'T0003': 'Pago de cuenta', 'T0004': 'Pago de sitio web', 'T0005': 'Donaci\u00f3n',
            'T0006': 'Tarjeta de regalo', 'T0007': 'Suscripci\u00f3n', 'T0008': 'Pago de factura',
            'T0009': 'Pago de multa', 'T0010': 'Pago de cup\u00f3n', 'T0011': 'Transferencia',
            'T0012': 'Pago de pedido', 'T0013': 'Pago de retiro', 'T0100': 'Pago gen\u00e9rico'
        };
        return labels[code] || code;
    }

    function initTable(data) {
        if (dataTable) {
            dataTable.destroy();
            dataTable = null;
        }

        if (data.length === 0) {
            document.querySelector('#transactionsTable tbody').innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-icon">📭</div><p>No se encontraron transacciones en el rango seleccionado</p></div></td></tr>';
            document.getElementById('exportCsvBtn').disabled = true;
            document.getElementById('exportTrackingReportBtn').disabled = true;
            document.getElementById('statsGrid').style.display = 'none';
            document.getElementById('resultInfo').textContent = '';
            return;
        }

        document.getElementById('statsGrid').style.display = 'grid';

        // Calcular desglose detallado
        let totalCount = data.length;
        let totalBruto = 0;
        let completadasCount = 0, completadasBruto = 0, completadasFees = 0, completadasNeto = 0;
        let reembolsadasCount = 0, reembolsadasTotal = 0;
        let pendientesCount = 0, pendientesTotal = 0;
        let retirosCount = 0, retirosTotal = 0;
        let currency = 'MXN';

        data.forEach(function(t) {
            const info = t.transaction_info || {};
            const amt = parseFloat(info.transaction_amount && info.transaction_amount.value || 0);
            const fee = parseFloat(info.fee_amount && info.fee_amount.value || 0);
            const status = info.transaction_status || '';
            const eventCode = info.transaction_event_code || '';
            const isRefunded = t.refund_info && t.refund_info.is_refunded;
            const cur = info.transaction_amount && info.transaction_amount.currency_code || 'MXN';
            if (cur) currency = cur;

            totalBruto += amt;

            if (eventCode === 'T0403') {
                retirosCount++;
                retirosTotal += amt;
            } else if (isRefunded) {
                reembolsadasCount++;
                reembolsadasTotal += amt;
            } else if (status === 'S') {
                completadasCount++;
                completadasBruto += amt;
                completadasFees += Math.abs(fee);
            } else {
                pendientesCount++;
                pendientesTotal += amt;
            }
        });

        completadasNeto = completadasBruto - completadasFees;

        // Total transacciones
        document.getElementById('txTotalCount').textContent = totalCount;
        document.getElementById('txTotalBruto').textContent = 'Bruto: ' + formatoMoneda(totalBruto, currency);

        // Completadas
        document.getElementById('txCompletadasCount').textContent = completadasCount;
        document.getElementById('txCompletadasBruto').innerHTML =
            'Bruto: ' + formatoMoneda(completadasBruto, currency) +
            '<br>Comisiones: −' + formatoMoneda(completadasFees, currency) +
            '<br><strong style="color:var(--color-success-text, #16a34a);">Neto: ' + formatoMoneda(completadasNeto, currency) + '</strong>';

        // Reembolsadas
        document.getElementById('txReembolsadasCount').textContent = reembolsadasCount;
        document.getElementById('txReembolsadasMonto').textContent = 'Total: ' + formatoMoneda(reembolsadasTotal, currency);

        // Pendientes
        document.getElementById('txPendientesCount').textContent = pendientesCount;
        document.getElementById('txPendientesMonto').textContent = 'Total: ' + formatoMoneda(pendientesTotal, currency);

        // Retiros
        document.getElementById('txRetirosCount').textContent = retirosCount;
        document.getElementById('txRetirosMonto').textContent = 'Total: ' + formatoMoneda(retirosTotal, currency);

        // Total Neto (el mismo valor del reporte)
        document.getElementById('txNetoAmount').textContent = formatoMoneda(completadasNeto, currency);

        document.getElementById('resultInfo').textContent = totalCount + ' transacci\u00f3n(es) encontradas';
        document.getElementById('exportCsvBtn').disabled = false;
        document.getElementById('exportTrackingReportBtn').disabled = false;

        const rows = data.map(function(t) {
            const info = t.transaction_info || {};
            const id = info.transaction_id || '';
            const date = formatDate(info.transaction_initiation_date);
            const currency = info.transaction_amount && info.transaction_amount.currency_code || 'MXN';
            return [
                '<div class="font-mono text-sm">' + id + '</div><div class="text-muted text-sm">' + getEventCodeLabel(info.transaction_event_code || '') + ' (' + (info.transaction_event_code || '') + ')</div>',
                renderClienteMonto(t),
                '<span class="currency-badge">' + currency + '</span>',
                date,
                renderStatus(t),
                renderTracking(t),
                renderActions(t)
            ];
        });

        dataTable = $('#transactionsTable').DataTable({
            data: rows,
            columns: [
                { title: 'Transacci\u00f3n' },
                { title: 'Cliente / Monto' },
                { title: 'Moneda' },
                { title: 'Fecha' },
                { title: 'Estado' },
                { title: 'Rastreo' },
                { title: 'Acciones' }
            ],
            language: {
                processing: 'Procesando...',
                search: 'Buscar:',
                lengthMenu: 'Mostrar _MENU_ registros',
                info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                infoEmpty: 'Mostrando 0 a 0 de 0 registros',
                infoFiltered: '(filtrado de _MAX_ registros totales)',
                loadingRecords: 'Cargando...',
                zeroRecords: 'No se encontraron registros',
                emptyTable: 'No hay datos disponibles',
                paginate: { first: 'Primero', previous: 'Anterior', next: 'Siguiente', last: 'Último' },
                aria: { sortAscending: ' activar para ordenar ascendente', sortDescending: ' activar para ordenar descendente' }
            },
            pageLength: 25,
            order: [[3, 'desc']],
            destroy: true,
            autoWidth: false,
            columnDefs: [
                { width: '200px', targets: 0 },
                { width: '180px', targets: 1 },
                { width: '60px', targets: 2 },
                { width: '140px', targets: 3 },
                { width: '130px', targets: 4 },
                { width: '100px', targets: 5 },
                { width: '160px', targets: 6, orderable: false }
            ],
            createdRow: function(row, rowData) {
                if (typeof rowData[4] === 'string' && rowData[4].indexOf('Reembolsado') !== -1) {
                    $(row).addClass('refunded-row');
                }
            }
        });
    }

    function exportCSV() {
        if (!transactionsData.length) return;
        const rows = [['ID Transacci\u00f3n', 'Cliente', 'Email', 'Monto', 'Moneda', 'Fecha', 'Estado', 'C\u00f3digo Evento', 'Tracking', 'Reembolsado']];
        transactionsData.forEach(function(t) {
            const info = t.transaction_info || {};
            const payer = t.payer_info || {};
            const trackers = t.trackers || [];
            const trackingStr = trackers.map(function(tr) { return (tr.carrier || '') + ': ' + (tr.tracking_number || ''); }).join('; ');
            const csvNameFromPayer = payer.payer_name
                ? (payer.payer_name.alternate_full_name || (payer.payer_name.given_name && payer.payer_name.surname ? payer.payer_name.given_name + ' ' + payer.payer_name.surname : '') || '')
                : '';
            rows.push([
                info.transaction_id || '',
                csvNameFromPayer || payer.email_address || '',
                payer.email_address || '',
                info.transaction_amount && info.transaction_amount.value || '',
                info.transaction_amount && info.transaction_amount.currency_code || '',
                info.transaction_initiation_date || '',
                info.transaction_status || '',
                info.transaction_event_code || '',
                trackingStr,
                t.refund_info && t.refund_info.is_refunded ? 'S\u00ed' : 'No'
            ]);
        });
        const csv = rows.map(function(r) { return r.map(function(v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
        const BOM = '\uFEFF';
        const blob = new Blob([BOM + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'paypal_transacciones_' + new Date().toISOString().slice(0,10) + '.csv';
        link.click();
    }

    document.getElementById('exportCsvBtn').addEventListener('click', exportCSV);

    document.getElementById('exportTrackingReportBtn').addEventListener('click', exportTrackingReport);

    function exportTrackingReport() {
        if (!transactionsData.length) return;
        const rows = [['ID Transacci\u00f3n', 'Cliente', 'Email', 'Fecha', 'Monto', 'Moneda', 'Estado', '\u00bfTiene Rastreo?', 'Paqueter\u00eda', 'N\u00famero de Gu\u00eda']];
        transactionsData.forEach(function(t) {
            const info = t.transaction_info || {};
            const payer = t.payer_info || {};
            const trackers = t.trackers || [];
            const nameFromPayer = payer.payer_name
                ? (payer.payer_name.alternate_full_name || (payer.payer_name.given_name && payer.payer_name.surname ? payer.payer_name.given_name + ' ' + payer.payer_name.surname : '') || '')
                : '';
            const name = nameFromPayer || payer.email_address || '';
            const hasTracking = trackers.length > 0;
            if (hasTracking) {
                trackers.forEach(function(tr) {
                    var guia = tr.tracking_number || '';
                    if (guia && !/\d/.test(guia)) return;
                    if (guia && /^\d+$/.test(guia)) {
                        guia = '="' + guia + '"';
                    }
                    rows.push([
                        info.transaction_id || '',
                        name,
                        payer.email_address || '',
                        info.transaction_initiation_date || '',
                        info.transaction_amount && info.transaction_amount.value || '',
                        info.transaction_amount && info.transaction_amount.currency_code || '',
                        info.transaction_status || '',
                        'S\u00ed',
                        tr.carrier || '',
                        guia
                    ]);
                });
            } else {
                rows.push([
                    info.transaction_id || '',
                    name,
                    payer.email_address || '',
                    info.transaction_initiation_date || '',
                    info.transaction_amount && info.transaction_amount.value || '',
                    info.transaction_amount && info.transaction_amount.currency_code || '',
                    info.transaction_status || '',
                    'No',
                    '',
                    ''
                ]);
            }
        });
        const csv = rows.map(function(r) { return r.map(function(v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(','); }).join('\n');
        const BOM = '\uFEFF';
        const blob = new Blob([BOM + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'paypal_reporte_guias_' + new Date().toISOString().slice(0,10) + '.csv';
        link.click();
    }

    document.getElementById('transactionsForm').addEventListener('submit', function(e) {
        e.preventDefault();
        let start = document.getElementById('start').value;
        let end = document.getElementById('end').value;
        const buyer = document.getElementById('buyer').value;
        if (!start || !end) { toast('Selecciona ambas fechas', 'error'); return; }
        const diffDays = (new Date(end) - new Date(start)) / 86400000;
        if (diffDays > 31) {
            const newEnd = new Date(start);
            newEnd.setDate(newEnd.getDate() + 30);
            end = newEnd.toISOString().slice(0, 10);
            document.getElementById('end').value = end;
            Swal.fire({
                icon: 'warning',
                title: 'Rango ajustado',
                text: 'El rango máximo es de 31 días. Se ajustó la fecha final al ' + end + '.',
                confirmButtonText: 'Entendido'
            });
        }
        const searchBtn = document.getElementById('searchBtn');
        searchBtn.disabled = true;
        searchBtn.textContent = '\u23f3 Buscando...';
        document.querySelector('#transactionsTable tbody').innerHTML = '<tr><td colspan="7"><div class="loading-spinner">\u23f3 Cargando transacciones...</div></td></tr>';
        var overlay = document.getElementById('rastreoLoadingOverlay');
        if (overlay) overlay.style.display = 'flex';
        $.ajax({
            url: 'requests/get_paypal_transactions.php',
            method: 'POST',
            data: { start: start, end: end, buyer: buyer },
            dataType: 'json',
            success: function(res) {
                rastreoLoading = false;
                if (res.status && res.transactions) {
                    transactionsData = res.transactions;
                    rastreoLoaded = true;
                    initTable(transactionsData);
                    loadTrackingBatch();
                } else {
                    rastreoLoaded = true;
                    if (overlay) overlay.style.display = 'none';
                    toast(res.error || 'Error al obtener transacciones', 'error');
                    document.querySelector('#transactionsTable tbody').innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-icon">\u274c</div><p>' + (res.error || 'Error de comunicaci\u00f3n con PayPal') + '</p></div></td></tr>';
                }
            },
            error: function(xhr) {
                rastreoLoaded = true;
                rastreoLoading = false;
                if (overlay) overlay.style.display = 'none';
                let msg = 'Error de conexi\u00f3n';
                try {
                    const r = JSON.parse(xhr.responseText);
                    msg = r.error || r.message || msg;
                } catch(e) {}
                toast(msg, 'error');
                document.querySelector('#transactionsTable tbody').innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-icon">\u274c</div><p>' + msg + '</p></div></td></tr>';
            },
            complete: function() {
                searchBtn.disabled = false;
                searchBtn.textContent = '\uD83D\uDD0D Buscar';
            }
        });
    });

    document.getElementById('trackingForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const txnId = document.getElementById('trackingTransactionId').value;
        const carrier = document.getElementById('carrier').value;
        const trackNumber = document.getElementById('trackNumber').value;
        if (!carrier || !trackNumber) { toast('Completa todos los campos', 'error'); return; }
        $.ajax({
            url: 'requests/add_tracking.php',
            method: 'POST',
            data: { transaction_id: txnId, carrier: carrier, track_number: trackNumber },
            dataType: 'json',
            success: function(res) {
                if (res.status) {
                    if (res.pending) {
                        toast(res.message, 'info');
                        pendingCount++;
                        updatePendingUI();
                    } else {
                        toast('Rastreo agregado correctamente', 'success');
                    }
                    cerrarModal('trackingModal');
                    if (dataTable) { dataTable.destroy(); dataTable = null; }
                    submitTransactionsForm();
                } else {
                    toast(res.message || 'Error al guardar rastreo', 'error');
                }
            },
            error: function(xhr) {
                let msg = 'Error al guardar rastreo';
                try { var r = JSON.parse(xhr.responseText); msg = r.message || msg; } catch(e) {}
                toast(msg, 'error');
            }
        });
    });

    document.getElementById('refundForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const captureId = document.getElementById('refundCaptureId').value;
        const currency = document.getElementById('refundCurrency').value;
        const type = document.getElementById('refundType').value;
        const amount = type === 'partial' ? document.getElementById('refundAmount').value : '';
        const reason = document.getElementById('refundReason').value;
        if (type === 'partial' && (!amount || parseFloat(amount) <= 0)) { toast('Ingresa un monto v\u00e1lido para el reembolso parcial', 'error'); return; }
        Swal.fire({
            title: '\u00bfEst\u00e1s seguro?',
            text: '\u00bfEst\u00e1s seguro de procesar este reembolso?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'S\u00ed, reembolsar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#dc2626'
        }).then((result) => {
            if (!result.isConfirmed) return;
            const data = { capture_id: captureId, currency: currency, reason: reason, refund_type: type };
            if (amount) data.amount = amount;
            $.ajax({
                url: 'requests/refund_paypal.php',
                method: 'POST',
                data: data,
                dataType: 'json',
                success: function(res) {
                    if (res.status) {
                        toast('Reembolso procesado correctamente', 'success');
                        cerrarModal('refundModal');
                        // Actualizaci\u00f3n optimista inmediata
                        // NO re-consultar al servidor (PayPal API delay sobrescribe el estado)
                        var optCapId = res.data && res.data.capture_id;
                        if (optCapId && transactionsData.length) {
                            for (var oi = 0; oi < transactionsData.length; oi++) {
                                if (transactionsData[oi].capture_id === optCapId && transactionsData[oi].refund_info) {
                                    transactionsData[oi].refund_info.is_refunded = true;
                                    transactionsData[oi].refund_info.refund_id = res.data.refund_id || null;
                                    transactionsData[oi].refund_info.amount_refunded = res.data.amount ? parseFloat(res.data.amount) : null;
                                    transactionsData[oi].refund_info.refund_date = res.data.create_time || null;
                                    transactionsData[oi].refund_info.refund_type = type === 'partial' ? 'partial' : 'full';
                                    break;
                                }
                            }
                            initTable(transactionsData);
                        }
                    } else {
                        toast(res.message || 'Error al procesar reembolso', 'error');
                    }
                },
                error: function(xhr) {
                    let msg = 'Error al procesar reembolso';
                    try { var r = JSON.parse(xhr.responseText); msg = r.message || msg; } catch(e) {}
                    toast(msg, 'error');
                }
            });
        });
    });

    function toggleChart() {
        const body = document.getElementById('chartBody');
        const toggle = document.getElementById('chartToggle');
        body.classList.toggle('hidden');
        toggle.textContent = body.classList.contains('hidden') ? '\u25b6' : '\u25bc';
    }

    function switchChartType(type) {
        document.querySelectorAll('.chart-type-btn').forEach(function(b) { b.classList.remove('active'); });
        document.querySelector('.chart-type-btn[data-type="' + type + '"]').classList.add('active');
        if (!chartInstance) return;
        if (type === 'ingresos') {
            chartInstance.data.datasets[0].label = 'Monto ($)';
            chartInstance.data.datasets[0].data = chartAmounts;
        } else {
            chartInstance.data.datasets[0].label = 'Cantidad';
            chartInstance.data.datasets[0].data = chartCounts;
        }
        chartInstance.update();
    }

    function initChart(labels, amounts, counts) {
        chartLabels = labels;
        chartAmounts = amounts;
        chartCounts = counts;
        if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
        const ctx = document.getElementById('chartCanvas').getContext('2d');
        chartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Monto ($)',
                    data: amounts,
                    backgroundColor: 'rgba(0, 112, 186, 0.6)',
                    borderColor: 'rgba(0, 112, 186, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return '$ ' + ctx.parsed.y.toFixed(2); }
                        }
                    }
                },
                scales: {
                    x: { ticks: { font: { size: 10 } }, grid: { display: false } },
                    y: { ticks: { font: { size: 10 }, callback: function(v) { return '$' + v.toFixed(0); } } }
                }
            }
        });
    }

    // === DASHBOARD CHARTS (Inicio) ===
    let dashLabelsParsed = <?= $dashDailyLabels ?: '[]' ?>;
    let dashAmountsParsed = <?= $dashDailyAmounts ?: '[]' ?>;
    let dashCountsParsed = <?= $dashDailyCounts ?: '[]' ?>;

    function initDashCharts() {
        if (!document.getElementById('dashBarChart')) return;
        if (dashLabelsParsed.length === 0) {
            document.querySelector('#section-inicio .chart-section')?.remove();
            return;
        }

        if (dashBarInstance) { dashBarInstance.destroy(); dashBarInstance = null; }
        if (dashDonutInstance) { dashDonutInstance.destroy(); dashDonutInstance = null; }

        const barCtx = document.getElementById('dashBarChart').getContext('2d');
        dashBarInstance = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: dashLabelsParsed,
                datasets: [{
                    label: 'Monto ($)',
                    data: dashAmountsParsed,
                    backgroundColor: 'rgba(0, 112, 186, 0.6)',
                    borderColor: 'rgba(0, 112, 186, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return '$ ' + ctx.parsed.y.toFixed(2); } } } },
                scales: {
                    x: { ticks: { font: { size: 10 } }, grid: { display: false } },
                    y: { ticks: { font: { size: 10 }, callback: function(v) { return '$' + v.toFixed(0); } } }
                }
            }
        });

        const donutCtx = document.getElementById('dashDonutChart').getContext('2d');
        const totalComplete = <?= $dashStats['total_completadas'] ?: 0 ?>;
        const totalRefund = <?= $dashStats['total_reembolsadas'] ?: 0 ?>;
        const totalChargebacks = <?= $dashStats['total_chargebacks'] ?: 0 ?>;
        const totalPending = <?= $dashStats['total_pendientes'] ?: 0 ?>;
        dashDonutInstance = new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: ['Completadas', 'Reembolsadas', 'Retiros', 'Pendientes'],
                datasets: [{
                    data: [totalComplete, totalRefund, totalChargebacks, totalPending],
                    backgroundColor: ['rgba(22, 163, 74, 0.8)', 'rgba(220, 38, 38, 0.8)', 'rgba(217, 119, 6, 0.8)', 'rgba(100, 116, 139, 0.8)'],
                    borderColor: ['#16a34a', '#dc2626', '#d97706', '#64748b'],
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
            dashBarInstance.data.datasets[0].data = dashAmountsParsed;
        } else {
            dashBarInstance.data.datasets[0].label = 'Cantidad';
            dashBarInstance.data.datasets[0].data = dashCountsParsed;
        }
        dashBarInstance.update();
    }

    function abrirDetalleModal(txn) {
        document.getElementById('detailModalTitle').textContent = txn.transaction_id;
        let html = '<div class="detail-grid">';
        var fields = [
            ['ID Transacci\u00f3n', txn.transaction_id],
            ['Estado', '<span class="status-badge ' + (txn.status_label === 'Completado' ? 'success' : (txn.is_refunded ? 'failed' : 'warning')) + '">' + (txn.status_label || txn.estado || '\u2014') + '</span>'],
            ['Cliente', txn.payer_name || '\u2014'],
            ['Email', txn.payer_email || '\u2014'],
            ['Pa\u00eds', txn.payer_country || '\u2014'],
            ['Fecha', txn.initiation_date ? new Date(txn.initiation_date).toLocaleString('es-MX', { timeZone: 'Etc/GMT+7' }) : '\u2014'],
            ['Monto', txn.amount_formatted],
            ['Moneda', txn.currency],
            ['Comisi\u00f3n', txn.fee_formatted],
            ['C\u00f3digo Evento', txn.event_code || '\u2014'],
            ['Factura ID', txn.invoice_id || '\u2014'],
            ['Custom Field', txn.custom_field || '\u2014']
        ];
        if (txn.is_refunded) {
            fields.push(['Reembolsado', 'S\u00ed']);
            fields.push(['Tipo', txn.refund_type === 'full' ? 'Total' : 'Parcial']);
            if (txn.refund_info && txn.refund_info.refund_id) fields.push(['ID Reembolso', txn.refund_info.refund_id]);
        }
        if (txn.trackers && txn.trackers.length > 0) {
            fields.push(['Rastreo', txn.trackers.map(function(t) { return (t.carrier || '') + ': ' + (t.tracking_number || ''); }).join('<br>')]);
        }
        if (txn.subject) fields.push(['Asunto', txn.subject]);
        fields.forEach(function(f) {
            html += '<div class="detail-item"><div class="detail-label">' + f[0] + '</div><div class="detail-value">' + f[1] + '</div></div>';
        });
        html += '</div>';
        document.getElementById('detailModalBody').innerHTML = html;
        abrirModal('detailModal');
    }

    $(document).on('click', '#transactionsTable tbody tr', function() {
        const rowData = dataTable ? dataTable.row(this).data() : null;
        if (rowData) {
            const match = rowData[0].match(/font-mono[^>]*>([^<]+)/);
            if (match) {
                const id = match[1].trim();
                const txn = transactionsData.find(function(t) { return t.transaction_info && t.transaction_info.transaction_id === id; });
                if (txn) {
                    const info = txn.transaction_info || {};
                    const payer = txn.payer_info || {};
                    const nameFromPayer = payer.payer_name
                        ? (payer.payer_name.alternate_full_name || (payer.payer_name.given_name && payer.payer_name.surname ? payer.payer_name.given_name + ' ' + payer.payer_name.surname : '') || '')
                        : '';
                    abrirDetalleModal({
                        transaction_id: info.transaction_id || id,
                        payer_name: nameFromPayer || payer.email_address || '\u2014',
                        payer_email: payer.email_address || '\u2014',
                        payer_country: payer.country_code || '\u2014',
                        initiation_date: info.transaction_initiation_date || '',
                        amount_formatted: info.transaction_amount && info.transaction_amount.value
                            ? formatoMoneda(info.transaction_amount.value, info.transaction_amount.currency_code || 'MXN')
                            : '$0.00',
                        currency: info.transaction_amount && info.transaction_amount.currency_code || 'MXN',
                        fee_formatted: info.fee_amount && info.fee_amount.value
                            ? formatoMoneda(Math.abs(parseFloat(info.fee_amount.value)), info.transaction_amount && info.transaction_amount.currency_code || 'MXN')
                            : '$0.00',
                        event_code: info.transaction_event_code || '\u2014',
                        invoice_id: info.invoice_id || '',
                        custom_field: info.custom_field || '',
                        subject: info.transaction_subject || '',
                        is_refunded: txn.refund_info && txn.refund_info.is_refunded,
                        refund_type: txn.refund_info && txn.refund_info.refund_type,
                        refund_info: txn.refund_info || {},
                        trackers: txn.trackers || [],
                        status_label: info.transaction_status === 'S' ? 'Completado' : (info.transaction_status || '\u2014'),
                        estado: info.transaction_status === 'S' ? 'Completado' : (info.transaction_status || '\u2014')
                    });
                }
            }
        }
    });

    const origInitTable = initTable;
    initTable = function(data) {
        origInitTable(data);
        if (data.length > 0) {
            const groups = {};
            data.forEach(function(t) {
                const info = t.transaction_info || {};
                if (info.transaction_event_code === 'T0403' || info.transaction_event_code === 'T110' || info.transaction_event_code === 'T120') return;
                const date = info.transaction_initiation_date || '';
                if (!date) return;
                var day = date.substring(0, 10);
                if (!groups[day]) groups[day] = { count: 0, total: 0 };
                groups[day].count++;
                groups[day].total += parseFloat(info.transaction_amount && info.transaction_amount.value || 0);
            });
            initChart(Object.keys(groups).sort(), Object.keys(groups).sort().map(function(l) { return groups[l].total; }), Object.keys(groups).sort().map(function(l) { return groups[l].count; }));
        }
    };

    // document.getElementById('trackingFormSection').addEventListener('submit', function(e) {
    //     e.preventDefault();
    //     const txnId = document.getElementById('trackTxnId').value.trim();
    //     const carrier = document.getElementById('trackCarrier').value;
    //     const trackNumber = document.getElementById('trackNumberSection').value.trim();
    //     if (!txnId || !carrier || !trackNumber) { toast('Completa todos los campos', 'error'); return; }
    //     const btn = document.getElementById('trackSectionBtn');
    //     btn.disabled = true;
    //     btn.textContent = '⏳ Guardando...';
    //     $.ajax({
    //         url: 'requests/add_tracking.php',
    //         method: 'POST',
    //         data: { transaction_id: txnId, carrier: carrier, track_number: trackNumber },
    //         dataType: 'json',
    //         success: function(res) {
    //             if (res.status) {
    //                 if (res.pending) {
    //                     toast(res.message, 'info');
    //                     pendingCount++;
    //                     updatePendingUI();
    //                 } else {
    //                     toast('Rastreo agregado correctamente', 'success');
    //                 }
    //                 document.getElementById('trackTxnId').value = '';
    //                 document.getElementById('trackCarrier').value = '';
    //                 document.getElementById('trackNumberSection').value = '';
    //             } else {
    //                 toast(res.message || 'Error al guardar rastreo', 'error');
    //             }
    //         },
    //         error: function(xhr) {
    //             let msg = 'Error al guardar rastreo';
    //             try { var r = JSON.parse(xhr.responseText); msg = r.message || msg; } catch(e) {}
    //             toast(msg, 'error');
    //         },
    //         complete: function() {
    //             btn.disabled = false;
    //             btn.textContent = '📦 Guardar rastreo';
    //         }
    //     });
    // });

    // document.getElementById('checkTrackingBtn').addEventListener('click', function() {
    //     const txnId = document.getElementById('checkTxnId').value.trim();
    //     const results = document.getElementById('trackingResults');
    //     if (!txnId) { toast('Ingresa un valor para consultar', 'error'); return; }
    //     results.innerHTML = '<div class="loading-spinner">\u23f3 Consultando...</div>';
    //     $.ajax({
    //         url: 'requests/check_tracking.php',
    //         method: 'POST',
    //         data: { value: txnId },
    //         dataType: 'json',
    //         success: function(res) {
    //             if (res.status && Array.isArray(res.tracking) && res.tracking.length) {
    //                 let html = '<div class="tracking-result-card"><div class="tracking-header">\u2705 Rastreos encontrados</div>';
    //                 res.tracking.forEach(function(t) {
    //                     html += '<div class="tracking-detail"><strong>Paqueter\u00eda:</strong> ' + (t.carrier || '\u2014') + '<br><strong>N\u00famero:</strong> ' + (t.tracking_number || '\u2014') + '<br><strong>Estado:</strong> ' + (t.status || 'Desconocido') + '</div><hr style="border-color:var(--border);margin:0.5rem 0;">';
    //                 });
    //                 html += '</div>';
    //                 results.innerHTML = html;
    //             } else if (res.message) {
    //                 results.innerHTML = '<div class="tracking-result-card"><div class="tracking-header">\u274c ' + res.message + '</div></div>';
    //             } else {
    //                 results.innerHTML = '<div class="tracking-result-card"><div class="tracking-header">\u274c No se encontraron rastreos</div><div class="tracking-detail">No hay informaci\u00f3n de rastreo para esta transacci\u00f3n</div></div>';
    //             }
    //         },
    //         error: function(xhr) {
    //             let msg = 'Error de conexi\u00f3n';
    //             try { var r = JSON.parse(xhr.responseText); msg = r.message || r.error || msg; } catch(e) {}
    //             results.innerHTML = '<div class="tracking-result-card"><div class="tracking-header">\u274c ' + msg + '</div></div>';
    //             toast(msg, 'error');
    //         }
    //     });
    // });

    document.getElementById('regenerateTokenBtn').addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.textContent = '⏳ Regenerando...';
        document.getElementById('tokenResult').innerHTML = '';
        $.ajax({
            url: 'requests/check_tracking.php',
            method: 'POST',
            data: { type: 'transaction_id', value: 'TOKEN_REGEN_' + Date.now() },
            dataType: 'json',
            complete: function() {
                btn.disabled = false;
                btn.textContent = '🔄 Regenerar token API';
                document.getElementById('tokenResult').innerHTML = '<div class="tracking-result-card"><div class="tracking-header">ℹ️ Token regenerado</div><div class="tracking-detail">Elimina el archivo token_cache.json manualmente si persisten problemas de autenticación.</div></div>';
                document.getElementById('tokenCacheStatus').textContent = 'Regenerado (verificar)';
                toast('Token regenerado. Si hay errores, elimina token_cache.json manualmente.', 'info');
            }
        });
    });

    let clientIdVisible = false;
    document.getElementById('showClientIdBtn').addEventListener('click', function() {
        const display = document.getElementById('clientIdDisplay');
        if (!clientIdVisible) {
            display.textContent = '<?= htmlspecialchars(PAYPAL_CLIENT_ID) ?>';
            this.textContent = '🙈 Ocultar Client ID';
            clientIdVisible = true;
        } else {
            display.textContent = '••••••••';
            this.textContent = '👁️ Mostrar Client ID';
            clientIdVisible = false;
        }
    });

    $.ajax({
        url: 'requests/check_tracking.php',
        method: 'POST',
        data: { type: 'transaction_id', value: 'TOKEN_CHECK' },
        dataType: 'json',
        success: function() {
            document.getElementById('tokenCacheStatus').textContent = '✅ Activo y funcionando';
        },
        error: function() {
            document.getElementById('tokenCacheStatus').textContent = '⚠️ No disponible (reintentar)';
        }
    });

    let currentSemana = null;
    let currentModo = 'semanal';
    let currentStart = null;
    let currentEnd = null;

    function loadReporte(semana, modo, start, end) {
        if (semana === 'today') {
            semana = new Date().toISOString().slice(0, 10);
        }
        var params = 'embed=1';
        if (modo === 'personalizado' && start && end) {
            params += '&modo=personalizado&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
            currentModo = 'personalizado';
            currentStart = start;
            currentEnd = end;
        } else {
            params += '&modo=semanal' + (semana ? '&semana=' + encodeURIComponent(semana) : '');
            currentModo = 'semanal';
            currentSemana = semana || new Date().toISOString().slice(0, 10);
        }

        const content = document.getElementById('reporteContent');
        content.innerHTML = '<div class="loading-spinner">⏳ Cargando reporte...</div>';

        fetch('paypal_reporte.php?' + params)
            .then(function(r) { return r.text(); })
            .then(function(html) {
                content.innerHTML = html;

                const dataScript = document.getElementById('reporteData');
                if (dataScript) {
                    try {
                        var parsed = JSON.parse(dataScript.textContent);
                        window._reporteData = parsed.data || parsed;
                        if (parsed.modo) currentModo = parsed.modo;
                        if (parsed.start) currentStart = parsed.start;
                        if (parsed.end) currentEnd = parsed.end;
                    } catch(e) {
                        window._reporteData = [];
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
                        var p = window._reporteData ? window._reporteData.find(function(d) { return d.transaction_id === id; }) : null;
                        if (p) abrirDetalleModalReporte(p);
                    });
                });

                var picker = content.querySelector('#semanaDatePickerEmbed');
                if (picker) {
                    picker.addEventListener('change', function() { loadReporte(this.value); });
                }
                var cStart = content.querySelector('#customStartEmbed');
                var cEnd = content.querySelector('#customEndEmbed');
                if (cStart && cEnd) {
                    cStart.addEventListener('change', function() { });
                    cEnd.addEventListener('change', function() { });
                }
            })
            .catch(function() {
                content.innerHTML = '<div class="alert alert-error">Error al cargar el reporte. Verifica la conexión.</div>';
            });
    }

    function navReporte(dir) {
        if (!currentSemana) {
            loadReporte(new Date().toISOString().slice(0, 10));
            return;
        }
        var d = new Date(currentSemana);
        d.setDate(d.getDate() + (dir * 7));
        loadReporte(d.toISOString().slice(0, 10));
    }

    function toggleModoReporte(modo) {
        if (modo === currentModo) return;
        if (modo === 'personalizado') {
            var end = new Date().toISOString().slice(0, 10);
            var start = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
            loadReporte(null, 'personalizado', start, end);
        } else {
            loadReporte('today');
        }
    }

    function navReporteCustom(dir) {
        if (!currentStart || !currentEnd) {
            toggleModoReporte('personalizado');
            return;
        }
        var diff = Math.round((new Date(currentEnd) - new Date(currentStart)) / 86400000) + 1;
        var d = new Date(currentStart);
        d.setDate(d.getDate() + (dir * diff));
        var newStart = d.toISOString().slice(0, 10);
        d.setDate(d.getDate() + diff - 1);
        var newEnd = d.toISOString().slice(0, 10);
        loadReporte(null, 'personalizado', newStart, newEnd);
    }

    function applyCustomRange() {
        var s, e;
        var content = document.getElementById('reporteContent');
        s = content.querySelector('#customStartEmbed');
        e = content.querySelector('#customEndEmbed');
        if (s && e && s.value && e.value) {
            loadReporte(null, 'personalizado', s.value, e.value);
        }
    }

    function abrirDetalleModalReporte(p) {
        abrirDetalleModal({
            transaction_id: p.transaction_id,
            payer_name: p.payer_name || '\u2014',
            payer_email: p.payer_email || '\u2014',
            payer_country: p.payer_country || '\u2014',
            initiation_date: p.initiation_date || '',
            amount_formatted: p.amount_formatted || '$0.00',
            currency: p.currency || 'MXN',
            fee_formatted: p.fee_formatted || '$0.00',
            event_code: p.event_code || '\u2014',
            invoice_id: p.invoice_id || '',
            custom_field: p.custom_field || '',
            subject: p.subject || '',
            is_refunded: p.is_refunded,
            refund_type: p.refund_type,
            refund_info: p.refund_info || {},
            trackers: p.trackers || [],
            status_label: p.status_label || '\u2014',
            estado: p.status_label || '\u2014'
        });
    }

    // === HEARTBEAT & PENDING TRACKING QUEUE ===
    function startHeartbeat() {
        heartbeatInterval = setInterval(function() {
            $.ajax({
                url: 'requests/ping.php',
                method: 'GET',
                dataType: 'json'
            });
            if (pendingCount > 0) {
                processPendingQueue();
            }
        }, 30000);
    }

    function processPendingQueue() {
        $.ajax({
            url: 'requests/process_pending_tracking.php',
            method: 'POST',
            dataType: 'json',
            success: function(res) {
                pendingCount = res.pending_count;
                updatePendingUI();
                if (res.results && res.results.length > 0) {
                    res.results.forEach(function(r) {
                        if (r.status === 'completed') {
                            toast(r.message, 'success');
                        }
                    });
                    // Refresh transactions table if visible
                    if (dataTable) {
                        dataTable.destroy();
                        dataTable = null;
                        submitTransactionsForm();
                    }
                }
            }
        });
    }

    function updatePendingUI() {
        const el = document.getElementById('pendingCount');
        const section = document.getElementById('pendingSection');
        if (el) el.textContent = pendingCount;
        if (section) {
            section.style.display = pendingCount > 0 ? 'block' : 'none';
        }
    }

    $(function() {
        if (document.getElementById('dashBarChart')) {
            initDashCharts();
        }
        startHeartbeat();
    });
    </script>
</body>
</html>

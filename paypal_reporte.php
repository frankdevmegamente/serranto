<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/paypal_helper.php';

$modo = $_GET['modo'] ?? 'semanal';
$semana_ref = $_GET['semana'] ?? null;
$start_date = $_GET['start'] ?? null;
$end_date = $_GET['end'] ?? null;
$tzMx = new DateTimeZone('America/Mexico_City');

if ($modo === 'personalizado' && $start_date && $end_date) {
    $start = new DateTime($start_date . ' 00:00:00', $tzMx);
    $start->setTimezone(new DateTimeZone('UTC'));
    $inicio_utc = $start->format('Y-m-d\TH:i:s\Z');
    $end = new DateTime($end_date . ' 23:59:59', $tzMx);
    $end->setTimezone(new DateTimeZone('UTC'));
    $fin_utc = $end->format('Y-m-d\TH:i:s\Z');
    $inicio_display = $start->format('d/m/Y');
    $fin_display = $end->format('d/m/Y');
    $inicio_str = $start_date;
    $fin_str = $end_date;
    $rango_dias = (new DateTime($start_date, $tzMx))->diff(new DateTime($end_date, $tzMx))->days + 1;
} else {
    $modo = 'semanal';
    $semana = obtenerSemanaPaypal($semana_ref, $tzMx);
    $inicio_utc = $semana['inicio_utc'];
    $fin_utc = $semana['fin_utc'];
    $inicio_display = $semana['inicio_display'];
    $fin_display = $semana['fin_display'];
    $inicio_str = $semana['inicio_str'];
    $fin_str = $semana['fin_str'];
}

$error_api = null;
$completadas = [];
$reembolsadas = [];
$pendientes = [];
$chargebacks = [];
$todos = [];
$resumen = [
    'total_completadas' => 0, 'total_reembolsadas' => 0, 'total_pendientes' => 0,
    'total_chargebacks' => 0, 'total_general' => 0, 'monto_total_formateado' => '$ 0.00',
    'monto_chargebacks_formateado' => '$ 0.00', 'moneda' => 'MXN'
];

try {
    $resultado = obtenerPagosPaypalPorRango($inicio_utc, $fin_utc, $inicio_utc, $fin_utc);

    if ($resultado['error']) {
        $error_api = $resultado['error'];
    } else {
        $rawTransactions = $resultado['data'];
        $procesadas = procesarTransaccionesPaypal($rawTransactions);
        $completadas = $procesadas['completadas'];
        $reembolsadas = $procesadas['reembolsadas'];
        $pendientes = $procesadas['pendientes'];
        $chargebacks = $procesadas['chargebacks'] ?? [];
        $conversiones = $procesadas['conversiones'] ?? [];
        $resumen = resumenPagosPaypal($completadas, $reembolsadas, $pendientes, $chargebacks, $conversiones);
        $todos = array_merge($completadas, $reembolsadas, $pendientes, $chargebacks, $conversiones);
        usort($todos, fn($a, $b) => ($b['initiation_date'] ?? '') <=> ($a['initiation_date'] ?? ''));
    }
} catch (Exception $e) {
    $error_api = $e->getMessage();
}

$total_pagos = $resumen['total_general'];

if ($modo === 'semanal') {
    $nav_anterior = (new DateTime($inicio_str))->modify('-7 days')->format('Y-m-d');
    $nav_siguiente = (new DateTime($inicio_str))->modify('+7 days')->format('Y-m-d');
} else {
    $dias = $rango_dias;
    $nav_anterior = (new DateTime($inicio_str))->modify("-{$dias} days")->format('Y-m-d');
    $nav_siguiente = (new DateTime($inicio_str))->modify("+{$dias} days")->format('Y-m-d');
}

$semana_actual_str = (new DateTime())->format('Y-m-d');

$embed = isset($_GET['embed']) && $_GET['embed'] === '1';

$fecha_actual = new DateTime();
$semana_actual_inicio = obtenerSemanaPaypal($fecha_actual->format('Y-m-d'));
$es_semana_actual = ($modo === 'semanal' && isset($semana) && $semana['inicio_str'] === $semana_actual_inicio['inicio_str']);

$export_params = '';
if ($modo === 'personalizado') {
    $export_params = "modo=personalizado&start={$inicio_str}&end={$fin_str}";
} else {
    $export_params = "semana=" . urlencode($semana_ref ?? $semana_actual_str);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportarCSVPaypalSemanal($todos, $inicio_str, $fin_str);
}

if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    generarPDFPaypal($todos, $inicio_str, $fin_str, $resumen);
}

if (isset($_GET['guardar']) && $_GET['guardar'] === '1') {
    $dir = __DIR__ . '/reportes';
    $csvPath = guardarCSVPaypal($todos, $inicio_str, $fin_str, $dir);
    $pdfPath = guardarPDFPaypal($todos, $inicio_str, $fin_str, $resumen, $dir);
    $mensaje = "Reporte guardado: " . basename($csvPath);
    if ($pdfPath) {
        $mensaje .= " y " . basename($pdfPath);
    }
}
$customStartVal = $modo === 'personalizado' ? $inicio_str : $semana_actual_str;
$customEndVal = $modo === 'personalizado' ? $fin_str : $semana_actual_str;
?>
<?php if ($embed): ?>
<main class="dashboard-content">
    <div class="semana-navegacion">
        <div class="modo-toggle">
            <button class="modo-btn <?= $modo === 'semanal' ? 'active' : '' ?>" data-modo="semanal" onclick="parent.toggleModoReporte('semanal')">📅 Semanal</button>
            <button class="modo-btn <?= $modo === 'personalizado' ? 'active' : '' ?>" data-modo="personalizado" onclick="parent.toggleModoReporte('personalizado')">📅 Personalizado</button>
        </div>

        <div class="nav-mode mode-semanal" style="display:<?= $modo === 'semanal' ? 'flex' : 'none' ?>;">
            <button class="btn" onclick="parent.navReporte(-1)">← Anterior</button>
            <div class="semana-selector">
                <span class="semana-rango"><?= $inicio_display ?> — <?= $fin_display ?></span>
                <input type="date" id="semanaDatePickerEmbed" value="<?= $inicio_str ?>">
            </div>
            <?php if (!$es_semana_actual): ?>
                <button class="btn" onclick="parent.navReporte(1)">Siguiente →</button>
            <?php else: ?>
                <span class="btn btn-disabled" style="opacity:0.5;cursor:not-allowed;">Siguiente →</span>
            <?php endif; ?>
            <button class="btn btn-sm" onclick="parent.loadReporte('today')">Semana actual</button>
        </div>

        <div class="nav-mode mode-personalizado" style="display:<?= $modo === 'personalizado' ? 'flex' : 'none' ?>;">
            <button class="btn" onclick="parent.navReporteCustom(-1)">← Anterior</button>
            <div class="custom-range-inputs">
                <label>Desde:</label>
                <input type="date" id="customStartEmbed" value="<?= $customStartVal ?>">
                <label>Hasta:</label>
                <input type="date" id="customEndEmbed" value="<?= $customEndVal ?>">
                <button class="btn btn-primary" onclick="parent.applyCustomRange()">🔍 Generar</button>
            </div>
            <button class="btn" onclick="parent.navReporteCustom(1)">Siguiente →</button>
            <button class="btn btn-sm" onclick="parent.loadReporte('today')">Hoy</button>
        </div>
    </div>
<?php else: ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte PayPal</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/reporte-semanal.css">
</head>
<body>

    <header class="dashboard-header">
        <h1>🅿️ Reporte PayPal <?= $modo === 'personalizado' ? '— Rango personalizado' : '— Semanal' ?></h1>
        <div class="header-actions">
            <a href="paypal_dashboard.php" class="btn-icon" title="Volver a PayPal">⬅ PayPal</a>
            <div class="user-info">
                <span><?= htmlspecialchars(ADMIN_USER) ?></span>
                <a href="logout.php" class="btn-logout">Salir</a>
            </div>
        </div>
    </header>

    <main class="dashboard-content">

        <div class="semana-navegacion">
            <div class="modo-toggle">
                <a href="?modo=semanal<?= $modo === 'semanal' ? '&' . $export_params : '' ?>" class="modo-btn <?= $modo === 'semanal' ? 'active' : '' ?>">📅 Semanal</a>
                <a href="?modo=personalizado&start=<?= $customStartVal ?>&end=<?= $customEndVal ?>" class="modo-btn <?= $modo === 'personalizado' ? 'active' : '' ?>">📅 Personalizado</a>
            </div>

            <div class="nav-mode mode-semanal" style="display:<?= $modo === 'semanal' ? 'flex' : 'none' ?>;">
                <a href="?semana=<?= $nav_anterior ?>" class="btn">← Anterior</a>
                <div class="semana-selector">
                    <span class="semana-rango"><?= $inicio_display ?> — <?= $fin_display ?></span>
                    <input type="date" id="semanaDatePicker" value="<?= $inicio_str ?>" onchange="irASemana(this.value)">
                </div>
                <?php if (!$es_semana_actual): ?>
                    <a href="?semana=<?= $nav_siguiente ?>" class="btn">Siguiente →</a>
                <?php else: ?>
                    <span class="btn btn-disabled" style="opacity:0.5;cursor:not-allowed;">Siguiente →</span>
                <?php endif; ?>
                <a href="?semana=<?= $semana_actual_str ?>" class="btn btn-sm <?= $es_semana_actual ? 'btn-primary' : '' ?>">Semana actual</a>
            </div>

            <div class="nav-mode mode-personalizado" style="display:<?= $modo === 'personalizado' ? 'flex' : 'none' ?>;">
                <a href="?modo=personalizado&start=<?= $nav_anterior ?>&end=<?= date('Y-m-d', strtotime($nav_anterior . ' +' . ($rango_dias - 1) . ' days')) ?>" class="btn">← Anterior</a>
                <div class="custom-range-inputs">
                    <label>Desde:</label>
                    <input type="date" id="customStart" value="<?= $inicio_str ?>">
                    <label>Hasta:</label>
                    <input type="date" id="customEnd" value="<?= $fin_str ?>">
                    <button class="btn btn-primary" onclick="applyCustomRange()">🔍 Generar</button>
                </div>
                <a href="?modo=personalizado&start=<?= $nav_siguiente ?>&end=<?= date('Y-m-d', strtotime($nav_siguiente . ' +' . ($rango_dias - 1) . ' days')) ?>" class="btn">Siguiente →</a>
                <a href="?modo=personalizado&start=<?= $semana_actual_str ?>&end=<?= $semana_actual_str ?>" class="btn btn-sm">Hoy</a>
            </div>
        </div>
<?php endif; ?>

        <?php if (isset($mensaje)): ?>
            <div class="alert alert-info"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <?php if ($error_api): ?>
            <div class="alert alert-error">
                <strong>Error de conexión con PayPal:</strong>
                <?= htmlspecialchars($error_api) ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid stats-grid-horizontal">
            <div class="stat-card total">
                <div class="stat-label">📊 Total Movimientos</div>
                <div class="stat-value"><?= $total_pagos ?></div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">✅ Completadas</div>
                <div class="stat-value"><?= $resumen['total_completadas'] ?></div>
            </div>
            <div class="stat-card failed">
                <div class="stat-label">↩️ Reembolsadas</div>
                <div class="stat-value"><?= $resumen['total_reembolsadas'] ?></div>
            </div>
            <div class="stat-card warning">
                <div class="stat-label">⏳ Pendientes</div>
                <div class="stat-value"><?= $resumen['total_pendientes'] ?></div>
            </div>
            <div class="stat-card currency">
                <div class="stat-label">🏦 Retiros</div>
                <div class="stat-value"><?= $resumen['total_chargebacks'] ?></div>
            </div>
            <div class="stat-card total">
                <div class="stat-label">💰 Total Bruto</div>
                <div class="stat-value stat-value-sm"><?= htmlspecialchars($resumen['monto_bruto_formateado'] ?? '$ 0.00') ?></div>
            </div>
            <div class="stat-card failed">
                <div class="stat-label">💸 Comisiones</div>
                <div class="stat-value stat-value-sm"><?= htmlspecialchars($resumen['total_comisiones_formateado'] ?? '$ 0.00') ?></div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">📈 Total Neto</div>
                <div class="stat-value stat-value-sm"><?= htmlspecialchars($resumen['total_neto_formateado'] ?? '$ 0.00') ?></div>
            </div>
            <div class="stat-card currency">
                <div class="stat-label">🪙 Moneda</div>
                <div class="stat-value"><?= htmlspecialchars($resumen['moneda_principal'] ?? 'MXN') ?></div>
            </div>
        </div>

        <?php if ($total_pagos > 0): ?>
            <div class="reporte-acciones">
                <a href="<?= $embed ? 'paypal_reporte.php?' : '?' ?>export=csv&<?= $export_params ?>"<?= $embed ? ' target="_blank"' : '' ?> class="btn">⬇ Exportar CSV</a>
                <a href="<?= $embed ? 'paypal_reporte.php?' : '?' ?>export=pdf&<?= $export_params ?>"<?= $embed ? ' target="_blank"' : '' ?> class="btn">📄 Descargar PDF</a>
                <a href="<?= $embed ? 'paypal_reporte.php?' : '?' ?>guardar=1&<?= $export_params ?>"<?= $embed ? ' target="_blank"' : '' ?> class="btn">💾 Guardar en servidor</a>
                <span class="reporte-info text-muted"><?= $total_pagos ?> movimiento(s) en este período</span>
            </div>
        <?php endif; ?>

        <div class="tabs">
            <button class="tab-btn active" data-tab="todos">Todos <span class="badge"><?= $total_pagos ?></span></button>
            <button class="tab-btn" data-tab="completadas">Completadas <span class="badge"><?= $resumen['total_completadas'] ?></span></button>
            <button class="tab-btn" data-tab="reembolsadas">Reembolsadas <span class="badge"><?= $resumen['total_reembolsadas'] ?></span></button>
            <button class="tab-btn" data-tab="pendientes">Pendientes <span class="badge"><?= $resumen['total_pendientes'] ?></span></button>
            <button class="tab-btn" data-tab="chargebacks">Retiros <span class="badge"><?= $resumen['total_chargebacks'] ?></span></button>
        </div>

        <div class="tab-content active" id="tab-todos">
            <?php renderTablaPaypal($todos); ?>
        </div>
        <div class="tab-content" id="tab-completadas">
            <?php renderTablaPaypal($completadas); ?>
        </div>
        <div class="tab-content" id="tab-reembolsadas">
            <?php renderTablaPaypal($reembolsadas); ?>
        </div>
        <div class="tab-content" id="tab-pendientes">
            <?php renderTablaPaypal($pendientes); ?>
        </div>
        <div class="tab-content" id="tab-chargebacks">
            <?php renderTablaPaypal($chargebacks); ?>
        </div>

    </main>

    <?php if ($embed): ?>
    <script id="reporteData" type="application/json"><?= json_encode(['data' => $todos, 'modo' => $modo, 'start' => $inicio_str, 'end' => $fin_str], JSON_UNESCAPED_UNICODE) ?></script>
    <?php else: ?>
    <div class="modal-overlay" id="modalOverlay" onclick="cerrarModalOutside(event)">
        <div class="modal">
            <div class="modal-header">
                <h2 id="modalTitle">Transacción</h2>
                <button class="modal-close" onclick="cerrarModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>

    <script>
    const pagosData = <?= json_encode($todos, JSON_UNESCAPED_UNICODE) ?>;

    function irASemana(val) {
        if (val) window.location.href = '?semana=' + val;
    }

    function toggleModo(modo) {
        if (modo === 'personalizado') {
            var s = document.getElementById('customStart').value;
            var e = document.getElementById('customEnd').value;
            window.location.href = '?modo=personalizado&start=' + s + '&end=' + e;
        } else {
            window.location.href = '?modo=semanal&semana=<?= $semana_actual_str ?>';
        }
    }

    function applyCustomRange() {
        var s = document.getElementById('customStart').value;
        var e = document.getElementById('customEnd').value;
        if (s && e) {
            window.location.href = '?modo=personalizado&start=' + s + '&end=' + e;
        }
    }

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            document.getElementById('tab-' + this.dataset.tab).classList.add('active');
        });
    });

    function abrirModal(id) {
        const p = pagosData.find(d => d.transaction_id === id);
        if (!p) return;
        document.getElementById('modalTitle').textContent = p.transaction_id;
        let html = '<div class="detail-grid">';
        const fields = [
            ['ID Transacción', p.transaction_id],
            ['Estado', '<span class="status-badge ' + (p.status_label === 'Completado' ? 'success' : (p.is_refunded ? 'failed' : 'warning')) + '">' + p.status_label + '</span>'],
            ['Cliente', p.payer_name],
            ['Email', p.payer_email],
            ['País', p.payer_country],
            ['Fecha', p.initiation_date ? new Date(p.initiation_date).toLocaleString('es-MX') : '—'],
            ['Monto', p.amount_formatted],
            ['Moneda', p.currency],
            ['Comisión', p.fee_formatted],
            ['Código Evento', p.event_code],
            ['Factura ID', p.invoice_id || '—'],
            ['Custom Field', p.custom_field || '—'],
        ];

        if (p.is_refunded) {
            fields.push(['Reembolsado', 'Sí']);
            fields.push(['Tipo', p.refund_type === 'full' ? 'Total' : 'Parcial']);
            if (p.refund_info?.refund_id) {
                fields.push(['ID Reembolso', p.refund_info.refund_id]);
            }
        }

        if (p.trackers && p.trackers.length > 0) {
            const trackHtml = p.trackers.map(t => (t.carrier || '') + ': ' + (t.tracking_number || '')).join('<br>');
            fields.push(['Rastreo', trackHtml]);
        }

        if (p.subject) {
            fields.push(['Asunto', p.subject]);
        }

        fields.forEach(([label, value]) => {
            html += '<div class="detail-item"><div class="detail-label">' + label + '</div><div class="detail-value">' + value + '</div></div>';
        });

        html += '</div>';
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

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') cerrarModal();
    });
    </script>

    </body>
    </html>
    <?php endif; ?>

<?php

function renderTablaPaypal(array $pagos): void
{
    if (empty($pagos)): ?>
        <div class="loading">No hay movimientos en este período.</div>
    <?php else: ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Email</th>
                        <th>Monto</th>
                        <th>Comisión</th>
                        <th>Neto</th>
                        <th>Evento</th>
                        <th>Estado</th>
                        <th>ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagos as $i => $p): ?>
                        <tr onclick="<?= $embed ? "parent.abrirDetalleModalReporte('" . htmlspecialchars($p['transaction_id'], ENT_QUOTES) . "')" : "abrirModal('" . htmlspecialchars($p['transaction_id'], ENT_QUOTES) . "')" ?>"
                            data-id="<?= htmlspecialchars($p['transaction_id']) ?>">
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td class="text-nowrap"><?= $p['initiation_date'] ? date('d/m/Y H:i', strtotime($p['initiation_date'])) : '—' ?></td>
                            <td><?= htmlspecialchars($p['payer_name']) ?></td>
                            <td><?= htmlspecialchars($p['payer_email']) ?></td>
                            <td class="text-right"><span class="currency-badge"><?= htmlspecialchars($p['currency']) ?></span> <?= $p['amount_formatted'] ?></td>
                            <td class="text-right text-muted"><?= $p['fee_formatted'] ?></td>
                            <td class="text-right"><?= $p['neto_formatted'] ?></td>
                            <td class="font-mono text-sm"><?= htmlspecialchars($p['event_code']) ?></td>
                            <td>
                                <span class="status-badge <?= $p['status_label'] === 'Completado' ? 'success' : ($p['is_refunded'] ? 'failed' : 'warning') ?>">
                                    <?= htmlspecialchars($p['status_label']) ?>
                                </span>
                            </td>
                            <td class="payment-id"><?= htmlspecialchars($p['transaction_id']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif;
}

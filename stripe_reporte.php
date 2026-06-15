<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/stripe_helper.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    exit;
}

checkRateLimit(20, 60);

$modo = $_GET['modo'] ?? 'semanal';

if ($modo === 'personalizado') {
    $customStart = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
    $customEnd   = $_GET['end'] ?? date('Y-m-d');
    $customStartDt = new DateTime($customStart);
    $customEndDt   = new DateTime($customEnd);
    $customEndDt->setTime(23, 59, 59);
    $range_inicio_ts = $customStartDt->getTimestamp();
    $range_fin_ts    = $customEndDt->getTimestamp();
    $semana = obtenerSemana();
    $semana_ref = null;
} else {
    $semana_ref = $_GET['semana'] ?? null;
    $semana = obtenerSemana($semana_ref);
    $range_inicio_ts = $semana['inicio_ts'];
    $range_fin_ts = $semana['fin_ts'];
}

$error_api = null;
$exitosos = [];
$fallidos = [];
$reembolsadas = [];
$todos = [];
$resumen = ['total_exitosos' => 0, 'total_fallidos' => 0, 'monto_total_formateado' => '$ 0.00', 'moneda' => CURRENCY];

try {
    $resultado = obtenerPagosPorRango($range_inicio_ts, $range_fin_ts);

    if ($resultado['error']) {
        $error_api = $resultado['error'];
    } else {
        $intents = $resultado['data'];
        $clasificados = clasificarPagos($intents);
        $exitosos = $clasificados['exitosos'];
        $fallidos = $clasificados['fallidos'];
        $reembolsadas = array_filter($exitosos, fn($p) => ($p['reembolsado'] ?? false));
        $exitosos = array_filter($exitosos, fn($p) => !($p['reembolsado'] ?? false));
        $exitosos = array_values($exitosos);
        $reembolsadas = array_values($reembolsadas);
        $resumen = resumenPagos($exitosos, $fallidos, $reembolsadas);
        $todos = array_merge($exitosos, $reembolsadas, $fallidos);
        usort($todos, fn($a, $b) => $b['created_ts'] - $a['created_ts']);
    }
} catch (Exception $e) {
    $error_api = $e->getMessage();
}

$total_pagos = count($exitosos) + count($reembolsadas) + count($fallidos);

$semana_anterior = (new DateTime($semana['inicio_str'] ?? date('Y-m-d')))->modify('-7 days')->format('Y-m-d');
$semana_siguiente = (new DateTime($semana['inicio_str'] ?? date('Y-m-d')))->modify('+7 days')->format('Y-m-d');
$semana_actual_str = (new DateTime())->format('Y-m-d');

$fecha_actual = new DateTime();
$semana_actual_inicio = obtenerSemana($fecha_actual->format('Y-m-d'));
$es_semana_actual = $modo === 'semanal' && ($semana['inicio_str'] ?? '') === $semana_actual_inicio['inicio_str'];

$maxDate = date('Y-m-d');

$label_inicio = $modo === 'personalizado' ? ($_GET['start'] ?? $semana['inicio_str']) : $semana['inicio_str'];
$label_fin    = $modo === 'personalizado' ? ($_GET['end'] ?? $semana['fin_str']) : $semana['fin_str'];

$embed = isset($_GET['embed']) && $_GET['embed'] === '1';

$export_params = '';
if ($modo === 'personalizado') {
    $export_params = "modo=personalizado&start={$label_inicio}&end={$label_fin}";
} else {
    $export_params = "semana=" . urlencode($semana_ref ?? $semana_actual_str);
}

// --- Export handlers ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportarCSVSemanal($todos, $label_inicio, $label_fin);
}
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    generarPDF($todos, $label_inicio, $label_fin, $resumen);
}
if (isset($_GET['guardar']) && $_GET['guardar'] === '1') {
    $dir = __DIR__ . '/reportes';
    $csvPath = guardarCSVSemanal($todos, $label_inicio, $label_fin, $dir);
    $pdfPath = guardarPDFSemanal($todos, $label_inicio, $label_fin, $resumen, $dir);
    $mensaje = "Reporte guardado: " . basename($csvPath);
    if ($pdfPath) $mensaje .= " y " . basename($pdfPath);
}
?>
<?php if ($embed): ?>
<main class="dashboard-content">
    <div class="semana-navegacion" style="margin-bottom:1rem;">
        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;width:100%;">
            <div class="modo-tabs" style="display:flex;gap:0.25rem;margin-right:0.5rem;">
                <button class="btn btn-sm <?= $modo === 'semanal' ? 'btn-primary' : '' ?>" onclick="parent.toggleModoReporteStripe('semanal')">Semanal</button>
                <button class="btn btn-sm <?= $modo === 'personalizado' ? 'btn-primary' : '' ?>" onclick="parent.toggleModoReporteStripe('personalizado')">Personalizado</button>
            </div>

            <?php if ($modo === 'semanal'): ?>
                <button class="btn btn-sm" onclick="parent.navReporteStripe(-1)">←</button>
                <div class="semana-selector" style="display:flex;align-items:center;gap:0.5rem;">
                    <span class="semana-rango"><?= $semana['inicio_display'] ?? '' ?> — <?= $semana['fin_display'] ?? '' ?></span>
                    <input type="date" id="semanaDatePickerEmbed" value="<?= $semana['inicio_str'] ?? '' ?>" style="max-width:140px;">
                </div>
                <?php if (!$es_semana_actual): ?>
                    <button class="btn btn-sm" onclick="parent.navReporteStripe(1)">→</button>
                <?php else: ?>
                    <span class="btn btn-sm" style="opacity:0.5;cursor:not-allowed;">→</span>
                <?php endif; ?>
                <button class="btn btn-sm <?= $es_semana_actual ? 'btn-primary' : '' ?>" onclick="parent.loadStripeReporte('today')">Semana actual</button>
            <?php else: ?>
                <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                    <input type="date" id="customStartEmbed" value="<?= htmlspecialchars($_GET['start'] ?? date('Y-m-d', strtotime('-7 days'))) ?>" max="<?= $maxDate ?>" style="max-width:150px;">
                    <span>a</span>
                    <input type="date" id="customEndEmbed" value="<?= htmlspecialchars($_GET['end'] ?? date('Y-m-d')) ?>" max="<?= $maxDate ?>" style="max-width:150px;">
                    <button class="btn btn-sm btn-primary" onclick="parent.applyCustomRangeStripe()">Aplicar</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Semanal - Stripe</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/reporte-semanal.css">
</head>
<body>
    <header class="dashboard-header">
        <h1>💳 Reporte Stripe <?= $modo === 'personalizado' ? '— Rango personalizado' : '— Semanal' ?></h1>
        <div class="header-actions">
            <a href="stripe_dashboard.php" class="btn-icon" title="Volver a Stripe">⬅ Stripe</a>
            <div class="user-info">
                <span><?= htmlspecialchars($_SESSION['usuario'] ?? ADMIN_USER) ?></span>
                <a href="logout.php" class="btn-logout">Salir</a>
            </div>
        </div>
    </header>
    <main class="dashboard-content">
        <div class="semana-navegacion">
            <div class="modo-toggle">
                <a href="?modo=semanal<?= $modo === 'semanal' ? '&' . $export_params : '' ?>" class="modo-btn <?= $modo === 'semanal' ? 'active' : '' ?>">📅 Semanal</a>
                <a href="?modo=personalizado&start=<?= date('Y-m-d', strtotime('-7 days')) ?>&end=<?= date('Y-m-d') ?>" class="modo-btn <?= $modo === 'personalizado' ? 'active' : '' ?>">📅 Personalizado</a>
            </div>

            <div class="nav-mode mode-semanal" style="display:<?= $modo === 'semanal' ? 'flex' : 'none' ?>;">
                <a href="?<?= $export_params ?>&semana=<?= $semana_anterior ?>" class="btn">← Anterior</a>
                <div class="semana-selector">
                    <span class="semana-rango"><?= $semana['inicio_display'] ?? '' ?> — <?= $semana['fin_display'] ?? '' ?></span>
                    <input type="date" id="semanaDatePicker" value="<?= $semana['inicio_str'] ?? '' ?>" onchange="irASemanaStripe(this.value)">
                </div>
                <?php if (!$es_semana_actual): ?>
                    <a href="?<?= $export_params ?>&semana=<?= $semana_siguiente ?>" class="btn">Siguiente →</a>
                <?php else: ?>
                    <span class="btn btn-disabled" style="opacity:0.5;cursor:not-allowed;">Siguiente →</span>
                <?php endif; ?>
                <a href="?semana=<?= $semana_actual_str ?>" class="btn btn-sm <?= $es_semana_actual ? 'btn-primary' : '' ?>">Semana actual</a>
            </div>

            <div class="nav-mode mode-personalizado" style="display:<?= $modo === 'personalizado' ? 'flex' : 'none' ?>;">
                <a href="?modo=personalizado&start=<?= $semana_anterior ?>&end=<?= date('Y-m-d', strtotime($semana_anterior . ' +6 days')) ?>" class="btn">← Anterior</a>
                <div class="custom-range-inputs">
                    <label>Desde:</label>
                    <input type="date" id="customStart" value="<?= htmlspecialchars($_GET['start'] ?? date('Y-m-d', strtotime('-7 days'))) ?>">
                    <label>Hasta:</label>
                    <input type="date" id="customEnd" value="<?= htmlspecialchars($_GET['end'] ?? date('Y-m-d')) ?>">
                    <button class="btn btn-primary" onclick="applyCustomRangeStripe()">🔍 Generar</button>
                </div>
                <a href="?modo=personalizado&start=<?= $semana_siguiente ?>&end=<?= date('Y-m-d', strtotime($semana_siguiente . ' +6 days')) ?>" class="btn">Siguiente →</a>
                <a href="?modo=personalizado&start=<?= $semana_actual_str ?>&end=<?= $semana_actual_str ?>" class="btn btn-sm">Hoy</a>
            </div>
        </div>
<?php endif; ?>

        <?php if (isset($mensaje)): ?>
            <div class="alert alert-info"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <?php if ($error_api): ?>
            <div class="alert alert-error">
                <strong>Error de conexión con Stripe:</strong>
                <?= htmlspecialchars($error_api) ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-label">Total Movimientos</div>
                <div class="stat-value"><?= $total_pagos ?></div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">Exitosos</div>
                <div class="stat-value"><?= count($exitosos) ?></div>
            </div>
            <div class="stat-card failed">
                <div class="stat-label">Fallidos</div>
                <div class="stat-value"><?= count($fallidos) ?></div>
            </div>
            <div class="stat-card currency">
                <div class="stat-label">Total Recaudado</div>
                <div class="stat-value" style="font-size:1.25rem;"><?= $resumen['monto_total_formateado'] ?></div>
            </div>
            <div class="stat-card currency">
                <div class="stat-label">Total MXN</div>
                <div class="stat-value" style="font-size:1.1rem;word-break:break-word;"><?= htmlspecialchars($resumen['monto_total_mxn_formateado'] ?? '$ 0.00') ?></div>
            </div>
        </div>

        <?php if ($total_pagos > 0): ?>
            <div class="reporte-acciones">
                <a href="<?= $embed ? 'stripe_reporte.php?' : '?' ?>export=csv&<?= $export_params ?>"<?= $embed ? ' target="_blank"' : '' ?> class="btn">⬇ Exportar CSV</a>
                <a href="<?= $embed ? 'stripe_reporte.php?' : '?' ?>export=pdf&<?= $export_params ?>"<?= $embed ? ' target="_blank"' : '' ?> class="btn">📄 Descargar PDF</a>
                <a href="<?= $embed ? 'stripe_reporte.php?' : '?' ?>guardar=1&<?= $export_params ?>"<?= $embed ? ' target="_blank"' : '' ?> class="btn">💾 Guardar en servidor</a>
                <span class="reporte-info text-muted"><?= $total_pagos ?> movimiento(s) · <?= count($reembolsadas) ?> reembolsado(s)</span>
            </div>
        <?php endif; ?>

        <div class="tabs">
            <button class="tab-btn active" data-tab="todos">Todos <span class="badge"><?= $total_pagos ?></span></button>
            <button class="tab-btn" data-tab="exitosos">Exitosos <span class="badge"><?= count($exitosos) ?></span></button>
            <button class="tab-btn" data-tab="reembolsadas">Reembolsadas <span class="badge"><?= count($reembolsadas) ?></span></button>
            <button class="tab-btn" data-tab="fallidos">Fallidos <span class="badge"><?= count($fallidos) ?></span></button>
        </div>

        <div class="tab-content active" id="tab-todos">
            <?php renderTablaStripeReporte($todos); ?>
        </div>
        <div class="tab-content" id="tab-exitosos">
            <?php renderTablaStripeReporte($exitosos); ?>
        </div>
        <div class="tab-content" id="tab-reembolsadas">
            <?php renderTablaStripeReporte($reembolsadas); ?>
        </div>
        <div class="tab-content" id="tab-fallidos">
            <?php renderTablaStripeReporte($fallidos); ?>
        </div>
    </main>

    <?php if ($embed): ?>
    <script id="reporteStripeData" type="application/json"><?= json_encode(['data' => $todos, 'modo' => $modo, 'start' => $label_inicio, 'end' => $label_fin], JSON_UNESCAPED_UNICODE) ?></script>
    <?php else: ?>
    <div class="modal-overlay" id="modalOverlay" onclick="cerrarModalOutsideStripe(event)">
        <div class="modal">
            <div class="modal-header">
                <h2 id="modalTitle">Pago</h2>
                <button class="modal-close" onclick="cerrarModalStripe()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>

    <script>
    const pagosDataStripe = <?= json_encode($todos, JSON_UNESCAPED_UNICODE) ?>;

    function irASemanaStripe(val) {
        if (val) window.location.href = '?semana=' + val;
    }

    function toggleModoStripe(modo) {
        if (modo === 'personalizado') {
            var s = document.getElementById('customStart').value;
            var e = document.getElementById('customEnd').value;
            window.location.href = '?modo=personalizado&start=' + s + '&end=' + e;
        } else {
            window.location.href = '?modo=semanal&semana=<?= $semana_actual_str ?>';
        }
    }

    function applyCustomRangeStripe() {
        var s = document.getElementById('customStart').value;
        var e = document.getElementById('customEnd').value;
        if (s && e) {
            window.location.href = '?modo=personalizado&start=' + s + '&end=' + e;
        }
    }

    document.querySelectorAll('.tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
            document.querySelectorAll('.tab-content').forEach(function(t) { t.classList.remove('active'); });
            this.classList.add('active');
            document.getElementById('tab-' + this.dataset.tab).classList.add('active');
        });
    });

    function abrirModalStripe(id) {
        const p = pagosDataStripe.find(function(d) { return d.id === id; });
        if (!p) return;
        document.getElementById('modalTitle').textContent = p.id;
        let html = '<div class="detail-grid">';
        const fields = [
            ['ID', p.id],
            ['Estado', '<span class="status-badge ' + (p.estado === 'Exitoso' ? 'success' : 'failed') + '">' + p.estado + '</span>'],
            ['Cliente', p.nombre],
            ['Email', p.email],
            ['Teléfono', p.telefono],
            ['Fecha', p.fecha_actualizacion],
            ['Monto', p.monto_formateado],
            ['MXN', p.monto_mxn_formateado || '—'],
            ['Recibido', p.monto_recibido_formateado || '—'],
            ['Comisión', p.comision_formateado || '—'],
            ['Moneda', p.moneda],
            ['Tarjeta', p.card_brand !== '—' ? p.card_brand + ' ****' + p.card_last4 + ' (' + p.card_funding + ')' : (p.metodo_pago || '—')],
            ['Descripción', p.descripcion || '—'],
            ['Cliente Stripe ID', p.cliente_id || '—'],
        ];
        if (p.estado === 'Fallido') {
            fields.push(['Motivo', p.motivo || '—']);
            fields.push(['Código Error', p.error_code || '—']);
            fields.push(['Código Declinación', p.decline_code || '—']);
            fields.push(['Tipo Error', p.error_tipo || '—']);
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
            html += '<div style="margin-top:1rem;text-align:center;"><button class="btn btn-sm" onclick="event.stopPropagation();abrirModalRefundStripe(\'' + p.id + '\')">ℹ Reembolsar</button></div>';
        }
        document.getElementById('modalBody').innerHTML = html;
        document.getElementById('modalOverlay').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function cerrarModalStripe() {
        document.getElementById('modalOverlay').classList.remove('active');
        document.body.style.overflow = '';
    }

    function cerrarModalOutsideStripe(e) {
        if (e.target === e.currentTarget) cerrarModalStripe();
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') cerrarModalStripe();
    });

    document.getElementById('modalOverlay').addEventListener('click', function(e) {
        if (e.target === this) cerrarModalStripe();
    });

    function abrirModalRefundStripe(paymentId) {
        const p = pagosDataStripe.find(function(d) { return d.id === paymentId; });
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
            toast('Ingresa un monto válido para el reembolso parcial', 'error');
            return;
        }
        const submitBtn = document.getElementById('refundSubmitBtn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Procesando...';
        const formData = new FormData(document.getElementById('refundForm'));
        formData.append('charge_id', chargeId);
        formData.append('amount', isPartial ? amount : '0');
        Swal.fire({
            title: '¿Reembolsar este pago?',
            text: isPartial ? 'Monto: $' + parseFloat(amount).toFixed(2) : 'Reembolso total',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, reembolsar',
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
                toast('Error de conexión al procesar reembolso', 'error');
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
    </script>
    </body>
    </html>
    <?php endif; ?>

<?php
function renderTablaStripeReporte(array $pagos): void
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
                        <th>Teléfono</th>
                        <th>Monto</th>
                        <th>MXN</th>
                        <th>Recibido (MXN)</th>
                        <th>Tarjeta</th>
                        <th>Método</th>
                        <th>Estado</th>
                        <th>Error</th>
                        <th>ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagos as $i => $p): ?>
                        <tr onclick="<?php global $embed; echo $embed ? "parent.abrirDetalleModalReporteStripe('" . htmlspecialchars($p['id'], ENT_QUOTES) . "')" : "abrirModalStripe('" . htmlspecialchars($p['id'], ENT_QUOTES) . "')" ?>"
                            data-id="<?= htmlspecialchars($p['id']) ?>">
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td class="text-nowrap"><?= htmlspecialchars($p['fecha_actualizacion']) ?></td>
                            <td>
                                <?= htmlspecialchars($p['nombre']) ?>
                                <?php if (!empty($p['cliente_id'])): ?>
                                    <br><span class="text-muted" style="font-size:0.65rem;">ID: <?= htmlspecialchars($p['cliente_id']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($p['email']) ?></td>
                            <td><?= htmlspecialchars($p['telefono']) ?></td>
                            <td class="text-right"><?= htmlspecialchars($p['monto_formateado']) ?></td>
                            <td class="text-right"><?= htmlspecialchars($p['monto_mxn_formateado'] ?? '—') ?></td>
                            <td class="text-right"><?php
                                $feeMxn = $p['moneda'] === 'USD' ? ($p['comision_decimal'] * $p['tipo_cambio_usado']) : $p['comision_decimal'];
                                $recMxn = max(0, $p['monto_mxn'] - $feeMxn);
                                echo htmlspecialchars('$ ' . number_format($recMxn, 2));
                            ?></td>
                            <td>
                                <?php if ($p['card_brand'] !== '—'): ?>
                                    <span class="card-badge"><?= htmlspecialchars($p['card_brand']) ?> ****<?= htmlspecialchars($p['card_last4']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($p['metodo_pago'] ?? '—') ?></td>
                            <td>
                                <span class="status-badge <?= $p['estado'] === 'Exitoso' ? 'success' : 'failed' ?>">
                                    <?= $p['estado'] ?>
                                </span>
                            </td>
                            <td class="text-muted" style="max-width:140px;word-break:break-word;font-size:0.75rem;">
                                <?= htmlspecialchars($p['motivo'] ?? '—') ?>
                            </td>
                            <td class="payment-id"><?= htmlspecialchars($p['id']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif;
}
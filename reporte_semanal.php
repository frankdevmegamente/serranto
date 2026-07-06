<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/stripe_helper.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

checkRateLimit(30, 60);

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

// --- Export CSV ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    exportarCSVSemanal($todos, $label_inicio, $label_fin);
}

// --- Export PDF ---
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    generarPDF($todos, $label_inicio, $label_fin, $resumen);
}

// --- Save (for scheduled generation) ---
if (isset($_GET['guardar']) && $_GET['guardar'] === '1') {
    $dir = __DIR__ . '/reportes';
    $csvPath = guardarCSVSemanal($todos, $label_inicio, $label_fin, $dir);
    $pdfPath = guardarPDFSemanal($todos, $label_inicio, $label_fin, $resumen, $dir);
    $mensaje = "Reporte guardado: " . basename($csvPath);
    if ($pdfPath) {
        $mensaje .= " y " . basename($pdfPath);
    }
}
?>
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
        <h1>Reporte Semanal</h1>
        <div class="header-actions">
            <a href="dashboard.php" class="btn-icon" title="Volver al Dashboard">⬅ Dashboard</a>
            <div class="user-info">
                <span><?= htmlspecialchars(ADMIN_USER) ?></span>
                <a href="logout.php" class="btn-logout">Salir</a>
            </div>
        </div>
    </header>

    <main class="dashboard-content">

        <div class="semana-navegacion">
            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;width:100%;">
                <div class="modo-tabs" style="display:flex;gap:0.25rem;margin-right:0.5rem;">
                    <a href="?modo=semanal<?= isset($semana_ref) ? '&semana=' . urlencode($semana_ref) : '' ?>" class="btn btn-sm <?= $modo === 'semanal' ? 'btn-primary' : '' ?>">Semanal</a>
                    <a href="?modo=personalizado" class="btn btn-sm <?= $modo === 'personalizado' ? 'btn-primary' : '' ?>">Personalizado</a>
                </div>

                <?php if ($modo === 'semanal'): ?>
                    <a href="?semana=<?= $semana_anterior ?>" class="btn btn-sm">←</a>
                    <div class="semana-selector" style="display:flex;align-items:center;gap:0.5rem;">
                        <span class="semana-rango"><?= $semana['inicio_display'] ?? '' ?> — <?= $semana['fin_display'] ?? '' ?></span>
                        <input type="date" id="semanaDatePicker" value="<?= $semana['inicio_str'] ?? '' ?>" onchange="irASemana(this.value)" style="max-width:140px;">
                    </div>
                    <?php if (!$es_semana_actual): ?>
                        <a href="?semana=<?= $semana_siguiente ?>" class="btn btn-sm">→</a>
                    <?php else: ?>
                        <span class="btn btn-sm" style="opacity:0.5;cursor:not-allowed;">→</span>
                    <?php endif; ?>
                    <a href="?modo=semanal&semana=<?= $semana_actual_str ?>" class="btn btn-sm <?= $es_semana_actual ? 'btn-primary' : '' ?>">Semana actual</a>
                <?php else: ?>
                    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                        <input type="date" id="customStart" value="<?= htmlspecialchars($_GET['start'] ?? date('Y-m-d', strtotime('-7 days'))) ?>" max="<?= $maxDate ?>" style="max-width:150px;">
                        <span>a</span>
                        <input type="date" id="customEnd" value="<?= htmlspecialchars($_GET['end'] ?? date('Y-m-d')) ?>" max="<?= $maxDate ?>" style="max-width:150px;">
                        <button class="btn btn-sm btn-primary" onclick="aplicarCustom()">Aplicar</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

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
                <div class="stat-value"><?= $resumen['total_exitosos'] ?></div>
            </div>
            <div class="stat-card failed">
                <div class="stat-label">Fallidos</div>
                <div class="stat-value"><?= $resumen['total_fallidos'] ?></div>
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
                <?php
                $exportParams = $modo === 'personalizado'
                    ? 'modo=personalizado&start=' . urlencode($_GET['start'] ?? '') . '&end=' . urlencode($_GET['end'] ?? '')
                    : 'semana=' . urlencode($semana_ref ?? $semana_actual_str);
            ?>
            <a href="?export=csv&<?= $exportParams ?>" class="btn">⬇ Exportar CSV</a>
            <a href="?export=pdf&<?= $exportParams ?>" class="btn">📄 Descargar PDF</a>
            <a href="?guardar=1&<?= $exportParams ?>" class="btn">💾 Guardar en servidor</a>
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
            <?php renderTablaSemanal($todos); ?>
        </div>
        <div class="tab-content" id="tab-exitosos">
            <?php renderTablaSemanal($exitosos); ?>
        </div>
        <div class="tab-content" id="tab-reembolsadas">
            <?php renderTablaSemanal($reembolsadas); ?>
        </div>
        <div class="tab-content" id="tab-fallidos">
            <?php renderTablaSemanal($fallidos); ?>
        </div>

    </main>

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

    <script>
    const pagosData = <?= json_encode($todos, JSON_UNESCAPED_UNICODE) ?>;

    function irASemana(val) {
        if (val) window.location.href = '?modo=semanal&semana=' + val;
    }

    function aplicarCustom() {
        const s = document.getElementById('customStart');
        const e = document.getElementById('customEnd');
        if (s && e && s.value && e.value) {
            if (s.value > e.value) { alert('La fecha de inicio debe ser anterior a la fecha de fin.'); return; }
            window.location.href = '?modo=personalizado&start=' + encodeURIComponent(s.value) + '&end=' + encodeURIComponent(e.value);
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
        const p = pagosData.find(d => d.id === id);
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
            ['Comisi\u00f3n', p.comision_formateado || '—'],
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
        fields.forEach(([label, value]) => {
            html += '<div class="detail-item"><div class="detail-label">' + label + '</div><div class="detail-value">' + value + '</div></div>';
        });
        if (p.metadata && Object.keys(p.metadata).length) {
            html += '<div class="detail-item full"><div class="detail-label">Metadata</div><div class="detail-value"><pre style="font-size:0.75rem;background:var(--bg-code);padding:0.5rem;border-radius:var(--radius-sm);overflow-x:auto;">' + JSON.stringify(p.metadata, null, 2) + '</pre></div></div>';
        }
        html += '</div>';
        if (p.estado === 'Exitoso' && !p.reembolsado && p.charge_id) {
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

    // === REFUND ===
    function abrirModalRefund(paymentId) {
        const p = pagosData.find(function(d) { return d.id === paymentId; });
        if (!p || !p.charge_id) { return; }
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
        document.getElementById('refundAmountGroup').style.display = document.getElementById('refundType').value === 'partial' ? 'block' : 'none';
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
        if (isPartial && (!amount || parseFloat(amount) <= 0)) { alert('Ingresa un monto v\u00e1lido'); return false; }
        const submitBtn = document.getElementById('refundSubmitBtn');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Procesando...';
        const formData = new FormData(document.getElementById('refundForm'));
        formData.append('charge_id', chargeId);
        formData.append('amount', isPartial ? amount : '0');
        if (!confirm(isPartial ? 'Reembolso parcial de $' + parseFloat(amount).toFixed(2) + '?' : '\u00bfReembolso total?')) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Procesar reembolso';
            return false;
        }
        fetch('requests/refund_stripe.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': document.querySelector('input[name="csrf_token"]')?.value || '' },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status) { alert('Reembolso exitoso: $' + data.data.amount + ' ' + data.data.currency); cerrarModalRefund(); }
            else { alert(data.message || 'Error'); }
        })
        .catch(function() { alert('Error de conexi\u00f3n'); })
        .finally(function() { submitBtn.disabled = false; submitBtn.textContent = 'Procesar reembolso'; });
        return false;
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { cerrarModal(); cerrarModalRefund(); }
    });
    </script>

</body>
</html>

<?php

function renderTablaSemanal(array $pagos): void
{
    if (empty($pagos)): ?>
        <div class="loading">No hay movimientos en esta semana.</div>
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
                            <tr onclick="abrirModal('<?= htmlspecialchars($p['id'], ENT_QUOTES) ?>')"
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
                                    $recMxn = max(0, $p['monto_mxn'] - $p['comision_decimal']);
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

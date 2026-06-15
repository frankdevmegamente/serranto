<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

/**
 * Obtiene los payment intents de Stripe paginados.
 *
 * @param int    $limit  Máximo de resultados por página (1-100)
 * @param string $cursor ID del último elemento de la página anterior (para paginación)
 * @return array ['data' => [...], 'has_more' => bool]
 */
function obtenerPaymentIntents(int $limit = 50, ?string $cursor = null): array
{
    $params = [
        'limit'  => min($limit, 100),
        'expand' => ['data.latest_charge'],
    ];

    if ($cursor) {
        $params['starting_after'] = $cursor;
    }

    try {
        $intents = \Stripe\PaymentIntent::all($params);
        return [
            'data'     => $intents->data,
            'has_more' => $intents->has_more,
        ];
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('Stripe API Error: ' . $e->getMessage());
        return ['data' => [], 'has_more' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Clasifica los payment intents en exitosos y fallidos.
 *
 * @param array $intents Lista de PaymentIntent objects
 * @return array ['exitosos' => [...], 'fallidos' => [...]]
 */
function clasificarPagos(array $intents): array
{
    $exitosos = [];
    $fallidos = [];
    $reembolsadas = [];

    foreach ($intents as $pago) {
        $charge = $pago->latest_charge;
        $chargeData = null;
        if ($charge && is_object($charge)) {
            $chargeData = $charge;
        }

        $billing = $chargeData->billing_details ?? null;

        $emailVal = '—';
        if ($pago->receipt_email) {
            $emailVal = $pago->receipt_email;
        } elseif ($chargeData && $chargeData->receipt_email) {
            $emailVal = $chargeData->receipt_email;
        } elseif ($billing && $billing->email) {
            $emailVal = $billing->email;
        }

        $nombreVal = $billing->name ?? $pago->metadata->customer_name ?? $pago->metadata->nombre ?? '—';

        $comision = max(0, $pago->amount - $pago->amount_received);
        $montoDecimal = $pago->amount / 100;
        $monedaUpper = strtoupper($pago->currency);
        $tcActual = null;
        if ($monedaUpper === 'USD') {
            $tcInfo = obtenerInfoTipoCambio();
            $tcActual = $tcInfo['rate'];
        }
        $montoMxn = $monedaUpper === 'USD' ? round($montoDecimal * $tcActual, 2) : $montoDecimal;
        $pagoData = [
            'id'               => $pago->id,
            'charge_id'        => null,
            'monto'            => $pago->amount,
            'monto_recibido'   => $pago->amount_received,
            'monto_refunded'   => $pago->amount_refunded ?? 0,
            'comision'         => $comision,
            'monto_decimal'    => $montoDecimal,
            'monto_mxn'        => $montoMxn,
            'monto_mxn_formateado' => '$ ' . number_format($montoMxn, 2),
            'tipo_cambio_usado' => $tcActual,
            'monto_recibido_decimal' => $pago->amount_received / 100,
            'comision_decimal' => $comision / 100,
            'status'           => $pago->status,
            'created_ts'       => $pago->created,
            'moneda'           => $monedaUpper,
            'monto_formateado' => formatoMoneda($pago->amount, $pago->currency),
            'monto_recibido_formateado' => formatoMoneda($pago->amount_received, $pago->currency),
            'comision_formateado' => formatoMoneda($comision, $pago->currency),
            'email'            => $emailVal,
            'nombre'           => $nombreVal,
            'telefono'         => ($billing ? $billing->phone : null) ?? '—',
            'descripcion'      => $pago->description ?? '—',
            'metadata'         => $pago->metadata->toArray() ?: [],
            'metadata_txt'     => json_encode($pago->metadata->toArray() ?: [], JSON_UNESCAPED_UNICODE),
            'cliente_id'       => $pago->customer ?? null,
            'fecha_creacion'   => date('d/m/Y H:i', $pago->created),
            'fecha_actualizacion' => date('d/m/Y H:i', $pago->created),
            'tipo'             => null,
        ];

        if ($chargeData) {
            $pagoData['fecha_actualizacion'] = date('d/m/Y H:i', $chargeData->created);
            $pagoData['charge_id'] = $chargeData->id ?? null;
            $pagoData['receipt_url'] = $chargeData->receipt_url ?? null;
            $pagoData['receipt_email'] = $chargeData->receipt_email ?? null;

            $pmd = $chargeData->payment_method_details;
            if ($pmd && $pmd->type === 'card' && $pmd->card) {
                $pagoData['card_brand']  = $pmd->card->brand ?? '—';
                $pagoData['card_last4']  = $pmd->card->last4 ?? '—';
                $pagoData['card_funding'] = $pmd->card->funding ?? '—';
                $pagoData['card_country'] = $pmd->card->country ?? '—';
                $pagoData['metodo_pago'] = $pmd->card->brand . ' ****' . $pmd->card->last4;
            } else {
                $pagoData['card_brand']  = '—';
                $pagoData['card_last4']  = '—';
                $pagoData['card_funding'] = '—';
                $pagoData['card_country'] = '—';
                $pagoData['metodo_pago'] = $pmd->type ?? '—';
            }
        } else {
            $pagoData['receipt_url']   = null;
            $pagoData['receipt_email'] = null;
            $pagoData['card_brand']    = '—';
            $pagoData['card_last4']    = '—';
            $pagoData['card_funding']  = '—';
            $pagoData['card_country']  = '—';
            $pagoData['metodo_pago']   = '—';
        }

        $pagoData['reembolsado'] = ($pagoData['monto_refunded'] ?? 0) > 0;

        if ($pago->status === 'succeeded') {
            if ($pagoData['reembolsado']) {
                $pagoData['estado'] = 'Reembolsado';
                $pagoData['tipo']   = 'reembolsado';
                $pagoData['status_label'] = 'Reembolsado';
                $reembolsadas[] = $pagoData;
            } else {
                $pagoData['estado'] = 'Exitoso';
                $pagoData['tipo']   = 'exitoso';
                $pagoData['status_label'] = 'Exitoso';
                $exitosos[] = $pagoData;
            }
        } elseif (in_array($pago->status, ['requires_payment_method', 'requires_action', 'canceled'])) {
            $pagoData['estado'] = 'Fallido';
            $pagoData['tipo']   = 'fallido';
            $pagoData['status_label'] = 'Fallido';
            $error = $pago->last_payment_error;
            if ($error) {
                $pagoData['error_code']   = $error->code ?? '—';
                $pagoData['error_tipo']   = $error->type ?? '—';
                $pagoData['decline_code'] = $error->decline_code ?? '—';
                $pagoData['motivo']       = $error->message ?? 'Sin mensaje de error';
            } else {
                $pagoData['error_code']   = '—';
                $pagoData['error_tipo']   = '—';
                $pagoData['decline_code'] = '—';
                $pagoData['motivo']       = 'Error desconocido';
            }
            $fallidos[] = $pagoData;
        }
    }

    return [
        'exitosos' => $exitosos,
        'fallidos' => $fallidos,
        'reembolsadas' => $reembolsadas,
    ];
}

/**
 * Agrupa pagos exitosos por día para la gráfica.
 */
function agruparPorDia(array $pagos): array
{
    $agrupados = [];
    foreach ($pagos as $pago) {
        $dia = date('Y-m-d', $pago->created);
        if (!isset($agrupados[$dia])) {
            $agrupados[$dia] = ['fecha' => $dia, 'conteo' => 0, 'total' => 0];
        }
        $agrupados[$dia]['conteo']++;
        $agrupados[$dia]['total'] += $pago->amount;
    }
    ksort($agrupados);
    return array_values($agrupados);
}

/**
 * Formatea un monto de Stripe (centavos) a string legible.
 */
function formatoMoneda(int $centavos, string $moneda): string
{
    $simbolos = [
        'mxn' => '$', 'usd' => '$', 'eur' => '€',
        'gbp' => '£', 'brl' => 'R$', 'cop' => '$',
        'clp' => '$', 'ars' => '$', 'pen' => 'S/',
    ];
    $moneda = strtolower($moneda);
    $simbolo = $simbolos[$moneda] ?? '$';
    $decimales = in_array($moneda, ['clp', 'krw', 'jpy']) ? 0 : 2;
    $factor   = $decimales === 0 ? 1 : 100;

    return $simbolo . ' ' . number_format($centavos / $factor, $decimales);
}

/**
 * Calcula resumen de pagos.
 */
function resumenPagos(array $exitosos, array $fallidos, array $reembolsadas = []): array
{
    $todosExitosos = array_merge($exitosos, $reembolsadas);
    $totalExitoso = array_sum(array_column($todosExitosos, 'monto'));
    $totalMxn = array_sum(array_column($todosExitosos, 'monto_mxn'));
    $monedaPrimera = $todosExitosos ? $todosExitosos[0]['moneda'] : CURRENCY;
    $tcUsado = $todosExitosos ? ($todosExitosos[0]['tipo_cambio_usado'] ?? null) : null;
    $tcInfo = obtenerInfoTipoCambio();

    $totalComision = array_sum(array_column($exitosos, 'comision'));
    $totalNeto = array_sum(array_column($exitosos, 'monto_recibido'));
    $generalCount = count($exitosos) + count($fallidos) + count($reembolsadas);

    return [
        'total_exitosos'     => count($exitosos),
        'total_fallidos'     => count($fallidos),
        'total_reembolsadas' => count($reembolsadas),
        'total_general'      => $generalCount,
        'monto_total'        => $totalExitoso,
        'monto_total_formateado' => $exitosos ? formatoMoneda($totalExitoso, $monedaPrimera) : '$ 0.00',
        'monto_total_mxn'    => $totalMxn,
        'monto_total_mxn_formateado' => '$ ' . number_format($totalMxn, 2),
        'monto_comision_total' => $totalComision,
        'monto_comision_total_formateado' => $exitosos ? formatoMoneda($totalComision, $monedaPrimera) : '$ 0.00',
        'monto_neto_total'   => $totalNeto,
        'monto_neto_total_formateado' => $exitosos ? formatoMoneda($totalNeto, $monedaPrimera) : '$ 0.00',
        'tipo_cambio'        => $tcUsado,
        'tipo_cambio_source' => $tcInfo['source'],
        'tipo_cambio_updated' => $tcInfo['last_updated'],
        'moneda'             => $monedaPrimera,
    ];
}

/**
 * Exporta un array de pagos (procesados) a CSV y descarga.
 */
function exportarCSV(array $pagos): void
{
    $filename = 'stripe_pagos_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'ID', 'Estado', 'Fecha', 'Cliente', 'Email', 'Telefono',
        'Monto', 'MXN', 'Comision', 'Moneda', 'Recibido', 'Tarjeta', 'Metodo',
        'Error', 'Codigo Error', 'Decline Code',
        'Descripcion', 'Cliente Stripe ID'
    ]);

    foreach ($pagos as $p) {
        fputcsv($output, [
            $p['id'],
            $p['estado'],
            $p['fecha_actualizacion'],
            $p['nombre'],
            $p['email'],
            $p['telefono'],
            $p['monto_formateado'],
            $p['monto_mxn_formateado'] ?? '—',
            $p['comision_formateado'] ?? '',
            $p['moneda'],
            $p['monto_recibido_formateado'] ?? '',
            ($p['card_brand'] ?? '—') . ' ****' . ($p['card_last4'] ?? '—'),
            $p['metodo_pago'] ?? '',
            $p['motivo'] ?? '',
            $p['error_code'] ?? '',
            $p['decline_code'] ?? '',
            $p['descripcion'],
            $p['cliente_id'] ?? '',
        ]);
    }

    fclose($output);
    exit;
}

/**
 * Prepara datos para gráfica desde array de pagos procesados.
 */
function datosGrafica(array $pagos): array
{
    $agrupados = [];
    foreach ($pagos as $p) {
        $dia = date('Y-m-d', $p['created_ts']);
        if (!isset($agrupados[$dia])) {
            $agrupados[$dia] = ['fecha' => $dia, 'conteo' => 0, 'total' => 0];
        }
        $agrupados[$dia]['conteo']++;
        $agrupados[$dia]['total'] += $p['monto'];
    }
    ksort($agrupados);
    return array_values($agrupados);
}

// ================================================================
// FUNCIONES PARA REPORTE SEMANAL
// ================================================================

/**
 * Obtiene todos los payment intents en un rango de timestamps.
 * Maneja paginación automática para obtener TODOS los pagos.
 *
 * @param int $timestamp_inicio Unix timestamp (inicio del rango)
 * @param int $timestamp_fin    Unix timestamp (fin del rango)
 * @return array ['data' => [...], 'error' => string|null]
 */
function obtenerPagosPorRango(int $timestamp_inicio, int $timestamp_fin): array
{
    $params = [
        'limit'  => 100,
        'expand' => ['data.latest_charge'],
        'created' => [
            'gte' => $timestamp_inicio,
            'lte' => $timestamp_fin,
        ],
    ];

    $allIntents = [];
    $hasMore = true;

    while ($hasMore) {
        try {
            $result = \Stripe\PaymentIntent::all($params);
            $allIntents = array_merge($allIntents, $result->data);
            $hasMore = $result->has_more;
            if ($hasMore && !empty($result->data)) {
                $params['starting_after'] = end($result->data)->id;
            } else {
                break;
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('Stripe API Error (rango): ' . $e->getMessage());
            return ['data' => [], 'error' => $e->getMessage()];
        }
    }

    return ['data' => $allIntents, 'error' => null];
}

/**
 * Calcula las fechas de inicio (lunes) y fin (domingo) de la semana
 * que contiene la fecha dada. Si no se proporciona fecha, usa la fecha actual.
 *
 * @param string|null $fecha Fecha de referencia en formato Y-m-d
 * @return array Con claves: inicio (DateTime), fin (DateTime),
 *               inicio_ts (int), fin_ts (int), inicio_str, fin_str
 */
function obtenerSemana(?string $fecha = null): array
{
    $date = $fecha ? new DateTime($fecha) : new DateTime();
    $dayOfWeek = (int) $date->format('N');
    $date->modify('-' . ($dayOfWeek - 1) . ' days');

    $inicio = clone $date;
    $inicio->setTime(0, 0, 0);
    $inicio_ts = $inicio->getTimestamp();

    $date->modify('+6 days');
    $fin = clone $date;
    $fin->setTime(23, 59, 59);
    $fin_ts = $fin->getTimestamp();

    return [
        'inicio'    => $inicio,
        'fin'       => $fin,
        'inicio_ts' => $inicio_ts,
        'fin_ts'    => $fin_ts,
        'inicio_str'=> $inicio->format('Y-m-d'),
        'fin_str'   => $fin->format('Y-m-d'),
        'inicio_display' => $inicio->format('d/m/Y'),
        'fin_display'    => $fin->format('d/m/Y'),
    ];
}

/**
 * Exporta un array de pagos a CSV con formato de reporte semanal.
 */
function exportarCSVSemanal(array $pagos, string $semana_inicio, string $semana_fin): void
{
    $filename = 'reporte_semanal_' . $semana_inicio . '_al_' . $semana_fin . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'ID', 'Estado', 'Fecha', 'Cliente', 'Email', 'Telefono',
        'Monto', 'MXN', 'Moneda', 'Recibido', 'Tarjeta', 'Metodo',
        'Error', 'Codigo Error', 'Tipo Error', 'Decline Code',
        'Descripcion', 'Cliente Stripe ID', 'Recibo URL'
    ]);

    foreach ($pagos as $p) {
        fputcsv($output, [
            $p['id'],
            $p['estado'],
            $p['fecha_actualizacion'],
            $p['nombre'],
            $p['email'],
            $p['telefono'],
            $p['monto_formateado'],
            $p['monto_mxn_formateado'] ?? '—',
            $p['moneda'],
            $p['monto_recibido_formateado'] ?? '',
            ($p['card_brand'] ?? '—') . ' ****' . ($p['card_last4'] ?? '—'),
            $p['metodo_pago'] ?? '',
            $p['motivo'] ?? '',
            $p['error_code'] ?? '',
            $p['error_tipo'] ?? '',
            $p['decline_code'] ?? '',
            $p['descripcion'],
            $p['cliente_id'] ?? '',
            $p['receipt_url'] ?? '',
        ]);
    }

    fclose($output);
    exit;
}

/**
 * Construye el HTML para el PDF del reporte semanal.
 */
function buildReporteHTML(array $pagos, string $semana_inicio, string $semana_fin, array $resumen): string
{
    $totalPagos = $resumen['total_general'] ?? ($resumen['total_exitosos'] + $resumen['total_fallidos']);
    $moneda = $resumen['moneda'] ?? 'MXN';
    $comisionTotalDecimal = ($resumen['monto_comision_total'] ?? 0) / 100;
    $feeTotalMxn = $moneda === 'USD'
        ? ($comisionTotalDecimal * ($resumen['tipo_cambio'] ?? 1))
        : $comisionTotalDecimal;
    $recibidoTotalMxn = max(0, ($resumen['monto_total_mxn'] ?? 0) - $feeTotalMxn);
    $recibidoTotalMxnFormateado = '$ ' . number_format($recibidoTotalMxn, 2);

    $rows = '';
    foreach ($pagos as $i => $p) {
        $estadoClass = match($p['status_label'] ?? $p['estado']) {
            'Exitoso' => 'success',
            'Reembolsado' => 'failed',
            'Fallido' => 'failed',
            default => 'warning',
        };
        $comisionFormateado = $p['comision_formateado'] ?? '—';
        $rows .= '<tr>';
        $rows .= '<td>' . ($i + 1) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['id']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['fecha_actualizacion']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['nombre']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['email']) . '</td>';
        $rows .= '<td>' . htmlspecialchars(($p['card_brand'] ?? '—') . ' ****' . ($p['card_last4'] ?? '—')) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['monto_formateado']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['moneda']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($comisionFormateado) . '</td>';
        $feeEnMxn = $p['moneda'] === 'USD' ? ($p['comision_decimal'] * $p['tipo_cambio_usado']) : $p['comision_decimal'];
        $recibidoMxn = max(0, $p['monto_mxn'] - $feeEnMxn);
        $recibidoMxnFormateado = '$ ' . number_format($recibidoMxn, 2);
        $rows .= '<td>' . htmlspecialchars($recibidoMxnFormateado) . '</td>';
        $rows .= '<td class="' . $estadoClass . '">' . htmlspecialchars($p['status_label'] ?? $p['estado']) . '</td>';
        $rows .= '</tr>';
    }

    return '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 7.5pt; color: #1e293b; }
    h1 { font-size: 16pt; color: #0f172a; margin: 0 0 2px; }
    .subtitle { font-size: 8pt; color: #64748b; margin-bottom: 10px; }
    @page { margin: 10mm 8mm; }
    .summary-table { width: 100%; border-collapse: separate; border-spacing: 5px; margin-bottom: 12px; }
    .summary-table td { width: 14.28%; vertical-align: top; padding: 0; }
    .summary-box { padding: 7px 10px; border-radius: 6px; border-left: 4px solid #e2e8f0; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
    .summary-box .box-label { font-size: 6pt; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 700; margin-bottom: 1px; }
    .summary-box .box-value { font-size: 13pt; font-weight: 800; line-height: 1.2; }
    .summary-box .box-sub { font-size: 5.5pt; color: #94a3b8; margin-top: 1px; }
    .box-mov { border-left-color: #3b82f6; } .box-mov .box-value { color: #1e40af; }
    .box-ok { border-left-color: #22c55e; } .box-ok .box-value { color: #16a34a; }
    .box-refund { border-left-color: #94a3b8; } .box-refund .box-value { color: #64748b; }
    .box-fail { border-left-color: #ef4444; } .box-fail .box-value { color: #dc2626; }
    .box-revenue { border-left-color: #f59e0b; } .box-revenue .box-value { color: #d97706; }
    .box-net { border-left-color: #8b5cf6; } .box-net .box-value { color: #7c3aed; }
    .box-fees { border-left-color: #64748b; } .box-fees .box-value { color: #475569; }
    table { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
    th { background: #f8fafc; padding: 6px 4px; text-align: left; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
    td { padding: 4px; border-bottom: 1px solid #f1f5f9; }
    tr:nth-child(even) { background: #fafafa; }
    .success { color: #16a34a; font-weight: 600; }
    .failed { color: #dc2626; font-weight: 600; }
    .warning { color: #d97706; font-weight: 600; }
    .footer { margin-top: 16px; font-size: 7pt; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
    <h1>Reporte Semanal Stripe</h1>
    <div class="subtitle">' . $semana_inicio . ' al ' . $semana_fin . '</div>
    <table class="summary-table">
        <tr>
            <td><div class="summary-box box-mov"><div class="box-label">Total Movimientos</div><div class="box-value">' . $totalPagos . '</div><div class="box-sub">transacciones</div></div></td>
            <td><div class="summary-box box-ok"><div class="box-label">Exitosos</div><div class="box-value">' . $resumen['total_exitosos'] . '</div><div class="box-sub">pagadas</div></div></td>
            <td><div class="summary-box box-refund"><div class="box-label">Reembolsadas</div><div class="box-value">' . ($resumen['total_reembolsadas'] ?? 0) . '</div><div class="box-sub">reembolsos</div></div></td>
            <td><div class="summary-box box-fail"><div class="box-label">Fallidos</div><div class="box-value">' . $resumen['total_fallidos'] . '</div><div class="box-sub">fallos</div></div></td>
            <td><div class="summary-box box-revenue"><div class="box-label">Total Recaudado</div><div class="box-value">' . htmlspecialchars($resumen['monto_total_formateado']) . '</div><div class="box-sub">ingresos brutos</div></div></td>
            <td><div class="summary-box box-net"><div class="box-label">Recibido (MXN)</div><div class="box-value">' . htmlspecialchars($recibidoTotalMxnFormateado) . '</div><div class="box-sub">neto en pesos</div></div></td>
            <td><div class="summary-box box-fees"><div class="box-label">Total Comisiones</div><div class="box-value">' . htmlspecialchars($resumen['monto_comision_total_formateado'] ?? '$ 0.00') . '</div><div class="box-sub">cobradas</div></div></td>
        </tr>
    </table>
    <table>
        <thead>
            <tr>
                <th>#</th><th>ID</th><th>Fecha</th><th>Cliente</th><th>Email</th><th>Tarjeta</th>
                <th>Monto</th><th>Moneda</th><th>Comision</th><th>Recibido (MXN)</th><th>Estado</th>
            </tr>
        </thead>
        <tbody>
            ' . $rows . '
        </tbody>
    </table>
    <div class="footer">Generado el ' . date('d/m/Y H:i') . ' · Stripe Dashboard</div>
</body>
</html>';
}

/**
 * Genera y descarga un PDF del reporte semanal usando DomPDF.
 */
function generarPDF(array $pagos, string $semana_inicio, string $semana_fin, array $resumen): void
{
    if (!class_exists('\\Dompdf\\Dompdf')) {
        http_response_code(500);
        echo 'Error: DomPDF no está instalado. Ejecuta: composer require dompdf/dompdf';
        exit;
    }

    $html = buildReporteHTML($pagos, $semana_inicio, $semana_fin, $resumen);

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream("reporte_semanal_{$semana_inicio}_al_{$semana_fin}.pdf", ['Attachment' => true]);
    exit;
}

/**
 * Guarda el CSV del reporte semanal en un archivo (para generación automática).
 *
 * @return string Ruta del archivo generado
 */
function guardarCSVSemanal(array $pagos, string $semana_inicio, string $semana_fin, string $dir): string
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = "reporte_semanal_{$semana_inicio}_al_{$semana_fin}.csv";
    $filepath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    $output = fopen($filepath, 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'ID', 'Estado', 'Fecha', 'Cliente', 'Email', 'Telefono',
        'Monto', 'MXN', 'Moneda', 'Recibido', 'Tarjeta', 'Metodo',
        'Error', 'Codigo Error', 'Tipo Error', 'Decline Code',
        'Descripcion', 'Cliente Stripe ID', 'Recibo URL'
    ]);

    foreach ($pagos as $p) {
        fputcsv($output, [
            $p['id'],
            $p['estado'],
            $p['fecha_actualizacion'],
            $p['nombre'],
            $p['email'],
            $p['telefono'],
            $p['monto_formateado'],
            $p['monto_mxn_formateado'] ?? '—',
            $p['moneda'],
            $p['monto_recibido_formateado'] ?? '',
            ($p['card_brand'] ?? '—') . ' ****' . ($p['card_last4'] ?? '—'),
            $p['metodo_pago'] ?? '',
            $p['motivo'] ?? '',
            $p['error_code'] ?? '',
            $p['error_tipo'] ?? '',
            $p['decline_code'] ?? '',
            $p['descripcion'],
            $p['cliente_id'] ?? '',
            $p['receipt_url'] ?? '',
        ]);
    }

    fclose($output);
    return $filepath;
}

/**
 * Guarda el PDF del reporte semanal en un archivo (para generación automática).
 *
 * @return string Ruta del archivo generado, o string vacío si falla
 */
function guardarPDFSemanal(array $pagos, string $semana_inicio, string $semana_fin, array $resumen, string $dir): string
{
    if (!class_exists('\\Dompdf\\Dompdf')) {
        return '';
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = "reporte_semanal_{$semana_inicio}_al_{$semana_fin}.pdf";
    $filepath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    $html = buildReporteHTML($pagos, $semana_inicio, $semana_fin, $resumen);

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    file_put_contents($filepath, $dompdf->output());

    return $filepath;
}

/**
 * Obtiene el tipo de cambio USD → MXN.
 *
 * 1) Static cache dentro de la misma request.
 * 2) Cache file (requests/tipo_cambio_cache.json) por 6 horas.
 * 3) API gratuita open.er-api.com.
 * 4) Fallback a constante TIPO_CAMBIO.
 *
 * @return array ['rate' => float, 'last_updated' => string|null, 'source' => string]
 */
function obtenerInfoTipoCambio(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cachePath = __DIR__ . '/requests/tipo_cambio_cache.json';
    $ttl = 21600; // 6 horas

    // 1) Intentar leer cache file
    $cachedData = null;
    if (file_exists($cachePath)) {
        $raw = @file_get_contents($cachePath);
        if ($raw !== false) {
            $cachedData = json_decode($raw, true);
        }
    }

    $now = time();

    // 2) Si el cache es válido (< 6h), usarlo
    if ($cachedData && isset($cachedData['rate'], $cachedData['updated_at'])) {
        if (($now - (int)$cachedData['updated_at']) < $ttl) {
            $cache = [
                'rate'         => (float)$cachedData['rate'],
                'last_updated' => $cachedData['last_updated'],
                'source'       => 'api',
            ];
            return $cache;
        }
    }

    // 3) Consultar API externa
    $apiRate = null;
    $apiUpdated = null;
    $apiOk = false;

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'method'  => 'GET',
            'header'  => 'Accept: application/json',
        ],
    ]);

    $response = @file_get_contents('https://open.er-api.com/v6/latest/USD', false, $ctx);

    if ($response !== false) {
        $data = json_decode($response, true);
        if ($data && ($data['result'] ?? '') === 'success' && isset($data['rates']['MXN'])) {
            $apiRate = (float)$data['rates']['MXN'];
            $apiUpdated = $data['time_last_update_utc'] ?? gmdate('Y-m-d\TH:i:s\Z');
            $apiOk = true;
        }
    }

    // 4) Guardar cache si la API respondió
    if ($apiOk) {
        $cacheData = [
            'rate'         => $apiRate,
            'updated_at'  => $now,
            'last_updated' => $apiUpdated,
        ];
        @file_put_contents($cachePath, json_encode($cacheData, JSON_UNESCAPED_UNICODE), LOCK_EX);

        $cache = [
            'rate'         => $apiRate,
            'last_updated' => $apiUpdated,
            'source'       => 'api',
        ];
        return $cache;
    }

    // 5) API falló: usar cache expirado si existe
    if ($cachedData && isset($cachedData['rate'])) {
        $cache = [
            'rate'         => (float)$cachedData['rate'],
            'last_updated' => $cachedData['last_updated'] ?? null,
            'source'       => 'api_cache_expired',
        ];
        return $cache;
    }

    // 6) Sin cache, sin API: usar valor configurado en .env
    $cache = [
        'rate'         => TIPO_CAMBIO,
        'last_updated' => null,
        'source'       => 'fallback',
    ];
    return $cache;
}

/**
 * Obtiene solo la tasa de cambio (float).
 */
function obtenerTipoCambio(): float
{
    $info = obtenerInfoTipoCambio();
    return $info['rate'];
}

<?php
/**
 * Script CLI para generacion automatica de reportes semanales PayPal.
 *
 * Uso:
 *   php generar_reporte_paypal.php
 *   php generar_reporte_paypal.php --semana=2026-05-18
 *   php generar_reporte_paypal.php --semana=2026-05-18 --dir=./reportes
 *   php generar_reporte_paypal.php --semana=2026-05-18 --solo-csv
 *
 * Si no se especifica --semana, genera el reporte de la semana pasada.
 * Para automatizar, programa en Windows Task Scheduler:
 *   php C:\xampp\htdocs\sistema_api\generar_reporte_paypal.php
 */

require_once __DIR__ . '/paypal_helper.php';

$args = getopt('', ['semana::', 'dir::', 'solo-csv::', 'solo-pdf::', 'help::']);

if (isset($args['help'])) {
    echo "Generador de Reporte Semanal PayPal\n";
    echo "====================================\n";
    echo "Uso: php generar_reporte_paypal.php [opciones]\n\n";
    echo "Opciones:\n";
    echo "  --semana=YYYY-MM-DD  Fecha de referencia de la semana (default: semana pasada)\n";
    echo "  --dir=RUTA           Directorio donde guardar los reportes (default: ./reportes/)\n";
    echo "  --solo-csv           Solo generar CSV (sin PDF)\n";
    echo "  --solo-pdf           Solo generar PDF (sin CSV)\n";
    echo "  --help               Muestra esta ayuda\n";
    exit(0);
}

$semana_ref = $args['semana'] ?? null;
$dir = $args['dir'] ?? __DIR__ . '/reportes';
$solo_csv = isset($args['solo-csv']);
$solo_pdf = isset($args['solo-pdf']);

if (!$semana_ref) {
    $semana_ref = (new DateTime())->modify('-7 days')->format('Y-m-d');
}

echo "Generando reporte semanal PayPal...\n";
echo "Semana de referencia: $semana_ref\n";

$semana = obtenerSemanaPaypal($semana_ref);
echo "Rango: {$semana['inicio_str']} al {$semana['fin_str']}\n";

$resultado = obtenerPagosPaypalPorRango($semana['inicio_utc'], $semana['fin_utc'], $semana['inicio_utc'], $semana['fin_utc']);

if ($resultado['error']) {
    echo "ERROR: {$resultado['error']}\n";
    exit(1);
}

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

$total_pagos = count($todos);
echo "Total movimientos: $total_pagos\n";
echo "Completadas: {$resumen['total_completadas']}\n";
echo "Reembolsadas: {$resumen['total_reembolsadas']}\n";
$retirado = $resumen['monto_retirado_mxn_formateado'] ?? '$ 0.00';
if ($retirado !== '$ 0.00') {
    echo "Depositado en banco (MXN): $retirado\n";
} else {
    echo "Recaudado: {$resumen['monto_total_formateado']}\n";
}

$archivos = [];

if (!$solo_pdf) {
    $csvPath = guardarCSVPaypal($todos, $semana['inicio_str'], $semana['fin_str'], $dir);
    $archivos[] = $csvPath;
    echo "CSV guardado: $csvPath\n";
}

if (!$solo_csv) {
    $pdfPath = guardarPDFPaypal($todos, $semana['inicio_str'], $semana['fin_str'], $resumen, $dir);
    if ($pdfPath) {
        $archivos[] = $pdfPath;
        echo "PDF guardado: $pdfPath\n";
    } else {
        echo "PDF no generado (DomPDF no disponible)\n";
    }
}

echo "\nReporte completado.\n";
exit(0);

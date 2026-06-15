<?php
/**
 * Script CLI para generación automática de reportes semanales.
 *
 * Uso:
 *   php generar_reporte_semanal.php
 *   php generar_reporte_semanal.php --semana=2025-03-10
 *   php generar_reporte_semanal.php --semana=2025-03-10 --dir=./reportes
 *   php generar_reporte_semanal.php --semana=2025-03-10 --solo-csv
 *
 * Si no se especifica --semana, genera el reporte de la semana pasada.
 * Para automatizar, programa en Windows Task Scheduler:
 *   php C:\xampp\htdocs\sistema_api\generar_reporte_semanal.php
 */

require_once __DIR__ . '/stripe_helper.php';

// Parse arguments
$args = getopt('', ['semana::', 'dir::', 'solo-csv::', 'solo-pdf::', 'help::']);

if (isset($args['help'])) {
    echo "Generador de Reporte Semanal\n";
    echo "============================\n";
    echo "Uso: php generar_reporte_semanal.php [opciones]\n\n";
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

echo "Generando reporte semanal...\n";
echo "Semana de referencia: $semana_ref\n";

$semana = obtenerSemana($semana_ref);
echo "Rango: {$semana['inicio_str']} al {$semana['fin_str']}\n";

$resultado = obtenerPagosPorRango($semana['inicio_ts'], $semana['fin_ts']);

if ($resultado['error']) {
    echo "ERROR: {$resultado['error']}\n";
    exit(1);
}

$intents = $resultado['data'];
$clasificados = clasificarPagos($intents);
$exitosos = $clasificados['exitosos'];
$fallidos = $clasificados['fallidos'];
$reembolsadas = $clasificados['reembolsadas'] ?? [];
$resumen = resumenPagos($exitosos, $fallidos, $reembolsadas);
$todos = array_merge($exitosos, $reembolsadas, $fallidos);
usort($todos, fn($a, $b) => $b['created_ts'] - $a['created_ts']);

$total_pagos = count($todos);
echo "Total movimientos: $total_pagos\n";
echo "Exitosos: {$resumen['total_exitosos']}\n";
echo "Reembolsadas: {$resumen['total_reembolsadas']}\n";
echo "Fallidos: {$resumen['total_fallidos']}\n";
echo "Recaudado: {$resumen['monto_total_formateado']}\n";
echo "Comisiones: {$resumen['monto_comision_total_formateado']}\n";
echo "Neto: {$resumen['monto_neto_total_formateado']}\n";

$archivos = [];

if (!$solo_pdf) {
    $csvPath = guardarCSVSemanal($todos, $semana['inicio_str'], $semana['fin_str'], $dir);
    $archivos[] = $csvPath;
    echo "CSV guardado: $csvPath\n";
}

if (!$solo_csv) {
    $pdfPath = guardarPDFSemanal($todos, $semana['inicio_str'], $semana['fin_str'], $resumen, $dir);
    if ($pdfPath) {
        $archivos[] = $pdfPath;
        echo "PDF guardado: $pdfPath\n";
    } else {
        echo "PDF no generado (DomPDF no disponible)\n";
    }
}

echo "\nReporte completado.\n";
exit(0);

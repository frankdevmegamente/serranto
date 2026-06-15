<?php
require_once __DIR__ . '/../paypal_helper.php';

session_status() === PHP_SESSION_NONE && session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

requireAuth();
validateCSRF();
checkRateLimit(30, 60);
session_write_close();

set_time_limit(120);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$raw = $_POST['transaction_ids'] ?? '';
$transactionIds = array_filter(array_map('trim', explode(',', $raw)));

if (empty($transactionIds)) {
    jsonResponse(['status' => false, 'message' => 'IDs de transacción requeridos'], 400);
}

try {
    $accessToken = getPayPalAccessToken();
    $results = getPayPalTrackingBatch($accessToken, $transactionIds);

    jsonResponse([
        'status' => true,
        'tracking' => $results
    ]);

} catch (Exception $e) {
    $errMsg = $e->getMessage();
    if (str_contains($errMsg, 'Límite de llamadas PayPal') || str_contains($errMsg, 'Cooldown activo')) {
        jsonResponse([
            'status' => true,
            'tracking' => [],
            'notice' => 'Límite de consultas PayPal alcanzado. Las guías se cargarán en el siguiente intento.'
        ]);
    } elseif (str_contains($errMsg, 'cURL errno 7')) {
        $msg = 'PayPal no está disponible en este momento. Intenta de nuevo más tarde.';
        jsonResponse(['status' => false, 'message' => $msg], 500);
    } else {
        $msg = 'Error al consultar PayPal. Es un problema temporal, no del sistema.';
        jsonResponse(['status' => false, 'message' => $msg], 500);
    }
}

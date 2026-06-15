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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$transactionId = trim($_POST['transaction_id'] ?? '');
$trackingNumber = trim($_POST['track_number'] ?? '');
$carrier = trim($_POST['carrier'] ?? '');

if (empty($transactionId)) {
    jsonResponse(['status' => false, 'message' => 'ID de transacción requerido'], 400);
}

if (empty($trackingNumber)) {
    jsonResponse(['status' => false, 'message' => 'Número de rastreo requerido'], 400);
}

if (empty($carrier)) {
    jsonResponse(['status' => false, 'message' => 'Paquetería requerida'], 400);
}

$validCarriers = [
    'USPS', 'DHL', 'FEDEX', 'UPS', 'ONTRAC', 'CANADA_POST',
    'ROYAL_MAIL', 'AUSTRALIA_POST', 'SEUR', 'GLS', 'CHRONOPOST',
    'LA_POSTE', 'TNT', 'ARAMEX', 'HERMES_LOGISTICS', 'OTHER'
];

if (!in_array($carrier, $validCarriers)) {
    jsonResponse(['status' => false, 'message' => 'Paquetería no válida'], 400);
}

try {
    $accessToken = getPayPalAccessToken();
    $result = updatePayPalTracking($accessToken, $transactionId, $trackingNumber, $carrier);

    clearPayPalCache();

    jsonResponse([
        'status' => true,
        'message' => 'Número de rastreo agregado correctamente',
        'data' => $result
    ]);

} catch (Exception $e) {
    $errMsg = $e->getMessage();
    if (str_contains($errMsg, 'cURL errno 7') || str_contains($errMsg, 'Could not connect')) {
        $entry = addPendingTracking($transactionId, $trackingNumber, $carrier);
        jsonResponse([
            'status' => true,
            'pending' => true,
            'message' => 'PayPal no está disponible. La guía se guardó para subirse automáticamente cuando PayPal vuelva.',
            'pending_id' => $entry['id'] ?? null
        ]);
    } else {
        jsonResponse([
            'status' => false,
            'message' => 'Error al comunicarse con PayPal. Intenta de nuevo.'
        ], 500);
    }
}

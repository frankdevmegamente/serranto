<?php
require_once __DIR__ . '/../paypal_helper.php';

session_status() === PHP_SESSION_NONE && session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

requireAuth();
validateCSRF();
checkRateLimit(60, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$value = trim($_POST['value'] ?? '');

if (empty($value)) {
    jsonResponse(['status' => false, 'message' => 'Valor de búsqueda requerido'], 400);
}

try {
    $accessToken = getPayPalAccessToken();
    $trackers = [];

    // Try all search methods automatically
    $searchTypes = ['transaction_id', 'tracking_number'];
    foreach ($searchTypes as $searchType) {
        try {
            $trackInfo = getPayPalTrackingInfo($accessToken, $value, $searchType);
            $found = $trackInfo['trackers'] ?? [];
            if (!empty($found)) {
                $trackers = $found;
                break;
            }
        } catch (Exception $e) {
            continue;
        }
    }

    jsonResponse([
        'status' => true,
        'tracking' => $trackers
    ]);

} catch (Exception $e) {
    jsonResponse([
        'status' => false,
        'message' => $e->getMessage()
    ]);
}

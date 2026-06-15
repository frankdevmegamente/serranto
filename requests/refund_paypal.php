<?php
require_once __DIR__ . '/../paypal_helper.php';

session_status() === PHP_SESSION_NONE && session_start();
header('Content-Type: application/json; charset=utf-8');

requireAuth();
validateCSRF();
checkRateLimit(10, 60);
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$captureId = trim($_POST['capture_id'] ?? '');
$amount = isset($_POST['amount']) ? floatval($_POST['amount']) : null;
$currency = trim($_POST['currency'] ?? 'MXN');
$reason = trim($_POST['reason'] ?? 'Solicitud del cliente');

if (empty($captureId)) {
    jsonResponse(['status' => false, 'message' => 'ID de captura requerido'], 400);
}

if (!preg_match('/^[A-Z0-9]{17}$/', $captureId)) {
    jsonResponse(['status' => false, 'message' => 'ID de captura inválido'], 400);
}

$validCurrencies = ['USD', 'EUR', 'GBP', 'MXN', 'CAD', 'AUD'];
if (!in_array(strtoupper($currency), $validCurrencies)) {
    $currency = 'MXN';
}

try {
    $accessToken = getPayPalAccessToken();

    $captureDetails = getPayPalCaptureDetails($accessToken, $captureId);

    if (!$captureDetails) {
        jsonResponse(['status' => false, 'message' => 'No se encontró la captura'], 404);
    }

    $captureStatus = $captureDetails['status'] ?? '';
    if ($captureStatus === 'REFUNDED') {
        jsonResponse(['status' => false, 'message' => 'Esta captura ya fue reembolsada completamente'], 400);
    }

    if (!in_array($captureStatus, ['COMPLETED', 'PARTIALLY_REFUNDED'])) {
        jsonResponse([
            'status' => false,
            'message' => 'Solo se pueden reembolsar capturas completadas. Estado: ' . $captureStatus
        ], 400);
    }

    $refundData = [
        'note_to_payer' => substr($reason, 0, 255)
    ];

    if ($amount !== null && $amount > 0) {
        $refundData['amount'] = [
            'value' => number_format($amount, 2, '.', ''),
            'currency_code' => strtoupper($currency)
        ];
    }

    $result = createPayPalRefund($accessToken, $captureId, $refundData);

    $refundId = $result['id'] ?? 'unknown';
    $refundAmount = $result['amount']['value'] ?? ($amount ?? 0);
    $refundCurrency = $result['amount']['currency_code'] ?? $currency;
    $createTime = $result['create_time'] ?? date('c');
    $isFull = $amount === null || $amount <= 0;

    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user' => $_SESSION['usuario'] ?? 'desconocido',
        'action' => 'refund_paypal',
        'capture_id' => $captureId,
        'refund_id' => $refundId,
        'amount' => $amount ?? 'full',
        'currency' => $currency,
        'reason' => $reason,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    file_put_contents(
        $logDir . '/refunds.log',
        json_encode($logData) . "\n",
        FILE_APPEND | LOCK_EX
    );

    // Guardar reembolso localmente para matching inmediato
    $dataDir = __DIR__ . '/../data';
    $refundsFile = $dataDir . '/refunds.json';
    $localRefunds = [];
    if (file_exists($refundsFile)) {
        $content = file_get_contents($refundsFile);
        $localRefunds = json_decode($content, true);
        if (!is_array($localRefunds)) {
            error_log("REFUNDS_JSON_CORRUPT: refunds.json no es un array válido, se reiniciará. Contenido: " . substr($content, 0, 500));
            $localRefunds = [];
        }
    }
    $localRefunds[] = [
        'capture_id'    => $captureId,
        'refund_id'     => $refundId,
        'amount_refunded' => (float)$refundAmount,
        'refund_type'   => $isFull ? 'full' : 'partial',
        'refund_date'   => $createTime,
        'currency'      => $refundCurrency,
    ];
    $written = file_put_contents($refundsFile, json_encode($localRefunds, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if ($written === false) {
        error_log("REFUNDS_JSON_WRITE_ERROR: No se pudo escribir en $refundsFile");
    }

    // Limpiar cache de PayPal para forzar datos frescos en el pr\u00f3ximo "Buscar"
    $cacheDir = __DIR__ . '/../data/paypal_cache';
    if (is_dir($cacheDir)) {
        $cacheFiles = glob($cacheDir . '/*.json');
        foreach ($cacheFiles as $cf) {
            @unlink($cf);
        }
    }

    jsonResponse([
        'status' => true,
        'message' => 'Reembolso procesado correctamente',
        'data' => [
            'refund_id' => $refundId,
            'amount' => $refundAmount,
            'currency' => $refundCurrency,
            'status' => $result['status'] ?? 'COMPLETED',
            'create_time' => $createTime,
            'capture_id' => $captureId
        ]
    ]);

} catch (Exception $e) {
    $errMsg = $e->getMessage();
    if (str_contains($errMsg, 'cURL errno 7')) {
        $msg = 'PayPal no está disponible en este momento. Intenta de nuevo más tarde.';
    } elseif (str_contains($errMsg, '401') || str_contains($errMsg, 'Unauthorized')) {
        $msg = 'Las credenciales de PayPal necesitan actualizarse. Contacta al administrador.';
    } else {
        $msg = 'Error al procesar el reembolso en PayPal. Intenta de nuevo.';
    }
    jsonResponse([
        'status' => false,
        'message' => $msg
    ], 500);
}

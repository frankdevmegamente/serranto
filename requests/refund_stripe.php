<?php
require_once __DIR__ . '/../stripe_helper.php';

session_status() === PHP_SESSION_NONE && session_start();
header('Content-Type: application/json; charset=utf-8');

requireAuth();
validateCSRF();
checkRateLimit(10, 60);
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$chargeId = trim($_POST['charge_id'] ?? '');
$amount = isset($_POST['amount']) ? floatval($_POST['amount']) : null;
$reason = trim($_POST['reason'] ?? 'Solicitud del cliente');

if (empty($chargeId)) {
    jsonResponse(['status' => false, 'message' => 'ID de cargo requerido'], 400);
}

if (!preg_match('/^ch_[a-zA-Z0-9]+$/', $chargeId)) {
    jsonResponse(['status' => false, 'message' => 'ID de cargo inválido'], 400);
}

$validReasons = ['duplicate', 'fraudulent', 'requested_by_customer', 'expired_uncaptured_charge'];
if (!in_array($reason, $validReasons)) {
    $reason = 'requested_by_customer';
}

try {
    $params = ['charge' => $chargeId];

    if ($amount !== null && $amount > 0) {
        $params['amount'] = (int) round($amount * 100);
    }

    $refund = \Stripe\Refund::create($params);

    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user' => $_SESSION['usuario'] ?? 'desconocido',
        'action' => 'refund_stripe',
        'charge_id' => $chargeId,
        'refund_id' => $refund->id,
        'amount' => $amount ?? 'full',
        'currency' => $refund->currency,
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

    jsonResponse([
        'status' => true,
        'message' => 'Reembolso procesado correctamente',
        'data' => [
            'refund_id' => $refund->id,
            'amount' => $refund->amount / 100,
            'currency' => strtoupper($refund->currency),
            'status' => $refund->status,
            'create_time' => date('c', $refund->created)
        ]
    ]);

} catch (\Stripe\Exception\CardException $e) {
    jsonResponse(['status' => false, 'message' => 'Error de tarjeta: ' . $e->getMessage()], 400);
} catch (\Stripe\Exception\InvalidRequestException $e) {
    jsonResponse(['status' => false, 'message' => $e->getMessage()], 400);
} catch (Exception $e) {
    jsonResponse(['status' => false, 'message' => 'Error al procesar el reembolso'], 500);
}

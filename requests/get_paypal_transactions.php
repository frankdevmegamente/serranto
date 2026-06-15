<?php
require_once __DIR__ . '/../paypal_helper.php';

session_status() === PHP_SESSION_NONE && session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

requireAuth();
validateCSRF();
checkRateLimit(20, 60);

// Liberar sesión para evitar bloqueo durante llamadas lentas a PayPal
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

$startInput = trim($_POST['start'] ?? '');
$endInput = trim($_POST['end'] ?? '');
$buyerName = trim($_POST['buyer'] ?? '');

if (!$startInput || !$endInput || !strtotime($startInput) || !strtotime($endInput)) {
    http_response_code(400);
    echo json_encode(['error' => 'Fechas inválidas']);
    exit;
}

$tzMx = new DateTimeZone('America/Mexico_City');
$startDateObj = new DateTime($startInput, $tzMx);
$endDateObj = new DateTime($endInput . ' 23:59:59', $tzMx);

$interval = $startDateObj->diff($endDateObj);
if ($interval->days > 31) {
    http_response_code(400);
    echo json_encode([
        'status' => false,
        'error' => 'PayPal no permite consultar un rango mayor a 31 días.'
    ]);
    exit;
}

$startDateObj->setTimezone(new DateTimeZone('UTC'));
$endDateObj->setTimezone(new DateTimeZone('UTC'));

$startDate = $startDateObj->format('Y-m-d\TH:i:s\Z');
$endDate = $endDateObj->format('Y-m-d\TH:i:s\Z');

$allowedEventCodes = [
    "T0000", "T0001", "T0002", "T0003", "T0004", "T0005",
    "T0006", "T0007", "T0008", "T0009", "T0010", "T0011",
    "T0012", "T0013", "T0100",
    "T110", "T120"
];

try {
    $accessToken = getPayPalAccessToken();
    $resultado = obtenerPagosPaypalPorRango($startDate, $endDate, $startDate, $endDate);

    if ($resultado['error']) {
        throw new Exception($resultado['error']);
    }

    $rawTransactions = $resultado['data'];
    $transactionsFiltered = [];

    $paymentEventCodes = ['T0000', 'T0001', 'T0002', 'T0003', 'T0004', 'T0011', 'T0013', 'T0100'];
    $refundEventCodes = ['T110', 'T120'];

    foreach ($rawTransactions as $transaction) {
        $eventId = $transaction['transaction_info']['transaction_event_code'] ?? 'UNKNOWN';

        if (!in_array($eventId, $allowedEventCodes)) {
            continue;
        }

        $payerName = $transaction['payer_info']['payer_name']['alternate_full_name'] ?? '';
        if ($buyerName !== '' && stripos($payerName, $buyerName) === false) {
            continue;
        }

        $transactionId = $transaction['transaction_info']['transaction_id'];
        $txInfo = $transaction['transaction_info'];

        $transaction['custom_id'] = $txInfo['custom_field'] ?? null;
        $transaction['invoice_id'] = $txInfo['invoice_id'] ?? null;

        $isMsi = false;
        $msiPlazo = 0;
        $customField = $txInfo['custom_field'] ?? '';
        $invoiceId = $txInfo['invoice_id'] ?? '';
        if ($customField && preg_match('/MSI[:_]\s*(\d+)/i', $customField, $m)) {
            $isMsi = true;
            $msiPlazo = (int)$m[1];
        } elseif ($invoiceId && preg_match('/MSI[:_]\s*(\d+)/i', $invoiceId, $m)) {
            $isMsi = true;
            $msiPlazo = (int)$m[1];
        }
        $item = $transaction['cart_info']['item_details'][0] ?? [];
        if (!empty($item['item_name']) && stripos($item['item_name'], 'MSI') !== false) {
            $isMsi = true;
            if (preg_match('/(\d+)\s*MSI/i', $item['item_name'], $m)) {
                $msiPlazo = (int)$m[1];
            }
        }
        $transaction['msi_info'] = $isMsi ? [
            'is_msi' => true,
            'number_of_installments' => $msiPlazo,
        ] : null;

        $eventCode = $txInfo['transaction_event_code'] ?? '';
        $txStatus = $txInfo['transaction_status'] ?? '';
        // Leer refund_info ORIGINAL de PayPal (contiene capture_status real)
        $originalRefundInfo = $transaction['refund_info'] ?? [];
        $originalCaptureStatus = $originalRefundInfo['capture_status'] ?? '';
        $isRefunded = $originalCaptureStatus === 'REFUNDED'
            || $originalCaptureStatus === 'PARTIALLY_REFUNDED'
            || in_array($eventCode, ['T110', 'T120', 'T1107']);
        // Determinar partial/full comparando montos (más confiable que event codes)
        $originalAmount = abs((float)($txInfo['transaction_amount']['value'] ?? 0));
        $rawRefunded = $originalRefundInfo['amount_refunded'] ?? null;
        $refundedAmountInUsd = 0;
        if (is_array($rawRefunded)) {
            $refundedAmountInUsd = abs((float)($rawRefunded['value'] ?? 0));
        } else {
            $refundedAmountInUsd = abs((float)$rawRefunded);
        }
        $refundType = null;
        if ($isRefunded) {
            if ($originalCaptureStatus === 'PARTIALLY_REFUNDED') {
                $refundType = 'partial';
            } elseif ($refundedAmountInUsd > 0 && $refundedAmountInUsd < $originalAmount) {
                $refundType = 'partial';
            } elseif ($refundedAmountInUsd > 0) {
                $refundType = 'full';
            }
            // Si amount_refunded es null y capture_status es REFUNDED,
            // dejamos refund_type = null para que pasos posteriores lo corrijan
        }
        $transaction['refund_info'] = [
            'is_refunded' => $isRefunded,
            'refund_type' => $refundType,
            'capture_status' => $originalCaptureStatus ?: $txStatus,
            'amount_refunded' => $refundedAmountInUsd > 0 ? $refundedAmountInUsd : null,
            'refund_date' => $originalRefundInfo['refund_time'] ?? null,
            'refund_id' => $originalRefundInfo['refund_id'] ?? null,
        ];

        if (in_array($eventCode, $paymentEventCodes) && $txStatus === 'S') {
            $transaction['capture_id'] = $transactionId;
        } else {
            $transaction['capture_id'] = null;
        }

        $utcDate = $transaction['transaction_info']['transaction_initiation_date'];
        $date = new DateTime($utcDate, new DateTimeZone('UTC'));
        $transaction['transaction_info']['transaction_initiation_date'] = $date->format('Y-m-d\TH:i:sP');

        $transactionsFiltered[] = $transaction;
    }

    // Paso 1.5: emparejar T0011 sin capture_id con su T0000 original por email del comprador
    // para que el 4to paso (capture details API) pueda determinar el tipo de reembolso
    $buyerPaymentMap = [];
    foreach ($transactionsFiltered as $t) {
        $ec = $t['transaction_info']['transaction_event_code'] ?? '';
        if (in_array($ec, $paymentEventCodes) && !empty($t['capture_id'])) {
            $email = strtolower(trim($t['payer_info']['email_address'] ?? ''));
            if ($email) {
                $buyerPaymentMap[$email][] = $t;
            }
        }
    }
    foreach ($transactionsFiltered as $i => $t) {
        $ec = $t['transaction_info']['transaction_event_code'] ?? '';
        if ($ec !== 'T0011') continue;
        if (!empty($t['capture_id'])) continue;
        if (!$t['refund_info']['is_refunded']) continue;
        $email = strtolower(trim($t['payer_info']['email_address'] ?? ''));
        if (!$email || !isset($buyerPaymentMap[$email])) continue;
        $bestMatch = null;
        $txnAmount = abs((float)($t['transaction_info']['transaction_amount']['value'] ?? 0));
        foreach ($buyerPaymentMap[$email] as $p) {
            $payAmount = abs((float)($p['transaction_info']['transaction_amount']['value'] ?? 0));
            if ($payAmount > 0 && $payAmount >= $txnAmount) {
                if ($bestMatch === null || $payAmount < $bestMatch['amount']) {
                    $bestMatch = ['tx' => $p, 'amount' => $payAmount];
                }
            }
        }
        if ($bestMatch && !empty($bestMatch['tx']['capture_id'])) {
            $transactionsFiltered[$i]['capture_id'] = $bestMatch['tx']['capture_id'];
            error_log("T0011_MATCH: TxnID=" . ($t['transaction_info']['transaction_id'] ?? '') . " matched to capture_id=" . $bestMatch['tx']['capture_id']);
        }
    }

    // Segundo paso: emparejar reembolsos (T110/T120) con sus pagos originales
    $refundsByKey = [];
    foreach ($transactionsFiltered as $t) {
        $ec = $t['transaction_info']['transaction_event_code'] ?? '';
        if (in_array($ec, $refundEventCodes)) {
            $key = ($t['invoice_id'] ?? '') . '|' . ($t['custom_field'] ?? '');
            if ($key !== '|') {
                $refundsByKey[$key][] = $t;
            }
        }
    }
    foreach ($transactionsFiltered as $i => $t) {
        $ec = $t['transaction_info']['transaction_event_code'] ?? '';
        $isPaymentEvent = in_array($ec, $paymentEventCodes);
        $isRefundedNonPayment = !$isPaymentEvent && $transactionsFiltered[$i]['refund_info']['is_refunded'];
        if (!$isPaymentEvent && !$isRefundedNonPayment) continue;
        $key = ($t['invoice_id'] ?? '') . '|' . ($t['custom_field'] ?? '');
        if (isset($refundsByKey[$key])) {
            $originalAmount = abs((float)($transactionsFiltered[$i]['transaction_info']['transaction_amount']['value'] ?? 0));
            $totalRefunded = 0;
            $lastRefund = end($refundsByKey[$key]);
            foreach ($refundsByKey[$key] as $r) {
                $amt = $r['transaction_info']['transaction_amount']['value'] ?? 0;
                $totalRefunded += abs((float)$amt);
            }
            $isPartial = $totalRefunded < $originalAmount;
            $transactionsFiltered[$i]['refund_info']['is_refunded'] = true;
            $transactionsFiltered[$i]['refund_info']['refund_type'] = $isPartial ? 'partial' : 'full';
            $transactionsFiltered[$i]['refund_info']['refund_id'] = $lastRefund['transaction_info']['transaction_id'] ?? null;
            $transactionsFiltered[$i]['refund_info']['amount_refunded'] = $totalRefunded;
            $transactionsFiltered[$i]['refund_info']['refund_date'] = $lastRefund['transaction_info']['transaction_initiation_date'] ?? null;
        }
    }

    // Tercer paso: emparejar por capture_id usando reembolsos locales
    $localRefundsFile = __DIR__ . '/../data/refunds.json';
    if (file_exists($localRefundsFile)) {
        $localRefunds = json_decode(file_get_contents($localRefundsFile), true) ?? [];
        if (!empty($localRefunds)) {
            foreach ($transactionsFiltered as $i => $t) {
                $ec = $t['transaction_info']['transaction_event_code'] ?? '';
                if (!in_array($ec, $paymentEventCodes)) continue;
                if ($transactionsFiltered[$i]['refund_info']['is_refunded']) continue;
                $capId = $transactionsFiltered[$i]['capture_id'] ?? '';
                if (!$capId) continue;
                foreach ($localRefunds as $rf) {
                    if (($rf['capture_id'] ?? '') === $capId) {
                        $transactionsFiltered[$i]['refund_info']['is_refunded'] = true;
                        $transactionsFiltered[$i]['refund_info']['refund_type'] = $rf['refund_type'] ?? 'full';
                        $transactionsFiltered[$i]['refund_info']['refund_id'] = $rf['refund_id'] ?? null;
                        $transactionsFiltered[$i]['refund_info']['amount_refunded'] = $rf['amount_refunded'] ?? null;
                        $transactionsFiltered[$i]['refund_info']['refund_date'] = $rf['refund_date'] ?? null;
                        break;
                    }
                }
            }
        }
    }

    // Cuarto paso: consultar estado real de captura para transacciones completadas
    // La API de captura (v2/payments/captures/{id}) devuelve el estado en tiempo real
    // Es la fuente autorizada: sobreescribe/corrige cualquier dato de pasos anteriores
    $captureCheckCount = 0;
    $maxCaptureChecks = 30;
    foreach ($transactionsFiltered as $i => $t) {
        if ($captureCheckCount >= $maxCaptureChecks) break;
        $ec = $t['transaction_info']['transaction_event_code'] ?? '';
        if (!in_array($ec, $paymentEventCodes)) continue;
        $capId = $transactionsFiltered[$i]['capture_id'] ?? '';
        if (!$capId) continue;
        try {
            $captureDetails = getPayPalCaptureDetails($accessToken, $capId);
            if ($captureDetails) {
                $capStatus = $captureDetails['status'] ?? '';
                if ($capStatus === 'PARTIALLY_REFUNDED' || $capStatus === 'REFUNDED') {
                    $amountRefunded = null;
                    if (isset($captureDetails['seller_receivable_breakdown']['total_refunded_amount'])) {
                        $amountRefunded = $captureDetails['seller_receivable_breakdown']['total_refunded_amount']['value'] ?? null;
                    }
                    $transactionsFiltered[$i]['refund_info']['is_refunded'] = true;
                    $transactionsFiltered[$i]['refund_info']['refund_type'] = $capStatus === 'PARTIALLY_REFUNDED' ? 'partial' : 'full';
                    $transactionsFiltered[$i]['refund_info']['capture_status'] = $capStatus;
                    $transactionsFiltered[$i]['refund_info']['amount_refunded'] = $amountRefunded;
                } elseif ($capStatus === 'COMPLETED' && $transactionsFiltered[$i]['refund_info']['is_refunded']) {
                    // Capture details dice COMPLETED: limpiar falso positivo de pasos anteriores
                    $transactionsFiltered[$i]['refund_info']['is_refunded'] = false;
                    $transactionsFiltered[$i]['refund_info']['refund_type'] = null;
                    $transactionsFiltered[$i]['refund_info']['capture_status'] = $capStatus;
                    $transactionsFiltered[$i]['refund_info']['amount_refunded'] = null;
                }
            }
        } catch (Exception $e) {
            error_log("CAPTURE_CHECK_ERROR: capture_id=$capId - " . $e->getMessage());
        }
        $captureCheckCount++;
    }

    // Paso final: si quedó is_refunded=true sin refund_type, default a 'full'
    foreach ($transactionsFiltered as $i => $t) {
        if ($t['refund_info']['is_refunded'] && $t['refund_info']['refund_type'] === null) {
            $transactionsFiltered[$i]['refund_info']['refund_type'] = 'full';
        }
    }

    echo json_encode([
        'status' => true,
        'transactions' => array_reverse($transactionsFiltered),
        'start' => $startDate,
        'end' => $endDate,
        'account_number' => '',
        'total_count' => count($transactionsFiltered)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("PAYPAL ERROR en getTransactions: " . $e->getMessage());
    $errMsg = $e->getMessage();
    if (str_contains($errMsg, 'Límite de llamadas PayPal') || str_contains($errMsg, 'Cooldown activo')) {
        $msg = 'Demasiadas solicitudes a PayPal. Espera un momento e intenta de nuevo.';
    } elseif (str_contains($errMsg, 'cURL errno 7') || str_contains($errMsg, 'Could not connect')) {
        $msg = 'PayPal no está disponible en este momento. Intenta de nuevo más tarde.';
    } elseif (str_contains($errMsg, 'cURL errno 28')) {
        $msg = 'PayPal tardó demasiado en responder. Intenta con un rango de fechas más pequeño.';
    } elseif (str_contains($errMsg, '401') || str_contains($errMsg, 'Unauthorized') || str_contains($errMsg, '403')) {
        $msg = 'Las credenciales de PayPal necesitan actualizarse. Contacta al administrador.';
    } else {
        $msg = 'Error al consultar PayPal. Es un problema temporal de PayPal, no del sistema.';
    }
    jsonResponse([
        'status' => false,
        'error' => $msg
    ], 500);
}

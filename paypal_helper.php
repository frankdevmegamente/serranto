<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

function executePayPalRequestWithRetry($ch, $maxRetries = 3) {
    $attempt = 0;
    $retryDelay = 2000000;

    while ($attempt < $maxRetries) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        if ($response !== false && $httpCode !== 429 && ($httpCode >= 200 && $httpCode < 500)) {
            return ['response' => $response, 'httpCode' => $httpCode];
        }

        $attempt++;
        if ($attempt >= $maxRetries) {
            $errorMsg = $response === false ? "Network Error (cURL errno $errno): $error" : "HTTP $httpCode";
            error_log("PayPal API falló tras $maxRetries intentos: $errorMsg");
            $logDir = __DIR__ . '/logs';
            if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
            file_put_contents(
                $logDir . '/paypal_errors.log',
                "[" . date('Y-m-d H:i:s') . "] $errorMsg\n",
                FILE_APPEND | LOCK_EX
            );
            return ['response' => $response, 'httpCode' => $httpCode, 'error' => $error, 'errno' => $errno];
        }

        usleep($retryDelay);
        $retryDelay *= 2;
    }
}

function getPayPalAccessToken() {
    enforcePayPalCooldown();

    $cacheFile = __DIR__ . '/requests/token_cache.json';

    if (file_exists($cacheFile)) {
        $content = @file_get_contents($cacheFile);
        if ($content) {
            $cache = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($cache['expires_at']) && time() < $cache['expires_at']) {
                return $cache['access_token'];
            }
        }
    }

    waitForPayPalRateLimit();
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => PAYPAL_API_URL . '/v1/oauth2/token',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: en_US'
        ],
        CURLOPT_USERPWD => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = executePayPalRequestWithRetry($ch, 3);
    curl_close($ch);

    if ($result['httpCode'] !== 200 || $result['response'] === false) {
        $detail = '';
        if (isset($result['errno']) && $result['errno']) {
            $detail = " (cURL errno {$result['errno']}: {$result['error']})";
        }
        throw new Exception("Error al obtener token PayPal: HTTP {$result['httpCode']}$detail");
    }

    $data = json_decode($result['response'], true);
    if (!isset($data['access_token']) || !isset($data['expires_in'])) {
        throw new Exception('Respuesta inválida de PayPal al obtener token');
    }

    $cache = [
        'access_token' => $data['access_token'],
        'expires_at' => time() + $data['expires_in'] - 300
    ];
    file_put_contents($cacheFile, json_encode($cache), LOCK_EX);

    return $cache['access_token'];
}

function getPayPalTransactions($accessToken, $startDate, $endDate) {
    enforcePayPalCooldown();

    $url = PAYPAL_API_URL . "/v1/reporting/transactions?start_date=$startDate&end_date=$endDate&fields=all&page_size=100";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = executePayPalRequestWithRetry($ch, 3);
    curl_close($ch);

    if ($result['httpCode'] !== 200) {
        $errDetail = isset($result['errno']) ? " (cURL errno {$result['errno']}: {$result['error']})" : '';
        throw new Exception("Error al obtener transacciones: HTTP {$result['httpCode']}$errDetail");
    }

    return json_decode($result['response'], true);
}

function getPayPalCaptureDetails($accessToken, $captureId) {
    enforcePayPalCooldown();

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => PAYPAL_API_URL . "/v2/payments/captures/$captureId",
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = executePayPalRequestWithRetry($ch, 3);
    curl_close($ch);

    if ($result['httpCode'] !== 200) {
        $errno = $result['errno'] ?? 'N/A';
        $errMsg = $result['error'] ?? ($result['response'] ?? 'unknown');
        error_log("PayPal CaptureDetails error: HTTP {$result['httpCode']}, cURL errno $errno: $errMsg");
    }

    return $result['httpCode'] === 200 ? json_decode($result['response'], true) : null;
}

function getPayPalTrackingInfo($accessToken, $identifier, $searchType = 'transaction_id', $accountId = '') {
    enforcePayPalCooldown();

    $param = $searchType === 'tracking_number' ? 'tracking_number' : 'transaction_id';
    $url = PAYPAL_API_URL . "/v1/shipping/trackers?" . $param . "=" . urlencode($identifier);
    if ($accountId) {
        $url .= "&account_id=" . urlencode($accountId);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = executePayPalRequestWithRetry($ch, 3);
    curl_close($ch);

    if ($result['httpCode'] !== 200) {
        $errDetail = isset($result['errno']) && $result['errno'] ? " (cURL errno {$result['errno']}: {$result['error']})" : '';
        $responseBody = $result['response'] ?: 'Sin respuesta';
        throw new Exception("PayPal TrackingInfo error: HTTP {$result['httpCode']}$errDetail - $responseBody");
    }

    $decoded = json_decode($result['response'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Error al decodificar respuesta de PayPal TrackingInfo');
    }

    return $decoded;
}

function updatePayPalTracking($accessToken, $transactionId, $trackingNumber, $carrier) {
    enforcePayPalCooldown();
    waitForPayPalRateLimit();

    $data = [
        'trackers' => [[
            'transaction_id' => $transactionId,
            'tracking_number' => $trackingNumber,
            'status' => 'SHIPPED',
            'carrier' => $carrier
        ]]
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => PAYPAL_API_URL . '/v1/shipping/trackers',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = executePayPalRequestWithRetry($ch, 3);
    curl_close($ch);

    if ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
        throw new Exception("Error al actualizar tracking: HTTP " . $result['httpCode'] . " - " . $result['response']);
    }

    return json_decode($result['response'], true);
}

function getPayPalTrackingBatch($accessToken, $transactionIds, $accountId = '') {
    $results = [];

    foreach ($transactionIds as $i => $transactionId) {
        try {
            if ($i > 0) usleep(50000);
            $trackInfo = getPayPalTrackingInfo($accessToken, $transactionId, 'transaction_id', $accountId);
            $trackers = $trackInfo['trackers'] ?? [];
            foreach ($trackers as $i => $tr) {
                if (isset($trackers[$i]['tracking_number'])) {
                    $trackers[$i]['tracking_number'] = (string) $trackers[$i]['tracking_number'];
                }
            }
            $results[$transactionId] = [
                'status' => true,
                'trackers' => $trackers
            ];
        } catch (Exception $e) {
            $results[$transactionId] = [
                'status' => false,
                'trackers' => []
            ];
        }
    }

    return $results;
}

function getPayPalRefundDetails($accessToken, $refundUrl) {
    enforcePayPalCooldown();

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $refundUrl,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = executePayPalRequestWithRetry($ch, 3);
    curl_close($ch);

    return $result['httpCode'] === 200 ? json_decode($result['response'], true) : null;
}

function createPayPalRefund($accessToken, $captureId, $refundData) {
    enforcePayPalCooldown();
    waitForPayPalRateLimit();

    $url = PAYPAL_API_URL . "/v2/payments/captures/{$captureId}/refund";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'Prefer: return=representation'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($refundData),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = executePayPalRequestWithRetry($ch, 3);
    curl_close($ch);

    $httpCode = $result['httpCode'];

    if ($httpCode < 200 || $httpCode >= 300) {
        $errorData = json_decode($result['response'], true);
        $curlDetail = isset($result['errno']) ? " (cURL errno {$result['errno']}: {$result['error']})" : '';
        $errorMessage = $errorData['details'][0]['description'] ??
                        $errorData['message'] ??
                        "Error HTTP {$httpCode}$curlDetail";
        throw new Exception($errorMessage);
    }

    return json_decode($result['response'], true);
}

function formatoMonedaPaypal($amount, $currency = 'MXN') {
    $simbolos = [
        'MXN' => '$', 'USD' => '$', 'EUR' => '€',
        'GBP' => '£', 'CAD' => 'C$', 'AUD' => 'A$',
    ];
    $simbolo = $simbolos[strtoupper($currency)] ?? '$';
    return $simbolo . ' ' . number_format((float)$amount, 2);
}

function checkPayPalConnectivity(): array {
    $host = parse_url(PAYPAL_API_URL, PHP_URL_HOST) ?: 'api-m.paypal.com';
    $ch = curl_init("https://$host/");
    curl_setopt_array($ch, [
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    return ['ok' => $errno === 0, 'errno' => $errno, 'error' => $error];
}

// ================================================================
// RATE LIMITER LOCAL (evita saturar el bloqueo de Neubox/Cloudflare)
// ================================================================

define('PAYPAL_RATE_LIMIT_FILE', __DIR__ . '/data/paypal_rate.json');

function getPayPalRateLimitCalls(): array {
    if (!file_exists(PAYPAL_RATE_LIMIT_FILE)) {
        return [];
    }
    $content = @file_get_contents(PAYPAL_RATE_LIMIT_FILE);
    if ($content === false) return [];
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function savePayPalRateLimitCalls(array $calls): void {
    $dir = dirname(PAYPAL_RATE_LIMIT_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    file_put_contents(PAYPAL_RATE_LIMIT_FILE, json_encode($calls, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function waitForPayPalRateLimit(): void {
    if (!defined('PAYPAL_RATE_LIMIT_ENABLED') || !PAYPAL_RATE_LIMIT_ENABLED) return;
    $maxCalls = defined('PAYPAL_RATE_LIMIT_CALLS') ? PAYPAL_RATE_LIMIT_CALLS : 3;
    $window = defined('PAYPAL_RATE_LIMIT_WINDOW') ? PAYPAL_RATE_LIMIT_WINDOW : 60;
    $calls = getPayPalRateLimitCalls();
    $now = time();
    $cutoff = $now - $window;
    $calls = array_values(array_filter($calls, fn($ts) => $ts > $cutoff));
    if (count($calls) >= $maxCalls) {
        $oldest = min($calls);
        $wait = $oldest + $window - $now;
        if ($wait > 0) {
            throw new Exception('Límite de llamadas PayPal alcanzado. Espera ' . ceil($wait) . 's antes de la siguiente.');
        }
        $calls = [];
    }
    $calls[] = time();
    savePayPalRateLimitCalls($calls);
}

function enforcePayPalCooldown(): void {
    if (checkPayPalCooldown()) {
        $until = $_SESSION['paypal_cooldown_until'] ?? 0;
        $remaining = $until - time();
        throw new Exception('PayPal no está disponible. Cooldown activo por ' . ceil($remaining / 60) . ' minuto(s) más.');
    }
}

function clearPayPalCache(): void {
    $cacheDir = __DIR__ . '/data/paypal_cache';
    if (!is_dir($cacheDir)) return;
    $files = glob($cacheDir . '/*.json');
    foreach ($files as $f) {
        @unlink($f);
    }
}

// ================================================================
// FUNCIONES PARA REPORTE SEMANAL PAYPAL
// ================================================================

function getPayPalCacheKey(string $startDate, string $endDate, ?string $filterStart, ?string $filterEnd): string {
    return md5($startDate . '|' . $endDate . '|' . ($filterStart ?? '') . '|' . ($filterEnd ?? ''));
}

function getCachedPayPalResult(string $cacheKey): ?array {
    $cacheFile = __DIR__ . '/data/paypal_cache/' . $cacheKey . '.json';
    if (!file_exists($cacheFile)) return null;
    $data = json_decode(@file_get_contents($cacheFile), true);
    if (!$data || !isset($data['expires']) || $data['expires'] < time()) {
        @unlink($cacheFile);
        return null;
    }
    return $data['data'];
}

function setCachedPayPalResult(string $cacheKey, array $data): void {
    $cacheDir = __DIR__ . '/data/paypal_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $payload = json_encode(['expires' => time() + 300, 'data' => $data]);
    @file_put_contents($cacheDir . '/' . $cacheKey . '.json', $payload, LOCK_EX);
}

function obtenerPagosPaypalPorRango(string $startDate, string $endDate, ?string $filterStart = null, ?string $filterEnd = null): array
{
    $cacheKey = getPayPalCacheKey($startDate, $endDate, $filterStart, $filterEnd);
    $cached = getCachedPayPalResult($cacheKey);
    if ($cached !== null) {
        return ['data' => $cached, 'error' => null];
    }

    try {
        $accessToken = getPayPalAccessToken();
        $allTransactions = [];
        $pageSize = 100;
        $hasMore = true;

        $useUpdatedFilter = $filterStart !== null && $filterEnd !== null;

        if ($useUpdatedFilter) {
            $dt = new DateTime($startDate);
            $dt2 = new DateTime($endDate);
            $origDays = $dt->diff($dt2)->days;
            $available = 31 - $origDays;
            $padBefore = $available > 0 ? min(7, max(0, (int)floor($available * 0.7))) : 0;
            $padAfter = $available > 0 ? min(3, max(0, $available - $padBefore)) : 0;

            $dt = new DateTime($startDate);
            if ($padBefore > 0) $dt->modify("-{$padBefore} days");
            $apiStart = $dt->format('Y-m-d\TH:i:s\Z');
            $dt2 = new DateTime($endDate);
            if ($padAfter > 0) $dt2->modify("+{$padAfter} days");
            $apiEnd = $dt2->format('Y-m-d\TH:i:s\Z');

            $rangeStart = $apiStart;
            $rangeEnd = $apiEnd;
        } else {
            $rangeStart = $startDate;
            $rangeEnd = $endDate;
        }

        $startDateOriginal = $rangeStart;
        $endDateOriginal = $rangeEnd;
        $startDateCursor = $rangeStart;

        while ($hasMore) {
            $url = PAYPAL_API_URL . "/v1/reporting/transactions?start_date={$startDateCursor}&end_date={$rangeEnd}&fields=all&page_size={$pageSize}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 30
            ]);

            $result = executePayPalRequestWithRetry($ch, 3);
            curl_close($ch);

            if ($result['httpCode'] !== 200) {
                $errDetail = isset($result['errno']) ? " (cURL errno {$result['errno']}: {$result['error']})" : '';
                return ['data' => [], 'error' => "PayPal API error: HTTP {$result['httpCode']}$errDetail"];
            }

            $response = json_decode($result['response'], true);
            $transactions = $response['transaction_details'] ?? [];
            $allTransactions = array_merge($allTransactions, $transactions);

            $hasMore = count($transactions) >= $pageSize;
            if ($hasMore && !empty($transactions)) {
                $last = end($transactions);
                $lastDate = $last['transaction_info']['transaction_initiation_date'] ?? '';
                if ($lastDate) {
                    $dt = new DateTime($lastDate);
                    $dt->modify('+1 second');
                    $startDateCursor = $dt->format('Y-m-d\TH:i:s\Z');
                } else {
                    $hasMore = false;
                }
            }
        }

        if ($useUpdatedFilter) {
            $allTransactions = array_filter($allTransactions, function($t) use ($filterStart, $filterEnd) {
                $date = $t['transaction_info']['transaction_updated_date'] ?? '';
                return $date >= $filterStart && $date <= $filterEnd;
            });
        } else {
            $allTransactions = array_filter($allTransactions, function($t) use ($startDateOriginal, $endDateOriginal) {
                $date = $t['transaction_info']['transaction_initiation_date'] ?? '';
                return $date >= $startDateOriginal && $date <= $endDateOriginal;
            });
        }
        $allTransactions = array_values($allTransactions);

        setCachedPayPalResult($cacheKey, $allTransactions);

        return ['data' => $allTransactions, 'error' => null];
    } catch (Exception $e) {
        error_log('PayPal API Error (rango): ' . $e->getMessage());
        return ['data' => [], 'error' => $e->getMessage()];
    }
}

function procesarTransaccionesPaypal(array $transactions): array
{
    $completadas = [];
    $reembolsadas = [];
    $pendientes = [];
    $chargebacks = [];
    $conversiones = [];
    $paymentEventCodes = ['T0000', 'T0001', 'T0002', 'T0003', 'T0004', 'T0011', 'T0013', 'T0100'];
    $refundEventCodes = ['T110', 'T120'];

    foreach ($transactions as $t) {
        $info = $t['transaction_info'] ?? [];
        $payer = $t['payer_info'] ?? [];
        $status = $info['transaction_status'] ?? '';
        $eventCode = $info['transaction_event_code'] ?? '';
        $subject = $info['transaction_subject'] ?? '';
        $captureStatus = $t['refund_info']['capture_status'] ?? '';
        $isRefunded = in_array($captureStatus, ['REFUNDED', 'PARTIALLY_REFUNDED'])
            || in_array($eventCode, ['T110', 'T120', 'T1107']);
        $amount = $info['transaction_amount']['value'] ?? 0;
        $currency = $info['transaction_amount']['currency_code'] ?? 'MXN';

        $captureId = (in_array($eventCode, $paymentEventCodes) && $status === 'S')
            ? ($info['transaction_id'] ?? null) : null;

        $item = [
            'transaction_id' => $info['transaction_id'] ?? '',
            'event_code' => $eventCode,
            'capture_id' => $captureId,
            'amount' => (float)$amount,
            'currency' => $currency,
            'amount_formatted' => formatoMonedaPaypal($amount, $currency),
            'status' => $status,
            'initiation_date' => $info['transaction_initiation_date'] ?? '',
            'updated_date' => $info['transaction_updated_date'] ?? '',
            'fee' => $info['fee_amount']['value'] ?? 0,
            'fee_formatted' => formatoMonedaPaypal($info['fee_amount']['value'] ?? 0, $currency),
            'neto_formatted' => formatoMonedaPaypal($amount - ($info['fee_amount']['value'] ?? 0), $currency),
            'ending_balance' => $info['ending_balance']['value'] ?? 0,
            'payer_name' => $payer['payer_name']['alternate_full_name'] ?? $payer['email_address'] ?? '—',
            'payer_email' => $payer['email_address'] ?? '—',
            'payer_country' => $payer['country_code'] ?? '—',
            'invoice_id' => $info['invoice_id'] ?? '',
            'custom_field' => $info['custom_field'] ?? '',
            'subject' => $subject,
            'is_refunded' => $isRefunded,
            'refund_type' => $isRefunded
                ? ($captureStatus === 'PARTIALLY_REFUNDED' ? 'partial' : ($captureStatus === 'REFUNDED' || in_array($eventCode, ['T110', 'T1107']) ? 'full' : 'partial'))
                : null,
            'refund_info' => $t['refund_info'] ?? [],
            'trackers' => $t['trackers'] ?? [],
        ];

        $isCurrencyConversion = preg_match('/^T01\d{2}$/', $eventCode)
            || stripos($subject, 'currency conversion') !== false
            || stripos($subject, 'conversión') !== false;

        if ($eventCode === 'T0403') {
            $item['status_label'] = 'Retiro';
            if ($item['payer_name'] === '—' && $item['payer_email'] === '—') {
                $item['payer_name'] = 'Retiro automático';
            }
            $chargebacks[] = $item;
        } elseif ($isCurrencyConversion) {
            $item['status_label'] = 'Conversión';
            $conversiones[] = $item;
        } elseif ($isRefunded) {
            $item['status_label'] = $captureStatus === 'PARTIALLY_REFUNDED' ? 'Parcial' : ($captureStatus === 'REFUNDED' || in_array($eventCode, ['T110', 'T1107']) ? 'Reembolsado' : 'Parcial');
            $reembolsadas[] = $item;
        } elseif ($status === 'S') {
            $item['status_label'] = 'Completado';
            $completadas[] = $item;
        } elseif ($status === 'P') {
            $item['status_label'] = 'Pendiente';
            $pendientes[] = $item;
        } elseif ($status === 'H') {
            $item['status_label'] = 'Retenido';
            $pendientes[] = $item;
        } else {
            $item['status_label'] = $status ?: $eventCode ?: '—';
            $pendientes[] = $item;
        }
    }

    // Segundo paso: emparejar reembolsos (T110/T120) con pagos por invoice_id|custom_field
    $refundsByKey = [];
    foreach ($transactions as $t) {
        $ec = $t['transaction_info']['transaction_event_code'] ?? '';
        if (in_array($ec, $refundEventCodes)) {
            $key = ($t['invoice_id'] ?? '') . '|' . ($t['custom_field'] ?? '');
            if ($key !== '|') {
                $refundsByKey[$key][] = $t;
            }
        }
    }
    if (!empty($refundsByKey)) {
        foreach ($completadas as $i => $p) {
            $key = ($p['invoice_id'] ?? '') . '|' . ($p['custom_field'] ?? '');
            if (isset($refundsByKey[$key])) {
                $lastRefund = end($refundsByKey[$key]);
                $totalRefunded = 0;
                foreach ($refundsByKey[$key] as $r) {
                    $totalRefunded += abs((float)($r['transaction_info']['transaction_amount']['value'] ?? 0));
                }
                $isPartial = count($refundsByKey[$key]) > 1 || ($lastRefund['transaction_info']['transaction_event_code'] ?? '') === 'T120';
                $completadas[$i]['is_refunded'] = true;
                $completadas[$i]['refund_type'] = $isPartial ? 'partial' : 'full';
                $completadas[$i]['status_label'] = $isPartial ? 'Parcial' : 'Reembolsado';
                $completadas[$i]['refund_info']['is_refunded'] = true;
                $completadas[$i]['refund_info']['refund_type'] = $isPartial ? 'partial' : 'full';
                $completadas[$i]['refund_info']['refund_id'] = $lastRefund['transaction_info']['transaction_id'] ?? null;
                $completadas[$i]['refund_info']['amount_refunded'] = $totalRefunded;
                $completadas[$i]['refund_info']['refund_date'] = $lastRefund['transaction_info']['transaction_initiation_date'] ?? null;
                $reembolsadas[] = $completadas[$i];
                unset($completadas[$i]);
            }
        }
        $completadas = array_values($completadas);
    }

    // Tercer paso: emparejar por capture_id desde reembolsos locales
    $localRefundsFile = __DIR__ . '/../data/refunds.json';
    if (file_exists($localRefundsFile)) {
        $localRefunds = json_decode(file_get_contents($localRefundsFile), true) ?? [];
        if (!empty($localRefunds)) {
            foreach ($completadas as $i => $p) {
                $capId = $p['capture_id'] ?? '';
                if (!$capId) continue;
                foreach ($localRefunds as $rf) {
                    if (($rf['capture_id'] ?? '') === $capId) {
                        $completadas[$i]['is_refunded'] = true;
                        $completadas[$i]['refund_type'] = $rf['refund_type'] ?? 'full';
                        $completadas[$i]['status_label'] = ($rf['refund_type'] ?? 'full') === 'partial' ? 'Parcial' : 'Reembolsado';
                        $completadas[$i]['refund_info']['is_refunded'] = true;
                        $completadas[$i]['refund_info']['refund_type'] = $rf['refund_type'] ?? 'full';
                        $completadas[$i]['refund_info']['refund_id'] = $rf['refund_id'] ?? null;
                        $completadas[$i]['refund_info']['amount_refunded'] = $rf['amount_refunded'] ?? null;
                        $completadas[$i]['refund_info']['refund_date'] = $rf['refund_date'] ?? null;
                        $reembolsadas[] = $completadas[$i];
                        unset($completadas[$i]);
                        break;
                    }
                }
            }
            $completadas = array_values($completadas);
        }
    }

    return [
        'completadas' => $completadas,
        'reembolsadas' => $reembolsadas,
        'pendientes' => $pendientes,
        'chargebacks' => $chargebacks,
        'conversiones' => $conversiones,
    ];
}

function resumenPagosPaypal(array $completadas, array $reembolsadas, array $pendientes, array $chargebacks = [], array $conversiones = []): array
{
    $totalNeto = array_sum(array_map(fn($t) => $t['amount'] - abs($t['fee']), $completadas));
    $totalReembolsado = array_sum(array_column($reembolsadas, 'amount'));
    $totalChargebacks = array_sum(array_column($chargebacks, 'amount'));
    $currency = !empty($completadas) ? $completadas[0]['currency'] : (!empty($reembolsadas) ? $reembolsadas[0]['currency'] : (!empty($chargebacks) ? $chargebacks[0]['currency'] : 'MXN'));

    $totalComisiones = array_sum(array_map(fn($t) => abs($t['fee']), $completadas));
    $montoBruto = array_sum(array_column($completadas, 'amount'));

    // Group completed transactions by currency
    $porMoneda = [];
    foreach ($completadas as $t) {
        $cur = $t['currency'];
        if (!isset($porMoneda[$cur])) {
            $porMoneda[$cur] = ['cantidad' => 0, 'bruto' => 0, 'comisiones' => 0, 'neto' => 0];
        }
        $porMoneda[$cur]['cantidad']++;
        $porMoneda[$cur]['bruto'] += $t['amount'];
        $porMoneda[$cur]['comisiones'] += abs($t['fee']);
        $porMoneda[$cur]['neto'] += $t['amount'] - abs($t['fee']);
    }
    $monedas = [];
    foreach ($porMoneda as $cur => $data) {
        $monedas[] = [
            'moneda' => $cur,
            'cantidad' => $data['cantidad'],
            'bruto_formateado' => formatoMonedaPaypal($data['bruto'], $cur),
            'comisiones_formateado' => formatoMonedaPaypal($data['comisiones'], $cur),
            'neto_formateado' => formatoMonedaPaypal($data['neto'], $cur),
        ];
    }
    usort($monedas, fn($a, $b) => $b['cantidad'] <=> $a['cantidad']);

    // Calculate MXN withdrawal total (T0403 withdrawals) to match PayPal CSV exports
    $mxnWithdrawalTotal = 0;
    $hasMxnWithdrawals = false;
    foreach ($chargebacks as $cb) {
        if ($cb['currency'] === 'MXN' && $cb['event_code'] === 'T0403') {
            $mxnWithdrawalTotal += abs($cb['amount']);
            $hasMxnWithdrawals = true;
        }
    }

    return [
        'total_completadas' => count($completadas),
        'total_reembolsadas' => count($reembolsadas),
        'total_pendientes' => count($pendientes),
        'total_chargebacks' => count($chargebacks),
        'total_general' => count($completadas) + count($reembolsadas) + count($pendientes) + count($chargebacks) + count($conversiones),
        'monto_completado_bruto' => $montoBruto,
        'monto_reembolsado' => $totalReembolsado,
        'monto_chargebacks' => $totalChargebacks,
        'monto_total_formateado' => formatoMonedaPaypal($totalNeto, $currency),
        'monto_reembolsado_formateado' => formatoMonedaPaypal($totalReembolsado, $currency),
        'monto_chargebacks_formateado' => formatoMonedaPaypal($totalChargebacks, $currency),
        'monto_retirado_mxn_formateado' => $hasMxnWithdrawals ? formatoMonedaPaypal($mxnWithdrawalTotal, 'MXN') : '$ 0.00',
        'moneda' => $currency,
        'total_comisiones' => $totalComisiones,
        'total_comisiones_formateado' => formatoMonedaPaypal($totalComisiones, $currency),
        'monto_bruto_formateado' => formatoMonedaPaypal($montoBruto, $currency),
        'total_neto' => $totalNeto,
        'total_neto_formateado' => formatoMonedaPaypal($totalNeto, $currency),
        'moneda_principal' => $currency,
        'por_moneda' => $monedas,
    ];
}

function agruparPaypalPorDia(array $transactions): array
{
    $agrupados = [];
    foreach ($transactions as $t) {
        $dateStr = $t['initiation_date'] ?? '';
        if (!$dateStr) continue;
        try {
            $dt = new DateTime($dateStr);
            $dia = $dt->format('Y-m-d');
        } catch (Exception $e) {
            continue;
        }
        if (!isset($agrupados[$dia])) {
            $agrupados[$dia] = ['fecha' => $dia, 'conteo' => 0, 'total' => 0];
        }
        $agrupados[$dia]['conteo']++;
        $agrupados[$dia]['total'] += $t['amount'];
    }
    ksort($agrupados);
    return array_values($agrupados);
}

function exportarCSVPaypalSemanal(array $pagos, string $semana_inicio, string $semana_fin): void
{
    $filename = 'paypal_reporte_semanal_' . $semana_inicio . '_al_' . $semana_fin . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'ID Transaccion', 'Estado', 'Fecha', 'Cliente', 'Email',
        'Monto', 'Moneda', 'Comision', 'Tipo Evento',
        'Factura ID', 'Custom Field', 'Asunto',
        'Reembolsado', 'Tipo Reembolso', 'Tracking'
    ]);

    foreach ($pagos as $p) {
        $trackingStr = '';
        if (!empty($p['trackers'])) {
            $parts = [];
            foreach ($p['trackers'] as $tr) {
                $parts[] = ($tr['carrier'] ?? '') . ': ' . ($tr['tracking_number'] ?? '');
            }
            $trackingStr = implode('; ', $parts);
        }

        fputcsv($output, [
            $p['transaction_id'],
            $p['status_label'],
            $p['initiation_date'],
            $p['payer_name'],
            $p['payer_email'],
            $p['amount_formatted'],
            $p['currency'],
            $p['fee_formatted'],
            $p['event_code'],
            $p['invoice_id'],
            $p['custom_field'],
            $p['subject'],
            $p['is_refunded'] ? 'Si' : 'No',
            $p['refund_type'] ?? '—',
            $trackingStr,
        ]);
    }

    fclose($output);
    exit;
}

function buildReporteHTMLPaypal(array $pagos, string $semana_inicio, string $semana_fin, array $resumen): string
{
    $totalPagos = $resumen['total_general'];

    $rows = '';
    foreach ($pagos as $i => $p) {
        $estadoClass = match($p['status_label']) {
            'Completado' => 'success',
            'Reembolsado', 'Parcial' => 'failed',
            'Retiro' => 'warning',
            default => 'warning',
        };
        $rows .= '<tr>';
        $rows .= '<td>' . ($i + 1) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['transaction_id']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['initiation_date']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['payer_name']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['payer_email']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['amount_formatted']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['currency']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['fee_formatted']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['neto_formatted']) . '</td>';
        $rows .= '<td>' . htmlspecialchars($p['event_code']) . '</td>';
        $rows .= '<td class="' . $estadoClass . '">' . htmlspecialchars($p['status_label']) . '</td>';
        $rows .= '</tr>';
    }

    return '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 10mm 8mm; }
    body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 7.5pt; color: #1e293b; }
    h1 { font-size: 16pt; color: #0f172a; margin: 0 0 2px; }
    .subtitle { font-size: 8pt; color: #64748b; margin-bottom: 10px; }
    .summary-table { width: 100%; border-collapse: separate; border-spacing: 5px; margin-bottom: 12px; }
    .summary-table td { width: 16.66%; vertical-align: top; padding: 0; }
    .summary-box { padding: 7px 10px; border-radius: 6px; border-left: 4px solid #e2e8f0; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
    .summary-box .box-label { font-size: 6pt; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 700; margin-bottom: 1px; }
    .summary-box .box-value { font-size: 13pt; font-weight: 800; line-height: 1.2; }
    .summary-box .box-sub { font-size: 5.5pt; color: #94a3b8; margin-top: 1px; }
    .box-mov { border-left-color: #3b82f6; } .box-mov .box-value { color: #1e40af; }
    .box-ok { border-left-color: #22c55e; } .box-ok .box-value { color: #16a34a; }
    .box-refund { border-left-color: #94a3b8; } .box-refund .box-value { color: #64748b; }
    .box-fail { border-left-color: #ef4444; } .box-fail .box-value { color: #dc2626; }
    .box-retiros { border-left-color: #f97316; } .box-retiros .box-value { color: #ea580c; }
    .box-net { border-left-color: #8b5cf6; } .box-net .box-value { color: #7c3aed; }
    table { width: 100%; border-collapse: collapse; font-size: 6.5pt; }
    th { background: #f8fafc; padding: 4px 3px; text-align: left; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; border-bottom: 2px solid #e2e8f0; }
    td { padding: 3px; border-bottom: 1px solid #f1f5f9; }
    tr:nth-child(even) { background: #fafafa; }
    .success { color: #16a34a; font-weight: 600; }
    .failed { color: #dc2626; font-weight: 600; }
    .warning { color: #d97706; font-weight: 600; }
    .footer { margin-top: 12px; font-size: 6pt; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
    <h1>Reporte de Transacciones &mdash; PayPal</h1>
    <div class="subtitle">' . $semana_inicio . ' al ' . $semana_fin . '</div>
    <table class="summary-table">
        <tr>
            <td><div class="summary-box box-mov"><div class="box-label">Total Movimientos</div><div class="box-value">' . $totalPagos . '</div><div class="box-sub">transacciones en el periodo</div></div></td>
            <td><div class="summary-box box-ok"><div class="box-label">Completadas</div><div class="box-value">' . $resumen['total_completadas'] . '</div><div class="box-sub">' . htmlspecialchars($resumen['monto_bruto_formateado'] ?? '$ 0.00') . ' bruto</div></div></td>
            <td><div class="summary-box box-refund"><div class="box-label">Reembolsadas</div><div class="box-value">' . $resumen['total_reembolsadas'] . '</div><div class="box-sub">' . htmlspecialchars($resumen['monto_reembolsado_formateado'] ?? '$ 0.00') . ' devuelto</div></div></td>
            <td><div class="summary-box box-fail"><div class="box-label">Comisiones</div><div class="box-value">' . htmlspecialchars($resumen['total_comisiones_formateado'] ?? '$ 0.00') . '</div><div class="box-sub">cobrado por PayPal</div></div></td>
            <td><div class="summary-box box-retiros"><div class="box-label">Retiros</div><div class="box-value">' . $resumen['total_chargebacks'] . '</div><div class="box-sub">chargebacks y retiros</div></div></td>
            <td><div class="summary-box box-net"><div class="box-label">Total Neto (MXN)</div><div class="box-value">' . htmlspecialchars($resumen['total_neto_formateado'] ?? '$ 0.00') . '</div><div class="box-sub">bruto &minus; comisiones</div></div></td>
        </tr>
    </table>
    <table>
        <thead>
            <tr>
                <th>#</th><th>ID</th><th>Fecha</th><th>Cliente</th><th>Email</th>
                <th>Monto</th><th>Moneda</th><th>Comision</th><th>Neto</th><th>Evento</th><th>Estado</th>
            </tr>
        </thead>
        <tbody>
            ' . $rows . '
        </tbody>
    </table>
    <div class="footer">Generado el ' . date('d/m/Y H:i') . ' · PayPal Dashboard</div>
</body>
</html>';
}

function generarPDFPaypal(array $pagos, string $semana_inicio, string $semana_fin, array $resumen): void
{
    if (!class_exists('\\Dompdf\\Dompdf')) {
        http_response_code(500);
        echo 'Error: DomPDF no esta instalado. Ejecuta: composer require dompdf/dompdf';
        exit;
    }

    $html = buildReporteHTMLPaypal($pagos, $semana_inicio, $semana_fin, $resumen);

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream("paypal_reporte_semanal_{$semana_inicio}_al_{$semana_fin}.pdf", ['Attachment' => true]);
    exit;
}

function guardarCSVPaypal(array $pagos, string $semana_inicio, string $semana_fin, string $dir): string
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = "paypal_reporte_semanal_{$semana_inicio}_al_{$semana_fin}.csv";
    $filepath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    $output = fopen($filepath, 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'ID Transaccion', 'Estado', 'Fecha', 'Cliente', 'Email',
        'Monto', 'Moneda', 'Comision', 'Tipo Evento',
        'Factura ID', 'Custom Field', 'Asunto',
        'Reembolsado', 'Tipo Reembolso', 'Tracking'
    ]);

    foreach ($pagos as $p) {
        $trackingStr = '';
        if (!empty($p['trackers'])) {
            $parts = [];
            foreach ($p['trackers'] as $tr) {
                $parts[] = ($tr['carrier'] ?? '') . ': ' . ($tr['tracking_number'] ?? '');
            }
            $trackingStr = implode('; ', $parts);
        }

        fputcsv($output, [
            $p['transaction_id'],
            $p['status_label'],
            $p['initiation_date'],
            $p['payer_name'],
            $p['payer_email'],
            $p['amount_formatted'],
            $p['currency'],
            $p['fee_formatted'],
            $p['event_code'],
            $p['invoice_id'],
            $p['custom_field'],
            $p['subject'],
            $p['is_refunded'] ? 'Si' : 'No',
            $p['refund_type'] ?? '—',
            $trackingStr,
        ]);
    }

    fclose($output);
    return $filepath;
}

function guardarPDFPaypal(array $pagos, string $semana_inicio, string $semana_fin, array $resumen, string $dir): string
{
    if (!class_exists('\\Dompdf\\Dompdf')) {
        return '';
    }

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = "paypal_reporte_semanal_{$semana_inicio}_al_{$semana_fin}.pdf";
    $filepath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    $html = buildReporteHTMLPaypal($pagos, $semana_inicio, $semana_fin, $resumen);

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    file_put_contents($filepath, $dompdf->output());

    return $filepath;
}

function obtenerSemanaPaypal(?string $fecha = null, ?DateTimeZone $tz = null): array
{
    $tz = $tz ?: new DateTimeZone('America/Mexico_City');
    $date = $fecha ? new DateTime($fecha, $tz) : new DateTime('now', $tz);
    $dayOfWeek = (int) $date->format('N');
    $date->modify('-' . ($dayOfWeek - 1) . ' days');

    $inicio = clone $date;
    $inicio->setTime(0, 0, 0);

    $date->modify('+6 days');
    $fin = clone $date;
    $fin->setTime(23, 59, 59);

    $inicio_utc = clone $inicio;
    $inicio_utc->setTimezone(new DateTimeZone('UTC'));
    $fin_utc = clone $fin;
    $fin_utc->setTimezone(new DateTimeZone('UTC'));

    return [
        'inicio'    => $inicio,
        'fin'       => $fin,
        'inicio_ts' => $inicio->getTimestamp(),
        'fin_ts'    => $fin->getTimestamp(),
        'inicio_str'=> $inicio->format('Y-m-d'),
        'fin_str'   => $fin->format('Y-m-d'),
        'inicio_display' => $inicio->format('d/m/Y'),
        'fin_display'    => $fin->format('d/m/Y'),
        'inicio_utc' => $inicio_utc->format('Y-m-d\TH:i:s\Z'),
        'fin_utc'    => $fin_utc->format('Y-m-d\TH:i:s\Z'),
    ];
}

// ================================================================
// PENDING TRACKING QUEUE
// ================================================================

define('PENDING_TRACKING_FILE', __DIR__ . '/data/pending_tracking.json');

function getPendingTracking(): array {
    if (!file_exists(PENDING_TRACKING_FILE)) {
        return [];
    }
    $content = @file_get_contents(PENDING_TRACKING_FILE);
    if ($content === false) {
        return [];
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return [];
    }
    return $data;
}

function savePendingTracking(array $items): void {
    $dir = dirname(PENDING_TRACKING_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents(PENDING_TRACKING_FILE, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function addPendingTracking(string $transactionId, string $trackingNumber, string $carrier): array {
    $items = getPendingTracking();
    $entry = [
        'id' => uniqid('pending_', true),
        'transaction_id' => $transactionId,
        'tracking_number' => $trackingNumber,
        'carrier' => $carrier,
        'created_at' => date('Y-m-d H:i:s'),
        'attempts' => 0,
        'last_error' => null,
        'status' => 'pending',
    ];
    $items[] = $entry;
    savePendingTracking($items);
    return $entry;
}

function removePendingTracking(string $id): void {
    $items = getPendingTracking();
    $items = array_values(array_filter($items, fn($item) => ($item['id'] ?? '') !== $id));
    savePendingTracking($items);
}

function processPendingTracking(): array {
    $items = getPendingTracking();
    $results = [];
    $processed = 0;
    $maxPerCall = 5;

    foreach ($items as $idx => &$item) {
        if ($processed >= $maxPerCall) {
            break;
        }
        if ($item['status'] !== 'pending') {
            continue;
        }
        if (($item['attempts'] ?? 0) >= 5) {
            $item['status'] = 'failed';
            $item['last_error'] = 'Excedió el máximo de reintentos (5)';
            $processed++;
            continue;
        }

        $item['status'] = 'processing';
        $item['attempts'] = ($item['attempts'] ?? 0) + 1;

        try {
            $accessToken = getPayPalAccessToken();
            $result = updatePayPalTracking($accessToken, $item['transaction_id'], $item['tracking_number'], $item['carrier']);
            $item['status'] = 'completed';
            $item['last_error'] = null;
            $results[] = [
                'id' => $item['id'],
                'transaction_id' => $item['transaction_id'],
                'status' => 'completed',
                'message' => '✅ Guía subida correctamente'
            ];
            clearPayPalCache();
            $processed++;
        } catch (Exception $e) {
            $item['last_error'] = $e->getMessage();
            if (str_contains($e->getMessage(), 'cURL errno 7') || str_contains($e->getMessage(), 'Could not connect') || str_contains($e->getMessage(), 'Cooldown activo')) {
                $item['status'] = 'pending';
                $results[] = [
                    'id' => $item['id'],
                    'transaction_id' => $item['transaction_id'],
                    'status' => 'pending',
                    'message' => '⏳ PayPal no disponible, reintentando más tarde'
                ];
                setPayPalCooldown();
                break;
            }
            $item['status'] = 'failed';
            $results[] = [
                'id' => $item['id'],
                'transaction_id' => $item['transaction_id'],
                'status' => 'failed',
                'message' => $e->getMessage()
            ];
            $processed++;
        }
    }
    unset($item);

    savePendingTracking($items);
    return $results;
}

// ================================================================
// PAYPAL COOLDOWN & CACHE
// ================================================================

function checkPayPalCooldown(): bool {
    if (!isset($_SESSION['paypal_cooldown_until'])) {
        return false;
    }
    if (time() < $_SESSION['paypal_cooldown_until']) {
        return true;
    }
    unset($_SESSION['paypal_cooldown_until']);
    unset($_SESSION['paypal_cooldown_failures']);
    return false;
}

function setPayPalCooldown(int $seconds = 0): void {
    $failures = ($_SESSION['paypal_cooldown_failures'] ?? 0) + 1;
    $_SESSION['paypal_cooldown_failures'] = $failures;
    $cooldownTimes = [300, 600, 1800];
    $idx = min($failures - 1, count($cooldownTimes) - 1);
    $cooldownSecs = $seconds > 0 ? $seconds : $cooldownTimes[$idx];
    $_SESSION['paypal_cooldown_until'] = time() + $cooldownSecs;
}

function clearPayPalCooldown(): void {
    unset($_SESSION['paypal_cooldown_until']);
    unset($_SESSION['paypal_cooldown_failures']);
}

function getDashboardCache(): ?array {
    if (!isset($_SESSION['paypal_dashboard_cache']) || !isset($_SESSION['paypal_dashboard_cache_time'])) {
        return null;
    }
    if (time() - $_SESSION['paypal_dashboard_cache_time'] > 3600) {
        unset($_SESSION['paypal_dashboard_cache']);
        unset($_SESSION['paypal_dashboard_cache_time']);
        return null;
    }
    return $_SESSION['paypal_dashboard_cache'];
}

function setDashboardCache(array $data): void {
    $_SESSION['paypal_dashboard_cache'] = $data;
    $_SESSION['paypal_dashboard_cache_time'] = time();
}

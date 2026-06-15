<?php
require_once __DIR__ . '/../paypal_helper.php';

session_status() === PHP_SESSION_NONE && session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

requireAuth();
validateCSRF();

$_SESSION['last_activity'] = time();

session_write_close();

$results = processPendingTracking();
$pending = getPendingTracking();
$pendingCount = count(array_filter($pending, fn($i) => ($i['status'] ?? '') === 'pending'));

jsonResponse([
    'status' => true,
    'results' => $results,
    'pending_count' => $pendingCount,
    'total' => count($pending)
]);

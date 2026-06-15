<?php
date_default_timezone_set('America/Mexico_City');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

define('ADMIN_USER', $_ENV['ADMIN_USER'] ?? 'admin');
define('ADMIN_PASS_HASH', $_ENV['ADMIN_PASS_HASH'] ?? '');
define('PAYPAL_CLIENT_ID', $_ENV['PAYPAL_CLIENT_ID'] ?? '');
define('PAYPAL_SECRET', $_ENV['PAYPAL_SECRET'] ?? '');
define('PAYPAL_API_URL', $_ENV['PAYPAL_API_URL'] ?? 'https://api-m.paypal.com');
define('PAYPAL_RATE_LIMIT_ENABLED', filter_var($_ENV['PAYPAL_RATE_LIMIT_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN));
define('PAYPAL_RATE_LIMIT_CALLS', (int)($_ENV['PAYPAL_RATE_LIMIT_CALLS'] ?? 3));
define('PAYPAL_RATE_LIMIT_WINDOW', (int)($_ENV['PAYPAL_RATE_LIMIT_WINDOW'] ?? 60));
define('STRIPE_SECRET_KEY', $_ENV['STRIPE_SECRET_KEY'] ?? '');
define('CURRENCY', 'MXN');
define('TIPO_CAMBIO', $_ENV['TIPO_CAMBIO'] ?? 20.00);

function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRF() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Token CSRF inválido']);
        exit;
    }
}

function requireAuth() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
}

function requireAdmin() {
    requireAuth();
    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Acceso denegado']);
        exit;
    }
}

function checkRateLimit($maxRequests = 100, $timeWindow = 60) {
    $now = time();
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = ['count' => 0, 'start' => $now];
    }
    if ($now - $_SESSION['rate_limit']['start'] > $timeWindow) {
        $_SESSION['rate_limit'] = ['count' => 0, 'start' => $now];
    }
    $_SESSION['rate_limit']['count']++;
    if ($_SESSION['rate_limit']['count'] > $maxRequests) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Demasiadas solicitudes. Intenta más tarde.']);
        exit;
    }
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function hasPayPal(): bool {
    return defined('PAYPAL_CLIENT_ID') && defined('PAYPAL_SECRET')
        && PAYPAL_CLIENT_ID !== '' && PAYPAL_SECRET !== '';
}

function hasStripe(): bool {
    return defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== '';
}

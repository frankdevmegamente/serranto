<?php
require_once __DIR__ . '/../config.php';
requireAuth();

$_SESSION['last_activity'] = time();

session_write_close();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'time' => time()]);

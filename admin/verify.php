<?php
/**
 * Файл: /admin/verify.php
 * 
 */

// Перетащите ползунок вправо для подтверждения
// === Безопасный запуск сессии ===
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['captcha_passed'] = time();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
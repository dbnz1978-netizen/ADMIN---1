<?php

/**
 * Название файла:      download.php
 * Назначение:          Безопасное скачивание прикреплённого файла из обращения.
 *                      Проверяет:
 *                      - Авторизацию пользователя
 *                      - Принадлежность файла (только свой или админ)
 *                      - Существование файла
 *                      Запрещает прямой доступ к файлам — только через этот скрипт.
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors' => false,   // включение отображения ошибок true/false
    'db_connect'     => true,    // подключение к базе
    'auth_check'     => true,    // подключение функций авторизации
    'sanitization'   => true,    // подключение валидации/экранирования
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// НАСТРОЙКА ДИРЕКТОРИИ ВЛОЖЕНИЙ
// ========================================

// ТА ЖЕ ДИРЕКТОРИЯ, ЧТО И В support.php
define('SUPPORT_ATTACHMENTS_DIR', $_SERVER['DOCUMENT_ROOT'] . '/../support/');

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ
// ========================================

$user = requireAuth($pdo);

if (!$user) {
    http_response_code(403);
    exit('Доступ запрещён');
}

// ========================================
// ПОЛУЧЕНИЕ ПАРАМЕТРОВ ЗАПРОСА
// ========================================

$filename = basename($_GET['file'] ?? '');
$ticketId = (int)($_GET['ticket_id'] ?? 0);

if (!$filename || !$ticketId) {
    header("Location: index.php");
    exit;
}

// ========================================
// ПРОВЕРКА ПРИНАДЛЕЖНОСТИ ФАЙЛА
// ========================================

$stmt = $pdo->prepare("
    SELECT t.user_id, m.attachment_path
    FROM support_tickets t
    JOIN support_messages m ON m.ticket_id = t.id
    WHERE t.id = ? AND m.attachment_path = ?
");
$stmt->execute([$ticketId, $filename]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

$userDataAdmin = getUserData($pdo, $user['id']);
$isAdmin       = ($userDataAdmin['author'] === 'admin');

if (!$record || ($record['user_id'] != $user['id'] && !$isAdmin)) {
    http_response_code(403);
    exit('Доступ запрещён');
}

// ========================================
// ПРОВЕРКА СУЩЕСТВОВАНИЯ ФАЙЛА
// ========================================

$filepath = SUPPORT_ATTACHMENTS_DIR . $filename;

if (!file_exists($filepath)) {
    http_response_code(404);
    exit('Файл не найден');
}

// ========================================
// ОТПРАВКА ФАЙЛА
// ========================================

// Отдаём файл
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);

// Закрываем соединение при завершении скрипта
exit;
<?php
/**
* Файл: admin/support/download.php
*
* Безопасное скачивание прикреплённого файла из обращения.
* Проверяет:
* - Авторизацию пользователя
* - Принадлежность файла (только свой или админ)
* - Существование файла
*
* Запрещает прямой доступ к файлам — только через этот скрипт.
*/

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/** 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// Подключаем системные компоненты
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей

// === ТА ЖЕ ДИРЕКТОРИЯ, ЧТО И В support.php ===
define('SUPPORT_ATTACHMENTS_DIR', $_SERVER['DOCUMENT_ROOT'] . '/../support/');

// Безопасный запуск сессии
startSessionSafe();

$user = requireAuth($pdo);
if (!$user) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    http_response_code(403);
    exit('Доступ запрещён');
}

$filename = basename($_GET['file'] ?? '');
$ticket_id = (int)($_GET['ticket_id'] ?? 0);

if (!$filename || !$ticket_id) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: index.php");
    exit;
}

// Проверка принадлежности
$stmt = $pdo->prepare("
    SELECT t.user_id, m.attachment_path
    FROM support_tickets t
    JOIN support_messages m ON m.ticket_id = t.id
    WHERE t.id = ? AND m.attachment_path = ?
");
$stmt->execute([$ticket_id, $filename]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

$userDataAdmin = getUserData($pdo, $user['id']);
$is_admin = ($userDataAdmin['author'] === 'admin');

if (!$record || ($record['user_id'] != $user['id'] && !$is_admin)) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    http_response_code(403);
    exit('Доступ запрещён');
}

$filepath = SUPPORT_ATTACHMENTS_DIR . $filename;

if (!file_exists($filepath)) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    http_response_code(404);
    exit('Файл не найден');
}

// Отдаём файл
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
exit;
<?php
/**
* Файл: admin/support/delete.php
*
* Удаляет обращение пользователя и все связанные сообщения.
* Также удаляет прикреплённые файлы из защищённой директории вне public_html.
*
* Требования:
* - Только для администраторов
* - Обязательный параметр ?id=...
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
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования

// === ТА ЖЕ ДИРЕКТОРИЯ ===
define('SUPPORT_ATTACHMENTS_DIR', $_SERVER['DOCUMENT_ROOT'] . '/../support/');

startSessionSafe();
$adminData = getAdminData($pdo);
if (!$adminData) { header("Location: ../logout.php"); exit; }
$user = requireAuth($pdo);
if (!$user) { header("Location: ../logout.php"); exit; }
$userDataAdmin = getUserData($pdo, $user['id']);
if ($userDataAdmin['author'] !== 'admin') {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../../index.php");
    exit;
}

$ticket_id = (int)($_GET['id'] ?? 0);
if (!$ticket_id) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: index.php");
    exit;
}

// Получаем пути файлов
$stmt = $pdo->prepare("SELECT attachment_path FROM support_messages WHERE ticket_id = ?");
$stmt->execute([$ticket_id]);
$files = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Удаляем тикет (ON DELETE CASCADE удалит все сообщения)
$pdo->prepare("DELETE FROM support_tickets WHERE id = ?")->execute([$ticket_id]);

// Удаляем файлы
foreach ($files as $file) {
    if ($file) {
        $fullPath = SUPPORT_ATTACHMENTS_DIR . $file;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
}

logEvent("Админ {$user['id']} удалил обращение #{$ticket_id} и файлы", true, 'info');
// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
header("Location: index.php");
exit;
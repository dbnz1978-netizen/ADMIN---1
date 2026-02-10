<?php

/**
 * Название файла:      delete.php
 * Назначение:          Удаляет обращение пользователя и все связанные сообщения
 *                      Также удаляет прикреплённые файлы из защищённой директории вне public_html
 *                      Требования:
 *                      - Только для администраторов
 *                      - Обязательный параметр ?id=...
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors' => false,  // включение отображения ошибок true/false
    'set_encoding'   => true,   // включение кодировки UTF-8
    'db_connect'     => true,   // подключение к базе
    'auth_check'     => true,   // подключение функций авторизации
    'file_log'       => true,   // подключение системы логирования
    'sanitization'   => true,   // подключение валидации/экранирования
    'csrf_token'     => true,   // генерация CSRF-токена
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// НАСТРОЙКА ДИРЕКТОРИИ ВЛОЖЕНИЙ
// ========================================

define('SUPPORT_ATTACHMENTS_DIR', $_SERVER['DOCUMENT_ROOT'] . '/../support/');

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ И ПОЛУЧЕНИЕ ДАННЫХ
// ========================================

$adminData = getAdminData($pdo);

if (!$adminData) {
    header("Location: ../logout.php");
    exit;
}

$user = requireAuth($pdo);

if (!$user) {
    header("Location: ../logout.php");
    exit;
}

$userDataAdmin = getUserData($pdo, $user['id']);

if ($userDataAdmin['author'] !== 'admin') {
    header("Location: ../../index.php");
    exit;
}

// ========================================
// ПРОВЕРКА МЕТОДА ЗАПРОСА
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    
    $_SESSION['flash_messages'] = [
        'success' => [],
        'error'   => ['Некорректный метод запроса.'],
    ];
    
    header("Location: index.php");
    exit;
}

// ========================================
// ПРОВЕРКА CSRF-ТОКЕНА
// ========================================

$csrfToken = $_POST['csrf_token'] ?? '';

if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    
    $_SESSION['flash_messages'] = [
        'success' => [],
        'error'   => ['Недействительная форма. Пожалуйста, обновите страницу.'],
    ];
    
    logEvent(
        "Проверка CSRF-токена не пройдена при удалении обращения — ID администратора: " . ($user['id'] ?? 'unknown') .
        " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        true,
        'error'
    );
    
    header("Location: index.php");
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ ID ОБРАЩЕНИЯ
// ========================================

$ticketId = (int)($_POST['id'] ?? 0);

if (!$ticketId) {
    $_SESSION['flash_messages'] = [
        'success' => [],
        'error'   => ['Некорректный идентификатор обращения.'],
    ];
    
    header("Location: index.php");
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ ПУТЕЙ ФАЙЛОВ
// ========================================

$stmt = $pdo->prepare("SELECT attachment_path FROM support_messages WHERE ticket_id = ?");
$stmt->execute([$ticketId]);
$files = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ========================================
// УДАЛЕНИЕ ОБРАЩЕНИЯ И ФАЙЛОВ
// ========================================

// Удаляем тикет (ON DELETE CASCADE удалит все сообщения)
$deleteStmt   = $pdo->prepare("DELETE FROM support_tickets WHERE id = ?");
$deleteStmt->execute([$ticketId]);
$deletedCount = $deleteStmt->rowCount();

// Удаляем файлы
foreach ($files as $file) {
    if ($file) {
        $fullPath = SUPPORT_ATTACHMENTS_DIR . $file;
        
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
}

// ========================================
// ЛОГИРОВАНИЕ И ПЕРЕНАПРАВЛЕНИЕ
// ========================================

logEvent("Админ {$user['id']} удалил обращение #{$ticketId} и файлы", true, 'info');

if ($deletedCount > 0) {
    $_SESSION['flash_messages'] = [
        'success' => ['Обращение удалено.'],
        'error'   => [],
    ];
} else {
    $_SESSION['flash_messages'] = [
        'success' => [],
        'error'   => ['Обращение не найдено или уже удалено.'],
    ];
}

header("Location: index.php");
exit;
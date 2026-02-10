<?php

/**
 * Название файла:      update_photo_info.php
 * Назначение:          Обработчик AJAX-запроса для обновления метаданных фотографии
 *                      (заголовка, описания и alt-текста). Поддерживает форматы:
 *                      JPEG, PNG, GIF, WebP, AVIF, JPEG XL
 * Автор:               User
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// ========================================
// КОНФИГУРАЦИЯ СКРИПТА
// ========================================

$config = [
    'display_errors'   => false,  // Включение отображения ошибок (true/false)
    'set_encoding'     => true,   // Включение кодировки UTF-8
    'db_connect'       => true,   // Подключение к базе данных
    'auth_check'       => true,   // Подключение функций авторизации
    'file_log'         => true,   // Подключение системы логирования
    'sanitization'     => true,   // Подключение валидации/экранирования
    'csrf_token'       => true,   // Генерация и проверка CSRF-токена
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Ошибка: администратор не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

// ========================================
// ПРОВЕРКА МЕТОДА ЗАПРОСА
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $msg = 'Method not allowed: ' . $_SERVER['REQUEST_METHOD'];
    logEvent("VALIDATION_ERROR: $msg", LOG_ERROR_ENABLED, 'error');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод запроса должен быть POST']);
    exit;
}

// ========================================
// ПРОВЕРКА CSRF-ТОКЕНА
// ========================================

$csrfTokenSession = $_SESSION['csrf_token'] ?? '';
$csrfTokenRequest = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($csrfTokenSession) || empty($csrfTokenRequest) || !hash_equals($csrfTokenSession, $csrfTokenRequest)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недействительный CSRF-токен. Обновите страницу.']);
    exit;
}

// ========================================
// ВАЛИДАЦИЯ ID ФОТОГРАФИИ
// ========================================

// Проверяем наличие и корректность ID фотографии
if (!isset($_POST['id']) || !is_numeric($_POST['id']) || intval($_POST['id']) <= 0) {
    $msg = 'Photo ID is required and must be a positive integer (received: ' . ($_POST['id'] ?? 'null') . ')';
    logEvent("VALIDATION_ERROR: $msg", LOG_ERROR_ENABLED, 'error');
    echo json_encode(['success' => false, 'message' => 'Неверный или отсутствующий ID фотографии']);
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ И ВАЛИДАЦИЯ ВХОДНЫХ ДАННЫХ
// ========================================

$photoId      = intval($_POST['id']);
$title        = isset($_POST['title']) ? trim($_POST['title']) : '';
$description  = isset($_POST['description']) ? trim($_POST['description']) : '';
$altText      = isset($_POST['alt_text']) ? trim($_POST['alt_text']) : '';
$validationErrors = [];

// Валидация заголовка
if (!empty($title)) {
    $title = substr($title, 0, 100);
    // Валидация текстового поля
    $resultField = validateTextareaField($title, 1, 100, 'Заголовок');
    if ($resultField['valid']) {
        $title = $resultField['value'];
    } else {
        $validationErrors[] = $resultField['error'];
    }
}

// Валидация описания
if (!empty($description)) {
    $description = substr($description, 0, 255);
    // Валидация текстового поля
    $resultField = validateTextareaField($description, 1, 255, 'Описание');
    if ($resultField['valid']) {
        $description = $resultField['value'];
    } else {
        $validationErrors[] = $resultField['error'];
    }
}

// Валидация alt-текста
if (!empty($altText)) {
    $altText = substr($altText, 0, 200);
    // Валидация текстового поля
    $resultField = validateTextareaField($altText, 1, 200, 'Alt текст');
    if ($resultField['valid']) {
        $altText = $resultField['value'];
    } else {
        $validationErrors[] = $resultField['error'];
    }
}

// ========================================
// ПРОВЕРКА РЕЗУЛЬТАТОВ ВАЛИДАЦИИ
// ========================================

if (!empty($validationErrors)) {
    $message = implode('; ', $validationErrors);
    logEvent("VALIDATION_ERROR: {$message} (Photo ID: {$photoId})", LOG_ERROR_ENABLED, 'error');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// ========================================
// ОБНОВЛЕНИЕ МЕТАДАННЫХ В БАЗЕ ДАННЫХ
// ========================================

try {
    // Подготавливаем SQL-запрос для обновления записи
    $stmt = $pdo->prepare("UPDATE `media_files` SET 
                          `title` = ?, 
                          `description` = ?, 
                          `alt_text` = ?
                          WHERE `id` = ?");

    $result = $stmt->execute([$title, $description, $altText, $photoId]);

    if ($result && $stmt->rowCount() > 0) {
        logEvent("INFO: Photo metadata updated — ID: $photoId", LOG_INFO_ENABLED, 'info');
        echo json_encode([
            'success' => true, 
            'message' => 'Данные успешно обновлены'
        ]);
    } else {
        // Запись не найдена или данные не изменились
        logEvent("INFO: No changes or photo not found — ID: $photoId", LOG_INFO_ENABLED, 'info');
        echo json_encode([
            'success' => false, 
            'message' => 'Данные не были обновлены (возможно, запись не найдена или значения не изменились)'
        ]);
    }

} catch (Exception $e) {
    $errorMessage = 'Database error: ' . $e->getMessage() . ' (Photo ID: ' . $photoId . ')';
    logEvent("DB_ERROR: $errorMessage", LOG_ERROR_ENABLED, 'error');
    // Для безопасности не возвращаем детали ошибки пользователю в продакшене
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка при обновлении данных']);
}

// Закрываем соединение при завершении скрипта
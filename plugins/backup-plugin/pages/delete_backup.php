<?php

/**
 * Название файла:      delete_backup.php
 * Назначение:          Безопасное удаление резервных копий
 *                      - Проверка прав администратора
 *                      - Удаление файлов из admin/backups
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-12
 * Последнее изменение: 2026-02-12
 */

// ======================================== 
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors'  => false,        // отключение отображения ошибок
    'set_encoding'    => true,         // включение кодировки UTF-8
    'db_connect'      => true,         // подключение к базе
    'auth_check'      => true,         // подключение функций авторизации
    'file_log'        => true,         // подключение системы логирования
    'display_alerts'  => false,        // отключение отображения сообщений
    'sanitization'    => true,         // подключение валидации/экранирования
    'csrf_token'      => true,         // генерация CSRF-токена
    'plugin_access'   => true,         // подключение систему управления доступом к плагинам
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../../../admin/functions/init.php';

// ========================================
// ПРОВЕРКА ДОСТУПА К ПЛАГИНУ
// ========================================

// Подключаем функции резервного копирования
require_once __DIR__ . '/../functions/backup_functions.php';

$pluginName = getPluginNameFromPath(__DIR__);
// pluginAccessGuard проверяет авторизацию, права и доступ к плагину
// Требуем только администраторский доступ для удаления бэкапов
$userDataAdmin = pluginAccessGuard($pdo, $pluginName, 'admin');

// ========================================
// ОБРАБОТКА УДАЛЕНИЯ
// ========================================

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Метод не разрешён.']));
}

// Проверка CSRF токена
$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($csrfToken)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Недействительный CSRF токен.']));
}

if (!isset($_POST['file'])) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Не указан файл для удаления.']));
}

$fileName = basename($_POST['file']); // basename для дополнительной защиты

// Валидация имени файла
if (!isValidBackupFileName($fileName)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Недопустимое имя файла.']));
}

// Определяем путь к директории с резервными копиями в ../backups
// Используем realpath для корректного разрешения символических ссылок
// и работы независимо от структуры сервера (с public_html или без)
$rootPath = realpath(__DIR__ . '/../../../');
if ($rootPath === false) {
    // Если корневая директория не существует или недоступна
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Корневая директория не найдена.']));
}
// NOTE: dirname($rootPath) специально используется для размещения backups
// вне веб-доступной директории (../backups относительно корня сайта)
// Это повышает безопасность, предотвращая прямой доступ к резервным копиям через веб
$backupDir = dirname($rootPath) . '/backups';
$filePath = $backupDir . '/' . $fileName;

// Проверяем существование файла
if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    exit(json_encode(['success' => false, 'message' => 'Файл не найден.']));
}

// Проверяем, что путь к файлу действительно находится в директории backups
$realFilePath = realpath($filePath);
$realBackupDir = realpath($backupDir);

if ($realFilePath === false || $realBackupDir === false || strpos($realFilePath, $realBackupDir) !== 0) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Доступ к файлу запрещён.']));
}

// Удаляем файл
if (unlink($filePath)) {
    exit(json_encode(['success' => true, 'message' => 'Резервная копия успешно удалена.']));
} else {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Не удалось удалить файл.']));
}

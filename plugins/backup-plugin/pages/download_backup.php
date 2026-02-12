<?php

/**
 * Название файла:      download_backup.php
 * Назначение:          Безопасная загрузка резервных копий
 *                      - Проверка прав администратора
 *                      - Загрузка файлов из admin/backups
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
    'csrf_token'      => false,        // не нужен CSRF-токен для GET запросов
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
// Требуем только администраторский доступ для скачивания бэкапов
$userDataAdmin = pluginAccessGuard($pdo, $pluginName, 'admin');

// ========================================
// ОБРАБОТКА СКАЧИВАНИЯ
// ========================================

if (!isset($_GET['file'])) {
    http_response_code(400);
    exit('Не указан файл для скачивания.');
}

$fileName = basename($_GET['file']); // basename для дополнительной защиты

// Валидация имени файла
if (!isValidBackupFileName($fileName)) {
    http_response_code(400);
    exit('Недопустимое имя файла.');
}

// Определяем путь к директории с резервными копиями в admin/backups
$rootPath = realpath(__DIR__ . '/../../../..');
$backupDir = $rootPath . '/admin/backups';
$filePath = $backupDir . '/' . $fileName;

// Проверяем существование файла
if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    exit('Файл не найден.');
}

// Проверяем, что путь к файлу действительно находится в директории backups
$realFilePath = realpath($filePath);
$realBackupDir = realpath($backupDir);

if ($realFilePath === false || $realBackupDir === false || strpos($realFilePath, $realBackupDir) !== 0) {
    http_response_code(403);
    exit('Доступ к файлу запрещён.');
}

// Отправляем файл пользователю
// Дополнительная защита от header injection - удаляем любые переводы строк
$safeFileName = str_replace(["\r", "\n", '"'], '', $fileName);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $safeFileName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Очищаем буферы вывода
if (ob_get_level()) {
    ob_end_clean();
}

// Отправляем файл
readfile($filePath);
exit;

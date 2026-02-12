<?php

/**
 * Название файла:      delete_backup.php
 * Назначение:          Безопасное удаление резервных копий
 *                      - Проверка прав администратора
 *                      - Удаление файлов вне корня сайта
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
// ПРОВЕРКА ПРАВ АДМИНИСТРАТОРА
// ========================================

// Проверяем, что пользователь авторизован и имеет права администратора
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Доступ запрещён. Требуется авторизация.']));
}

// Получаем данные текущего пользователя
try {
    $stmt = $pdo->prepare("SELECT author FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || $user['author'] !== 'admin') {
        http_response_code(403);
        exit(json_encode(['success' => false, 'message' => 'Доступ запрещён. Требуются права администратора.']));
    }
} catch (PDOException $e) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Ошибка проверки прав доступа.']));
}

// ========================================
// ПРОВЕРКА ДОСТУПА К ПЛАГИНУ
// ========================================

// Автоматическое определение имени плагина
function getPluginName() {
    $path = __DIR__;
    $parts = explode('/', str_replace('\\', '/', $path));
    foreach ($parts as $i => $part) {
        if ($part === 'plugins' && isset($parts[$i + 1])) {
            return $parts[$i + 1];
        }
    }
    return 'unknown';
}

$pluginName = getPluginName();
$userDataAdmin = pluginAccessGuard($pdo, $pluginName);

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

$fileName = $_POST['file'];

// Валидация имени файла
if (!preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $fileName)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Недопустимое имя файла.']));
}

// Определяем путь к директории с резервными копиями (вне корня сайта)
$rootPath = realpath(__DIR__ . '/../../../../');
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

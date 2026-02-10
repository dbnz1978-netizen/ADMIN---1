<?php
/**
 * Файл: /admin/functions/init.php
 * 
 * Центральная инициализация проекта для админки.
 * Позволяет включать или отключать функции через массив $config на каждой странице.
 * 
 * Принципы работы:
 * - Все функции по умолчанию выключены.
 * - Если ключ отсутствует в $config на странице — он автоматически считается false.
 * - На странице достаточно задать $config и подключить этот файл через require_once.
 * 
 * Пример использования на странице:
 * 
 * $config = [
 *     'display_errors' => true,
 *     'set_encoding'   => true,
 *     'db_connect'     => true,
 *     'auth_check'     => true,
 *     'csrf_token'     => true,
 * ];
 * require_once __DIR__ . '/init.php';
 */

// === Определяем доступ к файлам ===
if (!defined('APP_ACCESS')) {
    $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $hasScriptName = $scriptName !== '';
    $initPath = realpath(__FILE__);
    $scriptFilenameReal = realpath($scriptFilename);
    $isDirectAccess = false;
    $checkedScriptName = false;
    if ($scriptFilenameReal && $initPath) {
        $isDirectAccess = $scriptFilenameReal === $initPath;
    } elseif ($hasScriptName && $initPath) {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $docRootReal = $docRoot !== '' ? realpath($docRoot) : false;
        if ($docRootReal && strpos($initPath, $docRootReal) === 0) {
            $expectedScriptName = str_replace('\\', '/', substr($initPath, strlen($docRootReal)));
            $normalizedScriptName = str_replace('\\', '/', $scriptName);
            $checkedScriptName = true;
            $isDirectAccess = $expectedScriptName !== '' && $normalizedScriptName === $expectedScriptName;
        }
    }
    $shouldLogVerificationFailure = !$isDirectAccess && $hasScriptName && $scriptFilenameReal === false && !$checkedScriptName;
    if ($shouldLogVerificationFailure) {
        error_log('[init.php] Unable to verify direct access context for SCRIPT_NAME check.');
    }
    if ($isDirectAccess) {
        http_response_code(403);
        exit('Forbidden');
    }

    define('APP_ACCESS', true); // Используется для защиты прямого доступа к файлам
}

// === Установка значений по умолчанию ===
$defaultConfig = [
    'display_errors'  => false,         // отображение ошибок PHP (для разработки)
    'set_encoding'    => false,         // установка кодировки UTF-8
    'db_connect'      => false,         // подключение базы данных
    'auth_check'      => false,         // подключение функций авторизации
    'file_log'        => false,         // подключение системы логирования
    'display_alerts'  => false,         // подключение функций отображения сообщений
    'sanitization'    => false,         // подключение функций валидации и экранирования
    'mailer'          => false,         // подключение отправка email уведомлений
    'jsondata'        => false,         // подключение обновление JSON данных пользователя
    'htmleditor'      => false,         // подключение редактора WYSIWYG
    'pagination'      => false,         // генерации пагинации
    'csrf_token'      => false,         // генерация и валидация CSRF-токена
    'start_session'   => false,         // запуск Session
    'plugin_manager'  => false,         // подключение менеджера плагинов
];

// Если $config не задано на странице — создаём пустой массив
if (!isset($config) || !is_array($config)) {
    $config = [];
}

// Объединяем $config со значениями по умолчанию
// Если ключ не задан на странице — будет использоваться значение по умолчанию (false)
$config = array_merge($defaultConfig, $config);

// === Отображение ошибок (только для разработки) ===
if (!empty($config['display_errors'])) {
    ini_set('display_errors', 1);          // Включить отображение ошибок
    ini_set('display_startup_errors', 1);  // Отображать ошибки запуска PHP
    error_reporting(E_ALL);                // Отображать все уровни ошибок
}

// === Кодировка ===
if (!empty($config['set_encoding'])) {
    mb_internal_encoding('UTF-8');               // Внутренняя кодировка строк
    mb_http_output('UTF-8');                     // Кодировка вывода
    header('Content-Type: text/html; charset=utf-8'); // Заголовок Content-Type
}

// === Подключение зависимостей ===
if (!empty($config['db_connect'])) {
    // Подключение к базе данных (работает в любых контекстах: веб-сервер, CLI, и т.д.)
    require_once __DIR__ . '/../../connect/db.php';
}

// Подключаем file_log.php ПЕРЕД auth_check.php, чтобы использовать полноценное логирование
// если оно включено. auth_check.php содержит заглушку logEvent() на случай если file_log отключен.
if (!empty($config['file_log'])) {
    require_once __DIR__ . '/file_log.php'; // Логирование
}

$needsAuthCheck = !empty($config['auth_check']) || !empty($config['start_session']) || !empty($config['csrf_token']);
if ($needsAuthCheck) {
    require_once __DIR__ . '/auth_check.php'; // Функции авторизации
}

if (!empty($config['display_alerts'])) {
    require_once __DIR__ . '/display_alerts.php'; // Отображение сообщений

    $errors = [];
    $successMessages = [];
}

if (!empty($config['sanitization'])) {
    require_once __DIR__ . '/sanitization.php'; // Валидация/экранирование
}

if (!empty($config['mailer'])) {
    require_once __DIR__ . '/mailer.php'; // Отправка email уведомлений
}

if (!empty($config['jsondata'])) {
    require_once __DIR__ . '/jsondata.php'; // Обновление JSON данных пользователя
}

if (!empty($config['htmleditor'])) {
    require_once __DIR__ . '/htmleditor.php'; // HTML-редактор WYSIWYG на странице.
}

if (!empty($config['pagination'])) {
    require_once __DIR__ . '/pagination.php'; // Функция генерации HTML пагинации
}

if (!empty($config['plugin_manager'])) {
    require_once __DIR__ . '/plugin_manager.php'; // Менеджер плагинов
}

// === Генерация и валидация CSRF-токена (если включено) ===
if (!empty($config['csrf_token'])) {
    startSessionSafe();

    // Генерация токена, если он ещё не создан
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        // Генерируем случайный токен длиной 64 символа (32 байта)
    }

    // Функция валидации CSRF-токена
    if (!function_exists('validateCsrfToken')) {
        function validateCsrfToken($token) {
            return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
        }
    }
}

// === Запуск Session (если включено) ===
if (!empty($config['start_session'])) {
    startSessionSafe(); // Запуск Session (функция находится в auth_check.php)
}

<?php
/**
 * Файл: /admin/index.php
 * 
 * Админ-панель — Главная точка входа (административный роутер)
 * 
 * Назначение:
 *   - Единая точка входа для всех администраторов и модераторов.
 *   - Выполняет предварительную инициализацию: подключение БД, проверка целостности настроек администратора,
 *     проверка сессии, управление логированием, безопасное перенаправление.
 *   - НЕ отображает контент напрямую — только перенаправляет:
 *       • на logout.php — при отсутствии авторизации или критических ошибках;
 *       • на user/index.php — при успешной авторизации.
 * 
 * Безопасность:
 *   - Все перенаправления защищены от open redirect (жёстко заданные пути).
 *   - Проверка целостности записи 'admin' в БД — защита от повреждения конфигурации.
 *   - Логирование событий включается/выключается динамически через настройки администратора.
 *   - Кодировка UTF-8 установлена явно на всех уровнях (mbstring + header).
 * 
 * Логика выполнения:
 *   1. Инициализация кодировки и подключение зависимостей (БД, логирование, авторизация).
 *   2. Загрузка настроек администратора из БД (таблица `users`, запись с `author = 'admin'`).
 *      → При ошибке (запись отсутствует / ошибка БД / некорректный JSON) — немедленное перенаправление на logout.php.
 *   3. На основе настроек админа определяются глобальные флаги логирования (LOG_INFO_ENABLED, LOG_ERROR_ENABLED).
 *   4. Проверка текущей сессии:
 *        – если пользователь авторизован → логируем и перенаправляем в личный кабинет (user/index.php);
 *        – иначе → перенаправляем на logout.php (выход + форма входа).
 * 
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// Устанавливаем кодировку
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

// === Подключение зависимостей ===
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // Подключение к БД (объект $pdo)
require_once __DIR__ . '/functions/auth_check.php';                  // Проверка авторизации (checkAuth, redirectIfAuth, getAdminData)
require_once __DIR__ . '/functions/file_log.php';                    // Функции логирования (logEvent)

// Получаем настройки администратора из БД
$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    // Критическая ошибка: запись админа повреждена или отсутствует → безопасный выход
    header("Location: logout.php");
    exit;
}

// Настройка глобальных флагов логирования (управляется через админ-панель)
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);  // Логировать информационные события
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки и предупреждения

// Проверяем, авторизован ли текущий пользователь
$user = redirectIfAuth();  // Возвращает массив с данными или false

if ($user) {
    // Пользователь авторизован → перенаправляем в личный кабинет
    $redirectTo = 'user/index.php';
    logEvent("Авторизованный пользователь перенаправлен на: $redirectTo — ID: {$user['id']} — IP: {$_SERVER['REMOTE_ADDR']}", LOG_INFO_ENABLED, 'info');
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: $redirectTo");
    exit;
}

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});

// Пользователь не авторизован → отправляем на выход (logout.php обрабатывает сессию и показывает форму входа)
header("Location: logout.php");
exit;
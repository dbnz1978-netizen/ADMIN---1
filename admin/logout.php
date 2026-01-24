<?php
/**
 * Файл: /admin/logout.php
 *
 * Скрипт для безопасного выхода пользователя из системы.
 * Обеспечивает полное удаление данных аутентификации как на сервере,
 * так и на стороне клиента.
 *
 * Функциональность:
 * - Удаление токена "запомнить меня" из базы данных
 * - Очистка cookies на стороне клиента
 * - Уничтожение сессии PHP
 * - Логирование действий
 * - Перенаправление на страницу входа
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/** 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// === Подключение зависимостей ===
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/functions/auth_check.php';                  // Авторизация и получения данных пользователей
require_once __DIR__ . '/functions/file_log.php';                    // Система логирования

// Получаем настройки администратора
$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: logout.php");
    exit;
}

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

// Безопасный запуск сессии
startSessionSafe();

// === Инициализация переменных ===
$userId = $_SESSION['user_id'] ?? null;
$token = $_COOKIE['remember_token'] ?? null;

// === Логируем начало процесса выхода ===
$userInfo = $userId ? "user_id=$userId" : "неавторизованный пользователь";
logEvent("Инициирован выход из системы для $userInfo", LOG_INFO_ENABLED, 'info');

try {
    // === Удаление токена "запомнить меня", если он есть ===
    if ($token) {
        // Удаляем запись из user_sessions
        $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE token = ?");
        $deleted = $stmt->execute([$token]);

        if ($deleted) {
            logEvent("Токен 'запомнить меня' успешно удалён из базы данных для $userInfo", LOG_INFO_ENABLED, 'info');
        } else {
            logEvent("Не удалось удалить токен 'запомнить меня' из базы данных для $userInfo", LOG_ERROR_ENABLED, 'error');
        }

        // Удаляем куку на клиенте
        setcookie('remember_token', '', time() - 3600, '/', '', true, true); // secure + httponly
    }

    // === Уничтожение сессии ===
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Очищаем данные сессии
        $_SESSION = [];

        // Удаляем куку сессии на клиенте (важно!)
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 3600,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        // Уничтожаем саму сессию
        session_destroy();

        logEvent("Сессия успешно уничтожена для $userInfo", LOG_INFO_ENABLED, 'info');
    } else {
        logEvent("Активная сессия отсутствует — нечего уничтожать для $userInfo", LOG_INFO_ENABLED, 'info');
    }

} catch (Exception $e) {
    $errorMsg = "Ошибка при выходе из системы: " . $e->getMessage();
    logEvent($errorMsg, LOG_ERROR_ENABLED, 'error');
}

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});

// === Финальное перенаправление ===
$redirectUrl = 'authorization.php';
header("Location: $redirectUrl", true, 302);
exit;

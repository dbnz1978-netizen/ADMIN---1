<?php
/**
 * Файл: /admin/user/confirm_email.php
 *
 * Без дизайна. Обрабатывает подтверждение смены email по токену из ссылки.
 * После подтверждения — перенаправляет на personal_data.php с сообщением.
 */

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

mb_internal_encoding('UTF-8');
header('Content-Type: text/html; charset=utf-8');

// Подключаем зависимости
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования

// Безопасный запуск сессии
startSessionSafe();

// Получаем настройки администратора
$adminData = getAdminData($pdo);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);
define('LOG_INFO_ENABLED', ($adminData['log_info_enabled'] ?? false) === true);

// Получаем токен из URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $_SESSION['alerts'] = [['type' => 'error', 'message' => 'Недействительная ссылка подтверждения.']];
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: /admin/user/personal_data.php");
    exit;
}

try {
    // Ищем пользователя по токену (без привязки к сессии! важное изменение)
    $stmt = $pdo->prepare("
        SELECT id, pending_email, email_change_token, email_change_expires 
        FROM users 
        WHERE email_change_token = ? AND pending_email IS NOT NULL
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Токен не найден");
    }

    $expires = new DateTime($user['email_change_expires']);
    $now = new DateTime();

    if ($now > $expires) {
        // Удаляем просроченный токен
        $pdo->prepare("UPDATE users SET pending_email = NULL, email_change_token = NULL, email_change_expires = NULL WHERE id = ?")
            ->execute([$user['id']]);
        throw new Exception("Срок действия ссылки истёк");
    }

    // Подтверждаем email
    $newEmail = $user['pending_email'];
    $pdo->prepare("
        UPDATE users 
        SET email = ?, 
            pending_email = NULL, 
            email_change_token = NULL, 
            email_change_expires = NULL,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$newEmail, $user['id']]);

    // Обновляем сессию, если пользователь авторизован и это его аккаунт
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user['id']) {
        $_SESSION['user_email'] = $newEmail;
    }

    logEvent("Email подтверждён по ссылке — ID: {$user['id']}, новый email: $newEmail", LOG_INFO_ENABLED, 'info');

    $_SESSION['alerts'] = [['type' => 'success', 'message' => 'Email успешно подтверждён и изменён!']];

} catch (Exception $e) {
    logEvent("Ошибка подтверждения email: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    $_SESSION['alerts'] = [['type' => 'error', 'message' => 'Не удалось подтвердить email. Ссылка недействительна или устарела.']];
}


// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
header("Location: /admin/user/personal_data.php");
exit;
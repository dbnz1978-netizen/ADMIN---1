<?php

/**
 * Название файла:      confirm_email.php
 * Назначение:          Обрабатывает подтверждение смены email-адреса по уникальному токену из ссылки
 *                      Скрипт работает без авторизации — достаточно знать действительный токен
 *                      Логика работы:
 *                      - Получает токен из GET-параметра
 *                      - Находит пользователя с таким токеном и неподтверждённым email (pending_email)
 *                      - Проверяет срок действия токена (обычно 24 часа)
 *                      - Если всё в порядке — обновляет основной email, очищает временные поля
 *                      - Если пользователь уже авторизован — обновляет его email в сессии
 *                      - Перенаправляет на страницу личных данных с flash-сообщением
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors'  => false,        // включение отображения ошибок true/false
    'set_encoding'    => true,         // включение кодировки UTF-8
    'db_connect'      => true,         // подключение к базе
    'auth_check'      => true,         // подключение функций авторизации
    'file_log'        => true,         // подключение системы логирования
    'sanitization'    => true,         // подключение валидации/экранирования
    'start_session'   => true,         // запуск сессии
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';


// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

// Получаем настройки администратора (для логирования)
$adminData = getAdminData($pdo);

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);

// ========================================
// ПОЛУЧЕНИЕ И ПРОВЕРКА ТОКЕНА
// ========================================

// Получаем токен из URL
$token = $_GET['token'] ?? '';

// Проверка наличия токена
if (empty($token)) {
    $_SESSION['flash_messages']['error'] = ['Недействительная ссылка подтверждения.'];
    header("Location: /admin/user/personal_data.php");
    exit;
}

// ========================================
// ОБРАБОТКА ТОКЕНА
// ========================================

try {
    // Ищем пользователя по токену (без привязки к сессии — важно для подтверждения после выхода)
    $stmt = $pdo->prepare("
        SELECT id, pending_email, email_change_token, email_change_expires 
        FROM users 
        WHERE email_change_token = ? AND pending_email IS NOT NULL
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("Токен не найден в базе данных");
    }
    
    // Проверяем срок действия токена
    $expires = new DateTime($user['email_change_expires']);
    $now     = new DateTime();
    
    if ($now > $expires) {
        // Очищаем просроченные данные у пользователя
        $pdo->prepare("
            UPDATE users 
            SET pending_email = NULL, 
                email_change_token = NULL, 
                email_change_expires = NULL 
            WHERE id = ?
        ")->execute([$user['id']]);
        
        throw new Exception("Срок действия ссылки подтверждения истёк");
    }
    
    // Подтверждаем новый email
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
    
    // Если пользователь уже авторизован и это его аккаунт — обновляем email в сессии
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$user['id']) {
        $_SESSION['user_email'] = $newEmail;
    }
    
    // Логируем успешное подтверждение
    logEvent("Email подтверждён по ссылке — ID: {$user['id']}, новый email: $newEmail", LOG_INFO_ENABLED, 'info');
    
    // Устанавливаем flash-сообщение об успехе
    $_SESSION['flash_messages']['success'] = ['Email успешно подтверждён и изменён!'];
    
} catch (Exception $e) {
    // Логируем ошибку и показываем общее сообщение пользователю (без деталей)
    logEvent("Ошибка подтверждения email: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    $_SESSION['flash_messages']['error'] = ['Не удалось подтвердить email. Ссылка недействительна или устарела.'];
}

// ========================================
// ПЕРЕНАПРАВЛЕНИЕ
// ========================================

// Перенаправляем на страницу личных данных
header("Location: /admin/user/personal_data.php");
exit;
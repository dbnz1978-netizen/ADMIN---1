<?php

/**
 * Название файла:      verify_email.php
 * Назначение:          Скрипт для подтверждения email адреса пользователя после регистрации
 *                      Обрабатывает ссылку подтверждения, отправленную на email пользователя
 *                      - Проверяет валидность токена подтверждения
 *                      - Активирует учетную запись пользователя
 *                      - Отправляет приветственное письмо
 *                      - Выводит результат операции пользователю
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors' => false,  // Включение отображения ошибок true/false
    'set_encoding'   => true,   // Включение кодировки UTF-8
    'db_connect'     => true,   // Подключение к базе
    'auth_check'     => true,   // Подключение функций авторизации
    'file_log'       => true,   // Подключение системы логирования
    'display_alerts' => true,   // Подключение отображения сообщений
    'sanitization'   => true,   // Подключение валидации/экранирования
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/functions/init.php';


// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

// Получаем настройки администратора
$adminData = getAdminData($pdo);

if ($adminData === false) {
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ ИНТЕРФЕЙСА
// ========================================

$authTitle           = 'Подтверждение Email';                                      // Заголовок
$adminPanel          = $adminData['AdminPanel'] ?? 'AdminPanel';                   // Название Админ-панели
$adminUserId         = getAdminUserId($pdo);                                       // ID администратора
$logoPaths           = getThemeLogoPaths($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', $adminUserId);
$authLogoLight       = $logoPaths['light'];
$authLogoDark        = $logoPaths['dark'];
$authMetaTitle       = $authTitle . ' - ' . $adminPanel;
$authMetaDescription = $authTitle . ' — подтверждение адреса электронной почты.';
$authFavicon         = !empty($authLogoLight) ? $authLogoLight : 'img/avatar.svg';

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled'] ?? false) === true);  // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки true/false

// ========================================
// ПРОВЕРКА ТОКЕНА ПОДТВЕРЖДЕНИЯ
// ========================================

// Проверяем наличие токена
if (isset($_GET['token'])) {
    $token = trim($_GET['token']);
    
    try {
        // Один запрос: проверяем токен, срок действия, подтверждён ли email
        $stmt = $pdo->prepare("
            SELECT 
                id, email, data, email_verified,
                CASE 
                    WHEN token_expires > NOW() THEN 'valid'
                    ELSE 'expired'
                END AS token_status
            FROM users 
            WHERE verification_token = ?
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $errors[]   = "Неверная или устаревшая ссылка подтверждения.";
            $logMessage = "Попытка подтверждения по недействительному токену: " . substr($token, 0, 8)
                . "... — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
        } elseif ($user['token_status'] === 'expired') {
            $errors[]   = "Срок действия ссылки истек. Запросите новую ссылку для подтверждения email.";
            $logMessage = "Попытка подтверждения по просроченному токену: " . substr($token, 0, 8)
                . "... — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
        } elseif ($user['email_verified']) {
            $successMessages[] = "Ваш email уже был подтверждён ранее.";
        } else {
            // Декодируем JSON и проверяем целостность
            $userData = json_decode($user['data'], true);
            
            if (!is_array($userData)) {
                $userData   = [];
                $logMessage = "Предупреждение: некорректные JSON-данные пользователя (id={$user['id']})";
                logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
            }
            
            // Безопасное извлечение и экранирование имени
            $firstName = escape($userData['first_name'] ?? 'Пользователь');
            
            // Активируем аккаунт
            $update = $pdo->prepare(
                "UPDATE users SET email_verified = 1, verification_token = NULL, token_expires = NULL, updated_at = NOW() "
                . "WHERE id = ?"
            );
            $update->execute([$user['id']]);
            
            // Отправка письма
            require_once __DIR__ . '/functions/mailer.php';
            
            if (!sendWelcomeEmail($user['email'], $firstName, $adminPanel)) {
                $logMessage = "Не удалось отправить приветственное письмо на адрес: " . $user['email'];
                logEvent($logMessage, LOG_INFO_ENABLED, 'info');
            }
            
            $successMessages[] = "Ваш email успешно подтверждён! Теперь вы можете войти в систему.";
        }
    } catch (Exception $e) {
        $errors[]   = "Произошла ошибка при активации аккаунта. Пожалуйста, попробуйте позже.";
        $logMessage = "Ошибка при подтверждении email для токена: " . substr($token, 0, 8)
            . "... — " . $e->getMessage();
        logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    }
} else {
    $logMessage = "Попытка подтверждения email без токена — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    $errors[] = "Отсутствует токен подтверждения.";
}

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= escape($authMetaDescription) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= escape($authMetaTitle) ?></title>
    <!-- Модуль управления светлой/тёмной темой -->
    <script>
        (function() {
            const saved_theme = localStorage.getItem('theme');
            if (saved_theme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <!-- Подключение Bootstrap для стилизации -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Подключение кастомных стилей -->
    <link rel="stylesheet" href="css/main.css">
    <link rel="icon" href="<?= escape($authFavicon) ?>" type="image/x-icon">
    <style>
        body {
            display: grid;
            place-items: center;
            min-height: 100vh;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <a href="authorization.php" class="auth-logo">
                    <?php if (!empty($authLogoLight)): ?>
                        <img class="auth-logo-image auth-logo-light" src="<?= escape($authLogoLight) ?>" alt="<?= escape($adminPanel) ?>">
                        <img class="auth-logo-image auth-logo-dark" src="<?= escape($authLogoDark) ?>" alt="<?= escape($adminPanel) ?>">
                    <?php else: ?>
                        <i class="bi bi-shield-lock"></i>
                    <?php endif; ?>
                    <?= escape($adminPanel) ?>
                </a>
            </div>
            <div class="card-body text-center">
                <div class="mb-4">
                    <h1 class="auth-title">Подтверждение Email</h1>
                </div>
                <div class="mb-3">
                    <?php displayAlerts(
                        $successMessages,  // Массив сообщений об успехе
                        $errors,           // Массив сообщений об ошибках
                        false              // Показывать сообщения как toast-уведомления true/false
                    ); ?>
                </div>

                <?php if (!empty($successMessages)): ?>
                    <a href="authorization.php" class="btn btn-primary btn-lg">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Перейти к входу
                    </a>
                <?php elseif (!empty($errors)): ?>
                    <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                        <?php if (isset($adminData['allow_registration']) && $adminData['allow_registration']): ?>
                            <a href="registration.php" class="btn btn-outline-primary me-md-2">
                                <i class="bi bi-person-plus me-2"></i> Зарегистрироваться
                            </a>
                        <?php endif; ?>
                        <a href="authorization.php" class="btn btn-outline-secondary">
                            <i class="bi bi-box-arrow-in-right me-2"></i> Войти
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Модульный JS admin -->
    <script type="module" src="js/main.js"></script>
</body>
</html>
<?php

/**
 * Название файла:      logout.php
 * Назначение:          Скрипт для безопасного выхода пользователя из системы.
 *                      Обеспечивает полное удаление данных аутентификации как на сервере,
 *                      так и на стороне клиента.
 *                      Функциональность:
 *                      - Удаление токена "запомнить меня" из базы данных
 *                      - Очистка cookies на стороне клиента
 *                      - Уничтожение сессии PHP
 *                      - Логирование действий
 *                      - Перенаправление на страницу входа
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors' => false,   // включение отображения ошибок true/false
    'db_connect'     => true,    // подключение к базе
    'auth_check'     => true,    // подключение функций авторизации
    'file_log'       => true,    // подключение системы логирования
    'csrf_token'     => true,    // генерация CSRF-токена
    'start_session'  => true,    // запуск Session
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/functions/init.php';

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);

if ($adminData === false) {
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    // Продолжаем выход без настроек логирования, чтобы избежать цикла редиректов
    $adminData = [
        'log_info_enabled'  => false,
        'log_error_enabled' => false,
    ];
}

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);  // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки true/false

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ И МЕТОДА ЗАПРОСА
// ========================================

$isLoggedIn = !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

if (!$isLoggedIn) {
    header('Location: authorization.php', true, 302);
    exit;
}

$requestMethod    = $_SERVER['REQUEST_METHOD'] ?? '';
if ($requestMethod === '') {
    $logMessage = 'REQUEST_METHOD отсутствует при попытке выхода из системы';
    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    $requestMethod = 'GET';
}

$requestCsrfToken     = $_POST['csrf_token'] ?? '';
$csrfTokenCandidate   = is_string($requestCsrfToken) ? $requestCsrfToken : '';
$showConfirmation     = $requestMethod !== 'POST';
$errorMessage         = '';
$statusCode           = 200;

// ========================================
// ВАЛИДАЦИЯ CSRF-ТОКЕНА
// ========================================

if ($requestMethod === 'POST' && !validateCsrfToken($csrfTokenCandidate)) {
    $showConfirmation = true;
    $errorMessage     = 'Неверный CSRF-токен. Повторите выход.';
    $statusCode       = 403;
    $logMessage       = 'Ошибка CSRF при попытке выхода из системы';
    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
}

// ========================================
// ПОДТВЕРЖДЕНИЕ ВЫХОДА
// ========================================

if ($showConfirmation) {
    $csrfToken = $_SESSION['csrf_token'] ?? '';
    
    if (!is_string($csrfToken) || $csrfToken === '') {
        $csrfToken            = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $csrfToken;
    }
    
    $csrfTokenSafe       = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
    $confirmationText    = $errorMessage !== '' ? $errorMessage : 'Для выхода подтвердите действие.';
    $confirmationText    = htmlspecialchars($confirmationText, ENT_QUOTES, 'UTF-8');
    
    // Получение данных для оформления страницы
    $adminPanel     = htmlspecialchars($adminData['AdminPanel'] ?? 'AdminPanel', ENT_QUOTES, 'UTF-8');
    $adminUserId    = getAdminUserId($pdo);
    $logoPaths      = getThemeLogoPaths($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', $adminUserId);
    $authLogoLight  = htmlspecialchars($logoPaths['light'], ENT_QUOTES, 'UTF-8');
    $authLogoDark   = htmlspecialchars($logoPaths['dark'], ENT_QUOTES, 'UTF-8');
    $authFavicon    = !empty($authLogoLight) ? $authLogoLight : 'img/avatar.svg';
    
    $cspPolicy = "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net data:; img-src 'self' data:; form-action 'self'; base-uri 'self'";
    
    header("Content-Security-Policy: {$cspPolicy}");
    http_response_code($statusCode);
    
    echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Подтверждение выхода из системы">
    <meta name="robots" content="noindex, nofollow">
    <title>Подтверждение выхода - {$adminPanel}</title>
    
    <!-- Автоматическое применение сохраненной темы -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    
    <!-- Подключение стилей -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="icon" href="{$authFavicon}" type="image/x-icon">
    
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
    <!-- Переключатель темы -->
    <div class="theme-toggle-auth">
        <label class="theme-switch-auth">
            <input type="checkbox" id="themeToggleAuth">
            <span class="theme-slider-auth">
                <i class="bi bi-sun"></i>
                <i class="bi bi-moon"></i>
            </span>
        </label>
    </div>

    <!-- Основной контейнер -->
    <div class="auth-container">
        <div class="auth-card">
            <!-- Заголовок и логотип -->
            <div class="auth-header">
                <a href="index.php" class="auth-logo">
HTML;
    
    if (!empty($authLogoLight)) {
        echo <<<HTML
                    <img class="auth-logo-image auth-logo-light" src="{$authLogoLight}" alt="{$adminPanel}">
                    <img class="auth-logo-image auth-logo-dark" src="{$authLogoDark}" alt="{$adminPanel}">
HTML;
    } else {
        echo <<<HTML
                    <i class="bi bi-shield-lock"></i>
HTML;
    }
    
    echo <<<HTML
                    {$adminPanel}
                </a>
                <h1 class="auth-title">Выход из системы</h1>
                <p class="auth-subtitle">{$confirmationText}</p>    
            </div>

            <!-- Форма подтверждения выхода -->
            <form class="auth-form" method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="{$csrfTokenSafe}">
                
                <!-- Кнопки -->
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="bi bi-box-arrow-right"></i> Выйти
                </button>
                <a href="index.php" class="btn btn-secondary btn-block mt-2">
                    <i class="bi bi-x-circle"></i> Отмена
                </a>
            </form>
        </div>
    </div>

    <!-- Подключение JavaScript библиотек -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script type="module" src="js/main.js"></script>
</body>
</html>
HTML;
    exit;
}

// ========================================
// ИНИЦИАЛИЗАЦИЯ ПЕРЕМЕННЫХ
// ========================================

$userId = $_SESSION['user_id'] ?? null;
$token  = $_COOKIE['remember_token'] ?? null;

// ========================================
// ЛОГИРОВАНИЕ НАЧАЛА ПРОЦЕССА ВЫХОДА
// ========================================

$userInfo = $userId ? "user_id=$userId" : "неавторизованный пользователь";
logEvent("Инициирован выход из системы для $userInfo", LOG_INFO_ENABLED, 'info');

// ========================================
// ОСНОВНАЯ ЛОГИКА ВЫХОДА
// ========================================

try {
    
    // ========================================
    // УДАЛЕНИЕ ТОКЕНА "ЗАПОМНИТЬ МЕНЯ"
    // ========================================
    
    if ($token) {
        $tokenHash = hash('sha256', $token);
        
        // Удаляем запись из user_sessions
        $stmt   = $pdo->prepare("DELETE FROM user_sessions WHERE token = ?");
        $deleted = $stmt->execute([$tokenHash]);

        if ($deleted) {
            $logMessage = "Токен 'запомнить меня' успешно удалён из базы данных для $userInfo";
            logEvent($logMessage, LOG_INFO_ENABLED, 'info');
        } else {
            $logMessage = "Не удалось удалить токен 'запомнить меня' из базы данных для $userInfo";
            logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
        }

        // Delete cookie with conditional secure flag
        $isSecure = isSecureRequest();
        setcookie('remember_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);  // secure + httponly
    }

    // ========================================
    // УНИЧТОЖЕНИЕ СЕССИИ
    // ========================================
    
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

        $logMessage = "Сессия успешно уничтожена для $userInfo";
        logEvent($logMessage, LOG_INFO_ENABLED, 'info');
    } else {
        $logMessage = "Активная сессия отсутствует — нечего уничтожать для $userInfo";
        logEvent($logMessage, LOG_INFO_ENABLED, 'info');
    }

} catch (Exception $e) {
    $logoutErrorMessage = "Ошибка при выходе из системы: " . $e->getMessage();
    logEvent($logoutErrorMessage, LOG_ERROR_ENABLED, 'error');
}

// ========================================
// ФИНАЛЬНОЕ ПЕРЕНАПРАВЛЕНИЕ
// ========================================

$redirectUrl = 'authorization.php';
header("Location: $redirectUrl", true, 302);
exit;
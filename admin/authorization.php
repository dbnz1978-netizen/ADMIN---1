<?php

/**
 * Название файла:      authorization.php
 * Назначение:          Страница авторизации пользователей в административной панели
 *                      Обеспечивает безопасный вход в систему с многоуровневой защитой
 * Автор:               User
 * Версия:              1.0
 * Дата создания:       2026-02-08
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ СКРИПТА
// ========================================

$config = [
    'display_errors'   => false,  // Включение отображения ошибок (true/false)
    'set_encoding'     => true,   // Включение кодировки UTF-8
    'db_connect'       => true,   // Подключение к базе данных
    'auth_check'       => true,   // Подключение функций авторизации
    'file_log'         => true,   // Подключение системы логирования
    'display_alerts'   => true,   // Подключение отображения сообщений
    'sanitization'     => true,   // Подключение валидации/экранирования
    'csrf_token'       => true,   // Генерация и проверка CSRF-токена
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/functions/init.php';


// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Ошибка: администратор не найден / ошибка БД / некорректный JSON
    header("Location: logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ СТРАНИЦЫ АВТОРИЗАЦИИ
// ========================================

$authTitle          = 'Добро пожаловать';  // Заголовок страницы
$adminPanel         = $adminData['AdminPanel'] ?? 'AdminPanel';  // Название админ-панели
$adminUserId        = getAdminUserId($pdo);  // ID администратора
$logoPaths          = getThemeLogoPaths($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', $adminUserId);
$authLogoLight      = $logoPaths['light'];
$authLogoDark       = $logoPaths['dark'];
$authMetaTitle      = $authTitle . ' - ' . $adminPanel;
$authMetaDescription = $authTitle . ' — страница входа в административную панель.';
$authFavicon        = !empty($authLogoLight) ? $authLogoLight : 'img/avatar.svg';

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);  // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки true/false

// ========================================
// ПРОВЕРКА ТЕКУЩЕЙ АВТОРИЗАЦИИ
// ========================================

$userRedirect = redirectIfAuth($pdo);
if ($userRedirect) {
    $redirectTo = 'user/index.php';
    $logMessage = "Авторизованный пользователь перенаправлен на: $redirectTo — ID: {$userRedirect['id']} — IP: "
        . "{$_SERVER['REMOTE_ADDR']}";
    logEvent($logMessage, LOG_INFO_ENABLED, 'info');
    header("Location: $redirectTo");
    exit;
}

// ========================================
// ИНИЦИАЛИЗАЦИЯ СЧЕТЧИКОВ ПОПЫТОК ВХОДА
// ========================================

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts']     = 0;
    $_SESSION['login_attempt_time'] = time();
}
if (!isset($_SESSION['last_request_time'])) {
    $_SESSION['last_request_time'] = 0;
}

// ========================================
// ОГРАНИЧЕНИЕ ЧАСТОТЫ ЗАПРОСОВ (ЗАЩИТА ОТ СПАМА)
// ========================================

$currentTime      = time();
$lastRequestTime  = $_SESSION['last_request_time'];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $lastRequestTime > 0
    && ($currentTime - $lastRequestTime) < 2
) {
    $errors[]   = "Слишком частые запросы. Пожалуйста, подождите.";
    $logMessage = "Превышена частота запросов — IP: {$_SERVER['REMOTE_ADDR']} - URI: "
        . strtok($_SERVER['REQUEST_URI'], '?');
    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
}
$_SESSION['last_request_time'] = $currentTime;

// ========================================
// ОПРЕДЕЛЕНИЕ ПОКАЗА КАПЧИ
// ========================================

$showCaptcha = $_SESSION['login_attempts'] >= 3;

// Сброс попыток через 30 минут (1800 секунд)
if (isset($_SESSION['login_attempt_time']) && ($currentTime - $_SESSION['login_attempt_time']) > 1800) {
    $_SESSION['login_attempts']     = 0;
    $_SESSION['login_attempt_time'] = $currentTime;
    $showCaptcha                    = false;
}

// ========================================
// ГЕНЕРАЦИЯ ТОКЕНА КАПЧИ
// ========================================

$captchaToken = null;
if ($showCaptcha) {
    $captchaTokenTtl = 600;  // Время жизни токена: 10 минут
    $tokenCreated    = $_SESSION['captcha_token_created'] ?? 0;
    if (empty($_SESSION['captcha_token'])
        || empty($tokenCreated)
        || (time() - $tokenCreated) > $captchaTokenTtl
        || (!empty($_SESSION['captcha_token_used']) && $_SERVER['REQUEST_METHOD'] !== 'POST')
    ) {
        $_SESSION['captcha_token']         = bin2hex(random_bytes(32));
        $_SESSION['captcha_token_created'] = time();
        $_SESSION['captcha_token_used']    = false;
    }
    $captchaToken = $_SESSION['captcha_token'];
}

// ========================================
// АВТОМАТИЧЕСКИЙ ВХОД ЧЕРЕЗ "ЗАПОМНИТЬ МЕНЯ"
// ========================================

if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token']) && empty($errors)) {
    try {
        $rememberToken     = $_COOKIE['remember_token'];
        $rememberTokenHash = hash('sha256', $rememberToken);
        $tableExists       = $pdo->query("SHOW TABLES LIKE 'user_sessions'")->rowCount() > 0;
        
        if ($tableExists) {
            $stmt = $pdo->prepare("SELECT user_id, token FROM user_sessions WHERE token = ? AND expires_at > NOW()");
            $stmt->execute([$rememberTokenHash]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session && hash_equals((string) $session['token'], $rememberTokenHash)) {
                $stmt = $pdo->prepare(
                    "SELECT id, email, data FROM users WHERE id = ? AND email_verified = 1 AND status = 1"
                );
                $stmt->execute([$session['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    $userData = json_decode($user['data'], true);
                    if (!is_array($userData)) {
                        $userData  = [];
                        $logMessage = "Некорректные JSON-данные пользователя (id={$user['id']}) "
                            . "при автоматическом входе";
                        logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                    }
                    $firstName = escape($userData['first_name'] ?? 'Пользователь');
                    
                    session_regenerate_id(true);
                    $_SESSION['created'] = time();
                    
                    $_SESSION['user_id']      = $user['id'];
                    $_SESSION['user_email']   = $user['email'];
                    $_SESSION['user_name']    = $firstName;
                    $_SESSION['logged_in']    = true;
                    $_SESSION['login_time']   = time();
                    
                    header("Location: user/index.php");
                    exit;
                }
            } else {
                // Удаление недействительного токена
                $isSecure = isSecureRequest();
                setcookie('remember_token', '', [
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'secure'   => $isSecure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
        }
    } catch (Exception $e) {
        $errors[]    = "Ошибка при автоматическом входе. Пожалуйста, войдите вручную.";
        $errorType   = get_class($e);
        $requestId   = $_SERVER['HTTP_X_REQUEST_ID'] ?? ($_SERVER['UNIQUE_ID'] ?? '');
        $requestContext = !empty($requestId) ? " — ID запроса: {$requestId}" : '';
        $logMessage  = "Ошибка автоматического входа по токену — тип: {$errorType}{$requestContext}.";
        logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    }
}

// ========================================
// ОБРАБОТКА POST-ЗАПРОСА АВТОРИЗАЦИИ
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    
    // ========================================
    // ПРОВЕРКА CSRF-ТОКЕНА
    // ========================================
    
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Недопустимый запрос. Повторите попытку.";
        logEvent(
            "Попытка CSRF-атаки при авторизации — IP: {$_SERVER['REMOTE_ADDR']}",
            LOG_ERROR_ENABLED,
            'error'
        );
    } else {
        
        // ========================================
        // ВАЛИДАЦИЯ ВВЕДЕННЫХ ДАННЫХ
        // ========================================
        
        // Валидация email-адреса
        $resultEmail = validateEmail(trim($_POST['email'] ?? ''));
        if ($resultEmail['valid']) {
            $email = $resultEmail['email'];
        } else {
            $errors[] = $resultEmail['error'];
            $email    = false;
        }

        // Пароль
        $password = trim($_POST['password'] ?? '');

        // Флажок "Запомнить меня"
        $rememberMe = isset($_POST['rememberMe']);

        // ========================================
        // ПРОВЕРКА КАПЧИ (ЧЕРЕЗ СЕССИЮ)
        // ========================================
        
        if ($showCaptcha) {
            $captchaTokenPost    = $_POST['captcha_token'] ?? '';
            $sessionCaptchaToken = $_SESSION['captcha_token'] ?? '';
            $captchaPassed       = isset($_SESSION['captcha_passed']) && time() - $_SESSION['captcha_passed'] <= 300;
            $tokenUsed           = !empty($_SESSION['captcha_token_used']);
            $tokenValid          = $captchaPassed
                && $tokenUsed
                && is_string($captchaTokenPost)
                && $captchaTokenPost !== ''
                && $sessionCaptchaToken
                && hash_equals($sessionCaptchaToken, $captchaTokenPost);

            if (!$tokenValid) {
                $errors[] = "Пожалуйста, подтвердите, что вы не робот!";
            }
        }

        // ========================================
        // БАЗОВАЯ ВАЛИДАЦИЯ И ПРОВЕРКА ПОЛЬЗОВАТЕЛЯ
        // ========================================
        
        if (empty($errors)) {
            if (empty($email) || empty($password)) {
                $errors[] = "Заполните все поля!";
            } else {
                try {
                    // Поиск пользователя в базе данных
                    $stmt = $pdo->prepare(
                        "SELECT id, password, email_verified, data FROM users WHERE email = ? AND status = 1 LIMIT 1"
                    );
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                    // Проверка пароля (с защитой от тайминг-атак)
                    $passwordValid = false;
                    if ($user && isset($user['password'])) {
                        $passwordValid = password_verify($password, $user['password']);
                    } else {
                        // Защита от перебора: имитация проверки для несуществующего пользователя
                        password_verify('dummy_password', '$2y$10$dummyhashdummyhashdummyhashdummyha');
                    }
                
                    // ========================================
                    // ОБРАБОТКА УСПЕШНОЙ АВТОРИЗАЦИИ
                    // ========================================
                    
                    if ($user && $passwordValid) {
                        if (!$user['email_verified']) {
                            // Email не подтвержден
                            $errors[] = "Email не подтвержден. Проверьте вашу почту для подтверждения регистрации.";
                            $_SESSION['login_attempts']++;
                            $logMessage = "Попытка входа с неподтверждённым email: $email — IP: "
                                . "{$_SERVER['REMOTE_ADDR']}";
                            logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                        } else {
                            // Успешная авторизация
                            $userData = json_decode($user['data'], true);
                            if (!is_array($userData)) {
                                $userData   = [];
                                $logMessage = "Некорректные JSON-данные при входе (id={$user['id']})";
                                logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                            }
                            $firstName = escape($userData['first_name'] ?? 'Пользователь');
                        
                            session_regenerate_id(true);
                            $_SESSION['created'] = time();

                            // Сброс счетчиков и очистка токенов
                            $_SESSION['login_attempts']     = 0;
                            $_SESSION['login_attempt_time'] = time();
                            unset(
                                $_SESSION['captcha_passed'],
                                $_SESSION['captcha_token'],
                                $_SESSION['captcha_token_created'],
                                $_SESSION['captcha_token_used'],
                            );
                            $_SESSION['user_id']    = $user['id'];
                            $_SESSION['user_email'] = $email;
                            $_SESSION['user_name']  = $firstName;
                            $_SESSION['logged_in']  = true;
                            $_SESSION['login_time'] = time();
                        
                            // ========================================
                            // СОЗДАНИЕ ТОКЕНА "ЗАПОМНИТЬ МЕНЯ"
                            // ========================================
                            
                            if ($rememberMe) {
                                $token     = bin2hex(random_bytes(32));
                                $tokenHash = hash('sha256', $token);
                                $expires   = date('Y-m-d H:i:s', strtotime('+30 days'));
                            
                                $tableExists = $pdo->query("SHOW TABLES LIKE 'user_sessions'")->rowCount() > 0;
                            
                                if ($tableExists) {
                                    // Удаление старых токенов пользователя
                                    $deleteStmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                                    $deleteStmt->execute([$user['id']]);
                                
                                    // Создание нового токена
                                    $stmt = $pdo->prepare(
                                        "INSERT INTO user_sessions (user_id, token, expires_at) VALUES (?, ?, ?)"
                                    );
                                    if ($stmt->execute([$user['id'], $tokenHash, $expires])) {
                                        // Установка безопасного cookie
                                        $isSecure = isSecureRequest();
                                        setcookie('remember_token', $token, [
                                            'expires'  => time() + (30 * 24 * 60 * 60),
                                            'path'     => '/',
                                            'secure'   => $isSecure,
                                            'httponly' => true,
                                            'samesite' => 'Lax'
                                        ]);
                                    } else {
                                        $logMessage = "Ошибка создания токена 'Запомнить меня' для пользователя "
                                            . "ID={$user['id']}";
                                        logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                                    }
                                } else {
                                    $logMessage = "Таблица user_sessions не найдена — функция 'Запомнить меня' "
                                        . "недоступна";
                                    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                                }
                            }

                            $logMessage = "Успешная авторизация пользователя с email: $email — IP: "
                                . "{$_SERVER['REMOTE_ADDR']}";
                            logEvent($logMessage, LOG_INFO_ENABLED, 'info');
                            header("Location: user/index.php");
                            exit;
                        }
                    } else {
                        // Неудачная попытка входа
                        $errors[] = "Неверный email или пароль!";
                        $_SESSION['login_attempts']++;
                        $_SESSION['login_attempt_time'] = time();
                        $logMessage = "Неудачная попытка входа для email: $email — IP: {$_SERVER['REMOTE_ADDR']} "
                            . "— Попытка №{$_SESSION['login_attempts']}";
                        logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                    }
                } catch (Exception $e) {
                    $errors[]   = "Ошибка при авторизации. Пожалуйста, попробуйте позже.";
                    $logMessage = "Ошибка базы данных при авторизации (email: $email) — " . $e->getMessage();
                    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                }
            }
        }
    }
}

// ========================================
// ОБНОВЛЕНИЕ ТОКЕНА КАПЧИ ПОСЛЕ ПОПЫТКИ ВХОДА
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showCaptcha) {
    unset($_SESSION['captcha_passed']);
    $_SESSION['captcha_token_used']    = false;
    $_SESSION['captcha_token']         = bin2hex(random_bytes(32));
    $_SESSION['captcha_token_created'] = time();
    $captchaToken                      = $_SESSION['captcha_token'];
}

?>

<!-- ========================================
     HTML-РАЗМЕТКА СТРАНИЦЫ АВТОРИЗАЦИИ
     ======================================== -->
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= escape($authMetaDescription) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= escape($authMetaTitle) ?></title>
    
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

    <!-- Основной контейнер авторизации -->
    <div class="auth-container">
        <div class="auth-card">
            <!-- Заголовок и логотип -->
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
                <h1 class="auth-title">Добро пожаловать</h1>
                <p class="auth-subtitle">Войдите в свою учетную запись</p>    
            </div>

            <!-- Отображение сообщений об ошибках и успехе -->
            <?php displayAlerts(
                $successMessages,  // Массив сообщений об успехе
                $errors,           // Массив сообщений об ошибках
                false,             // Показывать сообщения как обычные (не toast)
            );
            ?>

            <!-- Форма авторизации -->
            <form class="auth-form" method="POST" action="">
                <!-- CSRF-токен для защиты от атак -->
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                <!-- Поле ввода email -->
                <div class="form-group">
                    <label for="email" class="form-label">Email адрес</label>
                    <input type="email" class="form-control" id="email" name="email" placeholder="your@email.com" required 
                           value="<?= escape($_POST['email'] ?? '') ?>">
                </div>

                <!-- Поле ввода пароля -->
                <div class="form-group">
                    <label for="password" class="form-label">Пароль</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Ваш пароль" required minlength="6">
                        <button type="button" class="password-toggle">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Капча (показывается только при необходимости) -->
                <?php if ($showCaptcha): ?>
                <div class="captcha-container">
                    <div class="captcha-text">Перетащите ползунок вправо для подтверждения</div>
                    <div class="captcha-slider" id="captchaSlider">
                        <div class="captcha-track">
                            <div class="captcha-progress" id="captchaProgress"></div>
                            <div class="captcha-progress-extended" id="captchaProgressExtended"></div>
                        </div>
                        <div class="captcha-handle" id="captchaHandle">
                            <i class="bi bi-arrow-right"></i>
                        </div>
                    </div>
                    <div class="captcha-instruction">Перетащите кружок со стрелкой до конца</div>
                    <input type="hidden" name="captcha_verified" id="captchaVerified" value="false">
                    <input type="hidden" name="captcha_token" id="captchaToken" value="<?= escape($captchaToken ?? '') ?>">
                </div>
                <?php endif; ?>

                <!-- Опции формы -->
                <div class="auth-options">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rememberMe" name="rememberMe" <?= isset($_POST['rememberMe']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="rememberMe">
                            Запомнить меня
                        </label>
                    </div>
                    <a href="forgot.php" class="forgot-password">Забыли пароль?</a>
                </div>

                <!-- Кнопка входа -->
                <button type="submit" class="btn btn-primary btn-block" id="submitBtn">
                    <i class="bi bi-box-arrow-in-right"></i> Войти
                </button>
            </form>

            <!-- Ссылка на регистрацию (если разрешена) -->
            <?php if (isset($adminData['allow_registration']) && $adminData['allow_registration']): ?>
            <div class="auth-footer">
                <p class="auth-footer-text">
                    Еще нет аккаунта? 
                    <a href="registration.php" class="auth-footer-link">Зарегистрироваться</a>
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Подключение JavaScript библиотек -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script type="module" src="js/main.js"></script>
</body>
</html>
<?php
/**
 * Файл: /admin/authorization.php
 * 
 * Страница авторизации пользователей в административной панели.
 * Обеспечивает безопасный вход в систему с многоуровневой защитой.
 * 
 * Функциональность:
 * - Авторизация по email и паролю
 * - Защита от брутфорс-атак с помощью счетчика попыток
 * - Автоматический вход через "Запомнить меня"
 * - Капча после нескольких неудачных попыток (безопасная, через сессию)
 * - CSRF-защита (добавлено)
 * - Проверка подтверждения email
 * - Безопасное хеширование и проверка паролей
 * 
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/** 
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
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/functions/auth_check.php';                  // Авторизация и получения данных пользователей
require_once __DIR__ . '/functions/file_log.php';                    // Система логирования
require_once __DIR__ . '/functions/display_alerts.php';              // Отображение сообщений
require_once __DIR__ . '/functions/sanitization.php';                // Валидация экранирование 

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

// Название Админ-панели
$AdminPanel = $adminData['AdminPanel'] ?? 'AdminPanel';

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

// Если пользователь уже авторизован, перенаправляем в админку
$userredirect = redirectIfAuth();
if ($userredirect) {
    $redirectTo = 'user/index.php';
    logEvent("Авторизованный пользователь перенаправлен на: $redirectTo — ID: {$userredirect['id']} — IP: {$_SERVER['REMOTE_ADDR']}", LOG_INFO_ENABLED, 'info');
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: $redirectTo");
    exit;
}

// Инициализируем переменные для сообщений
$errors = [];
$successMessages = [];

// === ГЕНЕРАЦИЯ CSRF-ТОКЕНА (если ещё не создан) ===
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * УЛУЧШЕННАЯ СИСТЕМА ЗАЩИТЫ ОТ БРУТФОРС-АТАК
 */
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_attempt_time'] = time();
}
if (!isset($_SESSION['last_request_time'])) {
    $_SESSION['last_request_time'] = 0;
}

/**
 * ОГРАНИЧЕНИЕ ЧАСТОТЫ ЗАПРОСОВ (только для POST)
 */
$current_time = time();
$last_request_time = $_SESSION['last_request_time'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    $last_request_time > 0 && 
    ($current_time - $last_request_time) < 2) {
    $errors[] = "Слишком частые запросы. Пожалуйста, подождите.";
    logEvent("Превышена частота запросов — IP: {$_SERVER['REMOTE_ADDR']} - URI: " . strtok($_SERVER['REQUEST_URI'], '?'), LOG_ERROR_ENABLED, 'error');
}
$_SESSION['last_request_time'] = $current_time;

/**
 * ОПРЕДЕЛЕНИЕ: показывать капчу или нет?
 */
$showCaptcha = $_SESSION['login_attempts'] >= 3;

// Сброс попыток через 30 минут
if (isset($_SESSION['login_attempt_time']) && ($current_time - $_SESSION['login_attempt_time']) > 1800) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_attempt_time'] = $current_time;
    $showCaptcha = false;
}

/**
 * АВТОМАТИЧЕСКИЙ ВХОД ЧЕРЕЗ "ЗАПОМНИТЬ МЕНЯ"
 */
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token']) && empty($errors)) {
    try {
        $tableExists = $pdo->query("SHOW TABLES LIKE 'user_sessions'")->rowCount() > 0;
        
        if ($tableExists) {
            $stmt = $pdo->prepare("SELECT user_id FROM user_sessions WHERE token = ? AND expires_at > NOW()");
            $stmt->execute([$_COOKIE['remember_token']]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                $stmt = $pdo->prepare("SELECT id, email, data FROM users WHERE id = ? AND email_verified = 1 AND status = 1");
                $stmt->execute([$session['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    $userData = json_decode($user['data'], true);
                    if (!is_array($userData)) {
                        $userData = [];
                        logEvent("Некорректные JSON-данные пользователя (id={$user['id']}) при автоматическом входе", LOG_ERROR_ENABLED, 'error');
                    }
                    $firstName = escape($userData['first_name'] ?? 'Пользователь');
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $firstName;
                    $_SESSION['logged_in'] = true;
                    $_SESSION['login_time'] = time();
                    
                    // Закрываем соединение при завершении скрипта
                    register_shutdown_function(function() {
                        if (isset($pdo)) {
                            $pdo = null; 
                        }
                    });
                    header("Location: user/index.php");
                    exit;
                }
            } else {
                setcookie('remember_token', '', time() - 3600, "/", "", true, true);
            }
        }
    } catch (Exception $e) {
        $errors[] = "Ошибка при автоматическом входе. Пожалуйста, войдите вручную.";
        logEvent("Ошибка автоматического входа по токену: " . substr($_COOKIE['remember_token'] ?? '', 0, 8) . "... — " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    }
}

/**
 * ОБРАБОТКА POST-ЗАПРОСА НА АВТОРИЗАЦИЮ
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    // === ПРОВЕРКА CSRF-ТОКЕНА ===
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Недопустимый запрос. Повторите попытку.";
        logEvent("Попытка CSRF-атаки при авторизации — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
    } else {

        // Валидация email-адреса
        $result_email = validateEmail(trim($_POST['email'] ?? ''));
        if ($result_email['valid']) {
            $email =  $result_email['email'];
        } else {
            $errors[] = $result_email['error'];
            $email = false;
        }

        // Пароль
        $password = trim($_POST['password'] ?? '');

        // Запомнить меня
        $rememberMe = isset($_POST['rememberMe']);

        /**
         * ПРОВЕРКА КАПЧИ — через сессию
         */
        if ($showCaptcha) {
            if (!isset($_SESSION['captcha_passed']) || time() - $_SESSION['captcha_passed'] > 300) {
                $errors[] = "Пожалуйста, подтвердите, что вы не робот!";
            }
        }

        /**
         * БАЗОВАЯ ВАЛИДАЦИЯ
         */
        if (empty($errors)) {
            if (empty($email) || empty($password)) {
                $errors[] = "Заполните все поля!";
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT id, password, email_verified, data FROM users WHERE email = ? AND status = 1 LIMIT 1");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                    $passwordValid = false;
                    if ($user && isset($user['password'])) {
                        $passwordValid = password_verify($password, $user['password']);
                    } else {
                        password_verify('dummy_password', '$2y$10$dummyhashdummyhashdummyhashdummyha');
                    }
                
                    if ($user && $passwordValid) {
                        if (!$user['email_verified']) {
                            $errors[] = "Email не подтвержден. Проверьте вашу почту для подтверждения регистрации.";
                            $_SESSION['login_attempts']++;
                            logEvent("Попытка входа с неподтверждённым email: $email — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
                        } else {
                            $userData = json_decode($user['data'], true);
                            if (!is_array($userData)) {
                                $userData = [];
                                logEvent("Некорректные JSON-данные при входе (id={$user['id']})", LOG_ERROR_ENABLED, 'error');
                            }
                            $firstName = escape($userData['first_name'] ?? 'Пользователь');
                        
                            // Сброс счётчиков + очистка токенов
                            $_SESSION['login_attempts'] = 0;
                            $_SESSION['login_attempt_time'] = time();
                            unset($_SESSION['captcha_passed']);
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['user_email'] = $email;
                            $_SESSION['user_name'] = $firstName;
                            $_SESSION['logged_in'] = true;
                            $_SESSION['login_time'] = time();
                        
                            /**
                             * "ЗАПОМНИТЬ МЕНЯ"
                             */
                            if ($rememberMe) {
                                $token = bin2hex(random_bytes(32));
                                $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                            
                                $tableExists = $pdo->query("SHOW TABLES LIKE 'user_sessions'")->rowCount() > 0;
                            
                                if ($tableExists) {
                                    $deleteStmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                                    $deleteStmt->execute([$user['id']]);
                                
                                    $stmt = $pdo->prepare("INSERT INTO user_sessions (user_id, token, expires_at) VALUES (?, ?, ?)");
                                    if ($stmt->execute([$user['id'], $token, $expires])) {
                                        setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), "/", "", true, true);
                                    } else {
                                        logEvent("Ошибка создания токена 'Запомнить меня' для пользователя ID={$user['id']}", LOG_ERROR_ENABLED, 'error');
                                    }
                                } else {
                                    logEvent("Таблица user_sessions не найдена — функция 'Запомнить меня' недоступна", LOG_ERROR_ENABLED, 'error');
                                }
                            }
                        
                            logEvent("Успешная авторизация пользователя с email: $email — IP: {$_SERVER['REMOTE_ADDR']}", LOG_INFO_ENABLED, 'info');
                            // Закрываем соединение при завершении скрипта
                            register_shutdown_function(function() {
                                if (isset($pdo)) {
                                    $pdo = null; 
                                }
                            });
                            header("Location: user/index.php");
                            exit;
                        }
                    } else {
                        $errors[] = "Неверный email или пароль!";
                        $_SESSION['login_attempts']++;
                        $_SESSION['login_attempt_time'] = time();
                        logEvent("Неудачная попытка входа для email: $email — IP: {$_SERVER['REMOTE_ADDR']} — Попытка №{$_SESSION['login_attempts']}", LOG_ERROR_ENABLED, 'error');
                    }
                } catch (Exception $e) {
                    $errors[] = "Ошибка при авторизации. Пожалуйста, попробуйте позже.";
                    logEvent("Ошибка базы данных при авторизации (email: $email) — " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
                }
            }
        }
    }
}

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Авторизация - <?= escape($AdminPanel) ?></title>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Подключение кастомных стилей -->
    <link rel="stylesheet" href="css/main.css">
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
    <div class="theme-toggle-auth">
        <label class="theme-switch-auth">
            <input type="checkbox" id="themeToggleAuth">
            <span class="theme-slider-auth">
                <i class="bi bi-sun"></i>
                <i class="bi bi-moon"></i>
            </span>
        </label>
    </div>

    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <a href="authorization.php" class="auth-logo">
                    <i class="bi bi-robot"></i>
                    <?= escape($AdminPanel) ?>
                </a>
                <h1 class="auth-title">Добро пожаловать</h1>
                <p class="auth-subtitle">Войдите в свою учетную запись</p>    
            </div>

            <!-- Отображение сообщений -->
            <?php displayAlerts($successMessages, $errors); ?>

            <form class="auth-form" method="POST" action="">
                <!-- CSRF-токен -->
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                <div class="form-group">
                    <label for="email" class="form-label">Email адрес</label>
                    <input type="email" class="form-control" id="email" name="email" placeholder="your@email.com" required 
                           value="<?= escape($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Пароль</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Ваш пароль" required minlength="6">
                        <button type="button" class="password-toggle">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Капча — показывается только при необходимости -->
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
                </div>
                <?php endif; ?>

                <div class="auth-options">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rememberMe" name="rememberMe" <?= isset($_POST['rememberMe']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="rememberMe">
                            Запомнить меня
                        </label>
                    </div>
                    <a href="forgot.php" class="forgot-password">Забыли пароль?</a>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="submitBtn">
                    <i class="bi bi-box-arrow-in-right"></i> Войти
                </button>
            </form>

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

    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Модульный JS admin -->
    <script type="module" src="js/main.js"></script>
</body>
</html>
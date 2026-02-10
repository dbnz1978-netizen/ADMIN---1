<?php

/**
 * Название файла:      forgot.php
 * Назначение:          Страница восстановления пароля для административной панели.
 *                      Безопасная реализация с защитой от перебора, утечек и атак.
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
    'set_encoding'   => true,    // включение кодировки UTF-8
    'db_connect'     => true,    // подключение к базе
    'auth_check'     => true,    // подключение функций авторизации
    'file_log'       => true,    // подключение системы логирования
    'display_alerts' => true,    // подключение отображения сообщений
    'mailer'         => true,    // подключение отправка email уведомлений
    'sanitization'   => true,    // подключение валидации/экранирования
    'csrf_token'     => true,    // генерация CSRF-токена
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/functions/init.php';


// Получаем настройки администратора
$adminData = getAdminData($pdo);

if ($adminData === false) {
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ
// ========================================

$authTitle         = 'Восстановление пароля';                              // Заголовок
$adminPanel        = $adminData['AdminPanel'] ?? 'AdminPanel';             // Название Админ-панели
$adminUserId       = getAdminUserId($pdo);                                 // Логотип админ-панели (для светлой/тёмной темы)
$logoPaths         = getThemeLogoPaths($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', $adminUserId);
$authLogoLight     = $logoPaths['light'];
$authLogoDark      = $logoPaths['dark'];
$authMetaTitle     = $authTitle . ' - ' . $adminPanel;
$authMetaDescription = $authTitle . ' — запрос ссылки на сброс пароля через email.';
$authFavicon       = !empty($authLogoLight) ? $authLogoLight : 'img/avatar.svg';

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);  // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки true/false

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ
// ========================================

// Если пользователь уже авторизован, перенаправляем в админку
$userRedirect = redirectIfAuth($pdo);

if ($userRedirect) {
    $redirectTo  = 'user/index.php';
    $logMessage  = "Авторизованный пользователь перенаправлен на: $redirectTo — ID: {$userRedirect['id']} — IP: "
        . "{$_SERVER['REMOTE_ADDR']}";
    logEvent($logMessage, LOG_INFO_ENABLED, 'info');
    header("Location: $redirectTo");
    exit;
}

// ========================================
// ИНИЦИАЛИЗАЦИЯ КАПЧИ
// ========================================

$captchaTokenTtl = 600;
$tokenCreated    = $_SESSION['captcha_token_created'] ?? 0;

if (
    empty($_SESSION['captcha_token']) ||
    empty($tokenCreated) ||
    (time() - $tokenCreated) > $captchaTokenTtl ||
    (!empty($_SESSION['captcha_token_used']) && $_SERVER['REQUEST_METHOD'] !== 'POST')
) {
    $_SESSION['captcha_token']       = bin2hex(random_bytes(32));
    $_SESSION['captcha_token_created'] = time();
    $_SESSION['captcha_token_used']  = false;
}

$captchaToken = $_SESSION['captcha_token'];

// ========================================
// ОБРАБОТКА POST-ЗАПРОСА
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ========================================
    // ПРОВЕРКА CSRF-ТОКЕНА
    // ========================================
    
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Недопустимый запрос. Повторите попытку.";
        logEvent(
            "Попытка CSRF-атаки при восстановлении пароля — IP: {$_SERVER['REMOTE_ADDR']}",
            LOG_ERROR_ENABLED,
            'error'
        );
    } else {
        
        // ========================================
        // ПРОВЕРКА КАПЧИ
        // ========================================
        
        $captchaTokenPost   = $_POST['captcha_token'] ?? '';
        $sessionCaptchaToken = $_SESSION['captcha_token'] ?? '';
        $captchaPassed      = isset($_SESSION['captcha_passed']) && time() - $_SESSION['captcha_passed'] <= 300;
        $tokenUsed          = !empty($_SESSION['captcha_token_used']);
        $tokenValid         = $captchaPassed
            && $tokenUsed
            && is_string($captchaTokenPost)
            && $captchaTokenPost !== ''
            && $sessionCaptchaToken
            && hash_equals($sessionCaptchaToken, $captchaTokenPost);

        if (!$tokenValid) {
            $errors[] = "Пожалуйста, подтвердите, что вы не робот!";
            logEvent(
                "Попытка сброса пароля без подтверждения капчи — IP: {$_SERVER['REMOTE_ADDR']}",
                LOG_ERROR_ENABLED,
                'error'
            );
        } else {
            
            // ========================================
            // ВАЛИДАЦИЯ EMAIL
            // ========================================
            
            $resultEmail = validateEmail(trim($_POST['email'] ?? ''));
            
            if ($resultEmail['valid']) {
                $email = $resultEmail['email'];
            } else {
                $errors[] = $resultEmail['error'];
                $email    = false;
            }

            // Если email не прошёл валидацию — не продолжаем
            if ($email === false) {
                // ошибки уже добавлены, выходим из цепочки
            } else {
                
                // ========================================
                // ОСНОВНАЯ ЛОГИКА ВОССТАНОВЛЕНИЯ ПАРОЛЯ
                // ========================================
                
                try {
                    
                    // ========================================
                    // ПРОВЕРКА СУЩЕСТВОВАНИЯ ПОЛЬЗОВАТЕЛЯ
                    // ========================================
                    
                    $stmt = $pdo->prepare("SELECT id, data, email_verified, status FROM users WHERE email = ? LIMIT 1");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Для безопасности: одинаковое поведение как при существовании, так и при отсутствии пользователя
                    if ($user) {
                        
                        // ========================================
                        // ПРОВЕРКА СТАТУСА АККАУНТА
                        // ========================================
                        
                        if ($user['status'] !== 1) {
                            $logMessage = "Попытка сброса пароля для неактивного пользователя: $email — IP: "
                                . "{$_SERVER['REMOTE_ADDR']}";
                            logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                        } elseif (!$user['email_verified']) {
                            $logMessage = "Попытка сброса пароля для неподтверждённого email: $email — IP: "
                                . "{$_SERVER['REMOTE_ADDR']}";
                            logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                        } else {
                            
                            // ========================================
                            // ГЕНЕРАЦИЯ ТОКЕНА И ОТПРАВКА EMAIL
                            // ========================================
                            
                            // Декодируем JSON данные пользователя
                            $userData = json_decode($user['data'], true);
                            
                            if (!is_array($userData)) {
                                $userData = [];
                                $logMessage = "Некорректные JSON-данные при восстановлении пароля (id={$user['id']})";
                                logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                            }
                            
                            $firstName = $userData['first_name'] ?? 'Пользователь';
                            
                            // ГЕНЕРАЦИЯ ТОКЕНА ДЛЯ СБРОСА ПАРОЛЯ
                            $token  = bin2hex(random_bytes(32));
                            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                            
                            // СОХРАНЕНИЕ ТОКЕНА В БАЗЕ ДАННЫХ
                            $update = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expire = ? WHERE id = ?");
                            $update->execute([$token, $expires, $user['id']]);
                            
                            // ОТПРАВКА EMAIL ЧЕРЕЗ ФУНКЦИЮ
                            if (sendPasswordResetEmail($email, $firstName, $token, $adminPanel)) {
                                $logMessage = "Ссылка для восстановления пароля успешно отправлена на: $email — IP: "
                                    . "{$_SERVER['REMOTE_ADDR']}";
                                logEvent($logMessage, LOG_INFO_ENABLED, 'info');
                            } else {
                                $logMessage = "Ошибка отправки email для сброса пароля на адрес: $email — IP: "
                                    . "{$_SERVER['REMOTE_ADDR']}";
                                logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                            }
                        }
                    } else {
                        // Пользователь не найден — логируем (низкий приоритет)
                        $logMessage = "Попытка сброса пароля для несуществующего email: $email — IP: "
                            . "{$_SERVER['REMOTE_ADDR']}";
                        logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                    }

                    // Сброс капчи
                    unset($_SESSION['captcha_passed']);

                    // ЕДИНСТВЕННЫЙ ОТВЕТ — всегда успешный (защита от перебора)
                    $successMessages[] = "Если пользователь с таким email существует, на него будет отправлена ссылка "
                        . "для восстановления пароля.";
                } catch (Exception $e) {
                    $errors[] = "Ошибка при обработке запроса. Пожалуйста, попробуйте позже.";
                    $logMessage = "Исключение при восстановлении пароля для email: $email — " . $e->getMessage();
                    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                }
            }
        }
    }
}

// ========================================
// ОБНОВЛЕНИЕ КАПЧИ ПОСЛЕ POST-ЗАПРОСА
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    unset($_SESSION['captcha_passed']);
    $_SESSION['captcha_token_used']  = false;
    $_SESSION['captcha_token']       = bin2hex(random_bytes(32));
    $_SESSION['captcha_token_created'] = time();
    $captchaToken                    = $_SESSION['captcha_token'];
}

// Закрываем соединение при завершении скрипта

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= escape($authMetaDescription) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= escape($authMetaTitle) ?></title>
    
    <!-- ========================================
         МОДУЛЬ УПРАВЛЕНИЯ СВЕТЛОЙ/ТЁМНОЙ ТЕМОЙ
         ======================================== -->
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
    <!-- ========================================
         ПЕРЕКЛЮЧАТЕЛЬ ТЕМЫ
         ======================================== -->
    <div class="theme-toggle-auth">
        <label class="theme-switch-auth">
            <input type="checkbox" id="themeToggleAuth">
            <span class="theme-slider-auth">
                <i class="bi bi-sun"></i>
                <i class="bi bi-moon"></i>
            </span>
        </label>
    </div>

    <!-- ========================================
         ОСНОВНОЙ КОНТЕЙНЕР
         ======================================== -->
    <div class="auth-container">
        <div class="auth-card">
            
            <!-- ========================================
                 ЗАГОЛОВОК
                 ======================================== -->
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
                <h1 class="auth-title">Восстановление пароля</h1>
            </div>

            <!-- ========================================
                 ОТОБРАЖЕНИЕ СООБЩЕНИЙ
                 ======================================== -->
            <?php displayAlerts(
                $successMessages,  // Массив сообщений об успехе
                $errors,           // Массив сообщений об ошибках
                false,             // Показывать сообщения как toast-уведомления true/false
            ); ?>

            <!-- ========================================
                 ФОРМА ВОССТАНОВЛЕНИЯ ПАРОЛЯ
                 ======================================== -->
            <?php if (empty($successMessages)): ?>
            <form class="auth-form" method="POST" action="">
                
                <!-- CSRF-токен -->
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                <!-- Поле email -->
                <div class="form-group">
                    <label for="email" class="form-label">Email адрес</label>
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="your@email.com" required 
                           value="<?= escape($_POST['email'] ?? '') ?>">
                    <div class="form-text">Введите email вашей учетной записи</div>
                </div>

                <!-- Капча -->
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

                <!-- Информационное сообщение -->
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> На указанный email будет отправлена ссылка для восстановления пароля
                </div>

                <!-- Кнопка отправки -->
                <button type="submit" class="btn btn-primary btn-block" id="sendResetBtn">
                    <i class="bi bi-send"></i> Отправить ссылку
                </button>
            </form>
            <?php endif; ?>

            <!-- ========================================
                 ФУТЕР
                 ======================================== -->
            <div class="auth-footer">
                <p class="auth-footer-text">
                    Вспомнили пароль? 
                    <a href="authorization.php" class="auth-footer-link">Войти в аккаунт</a>
                </p>
                <?php if (isset($adminData['allow_registration']) && $adminData['allow_registration']): ?>
                <p class="auth-footer-text">
                    Нет аккаунта?
                    <a href="registration.php" class="auth-footer-link">Зарегистрироваться</a>
                </p>
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
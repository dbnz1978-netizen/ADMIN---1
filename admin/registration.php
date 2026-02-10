<?php

/**
 * Название файла:      registration.php
 * Назначение:          Страница регистрации новых пользователей в административной панели
 *                      Безопасная и отказоустойчивая реализация
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors' => false,  // включение отображения ошибок true/false
    'set_encoding'   => true,   // включение кодировки UTF-8
    'db_connect'     => true,   // подключение к базе
    'auth_check'     => true,   // подключение функций авторизации
    'file_log'       => true,   // подключение системы логирования
    'display_alerts' => true,   // подключение отображения сообщений
    'mailer'         => true,   // подключение отправка email уведомлений
    'sanitization'   => true,   // подключение валидации/экранирования
    'csrf_token'     => true,   // генерация CSRF-токена
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

$authTitle         = 'Создать аккаунт';  // Заголовок
$adminPanel        = $adminData['AdminPanel'] ?? 'AdminPanel';  // Название Админ-панели
$adminUserId       = getAdminUserId($pdo);  // Логотип админ-панели (для светлой/тёмной темы)
$logoPaths         = getThemeLogoPaths($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', $adminUserId);
$authLogoLight     = $logoPaths['light'];
$authLogoDark      = $logoPaths['dark'];
$authMetaTitle     = $authTitle . ' - ' . $adminPanel;
$authMetaDescription = $authTitle . ' — регистрация нового пользователя в системе.';
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
// ПРОВЕРКА РАЗРЕШЕНИЯ РЕГИСТРАЦИИ
// ========================================

// Запрет регистрации пользователей — перенаправляем
if (isset($adminData['allow_registration']) && !$adminData['allow_registration']) {
    header("Location: authorization.php", true, 302);
    exit;
}

// ========================================
// ИНИЦИАЛИЗАЦИЯ CAPTCHA ТОКЕНА
// ========================================

$captchaTokenTtl = 600;
$tokenCreated    = $_SESSION['captcha_token_created'] ?? 0;

if (
    empty($_SESSION['captcha_token'])
    || empty($tokenCreated)
    || (time() - $tokenCreated) > $captchaTokenTtl
    || (!empty($_SESSION['captcha_token_used']) && $_SERVER['REQUEST_METHOD'] !== 'POST')
) {
    $_SESSION['captcha_token']        = bin2hex(random_bytes(32));
    $_SESSION['captcha_token_created'] = time();
    $_SESSION['captcha_token_used']    = false;
}

$captchaToken = $_SESSION['captcha_token'];

// ========================================
// ОБРАБОТКА POST-ЗАПРОСА НА РЕГИСТРАЦИЮ
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ========================================
    // ПРОВЕРКА CSRF-ТОКЕНА
    // ========================================
    
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Недопустимый запрос. Повторите попытку.";
        logEvent(
            "Попытка CSRF-атаки при регистрации — IP: {$_SERVER['REMOTE_ADDR']}",
            LOG_ERROR_ENABLED,
            'error'
        );
    } else {
        
        // ========================================
        // ВАЛИДАЦИЯ ОБЯЗАТЕЛЬНЫХ ПОЛЕЙ
        // ========================================
        
        // Обязательные поля (чекбоксы проверяются отдельно)
        $requiredFields = ['firstName', 'lastName', 'email', 'password', 'confirmPassword'];
        
        $missing = false;
        
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field]) || trim($_POST[$field] ?? '') === '') {
                $errors[] = "Заполните все обязательные поля!";
                $missing  = true;
                break;
            }
        }
        
        // ========================================
        // ПРОВЕРКА СОГЛАШЕНИЙ И CAPTCHA
        // ========================================
        
        if (!$missing) {
            if (!isset($_POST['agreement'])) {
                $errors[] = "Необходимо принять условия соглашения!";
            }
            
            if (!isset($_POST['privacyPolicy'])) {
                $errors[] = "Необходимо согласиться на обработку персональных данных!";
            }
            
            $captchaTokenPost     = $_POST['captcha_token'] ?? '';
            $sessionCaptchaToken  = $_SESSION['captcha_token'] ?? '';
            $captchaPassed        = isset($_SESSION['captcha_passed']) && time() - $_SESSION['captcha_passed'] <= 300;
            $tokenUsed            = !empty($_SESSION['captcha_token_used']);
            $tokenValid           = $captchaPassed
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
        // РАСШИРЕННАЯ ВАЛИДАЦИЯ ДАННЫХ
        // ========================================
        
        if (empty($errors)) {
            
            // Валидация текстового поля (имя)
            $resultFirst = validateNameField(trim($_POST['firstName'] ?? ''), 2, 50, 'Имя');
            
            if ($resultFirst['valid']) {
                $firstName = ($resultFirst['value']);
            } else {
                $errors[]  = ($resultFirst['error']);
                $firstName = false;
            }
            
            // Валидация текстового поля (фамилия)
            $resultLast = validateNameField(trim($_POST['lastName'] ?? ''), 2, 50, 'Фамилия');
            
            if ($resultLast['valid']) {
                $lastName = ($resultLast['value']);
            } else {
                $errors[] = ($resultLast['error']);
                $lastName = false;
            }
            
            // Валидация email-адреса
            $resultEmail = validateEmail(trim($_POST['email'] ?? ''));
            
            if ($resultEmail['valid']) {
                $email = $resultEmail['email'];
            } else {
                $errors[] = $resultEmail['error'];
                $email    = false;
            }
            
            // Валидация телефонного номера
            $resultPhone = validatePhone(trim($_POST['phone'] ?? ''));
            
            if ($resultPhone['valid']) {
                $phone = $resultPhone['value'] ?? '';
            } else {
                $errors[] = $resultPhone['error'];
                $phone    = false;
            }
            
            // Валидация пароля
            $resultPass = validatePassword(trim($_POST['password'] ?? ''));
            
            if ($resultPass['valid']) {
                $password = $resultPass['value'];
            } else {
                $errors[] = $resultPass['error'];
                $password = false;
            }
            
            if (empty($errors)) {
                // Подтверждение пароля
                $confirmPassword = trim($_POST['confirmPassword'] ?? '');
                
                // Сравнение паролей
                if ($password !== $confirmPassword) {
                    $errors[] = "Пароли не совпадают!";
                }
            }
            
            // ========================================
            // РЕГИСТРАЦИЯ ПОЛЬЗОВАТЕЛЯ
            // ========================================
            
            if (empty($errors)) {
                try {
                    // Проверка уникальности email (только активные)
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND status = 1 LIMIT 1");
                    $stmt->execute([$email]);
                    
                    if ($stmt->fetch()) {
                        $errors[] = "Пользователь с таким email уже зарегистрирован!";
                        logEvent(
                            "Попытка регистрации с уже существующим email: $email — IP: {$_SERVER['REMOTE_ADDR']}",
                            LOG_ERROR_ENABLED,
                            'error'
                        );
                    } else {
                        // Подготовка данных
                        $userData = [
                            'first_name'       => $firstName,
                            'last_name'        => $lastName,
                            'phone'            => $phone,
                            'registration_ip'  => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            'registration_date' => date('Y-m-d H:i:s'),
                        ];
                        
                        $jsonData = json_encode($userData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        
                        // Хеширование пароля
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        
                        if ($hash === false) {
                            throw new Exception("Не удалось захешировать пароль");
                        }
                        
                        // Генерация токена
                        $verificationToken = bin2hex(random_bytes(32));
                        $tokenExpires      = date('Y-m-d H:i:s', strtotime('+24 hours'));
                        
                        // Вставка в БД
                        $insert = $pdo->prepare(
                            "INSERT INTO users (author, email, password, data, verification_token, token_expires, status)
                             VALUES (?, ?, ?, ?, ?, ?, ?)"
                        );
                        
                        $insert->execute(['user', $email, $hash, $jsonData, $verificationToken, $tokenExpires, 1]);
                        
                        // Отправка email
                        $emailSent = sendVerificationEmail($email, $firstName, $verificationToken, $adminPanel);
                        
                        if ($emailSent) {
                            $successMessages[] = "Регистрация завершена! На вашу почту отправлено письмо с подтверждением.";
                            logEvent(
                                "Успешная регистрация нового пользователя: $email — IP: {$_SERVER['REMOTE_ADDR']}",
                                LOG_INFO_ENABLED,
                                'info'
                            );
                        } else {
                            $successMessages[] = "Регистрация прошла успешно, но не удалось отправить письмо подтверждения.";
                            logEvent(
                                "Регистрация без отправки email: $email — IP: {$_SERVER['REMOTE_ADDR']}",
                                LOG_INFO_ENABLED,
                                'info'
                            );
                        }
                        
                        // Сброс капчи и CSRF-токена после успешной регистрации (одноразовое использование)
                        unset($_SESSION['captcha_passed']);
                        unset($_SESSION['csrf_token']);
                    }
                } catch (Exception $e) {
                    $errors[] = "Ошибка при регистрации. Пожалуйста, попробуйте позже.";
                    logEvent(
                        "Исключение при регистрации пользователя с email: $email — " . $e->getMessage(),
                        LOG_ERROR_ENABLED,
                        'error'
                    );
                }
            }
        }
    }
}

// ========================================
// СБРОС CAPTCHA ПОСЛЕ POST-ЗАПРОСА
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    unset($_SESSION['captcha_passed']);
    $_SESSION['captcha_token_used']    = false;
    $_SESSION['captcha_token']         = bin2hex(random_bytes(32));
    $_SESSION['captcha_token_created'] = time();
    $captchaToken                      = $_SESSION['captcha_token'];
}

// ========================================
// СОХРАНЕНИЕ СОСТОЯНИЯ ЧЕКБОКСОВ
// ========================================

// Сохраняем состояние чекбоксов при повторной загрузке формы
$agreementChecked = isset($_POST['agreement']) ? 'checked' : '';
$privacyChecked   = isset($_POST['privacyPolicy']) ? 'checked' : '';

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= escape($authMetaDescription) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= escape($authMetaTitle) ?></title>
    <script>
        (function() {
            const saved_theme = localStorage.getItem('theme');
            if (saved_theme === 'dark') {
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
                    <?php if (!empty($authLogoLight)): ?>
                        <img class="auth-logo-image auth-logo-light" src="<?= escape($authLogoLight) ?>" alt="<?= escape($adminPanel) ?>">
                        <img class="auth-logo-image auth-logo-dark" src="<?= escape($authLogoDark) ?>" alt="<?= escape($adminPanel) ?>">
                    <?php else: ?>
                        <i class="bi bi-shield-lock"></i>
                    <?php endif; ?>
                    <?= escape($adminPanel) ?>
                </a>
                <?php if (empty($successMessages)): ?>
                    <h1 class="auth-title">Создать аккаунт</h1>
                    <p class="auth-subtitle">Заполните форму ниже чтобы создать новую учетную запись</p>
                <?php endif; ?>
            </div>
            
            <!-- Отображение сообщений -->
            <?php displayAlerts(
                $successMessages,  // Массив сообщений об успехе
                $errors,           // Массив сообщений об ошибках
                false              // Показывать сообщения как toast-уведомления true/false
            ); 
            ?>

            <?php if (empty($successMessages)): ?>
            <form class="auth-form" id="registrationForm" method="POST" action="">
                <!-- CSRF-токен -->
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="firstName" class="form-label">Имя *</label>
                        <input type="text" class="form-control" id="firstName" name="firstName" 
                               placeholder="Введите имя" required 
                               maxlength="50"
                               pattern="[а-яА-ЯёЁa-zA-Z\s\-ʼ’']{2,50}"
                               title="Только буквы, пробелы, дефисы и апострофы. Минимум 2 символа."
                               value="<?= escape($_POST['firstName'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="lastName" class="form-label">Фамилия *</label>
                        <input type="text" class="form-control" id="lastName" name="lastName" 
                               placeholder="Введите фамилию" required 
                               maxlength="50"
                               pattern="[а-яА-ЯёЁa-zA-Z\s\-ʼ’']{2,50}"
                               title="Только буквы, пробелы, дефисы и апострофы. Минимум 2 символа."
                               value="<?= escape($_POST['lastName'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Email адрес *</label>
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="your@email.com" required 
                           maxlength="254"
                           value="<?= escape($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Телефон (необязательно)</label>
                    <input type="tel" class="form-control" id="phone" name="phone" 
                           placeholder="+7 (XXX) XXX-XX-XX или + Код Страны Номер" 
                           maxlength="30"
                           value="<?= escape($_POST['phone'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Пароль *</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Создайте надежный пароль" required minlength="6" maxlength="128">
                        <button type="button" class="password-toggle">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirmPassword" class="form-label">Подтвердите пароль *</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="confirmPassword" name="confirmPassword" 
                               placeholder="Повторите пароль" required minlength="6" maxlength="128">
                        <button type="button" class="password-toggle">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
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

                <!-- ДВА ЧЕКБОКСА: Условия + Политика -->
                <div class="agreement-check mt-3">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="agreement" name="agreement" required <?= $agreementChecked ?>>
                        <label class="form-check-label" for="agreement">
                            Я соглашаюсь с <a href="#" class="privacy-link" data-bs-toggle="modal" data-bs-target="#termsModal">условиями использования</a>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="privacyPolicy" name="privacyPolicy" required <?= $privacyChecked ?>>
                        <label class="form-check-label" for="privacyPolicy">
                            Я согласен на обработку <a href="#" class="privacy-link" data-bs-toggle="modal" data-bs-target="#privacyModal">персональных данных</a>
                        </label>
                    </div>
                </div>

                <!-- Кнопка без disabled — CaptchaSlider сам включит её при успехе -->
                <button type="submit" class="btn btn-primary btn-block" id="registerBtn">
                    <i class="bi bi-person-plus"></i> Создать аккаунт
                </button>
            </form>
            <?php endif; ?>

            <!-- Модальное окно: Условия использования -->
            <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="termsModalLabel">Условия использования</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                            <?php 
                                // Валидация HTML
                                $terms = sanitizeHtmlFromEditor($adminData['terms'] ?? '');
                                echo $terms;
                            ?>
                            <div class="alert alert-info mt-3">
                                <i class="bi bi-info-circle"></i> Нажимая "Принять условия", вы подтверждаете свое согласие со всеми пунктами данного соглашения.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Модальное окно: Политика конфиденциальности -->
            <div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="privacyModalLabel">Политика конфиденциальности</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="max-height: 60vh; overflow-y: auto;">
                            <?php 
                                // Валидация HTML
                                $privacy = sanitizeHtmlFromEditor($adminData['privacy'] ?? '');
                                echo $privacy;
                            ?>
                            <div class="alert alert-info mt-3">
                                <i class="bi bi-shield-lock"></i> Нажимая «Принять», вы даёте добровольное согласие на обработку персональных данных в соответствии с данной Политикой.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="auth-footer">
                <p class="auth-footer-text">
                    Уже есть аккаунт? 
                    <a href="authorization.php" class="auth-footer-link">Войти</a>
                </p>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Модульный JS admin -->
    <script type="module" src="js/main.js"></script>
</body>
</html>
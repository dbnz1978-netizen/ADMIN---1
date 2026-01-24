<?php
/**
 * Файл: /admin/registration.php
 * 
 * Страница регистрации новых пользователей в административной панели.
 * Безопасная и отказоустойчивая реализация.
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

// Подключаем зависимости
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/functions/auth_check.php';                  // Авторизация и получение данных пользователей
require_once __DIR__ . '/functions/file_log.php';                    // Система логирования
require_once __DIR__ . '/functions/mailer.php';                      // Отправка email уведомлений 
require_once __DIR__ . '/functions/display_alerts.php';              // Отображение сообщений
require_once __DIR__ . '/functions/sanitization.php';                // Валидация экранирование 

// Получаем настройки администратора
$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: logout.php");
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
    header("Location: $redirectTo");
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
    exit;
}

// Запрет регистрации пользователей — перенаправляем
if (isset($adminData['allow_registration']) && !$adminData['allow_registration']) {
    header("Location: authorization.php", true, 302);
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
    exit;
}

// === ГЕНЕРАЦИЯ CSRF-ТОКЕНА (если ещё не создан) ===
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Инициализируем сообщения
$errors = [];
$successMessages = [];

/**
 * ОБРАБОТКА POST-ЗАПРОСА НА РЕГИСТРАЦИЮ
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // === ПРОВЕРКА CSRF-ТОКЕНА ===
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Недопустимый запрос. Повторите попытку.";
        logEvent("Попытка CSRF-атаки при регистрации — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
    } else {
        // Обязательные поля (чекбоксы проверяются отдельно)
        $required_fields = ['firstName', 'lastName', 'email', 'password', 'confirmPassword'];
        
        /**
         * ПРОВЕРКА ЗАПОЛНЕНИЯ ОБЯЗАТЕЛЬНЫХ ПОЛЕЙ
         */
        $missing = false;
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || trim($_POST[$field] ?? '') === '') {
                $errors[] = "Заполните все обязательные поля!";
                $missing = true;
                break;
            }
        }

        /**
         * ПРОВЕРКА СОГЛАШЕНИЙ И КАПЧИ — ЧЕРЕЗ СЕССИЮ!
         */
        if (!$missing) {
            if (!isset($_POST['agreement'])) {
                $errors[] = "Необходимо принять условия соглашения!";
            }
            if (!isset($_POST['privacyPolicy'])) {
                $errors[] = "Необходимо согласиться на обработку персональных данных!";
            }
            if (!isset($_SESSION['captcha_passed']) || time() - $_SESSION['captcha_passed'] > 300) {
                $errors[] = "Пожалуйста, подтвердите, что вы не робот!";
            }
        }

        /**
         * РАСШИРЕННАЯ ВАЛИДАЦИЯ
         */
        if (empty($errors)) {

            // Валидация текстового поля (имя)
            $resultfirst = validateNameField(trim($_POST['firstName'] ?? ''), 2, 50, 'Имя');
            if ($resultfirst['valid']) {
                $firstName = ($resultfirst['value']);
            } else {
                $errors[] = ($resultfirst['error']);
                $firstName = false;
            }

            // Валидация текстового поля (фамилия)
            $resultlast = validateNameField(trim($_POST['lastName'] ?? ''), 2, 50, 'Фамилия');
            if ($resultlast['valid']) {
                $lastName = ($resultlast['value']);
            } else {
                $errors[] = ($resultlast['error']);
                $lastName = false;
            }

            // Валидация email-адреса
            $result_email = validateEmail(trim($_POST['email'] ?? ''));
            if ($result_email['valid']) {
                $email =  $result_email['email'];
            } else {
                $errors[] = $result_email['error'];
                $email = false;
            }

            // Валидация телефонного номера
            $resultPhone = validatePhone(trim($_POST['phone'] ?? ''));
            if ($resultPhone['valid']) {
                $phone = $resultPhone['value'] ?? '';
            } else {
                $errors[] = $resultPhone['error'];
                $phone = false;
            }

            // Валидация пароля
            $result_pass = validatePassword(trim($_POST['password'] ?? ''));
            if ($result_pass['valid']) {
                $password = $result_pass['value'];
            } else {
                $errors[] = $result_pass['error'];
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

            // 7. Регистрация (если всё ОК)
            if (empty($errors)) {
                try {
                    // Проверка уникальности email (только активные)
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND status = 1 LIMIT 1");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $errors[] = "Пользователь с таким email уже зарегистрирован!";
                        logEvent("Попытка регистрации с уже существующим email: $email — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
                    } else {
                        // Подготовка данных
                        $userData = [
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'phone' => $phone,
                            'registration_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            'registration_date' => date('Y-m-d H:i:s')
                        ];
                        $jsonData = json_encode($userData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                        // Хеширование пароля
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        if ($hash === false) {
                            throw new Exception("Не удалось захешировать пароль");
                        }

                        // Генерация токена
                        $verificationToken = bin2hex(random_bytes(32));
                        $tokenExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));

                        // Вставка в БД
                        $insert = $pdo->prepare("INSERT INTO users (author, email, password, data, verification_token, token_expires, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $insert->execute(['user', $email, $hash, $jsonData, $verificationToken, $tokenExpires, 1]);

                        // Отправка email
                        $emailSent = sendVerificationEmail($email, $firstName, $verificationToken, $AdminPanel);
                        if ($emailSent) {
                            $successMessages[] = "Регистрация завершена! На вашу почту отправлено письмо с подтверждением.";
                            logEvent("Успешная регистрация нового пользователя: $email — IP: {$_SERVER['REMOTE_ADDR']}", LOG_INFO_ENABLED, 'info');
                        } else {
                            $successMessages[] = "Регистрация прошла успешно, но не удалось отправить письмо подтверждения.";
                            logEvent("Регистрация без отправки email: $email — IP: {$_SERVER['REMOTE_ADDR']}", LOG_INFO_ENABLED, 'info');
                        }

                        // Сброс капчи и CSRF-токена после успешной регистрации (одноразовое использование)
                        unset($_SESSION['captcha_passed']);
                        unset($_SESSION['csrf_token']);
                    }
                } catch (Exception $e) {
                    $errors[] = "Ошибка при регистрации. Пожалуйста, попробуйте позже.";
                    logEvent("Исключение при регистрации пользователя с email: $email — " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
                }
            }
        }
    }
}

// Сохраняем состояние чекбоксов при повторной загрузке формы
$agreementChecked = isset($_POST['agreement']) ? 'checked' : '';
$privacyChecked = isset($_POST['privacyPolicy']) ? 'checked' : '';

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
    <title>Регистрация - <?= escape($AdminPanel) ?></title>
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
                <div class="register-icon">
                    <i class="bi bi-person-plus"></i>
                </div>
                <h1 class="auth-title">Создать аккаунт</h1>
                <p class="auth-subtitle">Заполните форму ниже чтобы создать новую учетную запись</p>
            </div>
            
            <!-- Отображение сообщений -->
            <?php displayAlerts($successMessages, $errors); ?>

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
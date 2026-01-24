<?php
/**
 * Файл: /admin/forgot.php
 * 
 * Страница восстановления пароля для административной панели.
 * Безопасная реализация с защитой от перебора, утечек и атак.
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
 * ОБРАБОТКА POST-ЗАПРОСА НА ВОССТАНОВЛЕНИЕ ПАРОЛЯ
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // === ПРОВЕРКА CSRF-ТОКЕНА ===
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Недопустимый запрос. Повторите попытку.";
        logEvent("Попытка CSRF-атаки при восстановлении пароля — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
    } else {
        // ПРОВЕРКА КАПЧИ — ЧЕРЕЗ СЕССИЮ
        if (!isset($_SESSION['captcha_passed']) || time() - $_SESSION['captcha_passed'] > 300) {
            $errors[] = "Пожалуйста, подтвердите, что вы не робот!";
            logEvent("Попытка сброса пароля без подтверждения капчи — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
        }
        // Валидация email-адреса через функцию validateEmail
        else {
            $result_email = validateEmail(trim($_POST['email'] ?? ''));
            if ($result_email['valid']) {
                $email = $result_email['email'];
            } else {
                $errors[] = $result_email['error'];
                $email = false;
            }

            // Если email не прошёл валидацию — не продолжаем
            if ($email === false) {
                // ошибки уже добавлены, выходим из цепочки
            }
            // ОСНОВНАЯ ЛОГИКА ВОССТАНОВЛЕНИЯ ПАРОЛЯ
            else {
                try {
                    // ПРОВЕРКА СУЩЕСТВОВАНИЯ ПОЛЬЗОВАТЕЛЯ
                    $stmt = $pdo->prepare("SELECT id, data, email_verified, status FROM users WHERE email = ? LIMIT 1");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Для безопасности: одинаковое поведение как при существовании, так и при отсутствии пользователя
                    if ($user) {
                        // Проверяем, активен ли аккаунт и подтверждён ли email
                        if ($user['status'] !== 1) {
                            logEvent("Попытка сброса пароля для неактивного пользователя: $email — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
                        } elseif (!$user['email_verified']) {
                            logEvent("Попытка сброса пароля для неподтверждённого email: $email — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
                        } else {
                            // Декодируем JSON данные пользователя
                            $userData = json_decode($user['data'], true);
                            if (!is_array($userData)) {
                                $userData = [];
                                logEvent("Некорректные JSON-данные при восстановлении пароля (id={$user['id']})", LOG_ERROR_ENABLED, 'error');
                            }
                            $firstName = $userData['first_name'] ?? 'Пользователь';
                            
                            // ГЕНЕРАЦИЯ ТОКЕНА ДЛЯ СБРОСА ПАРОЛЯ
                            $token = bin2hex(random_bytes(32));
                            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                            
                            // СОХРАНЕНИЕ ТОКЕНА В БАЗЕ ДАННЫХ
                            $update = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expire = ? WHERE id = ?");
                            $update->execute([$token, $expires, $user['id']]);
                            
                            // ОТПРАВКА EMAIL ЧЕРЕЗ ФУНКЦИЮ
                            if (sendPasswordResetEmail($email, $firstName, $token, $AdminPanel)) {
                                logEvent("Ссылка для восстановления пароля успешно отправлена на: $email — IP: {$_SERVER['REMOTE_ADDR']}", LOG_INFO_ENABLED, 'info');
                            } else {
                                logEvent("Ошибка отправки email для сброса пароля на адрес: $email — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
                            }
                        }
                    } else {
                        // Пользователь не найден — логируем (низкий приоритет)
                        logEvent("Попытка сброса пароля для несуществующего email: $email — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
                    }

                    // Сброс капчи
                    unset($_SESSION['captcha_passed']);

                    // ЕДИНСТВЕННЫЙ ОТВЕТ — всегда успешный (защита от перебора)
                    $successMessages[] = "Если пользователь с таким email существует, на него будет отправлена ссылка для восстановления пароля.";
                } catch (Exception $e) {
                    $errors[] = "Ошибка при обработке запроса. Пожалуйста, попробуйте позже.";
                    logEvent("Исключение при восстановлении пароля для email: $email — " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
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
    <title>Восстановление пароля - <?= escape($AdminPanel) ?></title>
    <!-- Модуль управления светлой/тёмной темой -->
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
            <!-- Заголовок -->
            <div class="auth-header">
                <a href="authorization.php" class="auth-logo">
                    <i class="bi bi-robot"></i>
                    <?= escape($AdminPanel) ?>
                </a>
                <div class="recovery-icon">
                    <i class="bi bi-key"></i>
                </div>
                <h1 class="auth-title">Восстановление пароля</h1>
            </div>

            <!-- Отображение сообщений -->
            <?php displayAlerts($successMessages, $errors); ?>

            <!-- Форма (скрывается при успешной отправке) -->
            <?php if (empty($successMessages)): ?>
            <form class="auth-form" method="POST" action="">
                <!-- CSRF-токен через -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                <!-- Поле email -->
                <div class="form-group">
                    <label for="email" class="form-label">Email адрес</label>
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="your@email.com" required 
                           value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
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

            <!-- Футер -->
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
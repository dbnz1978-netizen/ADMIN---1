<?php
/**
 * Файл: /admin/reset.php
 * 
 * Скрипт для установки нового пароля после запроса восстановления.
 * Безопасная реализация с защитой от атак и утечек.
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

// === ГЕНЕРАЦИЯ CSRF-ТОКЕНА (если ещё не создан) ===
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Инициализация
$errors = [];
$successMessages = [];
$validToken = false;
$token = trim($_GET['token'] ?? '');
$passwordChanged = false;

// === Проверка токена ===
if ($token) {
    try {
        // Защита от timing-атак: всегда делаем запрос, даже при пустом токене
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE reset_token = ? AND reset_expire > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $validToken = true;

            // Обработка POST → смена пароля
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // === ПРОВЕРКА CSRF-ТОКЕНА ===
                if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                    $errors[] = "Недопустимый запрос. Повторите попытку.";
                    logEvent("Попытка CSRF-атаки при смене пароля (с токеном) — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
                } else {

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

                        // Валидация
                        if (empty($password) || empty($confirmPassword)) {
                            $errors[] = "Заполните все поля!";
                        } elseif ($password !== $confirmPassword) {
                            $errors[] = "Пароли не совпадают!";
                        } else {
                            // Хеширование
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            if ($hash === false) {
                                throw new Exception("Не удалось захешировать пароль");
                            }

                            // Обновление в БД
                            $update = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expire = NULL WHERE id = ? AND reset_token = ?");
                            $updated = $update->execute([$hash, $user['id'], $token]);

                            if ($updated && $update->rowCount() > 0) {
                                $successMessages[] = "Пароль успешно изменён! Через 3 секунды вы будете перенаправлены на страницу входа.";
                                $passwordChanged = true;
                                logEvent("Успешная смена пароля для email: {$user['email']} — IP: {$_SERVER['REMOTE_ADDR']}", LOG_INFO_ENABLED, 'info');
                                // Сброс токена после использования
                                unset($_SESSION['csrf_token']);
                            } else {
                                // Возможна гонка: токен уже использован другим запросом
                                $errors[] = "Ссылка уже использована или недействительна.";
                                logEvent("Попытка повторного использования токена: " . substr($token, 0, 8) . "... — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
                            }
                        }
                    }
                }
            }
        } else {
            $errors[] = "Неверная или устаревшая ссылка для восстановления пароля.";
            logEvent("Недействительный или просроченный токен сброса пароля: " . substr($token, 0, 8) . "... — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
        }
    } catch (Exception $e) {
        $errors[] = "Ошибка при обработке запроса. Пожалуйста, попробуйте позже.";
        logEvent("Исключение при обработке сброса пароля — " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    }
} else {
    $errors[] = "Отсутствует токен для восстановления пароля.";
    logEvent("Попытка смены пароля без токена — IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
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
    <title>Установка нового пароля - <?= $AdminPanel ?></title>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <?php if ($passwordChanged): ?>
    <meta http-equiv="refresh" content="3;url=authorization.php">
    <?php endif; ?>
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
                    <?= $AdminPanel ?>
                </a>
                <div class="recovery-icon">
                    <i class="bi bi-key"></i>
                </div>
                <h1 class="auth-title">Новый пароль</h1>
                <p class="auth-subtitle">Установите новый пароль для вашего аккаунта</p>
            </div>

            <!-- Отображение сообщений -->
            <?php displayAlerts($successMessages, $errors); ?>

            <?php if ($validToken && !$passwordChanged): ?>
                <form class="auth-form" method="POST" action="">
                    <!-- CSRF-токен через -->
                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                    <div class="form-group">
                        <label for="password" class="form-label">Новый пароль</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="Введите новый пароль" required minlength="6">
                            <button type="button" class="password-toggle">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirmPassword" class="form-label">Подтвердите пароль</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="confirmPassword" name="confirmPassword" 
                                   placeholder="Повторите новый пароль" required minlength="6">
                            <button type="button" class="password-toggle">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="bi bi-key"></i> Установить новый пароль
                    </button>
                </form>
            <?php elseif (!$validToken): ?>
                <div class="text-center">
                    <a href="forgot.php" class="btn btn-primary">
                        <i class="bi bi-arrow-clockwise"></i> Запросить новую ссылку
                    </a>
                </div>
            <?php endif; ?>

            <div class="auth-footer">
                <p class="auth-footer-text">
                    <a href="authorization.php" class="auth-footer-link">
                        <i class="bi bi-box-arrow-in-right"></i> Войти в аккаунт
                    </a>
                </p>
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


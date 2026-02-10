<?php

/**
 * Название файла:      reset.php
 * Назначение:          Скрипт для установки нового пароля после запроса восстановления
 *                      Безопасная реализация с защитой от атак и утечек
 * Автор:               User
 * Версия:              1.0
 * Дата создания:       2026-02-10
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
    'csrf_token'       => true,   // Генерация CSRF-токена
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
// НАСТРОЙКИ СТРАНИЦЫ ВОССТАНОВЛЕНИЯ ПАРОЛЯ
// ========================================

$authTitle           = 'Новый пароль';  // Заголовок страницы
$adminPanel          = $adminData['AdminPanel'] ?? 'AdminPanel';  // Название админ-панели
$adminUserId         = getAdminUserId($pdo);  // ID администратора
$logoPaths           = getThemeLogoPaths($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', $adminUserId);
$authLogoLight       = $logoPaths['light'];
$authLogoDark        = $logoPaths['dark'];
$authMetaTitle       = $authTitle . ' - ' . $adminPanel;
$authMetaDescription = $authTitle . ' — установка нового пароля для восстановления доступа.';
$authFavicon         = !empty($authLogoLight) ? $authLogoLight : 'img/avatar.svg';

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
// ИНИЦИАЛИЗАЦИЯ ПЕРЕМЕННЫХ
// ========================================

$validToken      = false;
$token           = trim($_GET['token'] ?? '');
$passwordChanged = false;

// ========================================
// ПРОВЕРКА ТОКЕНА ВОССТАНОВЛЕНИЯ ПАРОЛЯ
// ========================================

if ($token) {
    try {
        // Защита от timing-атак: всегда делаем запрос, даже при пустом токене
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE reset_token = ? AND reset_expire > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $validToken = true;

            // ========================================
            // ОБРАБОТКА СМЕНЫ ПАРОЛЯ (POST-ЗАПРОС)
            // ========================================
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                
                // ========================================
                // ПРОВЕРКА CSRF-ТОКЕНА
                // ========================================
                
                if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
                    $errors[] = "Недопустимый запрос. Повторите попытку.";
                    logEvent(
                        "Попытка CSRF-атаки при смене пароля (с токеном) — IP: {$_SERVER['REMOTE_ADDR']}",
                        LOG_ERROR_ENABLED,
                        'error'
                    );
                } else {
                    
                    // ========================================
                    // ВАЛИДАЦИЯ НОВОГО ПАРОЛЯ
                    // ========================================
                    
                    $resultPass = validatePassword(trim($_POST['password'] ?? ''));
                    if ($resultPass['valid']) {
                        $password = $resultPass['value'];
                    } else {
                        $errors[] = $resultPass['error'];
                        $password = false;
                    }

                    if (empty($errors)) {
                        
                        // ========================================
                        // ПОДТВЕРЖДЕНИЕ ПАРОЛЯ
                        // ========================================
                        
                        $confirmPassword = trim($_POST['confirmPassword'] ?? '');

                        // Валидация совпадения паролей
                        if (empty($password) || empty($confirmPassword)) {
                            $errors[] = "Заполните все поля!";
                        } elseif ($password !== $confirmPassword) {
                            $errors[] = "Пароли не совпадают!";
                        } else {
                            
                            // ========================================
                            // ХЕШИРОВАНИЕ И СОХРАНЕНИЕ НОВОГО ПАРОЛЯ
                            // ========================================
                            
                            $hash = password_hash($password, PASSWORD_DEFAULT);
                            if ($hash === false) {
                                throw new Exception("Не удалось захешировать пароль");
                            }

                            // Обновление пароля в базе данных
                            $update = $pdo->prepare(
                                "UPDATE users SET password = ?, reset_token = NULL, reset_expire = NULL "
                                . "WHERE id = ? AND reset_token = ?"
                            );
                            $updated = $update->execute([$hash, $user['id'], $token]);

                            if ($updated && $update->rowCount() > 0) {
                                $successMessages[] = "Пароль успешно изменён! Через 3 секунды вы будете перенаправлены "
                                    . "на страницу входа.";
                                $passwordChanged = true;
                                $logMessage      = "Успешная смена пароля для email: {$user['email']} — IP: "
                                    . "{$_SERVER['REMOTE_ADDR']}";
                                logEvent($logMessage, LOG_INFO_ENABLED, 'info');
                                // Сброс токена после использования
                                unset($_SESSION['csrf_token']);
                            } else {
                                // Возможна гонка: токен уже использован другим запросом
                                $errors[]   = "Ссылка уже использована или недействительна.";
                                $logMessage = "Попытка повторного использования токена: " . substr($token, 0, 8)
                                    . "... — IP: {$_SERVER['REMOTE_ADDR']}";
                                logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
                            }
                        }
                    }
                }
            }
        } else {
            $errors[]   = "Неверная или устаревшая ссылка для восстановления пароля.";
            $logMessage = "Недействительный или просроченный токен сброса пароля: " . substr($token, 0, 8)
                . "... — IP: {$_SERVER['REMOTE_ADDR']}";
            logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
        }
    } catch (Exception $e) {
        $errors[]   = "Ошибка при обработке запроса. Пожалуйста, попробуйте позже.";
        $logMessage = "Исключение при обработке сброса пароля — " . $e->getMessage();
        logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    }
} else {
    $errors[] = "Отсутствует токен для восстановления пароля.";
    logEvent(
        "Попытка смены пароля без токена — IP: {$_SERVER['REMOTE_ADDR']}",
        LOG_ERROR_ENABLED,
        'error'
    );
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
    
    <!-- Автоматическое применение сохраненной темы -->
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

    <!-- Основной контейнер -->
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
                <h1 class="auth-title">Новый пароль</h1>
                <p class="auth-subtitle">Установите новый пароль для вашего аккаунта</p>
            </div>

            <!-- Отображение сообщений -->
            <?php displayAlerts(
                $successMessages,  // Массив сообщений об успехе
                $errors,           // Массив сообщений об ошибках
                false,             // Показывать сообщения как обычные (не toast)
            );
            ?>

            <?php if ($validToken && !$passwordChanged): ?>
                <!-- Форма установки нового пароля -->
                <form class="auth-form" method="POST" action="">
                    <!-- CSRF-токен для защиты от атак -->
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
                <!-- Кнопка запроса новой ссылки -->
                <div class="text-center">
                    <a href="forgot.php" class="btn btn-primary">
                        <i class="bi bi-arrow-clockwise"></i> Запросить новую ссылку
                    </a>
                </div>
            <?php endif; ?>

            <!-- Ссылка на страницу входа -->
            <div class="auth-footer">
                <p class="auth-footer-text">
                    <a href="authorization.php" class="auth-footer-link">
                        <i class="bi bi-box-arrow-in-right"></i> Войти в аккаунт
                    </a>
                </p>
            </div>
        </div>
    </div>

    <!-- Подключение JavaScript библиотек -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script type="module" src="js/main.js"></script>
</body>
</html>
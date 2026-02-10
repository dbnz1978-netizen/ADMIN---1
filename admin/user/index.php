<?php

/**
 * Название файла:      index.php
 * Назначение:          Главная страница админ-панели после входа.
 *                      Отображает приветственное сообщение или системные уведомления,
 *                      если они включены в настройках администратора.
 *                      
 *                      Особенности:
 *                      - Доступ только для авторизованных пользователей с ролью 'admin'
 *                      - Поддерживает светлую/тёмную тему через localStorage
 *                      - HTML-контент из редактора проходит строгую санитизацию
 *                      - Все критические ошибки логируются даже до полной инициализации
 *                      - Использует централизованную систему инициализации (/functions/init.php)
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors'  => false,   // включение отображения ошибок true/false
    'set_encoding'    => true,    // включение кодировки UTF-8
    'db_connect'      => true,    // подключение к базе
    'auth_check'      => true,    // подключение функций авторизации
    'file_log'        => true,    // подключение системы логирования
    'display_alerts'  => true,    // подключение отображения сообщений
    'sanitization'    => true,    // подключение валидации/экранирования
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);

if ($adminData === false) {
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    // Логируем всегда, даже если логирование отключено — это критическая ошибка
    logEvent("Ошибка: admin не найден / ошибка БД / некорректный JSON", true, 'error');
    
    header("Location: ../logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ СТРАНИЦЫ
// ========================================

$titlemeta = 'Админ-панель';  // Название Админ-панели

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

// ========================================
// АВТОРИЗАЦИЯ И ПРОВЕРКА ПРАВ
// ========================================

try {
    
    // Проверка авторизации
    $user = requireAuth($pdo);
    
    if (!$user) {
        $redirectTo = '../logout.php';
        logEvent("Неавторизованный доступ — перенаправление на: $redirectTo — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}", LOG_INFO_ENABLED, 'info');
        
        header("Location: $redirectTo");
        exit;
    }

    // Получение данных пользователя
    $userDataAdmin = getUserData($pdo, $user['id']);
    
    // Проверка ошибки
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg        = $userDataAdmin['message'];
        $level      = $userDataAdmin['level'];
        $logEnabled = match($level) {
            'info'  => LOG_INFO_ENABLED,
            'error' => LOG_ERROR_ENABLED,
            default => LOG_ERROR_ENABLED
        };
        
        logEvent($msg, $logEnabled, $level);
        header("Location: ../logout.php");
        exit;
    }

    // Декодируем JSON-данные администратора
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

} catch (Exception $e) {
    // Логируем всегда — критическая ошибка инициализации
    logEvent("Ошибка при инициализации админ-панели: " . $e->getMessage(), true, 'error');
    
    header("Location: ../logout.php");
    exit;
}

// ========================================
// ЗАГРУЗКА FLASH-СООБЩЕНИЙ
// ========================================

// Загружаем flash-сообщения из сессии (если есть)
$successMessages = [];
$errors          = [];

if (!empty($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors          = $_SESSION['flash_messages']['error'] ?? [];
    
    // Удаляем их, чтобы не показывались при повторной загрузке
    unset($_SESSION['flash_messages']);
}

// ========================================
// ПОЛУЧЕНИЕ ЛОГОТИПА ПРОФИЛЯ
// ========================================

// Получает логотип профиля (thumbnail версия или fallback)
$adminUserId = getAdminUserId($pdo);
$logoProfile = getFileVersionFromList($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg', $adminUserId);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Админ-панель управления системой">
    <meta name="author" content="Админ-панель">
    <title><?php echo escape($titlemeta); ?></title>
    
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
    
    <!-- Локальные стили -->
    <link rel="stylesheet" href="../css/main.css">
    <link rel="icon" href="<?php echo escape($logoProfile); ?>" type="image/x-icon">
</head>

<body>
    <div class="container-fluid">

        <!-- ========================================
             БОКОВОЕ МЕНЮ
             ======================================== -->
        <?php require_once __DIR__ . '/../template/sidebar.php'; ?>

        <!-- ========================================
             ОСНОВНОЙ КОНТЕНТ
             ======================================== -->
        <main class="main-content">

            <!-- ========================================
                 ВЕРХНЯЯ ПАНЕЛЬ
                 ======================================== -->
            <?php require_once __DIR__ . '/../template/header.php'; ?>

            <!-- ========================================
                 ДИНАМИЧЕСКИЙ КОНТЕНТ
                 ======================================== -->
            <div class="content-area">
                
                <!-- ========================================
                     ОТОБРАЖЕНИЕ СООБЩЕНИЙ
                     ======================================== -->
                <?php displayAlerts(
                    $successMessages,      // Массив сообщений об успехе
                    $errors,               // Массив сообщений об ошибках
                    true                   // Показывать сообщения как toast-уведомления true/false
                ); ?>

                <!-- ========================================
                     СИСТЕМНЫЕ УВЕДОМЛЕНИЯ
                     ======================================== -->
                <?php if (($adminData['notifications'] ?? false) === true): ?>
                    <?php
                    // Санитизация HTML-контента из WYSIWYG-редактора
                    // Защищает от XSS и некорректного HTML
                    $editor1 = sanitizeHtmlFromEditor($adminData['editor_1'] ?? '');
                    ?>
                    <div class="alert alert-primary" role="alert">
                        <i class="bi bi-info-circle"></i> Уведомления
                        <div class="m-1">
                            <?= $editor1 ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <!-- ========================================
         ПОДКЛЮЧЕНИЕ СКРИПТОВ
         ======================================== -->
    
    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    
    <!-- Модульный JS admin -->
    <script type="module" src="../js/main.js"></script>

</body>
</html>
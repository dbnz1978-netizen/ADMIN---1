<?php

/**
 * Название файла:      access.php
 * Назначение:          Страница настроек доступа к плагину "Резервное копирование" по ролям пользователей.
 *                      Управление правами доступа для роли 'user'.
 *                      Доступ к странице только для пользователей с ролью 'admin'.
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-12
 * Последнее изменение: 2026-02-12
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors'  => false,         // включение отображения ошибок true/false
    'set_encoding'    => true,          // включение кодировки UTF-8
    'db_connect'      => true,          // подключение к базе
    'auth_check'      => true,          // подключение функций авторизации
    'file_log'        => true,          // подключение системы логирования
    'display_alerts'  => true,          // подключение отображения сообщений
    'sanitization'    => true,          // подключение валидации/экранирования
    'csrf_token'      => true,          // генерация CSRF-токена
    'plugin_access'   => true,          // подключение систему управления доступом к плагинам
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../../../../admin/functions/init.php';

// Подключаем функции резервного копирования
require_once __DIR__ . '/../../functions/backup_functions.php';

// ========================================
// ПРОВЕРКА ПРАВ АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);
if ($adminData === false) {
    header("Location: ../../../../admin/logout.php");
    exit;
}


// ========================================
// НАСТРОЙКИ
// ========================================

$pluginName    = getPluginNameFromPath(__DIR__);
$titlemeta     = 'Настройки';
$titlemetah3   = 'Управление доступом к плагину "Резервное копирование"';

// Включаем/отключаем логирование
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);


// ========================================
// АВТОРИЗАЦИЯ И ПРОВЕРКА ПРАВ
// ========================================

// Используем guard для проверки доступа (только admin может менять настройки)
$userDataAdmin = pluginAccessGuard($pdo, $pluginName, 'admin');

$currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];


// ========================================
// ЗАГРУЗКА FLASH-СООБЩЕНИЙ
// ========================================

$successMessages = [];
$errors          = [];

if (!empty($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors          = $_SESSION['flash_messages']['error'] ?? [];
    unset($_SESSION['flash_messages']);
}


// ========================================
// ОБРАБОТКА POST-ЗАПРОСА (СОХРАНЕНИЕ НАСТРОЕК)
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        logEvent(
            "Попытка сохранения настроек доступа к плагину '$pluginName' с невалидным CSRF токеном — ID: {$userDataAdmin['id']} — IP: {$_SERVER['REMOTE_ADDR']}",
            LOG_ERROR_ENABLED,
            'error'
        );
        
        $_SESSION['flash_messages']['error'][] = 'Ошибка проверки безопасности. Попробуйте снова.';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Получаем значения из формы (чекбоксы)
    $allowUser = isset($_POST['allow_user']);
    
    // ========================================
    // СОХРАНЕНИЕ НАСТРОЕК
    // ========================================
    
    // Получаем текущие настройки доступа
    $accessSettings = getPluginAccessSettings($pdo);
    
    if ($accessSettings === false) {
        $accessSettings = [];
    }
    
    // Обновляем настройки для плагина
    // Примечание: доступ для роли 'admin' всегда включён по дизайну системы.
    // Это гарантирует, что администраторы всегда могут управлять плагином и его настройками.
    $accessSettings[$pluginName] = [
        'user'  => $allowUser,
        'admin' => true
    ];
    
    // Сохраняем настройки доступа
    $accessResult = savePluginAccessSettings($pdo, $accessSettings);
    
    if ($accessResult) {
        logEvent(
            "Настройки плагина '$pluginName' обновлены — user: " . ($allowUser ? 'да' : 'нет') . " — ID: {$userDataAdmin['id']}",
            LOG_INFO_ENABLED,
            'info'
        );
        
        $_SESSION['flash_messages']['success'][] = 'Настройки успешно сохранены';
    } else {
        logEvent(
            "Ошибка сохранения настроек плагина '$pluginName' — ID: {$userDataAdmin['id']}",
            LOG_ERROR_ENABLED,
            'error'
        );
        
        $_SESSION['flash_messages']['error'][] = 'Ошибка при сохранении настроек';
    }
    
    // Редирект для предотвращения повторной отправки формы
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}


// ========================================
// ЗАГРУЗКА ТЕКУЩИХ НАСТРОЕК
// ========================================

$accessSettings = getPluginAccessSettings($pdo);
if ($accessSettings === false) {
    $accessSettings = [];
}

// Получаем настройки для плагина (по умолчанию доступ запрещён для обычных пользователей)
// Для плагина резервного копирования безопаснее по умолчанию запретить доступ для роли 'user'
$allowUser = $accessSettings[$pluginName]['user'] ?? false;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($titlemeta) ?> - Админ-панель</title>

    <!-- Модуль управления светлой/тёмной темой -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../../../admin/css/main.css">
</head>
<body>
    <div class="container-fluid">
        <?php require_once __DIR__ . '/../../../../admin/template/sidebar.php'; ?>

        <!-- Основной контент -->
        <main class="main-content">
            <?php require_once __DIR__ . '/../../../../admin/template/header.php'; ?>

            <!-- Отображение сообщений -->
            <?php displayAlerts(
                $successMessages,  // Массив сообщений об успехе
                $errors,           // Массив сообщений об ошибках
                true               // Показывать сообщения как toast-уведомления
            ); 
            ?>

            <!-- Форма настроек доступа -->
            <form method="POST" action="<?= escape($_SERVER['PHP_SELF']) ?>">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                <!-- ========================================
                     НАСТРОЙКИ ДОСТУПА ПО РОЛЯМ
                     ======================================== -->
                <div class="form-section">
                    <h3 class="card-title">
                        <i class="bi bi-shield-lock"></i>
                        Настройки доступа по ролям
                    </h3>

                    <div class="mb-4">
                        <p>
                            <i class="bi bi-info-circle"></i>
                            Настройте доступ для пользователей с ролью "user" к разделам плагина "Резервное копирование".
                            При отключении доступа пользователи не увидят меню плагина и не смогут открыть страницы напрямую.
                            Администраторы (роль "admin") всегда имеют полный доступ к плагину.
                        </p>
                        <p>
                            <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                            <strong>Важно:</strong> Страница скачивания резервных копий (download_backup.php) доступна только администраторам, независимо от этих настроек.
                        </p>
                    </div>

                    <div class="row col-example-row">
                        <div class="col-6">
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input 
                                            class="form-check-input" 
                                            type="checkbox" 
                                            id="allowUser" 
                                            name="allow_user"
                                            <?= $allowUser ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label" for="allowUser">Нет/Да</label>
                                    </div>
                                    <div class="form-text">Разрешить доступ пользователям</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================================
                     КНОПКИ ДЕЙСТВИЙ
                     ======================================== -->
                <div class="mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Сохранить настройки
                    </button>
                    <a href="/plugins/backup-plugin/pages/backup.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i>
                        Отмена
                    </a>
                </div>
            </form>
        </main>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Модульный JS admin -->    
    <script type="module" src="../../../../admin/js/main.js"></script>

</body>
</html>

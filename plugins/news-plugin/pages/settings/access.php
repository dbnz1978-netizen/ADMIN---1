<?php
/**
 * Файл: /plugins/news-plugin/pages/settings/access.php
 *
 * Назначение:
 * - Страница настроек доступа к плагину "Новости" по ролям пользователей
 * - Управление правами доступа для ролей 'user' и 'admin'
 * - Доступ к странице только для пользователей с ролью 'admin'
 *
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-11
 */

// === КОНФИГУРАЦИЯ ===
$config = [
    'display_errors'  => false,         // включение отображения ошибок true/false
    'set_encoding'    => true,          // включение кодировки UTF-8
    'db_connect'      => true,          // подключение к базе
    'auth_check'      => true,          // подключение функций авторизации
    'file_log'        => true,          // подключение системы логирования
    'display_alerts'  => true,          // подключение отображения сообщений
    'sanitization'    => true,          // подключение валидации/экранирования
    'csrf_token'      => true,          // генерация CSRF-токена
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../../../../admin/functions/init.php';

// Подключаем систему управления доступом к плагинам
require_once __DIR__ . '/../../../../admin/functions/plugin_access.php';

// =============================================================================
// Проверка прав администратора
// =============================================================================
$adminData = getAdminData($pdo);
if ($adminData === false) {
    header("Location: ../../../../admin/logout.php");
    exit;
}

// === НАСТРОЙКИ ===
$titlemeta = 'Настройки доступа';
$titlemetah3 = 'Управление доступом к плагину "Новости"';

// Включаем/отключаем логирование
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

// =============================================================================
// АВТОРИЗАЦИЯ И ПРОВЕРКА ПРАВ
// =============================================================================

// Используем guard для проверки доступа (только admin может менять настройки)
$userDataAdmin = pluginAccessGuard($pdo, 'news', 'admin');

$currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

// =============================================================================
// ЗАГРУЗКА FLASH-СООБЩЕНИЙ
// =============================================================================

$successMessages = [];
$errors = [];

if (!empty($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors          = $_SESSION['flash_messages']['error'] ?? [];
    unset($_SESSION['flash_messages']);
}

// =============================================================================
// ОБРАБОТКА POST-ЗАПРОСА (СОХРАНЕНИЕ НАСТРОЕК)
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        logEvent(
            "Попытка сохранения настроек доступа к плагину 'news' с невалидным CSRF токеном — ID: {$userDataAdmin['id']} — IP: {$_SERVER['REMOTE_ADDR']}",
            LOG_ERROR_ENABLED,
            'error'
        );
        
        $_SESSION['flash_messages']['error'][] = 'Ошибка проверки безопасности. Попробуйте снова.';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Получаем значения из формы (чекбоксы)
    $allowUser = isset($_POST['allow_user']);
    $allowAdmin = isset($_POST['allow_admin']);
    
    // Получаем текущие настройки доступа
    $accessSettings = getPluginAccessSettings($pdo);
    
    if ($accessSettings === false) {
        $accessSettings = [];
    }
    
    // Обновляем настройки для плагина 'news'
    $accessSettings['news'] = [
        'user' => $allowUser,
        'admin' => $allowAdmin
    ];
    
    // Сохраняем настройки
    $result = savePluginAccessSettings($pdo, $accessSettings);
    
    if ($result) {
        logEvent(
            "Настройки доступа к плагину 'news' обновлены — user: " . ($allowUser ? 'да' : 'нет') . ", admin: " . ($allowAdmin ? 'да' : 'нет') . " — ID: {$userDataAdmin['id']}",
            LOG_INFO_ENABLED,
            'info'
        );
        
        $_SESSION['flash_messages']['success'][] = 'Настройки доступа успешно сохранены';
    } else {
        logEvent(
            "Ошибка сохранения настроек доступа к плагину 'news' — ID: {$userDataAdmin['id']}",
            LOG_ERROR_ENABLED,
            'error'
        );
        
        $_SESSION['flash_messages']['error'][] = 'Ошибка при сохранении настроек';
    }
    
    // Редирект для предотвращения повторной отправки формы
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// =============================================================================
// ЗАГРУЗКА ТЕКУЩИХ НАСТРОЕК
// =============================================================================

$accessSettings = getPluginAccessSettings($pdo);
if ($accessSettings === false) {
    $accessSettings = [];
}

// Получаем настройки для плагина 'news' (по умолчанию доступ разрешён)
$allowUser = $accessSettings['news']['user'] ?? true;
$allowAdmin = $accessSettings['news']['admin'] ?? true;

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
    <link rel="stylesheet" href="/admin/css/main.css">
</head>
<body>
    <div class="container-fluid">
        <?php require_once __DIR__ . '/../../../../admin/template/sidebar.php'; ?>

        <!-- Основной контент -->
        <main class="main-content">
            <?php require_once __DIR__ . '/../../../../admin/template/header.php'; ?>

            <!-- Контейнер страницы -->
            <div class="page-container">
                
                <!-- Заголовок страницы -->
                <div class="page-header">
                    <div>
                        <h1><?= escape($titlemeta) ?></h1>
                        <p class="page-subtitle"><?= escape($titlemetah3) ?></p>
                    </div>
                    <div class="page-actions">
                        <a href="/plugins/news-plugin/pages/articles/article_list.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i>
                            Назад к новостям
                        </a>
                    </div>
                </div>

                <!-- Вывод сообщений -->
                <?php displayAlerts($successMessages, $errors); ?>

                <!-- Форма настроек доступа -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-shield-lock"></i>
                            Настройки доступа по ролям
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="<?= escape($_SERVER['PHP_SELF']) ?>">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                            <div class="mb-4">
                                <p class="text-muted">
                                    <i class="bi bi-info-circle"></i>
                                    Настройте, какие роли пользователей имеют доступ к разделам плагина "Новости".
                                    При отключении доступа пользователи не увидят меню плагина и не смогут открыть страницы напрямую.
                                </p>
                            </div>

                            <!-- Настройка доступа для роли 'user' -->
                            <div class="mb-4">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="form-check form-switch">
                                            <input 
                                                class="form-check-input" 
                                                type="checkbox" 
                                                role="switch" 
                                                id="allowUser" 
                                                name="allow_user"
                                                <?= $allowUser ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label" for="allowUser">
                                                <strong>Разрешить доступ пользователям (роль: user)</strong>
                                                <div class="text-muted small mt-1">
                                                    Пользователи с ролью "user" смогут просматривать и редактировать новости
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Настройка доступа для роли 'admin' -->
                            <div class="mb-4">
                                <div class="card">
                                    <div class="card-body">
                                        <div class="form-check form-switch">
                                            <input 
                                                class="form-check-input" 
                                                type="checkbox" 
                                                role="switch" 
                                                id="allowAdmin" 
                                                name="allow_admin"
                                                <?= $allowAdmin ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label" for="allowAdmin">
                                                <strong>Разрешить доступ администраторам (роль: admin)</strong>
                                                <div class="text-muted small mt-1">
                                                    Пользователи с ролью "admin" смогут просматривать и редактировать новости
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Кнопки действий -->
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i>
                                    Сохранить настройки
                                </button>
                                <a href="/plugins/news-plugin/pages/articles/article_list.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-circle"></i>
                                    Отмена
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/admin/js/main.js"></script>
</body>
</html>

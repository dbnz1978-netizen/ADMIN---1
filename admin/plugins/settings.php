<?php

/**
 * Название файла:      settings.php
 * Назначение:          Страница настроек отдельного плагина в админ-панели
 *                      Основные функции:
 *                      - Настройка включения/выключения плагина
 *                      - Настройка удаления таблиц плагина при удалении
 *                      - Отображение информации о плагине
 *                      Особенности:
 *                      - Доступ только для авторизованных администраторов (author = 'admin')
 *                      - Поддержка темной/светлой темы через localStorage
 *                      - Все входные данные экранируются перед выводом
 *                      - Защита от CSRF-атак
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
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
    'plugin_manager'  => true,          // подключение менеджера плагинов
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

// Получаем настройки администратора
$adminData = getAdminData($pdo);

if ($adminData === false) {
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ ИНТЕРФЕЙСА
// ========================================

$titlemeta = 'Настройки плагина';  // Название страницы

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);  // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки true/false

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ И ПОЛУЧЕНИЕ ДАННЫХ АДМИНИСТРАТОРА
// ========================================

try {
    // Проверка авторизации
    $user = requireAuth($pdo);
    
    if (!$user) {
        $redirectTo = '../logout.php';
        logEvent(
            "Неавторизованный доступ — перенаправление на: $redirectTo — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}",
            LOG_INFO_ENABLED,
            'info'
        );
        
        header("Location: $redirectTo");
        exit;
    }
    
    // Получение данных пользователя
    $userDataAdmin = getUserData($pdo, $user['id']);
    
    // Проверка ошибки
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg        = $userDataAdmin['message'];
        $level      = $userDataAdmin['level']; // 'info' или 'error'
        $logEnabled = match ($level) {
            'info'  => LOG_INFO_ENABLED,
            'error' => LOG_ERROR_ENABLED,
            default => LOG_ERROR_ENABLED
        };
        
        logEvent($msg, $logEnabled, $level);
        
        header("Location: ../logout.php");
        exit;
    }
    
    // Закрываем страницу от user
    // Перенаправляем на страницу входа если не Admin
    if ($userDataAdmin['author'] !== 'admin') {
        header("Location: ../logout.php");
        exit;
    }
    
} catch (Exception $e) {
    logEvent(
        "Ошибка при инициализации админ-панели: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        LOG_ERROR_ENABLED,
        'error'
    );
    
    header("Location: ../logout.php");
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ ИНФОРМАЦИИ О ПЛАГИНЕ
// ========================================

$pluginName = $_GET['plugin'] ?? '';

if (empty($pluginName)) {
    header("Location: list.php");
    exit;
}

$plugin = getPlugin($pdo, $pluginName);

if (!$plugin) {
    header("Location: list.php");
    exit;
}

if (!$plugin['is_installed']) {
    header("Location: list.php");
    exit;
}

// Сообщения об ошибках и успехе
$successMessages = [];
$errors          = [];

// ========================================
// ОБРАБОТКА ФОРМЫ НАСТРОЕК
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Пожалуйста, обновите страницу.';
        logEvent(
            "Проверка CSRF-токена не пройдена — ID администратора: " . $user['id'] .
            " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            LOG_ERROR_ENABLED,
            'error'
        );
    } else {
        // Обработка действия включения/выключения
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            
            if ($action === 'enable') {
                $result = enablePlugin($pdo, $pluginName);
            } elseif ($action === 'disable') {
                $result = disablePlugin($pdo, $pluginName);
            } else {
                $result = ['success' => false, 'message' => 'Неизвестное действие'];
            }
            
            if ($result['success']) {
                $successMessages[] = $result['message'];
                // Обновляем данные плагина
                $plugin = getPlugin($pdo, $pluginName);
            } else {
                $errors[] = $result['message'];
            }
        }
        
        // Обработка настройки "Удалять таблицы при удалении"
        // Проверяем, что это форма настроек (не enable/disable), проверив отсутствие action
        if (!isset($_POST['action'])) {
            // Если checkbox отмечен, $_POST['delete_tables_on_uninstall'] будет равен '1'
            // Если checkbox не отмечен, $_POST['delete_tables_on_uninstall'] не будет установлен
            $deleteTables = isset($_POST['delete_tables_on_uninstall']) && $_POST['delete_tables_on_uninstall'] === '1';
            $result = updatePluginDeleteTablesOption($pdo, $pluginName, $deleteTables);
            
            if ($result['success']) {
                $successMessages[] = $result['message'];
                // Обновляем данные плагина
                $plugin = getPlugin($pdo, $pluginName);
            } else {
                $errors[] = $result['message'];
            }
        }
    }
    
    // ========================================
    // СОХРАНЕНИЕ СООБЩЕНИЙ В СЕССИЮ И ПЕРЕНАПРАВЛЕНИЕ
    // ========================================
    
    if (!empty($errors) || !empty($successMessages)) {
        $_SESSION['flash_messages'] = [
            'success' => $successMessages,
            'error'   => $errors
        ];
    }
    
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// ========================================
// ИЗВЛЕЧЕНИЕ FLASH-СООБЩЕНИЙ
// ========================================

if (isset($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors          = $_SESSION['flash_messages']['error'] ?? [];
    unset($_SESSION['flash_messages']);
}

// ========================================
// ПОЛУЧЕНИЕ ЛОГОТИПА
// ========================================

// Получает логотип
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
    <title><?= escape($titlemeta . ' - ' . $plugin['display_name']) ?></title>
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
        <!-- Боковое меню -->
        <?php require_once __DIR__ . '/../template/sidebar.php'; ?>

        <!-- Основной контент -->
        <main class="main-content">
            <!-- Верхняя панель -->
            <?php require_once __DIR__ . '/../template/header.php'; ?>

            <!-- ========================================================================= -->
            <!-- HTML ИНТЕРФЕЙС СТРАНИЦЫ -->
            <!-- ========================================================================= -->

            <div class="content-card">
                <!-- Заголовок страницы -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="card-title mb-0">
                        <i class="bi bi-gear"></i>
                        <?= escape('Настройки плагина: ' . $plugin['display_name']) ?>
                    </h3>
                    <a href="list.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Назад к списку
                    </a>
                </div>

                <!-- Отображение сообщений -->
                <?php displayAlerts(
                    $successMessages,  // Массив сообщений об успехе
                    $errors,           // Массив сообщений об ошибках
                    false              // Показывать сообщения как toast-уведомления true/false
                ); 
                ?>

                <!-- Информация о плагине -->
                <div class="bordered-card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Информация о плагине</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <strong>Название:</strong>
                            </div>
                            <div class="col-md-9">
                                <?= escape($plugin['display_name']) ?>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <strong>Идентификатор:</strong>
                            </div>
                            <div class="col-md-9">
                                <code><?= escape($plugin['name']) ?></code>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <strong>Версия:</strong>
                            </div>
                            <div class="col-md-9">
                                <span class="badge bg-info"><?= escape($plugin['version']) ?></span>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <strong>Автор:</strong>
                            </div>
                            <div class="col-md-9">
                                <?= escape($plugin['author']) ?>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <strong>Описание:</strong>
                            </div>
                            <div class="col-md-9">
                                <?= escape($plugin['description'] ?: 'Нет описания') ?>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Статус:</strong>
                            </div>
                            <div class="col-md-9">
                                <?php if ($plugin['is_enabled']): ?>
                                    <span class="badge bg-success">Включен</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Выключен</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Основные настройки -->
                <div class="bordered-card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Основные настройки</h5>
                    </div>
                    <div class="card-body">
                        <!-- Включение/выключение плагина -->
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <strong>Состояние плагина:</strong>
                            </div>
                            <div class="col-md-9">
                                <?php if ($plugin['is_enabled']): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="disable">
                                        <button type="submit" class="btn btn-warning">
                                            <i class="bi bi-pause-circle"></i> Выключить плагин
                                        </button>
                                    </form>
                                    <div>
                                        <small>Плагин в данный момент активен и работает</small>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="enable">
                                        <button type="submit" class="btn btn-success">
                                            <i class="bi bi-play-circle"></i> Включить плагин
                                        </button>
                                    </form>
                                    <div>
                                        <small>Плагин отключен и не работает</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Дополнительные настройки -->
                <div class="bordered-card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Дополнительные настройки</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            
                            <!-- Удалять таблицы при удалении -->
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" 
                                               type="checkbox" 
                                               role="switch" 
                                               id="deleteTablesSwitch" 
                                               name="delete_tables_on_uninstall" 
                                               value="1"
                                               <?= $plugin['delete_tables_on_uninstall'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="deleteTablesSwitch">
                                            <strong>Удалять таблицы плагина при полном удалении</strong>
                                        </label>
                                    </div>
                                    <p>
                                        <small>
                                            <i class="bi bi-exclamation-triangle text-warning"></i>
                                            Внимание: При включении этой опции все таблицы и данные плагина будут безвозвратно удалены при удалении плагина
                                        </small>
                                    </p>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-lg"></i> Сохранить настройки
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Опасная зона -->
                <div class="bordered-card mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-exclamation-triangle"></i> Опасная зона
                        </h5>
                    </div>
                    <div class="card-body">
                        <p>Удаление плагина приведет к удалению всех его настроек из базы данных.</p>
                        <?php if ($plugin['delete_tables_on_uninstall']): ?>
                            <p class="text-danger">
                                <strong>Внимание!</strong> Также будут удалены все таблицы и данные плагина, так как включена опция "Удалять таблицы при удалении".
                            </p>
                        <?php endif; ?>
                        <a href="list.php" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Перейти к удалению плагина
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Модульный JS admin -->
    <script type="module" src="../js/main.js"></script>
</body>
</html>

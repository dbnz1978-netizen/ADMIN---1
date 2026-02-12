<?php

/**
 * Название файла:      list.php
 * Назначение:          Страница списка плагинов в админ-панели с возможностью:
 *                      - Просмотра всех доступных плагинов (сканирование /plugins)
 *                      - Отображения статуса плагинов (установлен/не установлен, включен/выключен)
 *                      - Установки/удаления плагинов
 *                      - Включения/выключения плагинов
 *                      - Перехода на страницу настроек плагина
 *                      Особенности:
 *                      - Доступ только для авторизованных администраторов (author = 'admin')
 *                      - Плагины сканируются из директории /plugins
 *                      - Информация о плагинах читается из plugin.json
 *                      - Поддержка темной/светлой темы через localStorage
 *                      - Все входные данные экранируются перед выводом
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

$titlemeta = 'Плагины';  // Название страницы

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
    
    // Декодируем JSON-данные администратора
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];
    
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
// ОБРАБОТКА ЗАГРУЗКИ ПЛАГИНА
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Пожалуйста, обновите страницу.';
        logEvent(
            "Проверка CSRF-токена не пройдена при загрузке плагина — ID администратора: " . $user['id'] .
            " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            LOG_ERROR_ENABLED,
            'error'
        );
    } else {
        if (isset($_FILES['plugin_file'])) {
            $result = uploadPlugin($pdo, $_FILES['plugin_file']);
            
            if ($result['success']) {
                $successMessages[] = $result['message'];
            } else {
                $errors[] = $result['message'];
            }
        } else {
            $errors[] = 'Файл не был загружен';
        }
    }
}

// ========================================
// ОБРАБОТКА ДЕЙСТВИЙ С ПЛАГИНАМИ
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['plugin_name'])) {
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
        $action     = $_POST['action'];
        $pluginName = $_POST['plugin_name'];
        
        switch ($action) {
            case 'install':
                $result = installPlugin($pdo, $pluginName);
                break;
                
            case 'uninstall':
                $deleteFiles = isset($_POST['delete_files']) && $_POST['delete_files'] == '1';
                $result = uninstallPlugin($pdo, $pluginName, $deleteFiles);
                break;
                
            case 'enable':
                $result = enablePlugin($pdo, $pluginName);
                break;
                
            case 'disable':
                $result = disablePlugin($pdo, $pluginName);
                break;
                
            default:
                $result = ['success' => false, 'message' => 'Неизвестное действие'];
        }
        
        if ($result['success']) {
            $successMessages[] = $result['message'];
        } else {
            $errors[] = $result['message'];
        }
    }
}

// ========================================
// ПОЛУЧЕНИЕ СПИСКА ПЛАГИНОВ
// ========================================

$plugins = scanPlugins($pdo);

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
    <title><?= escape($titlemeta) ?></title>
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

            <div class="content-card table-card">
                <!-- Заголовок страницы с иконкой -->
                <h3 class="card-title">
                    <i class="bi bi-plugin"></i>
                    <?= escape('Список плагинов') ?>
                </h3>

                <!-- Отображение сообщений -->
                <?php displayAlerts(
                    $successMessages,  // Массив сообщений об успехе
                    $errors,           // Массив сообщений об ошибках
                    true               // Показывать сообщения как toast-уведомления true/false
                ); 
                ?>

                <!-- Информация о количестве плагинов -->
                <div class="mb-3 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge bg-secondary">Всего плагинов: <?= count($plugins) ?></span>
                        <span class="badge bg-success">Установлено: <?= count(array_filter($plugins, fn($p) => $p['is_installed'])) ?></span>
                        <span class="badge bg-primary">Включено: <?= count(array_filter($plugins, fn($p) => $p['is_enabled'])) ?></span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadPluginModal">
                            <i class="bi bi-upload"></i> Загрузить плагин
                        </button>
                    </div>
                </div>

                <!-- Таблица с данными плагинов -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Плагин</th>
                                <th>Описание</th>
                                <th>Версия</th>
                                <th>Автор</th>
                                <th>Статус</th>
                                <th width="200">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($plugins)): ?>
                                <!-- Сообщение при отсутствии плагинов -->
                                <tr>
                                    <td colspan="6" class="text-center py-4">
                                        <i class="bi bi-plugin display-4"></i>
                                        <p class="mt-2 auth-subtitle">
                                            <?= escape('Плагины не найдены') ?>
                                        </p>
                                        <p class="auth-subtitle">
                                            <?= escape('Добавьте плагины в директорию /plugins') ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <!-- Цикл отображения данных каждого плагина -->
                                <?php foreach ($plugins as $plugin): ?>
                                <tr>
                                    <!-- Название плагина -->
                                    <td>
                                        <strong><?= escape($plugin['display_name']) ?></strong>
                                        <br>
                                        <?= escape($plugin['name']) ?>
                                    </td>

                                    <!-- Описание плагина -->
                                    <td>
                                        <?= escape($plugin['description'] ?: 'Нет описания') ?>
                                    </td>

                                    <!-- Версия плагина -->
                                    <td>
                                        <span class="badge bg-info"><?= escape($plugin['version']) ?></span>
                                    </td>

                                    <!-- Автор плагина -->
                                    <td>
                                        <?= escape($plugin['author']) ?>
                                    </td>

                                    <!-- Статус плагина -->
                                    <td>
                                        <?php if (!$plugin['is_installed']): ?>
                                            <span class="badge bg-secondary">Не установлен</span>
                                        <?php elseif ($plugin['is_enabled']): ?>
                                            <span class="badge bg-success">Включен</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Выключен</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Кнопки действий -->
                                    <td>
                                        <div class="btn-group" style="gap: 4px;">
                                            <?php if (!$plugin['is_installed']): ?>
                                                <!-- Кнопка установки -->
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="install">
                                                    <input type="hidden" name="plugin_name" value="<?= escape($plugin['name']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-success" title="Установить">
                                                        <i class="bi bi-download"></i> Установить
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <?php if ($plugin['is_enabled']): ?>
                                                    <!-- Кнопка выключения -->
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="disable">
                                                        <input type="hidden" name="plugin_name" value="<?= escape($plugin['name']) ?>">
                                                        <button type="submit" class="btn btn-sm btn-warning" title="Выключить">
                                                            <i class="bi bi-pause-circle"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <!-- Кнопка включения -->
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                        <input type="hidden" name="action" value="enable">
                                                        <input type="hidden" name="plugin_name" value="<?= escape($plugin['name']) ?>">
                                                        <button type="submit" class="btn btn-sm btn-success" title="Включить">
                                                            <i class="bi bi-play-circle"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <!-- Кнопка настроек -->
                                                <a href="settings.php?plugin=<?= urlencode($plugin['name']) ?>" 
                                                   class="btn btn-sm btn-outline-primary" 
                                                   title="Настройки">
                                                    <i class="bi bi-gear"></i>
                                                </a>
                                                
                                                <!-- Кнопка удаления -->
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-danger delete-plugin" 
                                                        data-plugin-name="<?= escape($plugin['name']) ?>"
                                                        data-plugin-display-name="<?= escape($plugin['display_name']) ?>"
                                                        title="Удалить">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Модальное окно загрузки плагина -->
    <div class="modal fade" id="uploadPluginModal" tabindex="-1" aria-labelledby="uploadPluginModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadPluginModalLabel">
                        <i class="bi bi-upload"></i> Загрузить плагин
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="uploadPluginForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="upload">
                        
                        <div class="mb-3">
                            <label for="pluginFile" class="form-label">
                                <strong>Выберите ZIP-архив с плагином:</strong>
                            </label>
                            <input type="file" 
                                   class="form-control" 
                                   id="pluginFile" 
                                   name="plugin_file" 
                                   accept=".zip"
                                   required>
                        </div>
                        
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle"></i>
                            <strong>Требования:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Формат файла: ZIP-архив</li>
                                <li>Максимальный размер: 10 MB</li>
                                <li>Архив должен содержать файл plugin.json</li>
                                <li>Плагин будет установлен с отключенным статусом</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload"></i> Загрузить
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальное окно подтверждения удаления -->
    <div class="modal fade" id="deletePluginModal" tabindex="-1" aria-labelledby="deletePluginModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deletePluginModalLabel">Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Вы действительно хотите удалить плагин "<span id="pluginDisplayName"></span>"?</p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="deleteFilesCheckbox" name="delete_files" value="1">
                        <label class="form-check-label" for="deleteFilesCheckbox">
                            Удалить файлы плагина с диска
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <form method="POST" id="deletePluginForm">
                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="uninstall">
                        <input type="hidden" name="plugin_name" id="deletePluginName">
                        <input type="hidden" name="delete_files" id="deleteFilesInput" value="0">
                        <button type="submit" class="btn btn-danger">Удалить</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Модульный JS admin -->
    <script type="module" src="../js/main.js"></script>
    
    <script>
        // Обработка кнопки удаления плагина
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButtons = document.querySelectorAll('.delete-plugin');
            const modal = new bootstrap.Modal(document.getElementById('deletePluginModal'));
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const pluginName = this.getAttribute('data-plugin-name');
                    const pluginDisplayName = this.getAttribute('data-plugin-display-name');
                    
                    document.getElementById('deletePluginName').value = pluginName;
                    document.getElementById('pluginDisplayName').textContent = pluginDisplayName;
                    
                    modal.show();
                });
            });
            
            // Обработка чекбокса "Удалить файлы с диска"
            document.getElementById('deleteFilesCheckbox').addEventListener('change', function() {
                document.getElementById('deleteFilesInput').value = this.checked ? '1' : '0';
            });
        });
    </script>
</body>
</html>

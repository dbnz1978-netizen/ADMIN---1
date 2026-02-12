<?php

/**
 * Название файла:      backup.php
 * Назначение:          Страница создания резервной копии сайта
 *                      - Выбор таблиц БД для резервного копирования
 *                      - Выбор папок для резервного копирования
 *                      - Создание ZIP-архива с данными и установщиком
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-12
 * Последнее изменение: 2026-02-12
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors'  => false,
    'set_encoding'    => true,
    'db_connect'      => true,
    'auth_check'      => true,
    'file_log'        => true,
    'display_alerts'  => true,
    'sanitization'    => true,
    'start_session'   => true,
    'csrf_token'      => true,
    'plugin_access'   => true,
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../../../admin/functions/init.php';

// ========================================
// ПРОВЕРКА ПРАВ АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);
if ($adminData === false) {
    header("Location: ../../../admin/logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ
// ========================================

$titlemeta = 'Резервное копирование';

// Включаем/отключаем логирование
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

// ========================================
// ПРОВЕРКА ДОСТУПА К ПЛАГИНУ
// ========================================

// Автоматическое определение имени плагина
function getPluginName() {
    $path = __DIR__;
    $parts = explode('/', str_replace('\\', '/', $path));
    foreach ($parts as $i => $part) {
        if ($part === 'plugins' && isset($parts[$i + 1])) {
            return $parts[$i + 1];
        }
    }
    return 'unknown';
}

$pluginName = getPluginName();
$userDataAdmin = pluginAccessGuard($pdo, $pluginName);
$currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

// ========================================
// ОБРАБОТКА СОЗДАНИЯ РЕЗЕРВНОЙ КОПИИ
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_backup') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Пожалуйста, обновите страницу.';
    } else {
        // Получаем выбранные таблицы и папки
        $selectedTables = $_POST['tables'] ?? [];
        $selectedFolders = $_POST['folders'] ?? [];
        
        // Подключаем функцию создания резервной копии
        require_once __DIR__ . '/../functions/backup_functions.php';
        
        $result = createBackup($pdo, $selectedTables, $selectedFolders);
        
        if ($result['success']) {
            $successMessages[] = $result['message'];
            if (isset($result['download_url'])) {
                $downloadUrl = $result['download_url'];
            }
        } else {
            $errors[] = $result['message'];
        }
    }
}

// ========================================
// ПОЛУЧЕНИЕ СПИСКА ТАБЛИЦ БД
// ========================================

try {
    $stmt = $pdo->query("SHOW TABLES");
    $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $errors[] = 'Ошибка при получении списка таблиц: ' . $e->getMessage();
    $allTables = [];
}

// ========================================
// ПОЛУЧЕНИЕ СПИСКА ПАПОК
// ========================================

$rootPath = realpath(__DIR__ . '/../../../../');
$allFolders = [];

try {
    $items = scandir($rootPath);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $itemPath = $rootPath . '/' . $item;
        if (is_dir($itemPath)) {
            $allFolders[] = $item;
        }
    }
} catch (Exception $e) {
    $errors[] = 'Ошибка при получении списка папок: ' . $e->getMessage();
}

// ========================================
// ПОЛУЧЕНИЕ ЛОГОТИПА
// ========================================

$adminUserId = getAdminUserId($pdo);
$logoProfile = getFileVersionFromList($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', '../../../admin/img/avatar.svg', $adminUserId);

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Резервное копирование сайта">
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
    <link rel="stylesheet" href="../../../admin/css/main.css">
    <link rel="icon" href="<?php echo escape($logoProfile); ?>" type="image/x-icon">
</head>
<body>
    <div class="container-fluid">
        <!-- Боковое меню -->
        <?php require_once __DIR__ . '/../../../admin/template/sidebar.php'; ?>

        <!-- Основной контент -->
        <main class="main-content">
            <!-- Верхняя панель -->
            <?php require_once __DIR__ . '/../../../admin/template/header.php'; ?>

            <!-- Основной контент страницы -->
            <div class="content-card">
                <h3 class="card-title">
                    <i class="bi bi-database-add"></i>
                    <?= escape('Создание резервной копии') ?>
                </h3>

                <!-- Отображение сообщений -->
                <?php displayAlerts(
                    $successMessages ?? [],
                    $errors ?? [],
                    true
                ); ?>

                <?php if (isset($downloadUrl)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i>
                    Резервная копия успешно создана!
                    <a href="<?= escape($downloadUrl) ?>" class="btn btn-sm btn-success ms-3" download>
                        <i class="bi bi-download"></i> Скачать архив
                    </a>
                </div>
                <?php endif; ?>

                <form method="POST" id="backupForm">
                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="create_backup">

                    <!-- Выбор таблиц БД -->
                    <div class="mb-4">
                        <h4 class="mb-3">
                            <i class="bi bi-table"></i> Таблицы базы данных
                        </h4>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="selectAllTables" checked>
                            <label class="form-check-label fw-bold" for="selectAllTables">
                                Выбрать все таблицы
                            </label>
                        </div>
                        <div class="row">
                            <?php foreach ($allTables as $table): ?>
                            <div class="col-md-4 col-sm-6 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input table-checkbox" 
                                           type="checkbox" 
                                           name="tables[]" 
                                           value="<?= escape($table) ?>" 
                                           id="table_<?= escape($table) ?>" 
                                           checked>
                                    <label class="form-check-label" for="table_<?= escape($table) ?>">
                                        <?= escape($table) ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Выбор папок -->
                    <div class="mb-4">
                        <h4 class="mb-3">
                            <i class="bi bi-folder"></i> Папки сайта
                        </h4>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Папка <code>admin</code> будет автоматически включена в резервную копию.
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="selectAllFolders" checked>
                            <label class="form-check-label fw-bold" for="selectAllFolders">
                                Выбрать все папки
                            </label>
                        </div>
                        <div class="row">
                            <?php foreach ($allFolders as $folder): ?>
                            <?php if ($folder === 'admin') continue; // Пропускаем admin, он будет добавлен автоматически ?>
                            <div class="col-md-4 col-sm-6 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input folder-checkbox" 
                                           type="checkbox" 
                                           name="folders[]" 
                                           value="<?= escape($folder) ?>" 
                                           id="folder_<?= escape($folder) ?>" 
                                           checked>
                                    <label class="form-check-label" for="folder_<?= escape($folder) ?>">
                                        <?= escape($folder) ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Кнопка создания резервной копии -->
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-archive"></i> Создать резервную копию
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Модульный JS admin -->
    <script type="module" src="../../../admin/js/main.js"></script>
    
    <script>
        // Обработка чекбокса "Выбрать все таблицы"
        document.getElementById('selectAllTables').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.table-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Обработка чекбокса "Выбрать все папки"
        document.getElementById('selectAllFolders').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.folder-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Синхронизация состояния чекбокса "Выбрать все"
        document.querySelectorAll('.table-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allCheckboxes = document.querySelectorAll('.table-checkbox');
                const allChecked = Array.from(allCheckboxes).every(cb => cb.checked);
                document.getElementById('selectAllTables').checked = allChecked;
            });
        });

        document.querySelectorAll('.folder-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allCheckboxes = document.querySelectorAll('.folder-checkbox');
                const allChecked = Array.from(allCheckboxes).every(cb => cb.checked);
                document.getElementById('selectAllFolders').checked = allChecked;
            });
        });
    </script>
</body>
</html>

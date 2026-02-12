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
    'display_errors'  => true,         // включение отображения ошибок true/false
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

// Используем централизованную функцию из backup_functions.php
$pluginName = getPluginNameFromPath(__DIR__);
$userDataAdmin = pluginAccessGuard($pdo, $pluginName);
$currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

// ========================================
// ПОДКЛЮЧЕНИЕ ФУНКЦИЙ РЕЗЕРВНОГО КОПИРОВАНИЯ
// ========================================

require_once __DIR__ . '/../functions/backup_functions.php';

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
        
        $result = createBackup($pdo, $selectedTables, $selectedFolders);
        
        if ($result['success']) {
            // Используем POST-Redirect-GET паттерн для предотвращения повторной отправки формы
            // При обновлении страницы (F5 или reload после удаления) не будет повторного создания резервной копии
            $backupFile = $result['backup_file'];
            header("Location: backup.php?backup_created=" . urlencode($backupFile));
            exit;
        } else {
            $errors[] = $result['message'];
        }
    }
}

// Обработка успешного создания резервной копии после редиректа
if (isset($_GET['backup_created']) && !empty($_GET['backup_created'])) {
    $backupFile = basename($_GET['backup_created']); // basename() предотвращает атаки обхода директорий, удаляя путь из имени файла
    if (isValidBackupFileName($backupFile)) {
        $successMessages[] = 'Резервная копия успешно создана!';
    }
}

// ========================================
// ПОЛУЧЕНИЕ СПИСКА СУЩЕСТВУЮЩИХ РЕЗЕРВНЫХ КОПИЙ
// ========================================

$backupsList = getBackupsList();

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

$rootPath = realpath(__DIR__ . '/../../../');
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

                <?php if (isset($backupFile)): ?>
                <div class="alert alert-success" data-backup-created-alert>
                    <i class="bi bi-check-circle"></i>
                    Резервная копия успешно создана!
                    <?php if ($userDataAdmin['author'] === 'admin'): ?>
                    <a href="download_backup.php?file=<?= escape(urlencode($backupFile)) ?>" class="btn btn-sm btn-success ms-3">
                        <i class="bi bi-download"></i> Скачать архив
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Список существующих резервных копий -->
                <?php if (!empty($backupsList)): ?>
                <div class="mb-4">
                    <h4 class="mb-3">
                        <i class="bi bi-archive"></i> Существующие резервные копии
                    </h4>
                    <div class="table-responsive table-card">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Имя файла</th>
                                    <th>Дата создания</th>
                                    <th>Размер</th>
                                    <th class="text-end">Действия</th>
                                </tr>
                            </thead>
                            <tbody id="backupsTableBody">
                                <?php foreach ($backupsList as $backup): ?>
                                <tr data-backup-file="<?= escape($backup['name']) ?>">
                                    <td>
                                        <i class="bi bi-file-earmark-zip"></i>
                                        <?= escape($backup['name']) ?>
                                    </td>
                                    <td>
                                        <?= date('d.m.Y H:i:s', $backup['date']) ?>
                                    </td>
                                    <td>
                                        <?= formatFileSize($backup['size']) ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($userDataAdmin['author'] === 'admin'): ?>
                                        <a href="download_backup.php?file=<?= escape(urlencode($backup['name'])) ?>" 
                                           class="btn btn-sm btn-outline-primary"
                                           title="Скачать">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-danger delete-backup" 
                                                data-file="<?= escape($backup['name']) ?>"
                                                title="Удалить">
                                            <i class="bi bi-trash"></i> 
                                        </button>
                                        <?php else: ?>
                                        <span class="text-muted small">Только для администраторов</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <hr class="my-4">

                <h3 class="card-title mb-4">
                    <i class="bi bi-plus-circle"></i>
                    <?= escape('Создать новую резервную копию') ?>
                </h3>

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
                            Папки <code>admin | connect | plugins</code> будут автоматически включены в резервную копию.
                            <br><small>Примечание: учетные данные базы данных в <code>connect/db.php</code> будут очищены для безопасности.</small>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="selectAllFolders" checked>
                            <label class="form-check-label fw-bold" for="selectAllFolders">
                                Выбрать все папки
                            </label>
                        </div>
                        <div class="row">
                            <?php foreach ($allFolders as $folder): ?>
                            <?php if ($folder === 'admin' || $folder === 'connect' || $folder === 'plugins') continue; // Пропускаем admin и connect и plugins, они будут добавлены автоматически ?>
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
        // Функция для отображения toast-уведомлений
        function showToast(message, type = 'success') {
            const container = document.createElement('div');
            container.className = 'alert-toast-container position-fixed top-0 start-50 translate-middle-x mt-3';
            container.style.zIndex = '9999';
            
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.setAttribute('role', 'alert');
            alert.setAttribute('data-auto-close', '3000');
            
            const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
            const title = type === 'success' ? 'Успешно:' : 'Ошибка:';
            
            alert.innerHTML = `
                <i class="bi ${icon}" aria-hidden="true"></i><strong> ${title}</strong>
                <div class="alert-content">${message}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
            `;
            
            container.appendChild(alert);
            document.body.appendChild(container);
            
            // Автоматическое закрытие через 3 секунды
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => {
                    container.remove();
                }, 500);
            }, 3000);
            
            // Обработка ручного закрытия
            const closeBtn = alert.querySelector('.btn-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => {
                        container.remove();
                    }, 500);
                });
            }
        }
        
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

        // Обработка удаления резервной копии с использованием делегирования событий
        // Это позволяет обрабатывать кнопки, добавленные динамически после загрузки страницы
        document.addEventListener('click', function(event) {
            // Проверяем, был ли клик по кнопке удаления или её дочернему элементу
            const deleteButton = event.target.closest('.delete-backup');
            if (!deleteButton) return;
            
            const fileName = deleteButton.dataset.file;
            
            if (!confirm('Вы действительно хотите удалить резервную копию "' + fileName + '"? Это действие нельзя отменить.')) {
                return;
            }
            
            // Отключаем кнопку на время запроса
            deleteButton.disabled = true;
            const originalContent = deleteButton.innerHTML;
            deleteButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Удаление...';
            
            const formData = new FormData();
            formData.append('file', fileName);
            formData.append('csrf_token', '<?= escape($_SESSION['csrf_token']) ?>');
            
            fetch('delete_backup.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Удаляем GET параметр backup_created из URL
                    const url = new URL(window.location);
                    url.searchParams.delete('backup_created');
                    window.history.replaceState({}, '', url);
                    
                    // Скрываем alert о успешном создании резервной копии, если он есть
                    const backupCreatedAlert = document.querySelector('[data-backup-created-alert]');
                    if (backupCreatedAlert) {
                        backupCreatedAlert.style.transition = 'opacity 0.3s ease';
                        backupCreatedAlert.style.opacity = '0';
                        setTimeout(() => backupCreatedAlert.remove(), 300);
                    }
                    
                    // Находим строку таблицы и удаляем её с анимацией
                    // Используем CSS.escape для безопасного экранирования имени файла
                    const escapedFileName = CSS.escape ? CSS.escape(fileName) : fileName.replace(/["\\]/g, '\\$&');
                    const row = document.querySelector('tr[data-backup-file="' + escapedFileName + '"]');
                    if (row) {
                        row.style.transition = 'opacity 0.3s ease';
                        row.style.opacity = '0';
                        setTimeout(() => {
                            row.remove();
                            
                            // Проверяем, остались ли строки в таблице
                            const tbody = document.getElementById('backupsTableBody');
                            if (tbody && tbody.children.length === 0) {
                                // Если строк не осталось, перезагружаем страницу для скрытия таблицы
                                location.reload();
                            }
                        }, 300);
                    }
                    
                    // Показываем toast-уведомление об успехе
                    showToast(data.message, 'success');
                } else {
                    // Показываем toast-уведомление об ошибке
                    showToast('Ошибка: ' + data.message, 'danger');
                    // Восстанавливаем кнопку при ошибке
                    deleteButton.disabled = false;
                    deleteButton.innerHTML = originalContent;
                }
            })
            .catch(error => {
                // Показываем toast-уведомление об ошибке
                showToast('Ошибка при удалении файла: ' + error, 'danger');
                // Восстанавливаем кнопку при ошибке
                deleteButton.disabled = false;
                deleteButton.innerHTML = originalContent;
            });
        });
    </script>
</body>
</html>

<?php
/**
 * Файл: /admin/user/accounts_list.php
 * 
 * Админ-панель - Список пользователей
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

// Подключаем системные компоненты
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования
require_once __DIR__ . '/../functions/jsondata.php';                 // Обновление JSON данных пользователя
require_once __DIR__ . '/../functions/display_alerts.php';           // Отображения сообщений
require_once __DIR__ . '/../functions/sanitization.php';             // Валидация экранирование 

// Получаем настройки администратора
$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

try {
    // Проверка авторизации
    $user = requireAuth($pdo);
    if (!$user) {
        $redirectTo = '../logout.php';
        logEvent("Неавторизованный доступ — перенаправление на: $redirectTo — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}", LOG_INFO_ENABLED, 'info');
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: $redirectTo");
        exit;
    }

    // Получение данных пользователя
    $userDataAdmin = getUserData($pdo, $user['id']);
    // Проверка ошибки
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg = $userDataAdmin['message'];
        $level = $userDataAdmin['level']; // 'info' или 'error'
        $logEnabled = match($level) {'info'  => LOG_INFO_ENABLED, 'error' => LOG_ERROR_ENABLED, default => LOG_ERROR_ENABLED};
        logEvent($msg, $logEnabled, $level);
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");
        exit;
    }

    // Успех
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

    // Закрываем страницу от user
    // Перенаправляем на страницу входа если не Admin
    if ($userDataAdmin['author'] !== 'admin') {
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");
        exit;
    }

} catch (Exception $e) {
    logEvent("Ошибка при инициализации админ-панели: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

/**
 * Получение аватара пользователя из медиа-библиотеки
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param string $images Строка с ID изображений через запятую
 * @param string $defaultAvatar Путь к аватару по умолчанию
 * @return string Путь к аватару пользователя
 */
function getUserAvatar($pdo, $images, $defaultAvatar = 'img/photo.svg') {
    // Если нет изображений - возвращаем аватар по умолчанию
    if (empty($images)) {
        return $defaultAvatar;
    }

    // Разбираем строку с ID изображений
    $imageIds = explode(',', $images);
    $lastImageId = end($imageIds);
    $lastImageId = (int)$lastImageId;

    // Если ID невалидный - возвращаем аватар по умолчанию
    if ($lastImageId <= 0) {
        return $defaultAvatar;
    }

    try {
        // Получаем информацию о файле из медиа-библиотеки
        $mediaStmt = $pdo->prepare("SELECT file_versions FROM media_files WHERE id = ?");
        $mediaStmt->execute([$lastImageId]);
        $mediaFile = $mediaStmt->fetch(PDO::FETCH_ASSOC);

        // Если файл найден и есть версии
        if ($mediaFile && !empty($mediaFile['file_versions'])) {
            $fileVersions = json_decode($mediaFile['file_versions'], true);
            // Используем thumbnail версию для аватара (150x150)
            if (isset($fileVersions['thumbnail']['path'])) {
                return '/uploads/' . $fileVersions['thumbnail']['path'];
            }
        }
    } catch (PDOException $e) {
        // В случае ошибки возвращаем аватар по умолчанию + логируем
        logEvent("Ошибка загрузки аватара пользователя для изображения ID $lastImageId: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    }

    return $defaultAvatar;
}

/**
 * Удаляет все медиафайлы пользователя с диска и из базы данных
 *
 * @param PDO $pdo Объект подключения к БД
 * @param int $userId ID пользователя
 * @return array ['deleted_files' => int, 'deleted_records' => int, 'errors' => array]
 */
function deleteUserMediaFiles($pdo, $userId) {
    $uploadDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/';
    $deletedFiles = 0;
    $errors = [];

    try {
        // 1. Получаем все файлы пользователя
        $stmt = $pdo->prepare("SELECT id, file_versions FROM media_files WHERE user_id = ?");
        $stmt->execute([$userId]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($files)) {
            return ['deleted_files' => 0, 'deleted_records' => 0, 'errors' => []];
        }

        // 2. Удаляем файлы с диска
        foreach ($files as $file) {
            $fileVersions = json_decode($file['file_versions'], true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($fileVersions)) {
                continue;
            }

            // Поддерживаем как ассоциативный (thumbnail, original), так и индексный массив
            $versions = is_numeric(key($fileVersions)) ? $fileVersions : array_values($fileVersions);

            foreach ($versions as $version) {
                if (isset($version['path']) && !empty($version['path'])) {
                    $filePath = $uploadDir . '/' . ltrim($version['path'], '/');
                    if (file_exists($filePath)) {
                        if (!unlink($filePath)) {
                            $errors[] = "Не удалось удалить файл: " . $version['path'];
                        } else {
                            $deletedFiles++;
                        }
                    }
                }
            }
        }

        // 3. Удаляем записи из БД
        $deleteStmt = $pdo->prepare("DELETE FROM media_files WHERE user_id = ?");
        $deleteStmt->execute([$userId]);
        $deletedRecords = $deleteStmt->rowCount();

        return [
            'deleted_files' => $deletedFiles,
            'deleted_records' => $deletedRecords,
            'errors' => $errors
        ];

    } catch (PDOException $e) {
        return [
            'deleted_files' => 0,
            'deleted_records' => 0,
            'errors' => ['Ошибка БД при удалении файлов: ' . $e->getMessage()]
        ];
    }
}

// =============================================================================
// ОБРАБОТКА ПАРАМЕТРОВ И ФИЛЬТРОВ
// =============================================================================

// Определяем режим работы: основная страница или корзина
$isTrash = isset($_GET['trash']) && $_GET['trash'] == 1;

// Получаем параметры поиска и пагинации
$search = trim($_GET['search'] ?? '');        // Поисковый запрос
$page = max(1, (int)($_GET['page'] ?? 1));    // Текущая страница (минимум 1)
$limit = 30;                                  // Количество записей на странице
$offset = ($page - 1) * $limit;               // Смещение для SQL запроса

// Инициализация массивов для сообщений
$errors = [];
$successMessages = [];

// =============================================================================
// ОБРАБОТКА МАССОВЫХ ДЕЙСТВИЙ
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['user_ids'])) {
    $action = $_POST['action'];

    // Нормализуем ID пользователей в массив
    $userIds = is_array($_POST['user_ids']) ? $_POST['user_ids'] : [$_POST['user_ids']];

    // Безопасная обработка ID: преобразуем в числа и фильтруем пустые значения
    $userIds = array_map('intval', $userIds);
    $userIds = array_filter($userIds);

    if (!empty($userIds)) {
        try {
            // Создаем плейсхолдеры для SQL запроса (?, ?, ?)
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';

            // Обрабатываем различные типы действий
            switch ($action) {
                case 'delete':
                    if ($isTrash) {
                        // Полное удаление из корзины + удаление файлов
                        foreach ($userIds as $userId) {
                            // Удаляем медиафайлы пользователя
                            $mediaResult = deleteUserMediaFiles($pdo, $userId);
                            
                            // Удаляем запись пользователя (только из корзины!)
                            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND status = 0");
                            $stmt->execute([$userId]);

                            // Логируем ошибки удаления файлов
                            if (!empty($mediaResult['errors'])) {
                                logEvent("Ошибки при удалении файлов пользователя ID=$userId: " . implode('; ', $mediaResult['errors']), LOG_ERROR_ENABLED, 'error');
                            }
                        }
                        $successMessages[] = 'Пользователи и их файлы успешно удалены';
                    } else {
                        // Перемещение в корзину (без удаления файлов)
                        $stmt = $pdo->prepare("UPDATE users SET status = 0 WHERE id IN ($placeholders)");
                        $stmt->execute($userIds);
                        $successMessages[] = 'Пользователи перемещены в корзину';
                    }
                    break;

                case 'restore':
                    // Восстановление из корзины (установка status = 1)
                    $stmt = $pdo->prepare("UPDATE users SET status = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($userIds);
                    $successMessages[] = 'Пользователи восстановлены';
                    break;

                case 'trash':
                    // Добавление в корзину
                    $stmt = $pdo->prepare("UPDATE users SET status = 0 WHERE id IN ($placeholders)");
                    $stmt->execute($userIds);
                    $successMessages[] = 'Пользователи перемещены в корзину';
                    break;

                default:
                    $errors[] = 'Недопустимое действие.';
                    break;
            }

            // Логируем операцию для аудита
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $adminId = $user['id'] ?? 'unknown';
            logEvent("Выполнено массовое действие '$action' администратором ID: $adminId над пользователями: " . implode(',', $userIds) . " — IP: $ip", LOG_INFO_ENABLED, 'info');

        } catch (PDOException $e) {
            $errors[] = 'Ошибка при выполнении операции';
            logEvent("Ошибка базы данных при массовом действии '$action': " . $e->getMessage() . " — ID админа: " . ($user['id'] ?? 'unknown') . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
        }
    } else {
        $errors[] = 'Не выбраны пользователи для действия.';
    }
}

// =============================================================================
// ПОЛУЧЕНИЕ ДАННЫХ ПОЛЬЗОВАТЕЛЕЙ ИЗ БАЗЫ ДАННЫХ
// =============================================================================

try {
    // Базовые SQL запросы для получения данных
    $query = "SELECT * FROM users WHERE author != 'admin'";
    $countQuery = "SELECT COUNT(*) FROM users WHERE author != 'admin'";
    $params = [];

    // Добавляем условия фильтрации в зависимости от режима
    if ($isTrash) {
        // В режиме корзины показываем только пользователей со статусом 0
        $query .= " AND status = 0";
        $countQuery .= " AND status = 0";
    } else {
        // В обычном режиме показываем только активных пользователей (статус 1)
        $query .= " AND status = 1";
        $countQuery .= " AND status = 1";
    }

    // Добавляем условие поиска по email если задан поисковый запрос
    if (!empty($search)) {
        $query .= " AND email LIKE ?";
        $countQuery .= " AND email LIKE ?";
        $params[] = "%$search%";
    }

    // Добавляем сортировку по дате создания и ограничения для пагинации
    $query .= " ORDER BY created_at DESC LIMIT $limit OFFSET $offset";

    // Получаем общее количество пользователей для пагинации
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $totalUsers = $stmt->fetchColumn();

    // Получаем данные пользователей для текущей страницы
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Обрабатываем данные каждого пользователя
    foreach ($users as &$DataUser) {
        // Декодируем JSON данные из колонки data
        $userData = json_decode($DataUser['data'] ?? '{}', true) ?? [];

        // Извлекаем основные поля из JSON
        $DataUser['first_name'] = $userData['first_name'] ?? '';
        $DataUser['last_name'] = $userData['last_name'] ?? '';
        $DataUser['images'] = $userData['profile_images'] ?? '';

        // Получаем аватар пользователя с помощью функции
        $DataUser['avatar'] = getUserAvatar($pdo, $DataUser['images']);

        // Проверяем существование файла аватара
        $user_avatar_path = $_SERVER['DOCUMENT_ROOT'] . $DataUser['avatar'];
        if (!file_exists($user_avatar_path)) {
            $DataUser['avatar'] = '../img/person.svg';
        }
    }
    unset($DataUser); // Убираем ссылку на последний элемент

    // Вычисляем общее количество страниц для пагинации
    $totalPages = ceil($totalUsers / $limit);

} catch (PDOException $e) {
    $errors[] = 'Ошибка при загрузке данных пользователей';
    logEvent("Ошибка базы данных при загрузке списка пользователей: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
}

// Получает логотип
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/person.svg');
$titlemeta = 'Управления пользователями';

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
    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
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
    <!-- Заголовок страницы с иконкой и счетчиком -->
    <h3 class="card-title">
        <i class="bi bi-people"></i>
        <?= $isTrash ? escape('Корзина пользователей') : escape('Пользователи') ?>
    </h3>

    <!-- Отображение сообщений -->
    <?php displayAlerts($successMessages, $errors); ?>

    <!-- Навигация между основной страницей и корзиной -->
    <?php if (!$isTrash): ?>
        <?php
            // Получаем количество пользователей в корзине
            $trashCountStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE author != 'admin' AND status = 0");
            $trashCountStmt->execute();
            $trashCount = $trashCountStmt->fetchColumn();
        ?>
        <?php if ($trashCount > 0): ?>
            <!-- Кнопка перехода в корзину с счетчиком -->
            <a href="?folder=user&amp;file=accounts_list&amp;trash=1" class="btn btn-outline-danger mb-3">
                <i class="bi bi-trash"></i> Корзина
                <span class="badge bg-danger"><?= escape((string)$trashCount) ?></span>
            </a>
        <?php endif; ?>
        <?php else: ?>
            <!-- Кнопка возврата из корзины -->
            <a href="?folder=user&amp;file=accounts_list" class="btn btn-outline-primary mb-3">
                <i class="bi bi-arrow-left"></i> Назад к пользователям
            </a>
    <?php endif; ?>

    <!-- Кнопка добавления нового пользователя -->
    <a href="add_account.php" class="btn btn-primary ms-2 mb-3">
        <i class="bi bi-person-plus"></i> Добавить пользователя
    </a>

    <!-- Панель управления с поиском и действиями -->
    <div class="table-controls mb-3">
        <div class="row align-items-center">
            <div class="col-md-6">
                <!-- Форма поиска по email -->
                <form method="GET" class="d-flex mb-3">
                    <input type="hidden" name="folder" value="user">
                    <input type="hidden" name="file" value="accounts_list">
                    <?php if ($isTrash): ?>
                        <input type="hidden" name="trash" value="1">
                    <?php endif; ?>
                    <div class="input-group">
                        <input type="text"
                               name="search"
                               class="form-control"
                               placeholder="Поиск по email..."
                               value="<?= escape($search) ?>">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-search"></i>
                        </button>
                        <?php if (!empty($search)): ?>
                            <!-- Кнопка сброса поиска -->
                            <a href="?folder=user&amp;file=accounts_list<?= $isTrash ? '&amp;trash=1' : '' ?>"
                                class="btn btn-outline-secondary d-flex align-items-center justify-content-center"
                                title="Сбросить поиск">
                                <i class="bi bi-x"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="col-md-6 text-end">
                <!-- Форма массовых действий (только если есть пользователи) -->
                <?php if (!empty($users)): ?>
                <form method="POST" id="massActionForm" class="d-flex mb-3">
                    <!-- Выбор действия для массовой операции -->
                    <div class="input-group">
                        <select name="action" class="form-select">
                            <option value=""><?= escape('-- Выберите действие --') ?></option>
                            <?php if ($isTrash): ?>
                            <option value="restore"><?= escape('Восстановить') ?></option>
                            <option value="delete"><?= escape('Удалить навсегда') ?></option>
                            <?php else: ?>
                            <option value="trash"><?= escape('Добавить в корзину') ?></option>
                            <?php endif; ?>
                        </select>
                        <button type="submit" class="btn btn-outline-primary"><?= escape('Выполнить') ?></button>
                    </div>

                    <!-- Чекбоксы для пользователей (внутри формы, но вне таблицы — для корректного submit) -->
                    <?php foreach ($users as $user): ?>
                        <input type="hidden" name="user_ids[]" value="<?= (int)$user['id'] ?>" class="user-id-input">
                    <?php endforeach; ?>
                </form>
                <?php endif; ?>
            </div> 
        </div>
        <div class="user-role mb-3"><?= escape('Количество записей: ') ?><span class="badge bg-secondary"><?= escape((string)$totalUsers) ?></span></div>
    </div>

    <!-- Таблица с данными пользователей -->
    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th width="40">
                        <input class="form-check-input" type="checkbox" id="selectAll">
                    </th>
                    <th width="60"><?= escape('Изображение') ?></th>
                    <th><?= escape('Пользователь') ?></th>
                    <th><?= escape('Email') ?></th>
                    <th><?= escape('Роль') ?></th>
                    <th><?= escape('Дата регистрации') ?></th>
                    <th width="100"><?= escape('Действия') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <!-- Сообщение при отсутствии пользователей -->
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            <i class="bi bi-trash display-4"></i>
                            <p class="mt-2 auth-subtitle">
                                <?= $isTrash ? escape('Корзина пуста') : escape('Пользователи не найдены') ?>
                            </p>
                        </td>
                    </tr>
                <?php else: ?>
                    <!-- Цикл отображения данных каждого пользователя -->
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <!-- Чекбокс для выбора пользователя -->
                        <td>
                            <input type="checkbox" name="user_ids[]" value="<?= (int)$user['id'] ?>" class="user-checkbox form-check-input" form="massActionForm">
                        </td>

                        <!-- Аватар пользователя -->
                        <td>
                            <div class="user-avatar" style="width: 40px; height: 40px;">
                                <img src="<?= escape($user['avatar']) ?>"
                                     alt="<?= escape($user['first_name'] . ' ' . $user['last_name']) ?>"
                                     class="rounded-circle"
                                     style="width: 100%; height: 100%; object-fit: cover;">
                            </div>
                        </td>

                        <!-- Информация о пользователе -->
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="ms-2">
                                    <!-- Ссылка на редактирование пользователя -->
                                    <a href="add_account.php?id=<?= (int)$user['id'] ?>"
                                       class="user-link text-decoration-none">
                                        <?= escape($user['first_name'] . ' ' . $user['last_name']) ?>
                                    </a>
                                    <span class="badge bg-success"><?= escape('ID: ') . (int)$user['id'] ?></span>
                                </div>
                            </div>
                        </td>

                        <!-- Email пользователя -->
                        <td><?= escape($user['email']) ?></td>

                        <!-- Роль пользователя -->
                        <td>
                            <?php if ($user['author'] == 'admin'): ?>
                                <span class="badge bg-primary"><?= escape('Админ') ?></span>
                            <?php else: ?>
                                <span class="badge bg-success"><?= escape('Пользователь') ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Дата регистрации -->
                        <td>
                            <?= date('d.m.Y H:i', strtotime($user['created_at'])) ?>
                        </td>

                        <!-- Кнопки действий -->
                        <td>
                            <div class="btn-group" style="gap: 4px;">
                                <!-- Кнопка редактирования -->
                                <a href="add_account.php?id=<?= (int)$user['id'] ?>"
                                   class="btn btn-sm btn-outline-primary"
                                   title="<?= escape('Редактировать') ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($isTrash): ?>
                                    <!-- Действия в корзине -->
                                    <button type="button"
                                            class="btn btn-sm btn-outline-success restore-user"
                                            data-user-id="<?= (int)$user['id'] ?>"
                                            title="<?= escape('Восстановить') ?>">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-danger delete-user"
                                            data-user-id="<?= (int)$user['id'] ?>"
                                            title="<?= escape('Удалить навсегда') ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <!-- Действие на основной странице -->
                                    <button type="button"
                                            class="btn btn-sm btn-outline-danger trash-user"
                                            data-user-id="<?= (int)$user['id'] ?>"
                                            title="<?= escape('В корзину') ?>">
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

    <!-- Пагинация (отображается если страниц больше одной) -->
    <?php if ($totalPages > 1): ?>
    <nav aria-label="Пагинация" class="mt-3">
        <ul class="pagination justify-content-center">

            <?php if ($totalPages <= 5): ?>
                <!-- Все страницы (максимум 5) -->
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_filter([
                            'folder' => $_GET['folder'] ?? null,
                            'file'   => $_GET['file'] ?? null,
                            'trash'  => $_GET['trash'] ?? null,
                            'search' => $search,
                            'page'   => $i
                        ])) ?>"><?= escape((string)$i) ?></a>
                    </li>
                <?php endfor; ?>

            <?php else: ?>
                <!-- Кнопка "Назад" со стрелкой и title -->
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" 
                       href="<?= $page <= 1 ? '#' : '?' . http_build_query(array_filter([
                           'folder' => $_GET['folder'] ?? null,
                           'file'   => $_GET['file'] ?? null,
                           'trash'  => $_GET['trash'] ?? null,
                           'search' => $search,
                           'page'   => $page - 1
                       ])) ?>"
                       title="<?= escape('Назад') ?>"
                       aria-label="Назад">
                        <i class="bi bi-arrow-left-short"></i>
                    </a>
                </li>

                <?php
                // Формируем список страниц для отображения (максимум 5 цифр)
                $pagesToShow = [1]; // всегда первая

                // Оставшиеся слоты: 5 - 2 (1 и totalPages) = 3 для середины
                $middleSlots = 3;

                $left = $page - floor($middleSlots / 2);
                $right = $page + ceil($middleSlots / 2);

                // Корректируем границы
                if ($left < 2) {
                    $right = min($totalPages - 1, $right + (2 - $left));
                    $left = 2;
                }
                if ($right > $totalPages - 1) {
                    $left = max(2, $left - ($right - ($totalPages - 1)));
                    $right = $totalPages - 1;
                }

                // Добавляем окрестности
                for ($i = $left; $i <= $right; $i++) {
                    $pagesToShow[] = $i;
                }

                $pagesToShow[] = $totalPages; // всегда последняя
                $pagesToShow = array_values(array_unique($pagesToShow));
                sort($pagesToShow);

                // Вывод с многоточиями
                $last = 0;
                foreach ($pagesToShow as $p) {
                    if ($p - $last > 1) {
                        echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    echo '<li class="page-item' . ($p == $page ? ' active' : '') . '">';
                    echo '<a class="page-link" href="?';
                    echo http_build_query(array_filter([
                        'folder' => $_GET['folder'] ?? null,
                        'file'   => $_GET['file'] ?? null,
                        'trash'  => $_GET['trash'] ?? null,
                        'search' => $search,
                        'page'   => $p
                    ]));
                    echo '">' . escape((string)$p) . '</a></li>';
                    $last = $p;
                }
                ?>

                <!-- Кнопка "Вперёд" со стрелкой и title -->
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link"
                        href="<?= $page >= $totalPages ? '#' : '?' . http_build_query(array_filter([
                            'folder' => $_GET['folder'] ?? null,
                            'file'   => $_GET['file'] ?? null,
                            'trash'  => $_GET['trash'] ?? null,
                            'search' => $search,
                            'page'   => $page + 1
                        ])) ?>"
                        title="<?= escape('Вперёд') ?>"
                        aria-label="Вперёд">
                        <i class="bi bi-arrow-right-short"></i>
                    </a>
                </li>

            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>
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
<?php
/**
 * Файл: /admin/record/record_list.php
 *
 * Назначение:
 * - Админ-страница со списком категорий/разделов каталога.
 * - Поддерживает поиск по названию и поиск по родительской категории.
 * - Поддерживает "корзину" (status=0) и активные страницы (status=1).
 * - Поддерживает массовые действия (в корзину / восстановить / удалить навсегда).
 * - ФИЛЬТРАЦИЯ ПО users_id = $_SESSION['user_id'] для основных и родительских страниц.
 *
 * Важно:
 * - Таблица каталога задаётся в настройках ($catalogTable) — меняется в одном месте.
 * - Отбор страницы идёт по related_table (например 'shop') — это удобно, если в одной таблице
 *   храните разные сущности/контексты.
 * - Изображение берётся из JSON-колонки data по ключу $.image (оператор ->> возвращает текст).
 *
 * Где добавлять новые поля из data (JSON):
 * - В блоке SELECT: можно достать любое поле из JSON через (data ->> '$.key') AS alias
 * - В блоке вывода: использовать $row['alias']
 * Пример:
 *   (data ->> '$.meta.title') AS seo_title
 * Затем в таблице вывести $row['seo_title'].
 *
 * Безопасность:
 * - PDO не поддерживает плейсхолдеры для имён таблиц, поэтому таблицу нельзя брать из GET/POST.
 *   Здесь $catalogTable задаётся вручную (глобальная настройка).
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

define('APP_ACCESS', true);
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

// =============================================================================
// НАСТРОЙКИ (меняются в одном месте)
// =============================================================================

// Load module-specific configuration
require_once __DIR__ . '/../config/module_config.php';

// 3) Фильтр related_table ТОЛЬКО для поиска/отображения родительской категории
//    (когда вводите search_catalog и когда вытаскиваем catalog_name).
$catalogRelatedTable = $parentRelatedTable;

// =============================================================================
// Подключаем системные компоненты
// =============================================================================
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования
require_once __DIR__ . '/../functions/jsondata.php';                 // Обновление JSON данных пользователя
require_once __DIR__ . '/../functions/display_alerts.php';           // Отображения сообщений
require_once __DIR__ . '/../functions/sanitization.php';             // Валидация/экранирование

// Безопасный запуск сессии
startSessionSafe();

// =============================================================================
// Проверка прав администратора
// =============================================================================
$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

try {
    $user = requireAuth($pdo);
    if (!$user) {
        $redirectTo = '../logout.php';
        logEvent("Неавторизованный доступ — перенаправление на: $redirectTo — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}", LOG_INFO_ENABLED, 'info');
        header("Location: $redirectTo");
        exit;
    }

    $userDataAdmin = getUserData($pdo, $user['id']);
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg = $userDataAdmin['message'];
        $level = $userDataAdmin['level'];
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

    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

    // ПРОВЕРКА ПРАВ ДОСТУПА
    $haspagesAccess = false;
    if ($userDataAdmin['author'] === 'admin' && ($adminData['allow_catalog_admin'] ?? false) === true) {
        $haspagesAccess = true;
    } elseif ($userDataAdmin['author'] === 'user' && ($adminData['allow_catalog_users'] ?? false) === true) {
        $haspagesAccess = true;
    }

    // Если доступ запрещен - ЛОГАУТ!
    if (!$haspagesAccess) {
        logEvent("Доступ к магазину запрещен. Author: {$userDataAdmin['author']}, IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
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
 * Получение аватара (картинки) из медиа-библиотеки по ID.
 *
 * @param PDO $pdo
 * @param string $images Строка с ID изображений через запятую (или один ID)
 * @param string $defaultAvatar
 * @return string
 */
function getUserAvatar($pdo, $images, $defaultAvatar = '../img/galereya.svg') {
    if (empty($images)) {
        return $defaultAvatar;
    }

    $imageIds = explode(',', (string)$images);
    $lastImageId = end($imageIds);
    $lastImageId = (int)$lastImageId;

    if ($lastImageId <= 0) {
        return $defaultAvatar;
    }

    try {
        $mediaStmt = $pdo->prepare("SELECT file_versions FROM media_files WHERE id = ?");
        $mediaStmt->execute([$lastImageId]);
        $mediaFile = $mediaStmt->fetch(PDO::FETCH_ASSOC);

        if ($mediaFile && !empty($mediaFile['file_versions'])) {
            $fileVersions = json_decode($mediaFile['file_versions'], true);
            if (isset($fileVersions['thumbnail']['path'])) {
                return '/uploads/' . $fileVersions['thumbnail']['path'];
            }
        }
    } catch (PDOException $e) {
        logEvent("Ошибка загрузки аватара (media_files) для изображения ID $lastImageId: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    }

    return $defaultAvatar;
}

// =============================================================================
// ПАРАМЕТРЫ/ФИЛЬТРЫ (GET)
// =============================================================================

// Режим "корзины"
$isTrash = isset($_GET['trash']) && $_GET['trash'] == 1;

// Поиск по названию
$search = trim($_GET['search'] ?? '');

// Поиск по названию родительской категории
$search_catalog = trim($_GET['search_catalog'] ?? '');

// Пагинация
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

// Текущий user_id из сессии
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Сообщения и ошибки
$errors = [];
$successMessages = [];

// =============================================================================
// ОБРАБОТКА МАССОВЫХ ДЕЙСТВИЙ (POST)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['user_ids'])) {
    $action = $_POST['action'];

    // Нормализуем ID страницы
    $userIds = is_array($_POST['user_ids']) ? $_POST['user_ids'] : [$_POST['user_ids']];
    $userIds = array_map('intval', $userIds);
    $userIds = array_filter($userIds);

    if (!empty($userIds)) {
        try {
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';

            switch ($action) {
                case 'delete':
                    if ($isTrash) {
                        // Полное удаление из корзины
                        foreach ($userIds as $userId) {
                            $stmt = $pdo->prepare("DELETE FROM {$catalogTable} WHERE id = ? AND status = 0 AND related_table = ? AND users_id = ?");
                            $stmt->execute([$userId, $RELATED_TABLE_FILTER, $currentUserId]);
                        }
                        $successMessages[] = $successMessagesConfig['deleted'];
                    } else {
                        // Перемещение в корзину
                        $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 0 WHERE related_table = ? AND id IN ($placeholders) AND users_id = ?");
                        $stmt->execute(array_merge([$RELATED_TABLE_FILTER], $userIds, [$currentUserId]));
                        $successMessages[] = $successMessagesConfig['moved_to_trash'];
                    }
                    break;

                case 'restore':
                    // Восстановление из корзины
                    $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 1 WHERE related_table = ? AND id IN ($placeholders) AND users_id = ?");
                    $stmt->execute(array_merge([$RELATED_TABLE_FILTER], $userIds, [$currentUserId]));
                    $successMessages[] = $successMessagesConfig['restored'];
                    break;

                case 'trash':
                    // Добавление в корзину
                    $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 0 WHERE related_table = ? AND id IN ($placeholders) AND users_id = ?");
                    $stmt->execute(array_merge([$RELATED_TABLE_FILTER], $userIds, [$currentUserId]));
                    $successMessages[] = 'Новости перемещены в корзину';
                    break;

                default:
                    $errors[] = 'Недопустимое действие.';
                    break;
            }

            // Логирование действий
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $adminId = $user['id'] ?? 'unknown';
            logEvent("Выполнено массовое действие '$action' администратором ID: $adminId над {$catalogTable} IDs: " . implode(',', $userIds) . " — related_table={$RELATED_TABLE_FILTER} — users_id=$currentUserId — IP: $ip", LOG_INFO_ENABLED, 'info');

        } catch (PDOException $e) {
            $errors[] = 'Ошибка при выполнении операции';
            logEvent("Ошибка БД при массовом действии '$action' ({$catalogTable}): " . $e->getMessage() . " — ID админа: " . ($user['id'] ?? 'unknown') . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
        }
    } else {
        $errors[] = 'Не выбраны страницы для действия.';
    }
}

// =============================================================================
// ПОЛУЧЕНИЕ ДАННЫХ ИЗ ТАБЛИЦЫ (с фильтром related_table И users_id)
// =============================================================================
try {
    $query = "
        SELECT
            id,
            naime,
            url,
            (data ->> '$.image') AS image,
            created_at,
            author
        FROM {$catalogTable}
        WHERE status = :status
          AND related_table = :related_table
          AND users_id = :users_id
    ";

    $countQuery = "
        SELECT COUNT(*)
        FROM {$catalogTable}
        WHERE status = :status
          AND related_table = :related_table
          AND users_id = :users_id
    ";

    $params = [
        ':status' => $isTrash ? 0 : 1,
        ':related_table' => $RELATED_TABLE_FILTER,
        ':users_id' => $currentUserId,
    ];

    // Поиск по названию
    if ($search !== '') {
        $query .= " AND naime LIKE :search";
        $countQuery .= " AND naime LIKE :search";
        $params[':search'] = "%{$search}%";
    }

    // Поиск по родительской категории (родитель проверяем по $catalogRelatedTable И users_id)
    if ($search_catalog !== '') {
        // Используем уникальные имена параметров для подзапроса
        $query .= " AND EXISTS (
                        SELECT 1
                        FROM {$catalogTable} p
                        WHERE p.id = {$catalogTable}.author
                           AND p.status = 1
                           AND p.related_table = :catalog_related_table_search
                           AND p.users_id = :users_id_search
                           AND p.naime LIKE :search_catalog_search
                    )";
        $countQuery .= " AND EXISTS (
                            SELECT 1
                            FROM {$catalogTable} p
                            WHERE p.id = {$catalogTable}.author
                              AND p.status = 1
                              AND p.related_table = :catalog_related_table_search
                              AND p.users_id = :users_id_search
                              AND p.naime LIKE :search_catalog_search
                        )";
    
        // Уникальные параметры для подзапроса
        $params[':catalog_related_table_search'] = $catalogRelatedTable;
        $params[':users_id_search'] = $currentUserId;
        $params[':search_catalog_search'] = "%{$search_catalog}%";
    }

    // Сортировка и пагинация
    $query .= " ORDER BY sorting ASC";
    $query .= " LIMIT :limit OFFSET :offset";

    // Считаем количество
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $totalUsers = (int)$stmt->fetchColumn();

    // Получаем строки
    $stmt = $pdo->prepare($query);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }

    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // -------------------------------------------------------------------------
    // Подгружаем имена и URL родителей (author = ID родителя) ТОЛЬКО для текущего users_id
    // -------------------------------------------------------------------------
    $catalogMap = [];
    $catalogIds = [];

    foreach ($users as $u) {
        $pid = (int)($u['author'] ?? 0);
        if ($pid > 0) {
            $catalogIds[] = $pid;
        }
    }
    $catalogIds = array_values(array_unique($catalogIds));

    if (!empty($catalogIds)) {
        $ph = implode(',', array_fill(0, count($catalogIds), '?'));
        $pStmt = $pdo->prepare("
            SELECT 
                id, 
                naime, 
                url 
            FROM {$catalogTable} 
            WHERE related_table = ? 
              AND users_id = ? 
              AND id IN ($ph) 
              AND status = 1
        ");
        $pStmt->execute(array_merge([$catalogRelatedTable, $currentUserId], $catalogIds));

        while ($row = $pStmt->fetch(PDO::FETCH_ASSOC)) {
            $catalogMap[(int)$row['id']] = [
                'name' => $row['naime'],
                'url' => $row['url']
            ];
        }
    }

    foreach ($users as &$u) {
        $pid = (int)($u['author'] ?? 0);
        if ($pid > 0 && isset($catalogMap[$pid])) {
            $u['catalog_name'] = $catalogMap[$pid]['name'];
            $u['catalog_url'] = $catalogMap[$pid]['url'];
            // Формируем полную структуру URL: категория/товар
            $u['full_url'] = !empty($catalogMap[$pid]['url']) && !empty($u['url']) 
                ? trim($catalogMap[$pid]['url'], '/') . '/' . trim($u['url'], '/') 
                : $u['url'];
        } else {
            $u['catalog_name'] = '';
            $u['catalog_url'] = '';
            $u['full_url'] = $u['url'];
        }
    }
    unset($u);

    $totalPages = (int)ceil($totalUsers / $limit);

} catch (PDOException $e) {
    $errors[] = 'Ошибка при загрузке данных каталога';
    logEvent("Ошибка базы данных при загрузке списка {$catalogTable}: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
}

// =============================================================================
// Подготовка шаблона
// =============================================================================
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');


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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
</head>
<body>
<div class="container-fluid">
    <?php require_once __DIR__ . '/../template/sidebar.php'; ?>

    <main class="main-content">
        <?php require_once __DIR__ . '/../template/header.php'; ?>

        <div class="content-card table-card">
            <div class="row align-items-center mb-4">
                <div class="col-lg-6">
                    <h3 class="card-title d-flex align-items-center gap-2 mb-0">
                        <i class="bi bi-hdd-stack"></i>
                        <?= $isTrash ? 'Корзина' : $manageTitle ?>
                    </h3>
                </div>
                <div class="col-lg-6 text-end">
                    <a href="add_record.php"
                        class="btn btn-outline-primary" 
                        title="<?= $addButtonTitle ?>">
                        <i class="bi bi-plus-circle"></i> Добавить
                    </a>
                </div>
            </div>
            <!-- Сообщения об ошибках/успехе -->
            <?php displayAlerts($successMessages, $errors); ?>

            <?php if (!$isTrash): ?>
                <?php
                    $trashCountStmt = $pdo->prepare("SELECT COUNT(*) FROM {$catalogTable} WHERE related_table = ? AND status = 0 AND users_id = ?");
                    $trashCountStmt->execute([$RELATED_TABLE_FILTER, $currentUserId]);
                    $trashCount = (int)$trashCountStmt->fetchColumn();
                ?>
                <?php if ($trashCount > 0): ?>
                    <a href="?trash=1" class="btn btn-outline-danger mb-3">
                        <i class="bi bi-trash"></i> Корзина
                        <span class="badge bg-danger"><?= escape((string)$trashCount) ?></span>
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <a href="record_list.php" class="btn btn-outline-primary mb-3">
                    <i class="bi bi-arrow-left"></i> Выйти из корзины
                </a>
            <?php endif; ?>

            <div class="table-controls mb-3">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <form method="GET" class="d-flex mb-3">
                            <?php if ($isTrash): ?>
                                <input type="hidden" name="trash" value="1">
                            <?php endif; ?>

                            <div class="input-group" style="gap: 2px;">
                                <input type="text"
                                       name="search"
                                       class="form-control"
                                       placeholder="Поиск по названию..."
                                       value="<?= escape($search) ?>">

                                <input type="text"
                                       name="search_catalog"
                                       class="form-control"
                                       placeholder="Поиск по родительской категории..."
                                       value="<?= escape($search_catalog) ?>">

                                <button type="submit" class="btn btn-outline-primary">
                                    <i class="bi bi-search"></i>
                                </button>

                                <?php if ($search !== '' || $search_catalog !== ''): ?>
                                    <a href="<?= $isTrash ? '?trash=1' : 'record_list.php' ?>"
                                       class="btn btn-outline-danger d-flex align-items-center justify-content-center"
                                       title="Сбросить поиск">
                                        <i class="bi bi-x"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-5">
                        <?php if (!empty($users)): ?>
                            <form method="POST" id="massActionForm" class="d-flex mb-3">
                                <div class="input-group">
                                    <select name="action" class="form-select">
                                        <option value="">-- Выберите действие --</option>
                                        <?php if ($isTrash): ?>
                                            <option value="restore">Восстановить</option>
                                            <option value="delete">Удалить навсегда</option>
                                        <?php else: ?>
                                            <option value="trash">Добавить в корзину</option>
                                        <?php endif; ?>
                                    </select>
                                    <button type="submit" class="btn btn-outline-primary">Выполнить</button>
                                </div>
                                <?php foreach ($users as $row): ?>
                                    <input type="hidden" name="user_ids[]" value="<?= (int)$row['id'] ?>" class="user-id-input">
                                <?php endforeach; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="user-role mb-3">
                    Количество записей: 
                    <span class="badge bg-secondary"><?= escape((string)$totalUsers) ?></span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                    <tr>
                        <th width="40"><input class="form-check-input" type="checkbox" id="selectAll"></th>
                        <th width="60">Изображение</th>
                        <th>Название</th>
                        <th>Родительская категория</th>
                        <th width="120" class="text-center">URL</th>
                        <th>Дата</th>
                        <th width="100">Действия</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <i class="bi bi-trash display-4"></i>
                                <p class="mt-2 auth-subtitle">
                                    <?= $isTrash ? 'Корзина пуста' : 'Страницы не найдены' ?>
                                    <?php if ($currentUserId > 0): ?>
                                        <br><small class="text-muted">для пользователя ID: <?= $currentUserId ?></small>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $row): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="user_ids[]" value="<?= (int)$row['id'] ?>"
                                           class="user-checkbox form-check-input" form="massActionForm">
                                </td>

                                <?php
                                $rowImage = getUserAvatar($pdo, $row['image'] ?? '');
                                ?>

                                <td>
                                    <div class="user-avatar" style="width: 40px; height: 40px;">
                                        <img src="<?= escape($rowImage) ?>"
                                             alt="<?= escape($row['naime'] ?? '') ?>"
                                             style="width: 100%; height: 100%; object-fit: cover;">
                                    </div>
                                </td>

                                <td>
                                    <a href="add_record.php?id=<?= (int)$row['id'] ?>" class="user-link text-decoration-none">
                                        <?= escape($row['naime'] ?? '') ?>
                                    </a>
                                    <span class="badge bg-success"><?= 'ID: ' . (int)$row['id'] ?></span>
                                </td>

                                <td>
                                    <?php if (!empty($row['catalog_name'])): ?>
                                        <?php
                                        $catalogLink = '?' . http_build_query(array_filter([
                                            'trash'  => $isTrash ? '1' : null,
                                            'search' => null,
                                            'search_catalog' => $row['catalog_name'],
                                            'page'   => 1
                                        ]));
                                        ?>
                                        <a href="<?= escape($catalogLink) ?>" class="text-decoration-none">
                                            <?= escape($row['catalog_name']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center">
                                    <?php if (!empty($row['full_url'])): ?>
                                        <div class="d-flex flex-column gap-1 justify-content-center">
                                            <a href="/<?= escape($catalogRelatedTable) ?>/<?= escape($row['full_url']) ?>"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="btn btn-sm btn-link p-0 border-0 text-decoration-none fw-bold"
                                               title="Открыть в новом окне">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= !empty($row['created_at']) ? date('d.m.Y H:i', strtotime($row['created_at'])) : '' ?>
                                </td>

                                <td>
                                    <div class="btn-group" style="gap: 4px;">
                                        <a href="add_record.php?id=<?= (int)$row['id'] ?>"
                                           class="btn btn-sm btn-outline-primary"
                                           title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <?php if ($isTrash): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-success restore-user"
                                                    data-user-id="<?= (int)$row['id'] ?>"
                                                    title="Восстановить">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger delete-user"
                                                    data-user-id="<?= (int)$row['id'] ?>"
                                                    title="Удалить навсегда">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger trash-user"
                                                    data-user-id="<?= (int)$row['id'] ?>"
                                                    title="В корзину">
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

            <?php if ($totalPages > 1): ?>
                <nav aria-label="Пагинация" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if ($totalPages <= 5): ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_filter([
                                        'trash'  => $_GET['trash'] ?? null,
                                        'search' => $search,
                                        'search_catalog' => $search_catalog,
                                        'page'   => $i
                                    ])) ?>"><?= escape((string)$i) ?></a>
                                </li>
                            <?php endfor; ?>
                        <?php else: ?>
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link"
                                   href="<?= $page <= 1 ? '#' : '?' . http_build_query(array_filter([
                                       'trash'  => $_GET['trash'] ?? null,
                                       'search' => $search,
                                       'search_catalog' => $search_catalog,
                                       'page'   => $page - 1
                                   ])) ?>"
                                   title="Назад"
                                   aria-label="Назад">
                                    <i class="bi bi-arrow-left-short"></i>
                                </a>
                            </li>
                            <?php
                            $pagesToShow = [1];
                            $middleSlots = 3;

                            $left = $page - floor($middleSlots / 2);
                            $right = $page + ceil($middleSlots / 2);

                            if ($left < 2) {
                                $right = min($totalPages - 1, $right + (2 - $left));
                                $left = 2;
                            }
                            if ($right > $totalPages - 1) {
                                $left = max(2, $left - ($right - ($totalPages - 1)));
                                $right = $totalPages - 1;
                            }

                            for ($i = $left; $i <= $right; $i++) $pagesToShow[] = $i;

                            $pagesToShow[] = $totalPages;
                            $pagesToShow = array_values(array_unique($pagesToShow));
                            sort($pagesToShow);

                            $last = 0;
                            foreach ($pagesToShow as $p) {
                                if ($p - $last > 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                }
                                echo '<li class="page-item' . ($p == $page ? ' active' : '') . '">';
                                echo '<a class="page-link" href="?';
                                echo http_build_query(array_filter([
                                    'trash'  => $_GET['trash'] ?? null,
                                    'search' => $search,
                                    'search_catalog' => $search_catalog,
                                    'page'   => $p
                                ]));
                                echo '">' . escape((string)$p) . '</a></li>';
                                $last = $p;
                            }
                            ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link"
                                   href="<?= $page >= $totalPages ? '#' : '?' . http_build_query(array_filter([
                                       'trash'  => $_GET['trash'] ?? null,
                                       'search' => $search,
                                       'search_catalog' => $search_catalog,
                                       'page'   => $page + 1
                                   ])) ?>"
                                   title="Вперёд"
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

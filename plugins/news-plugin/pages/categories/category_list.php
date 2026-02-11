<?php
/**
 * Файл: /plugins/news-plugin/pages/categories/category_list.php
 *
 * Назначение:
 * - Админ-страница со списком категорий новостей.
 * - Поддерживает поиск по названию и поиск по родительской категории.
 * - Поддерживает "корзину" (status=0) и активные записи (status=1).
 * - Поддерживает массовые действия (в корзину / восстановить / удалить навсегда).
 * - ФИЛЬТРАЦИЯ ПО users_id = $_SESSION['user_id'] для основных и родительских записей.
 *
 * Важно:
 * - Таблица задаётся в настройках ($catalogTable = 'news_categories').
 * - Все данные хранятся в отдельных колонках (name, url, parent_id, meta_title, meta_description, description, image).
 * - Изображение берётся из колонки image (не JSON).
 *
 * Безопасность:
 * - PDO не поддерживает плейсхолдеры для имён таблиц, поэтому таблицу нельзя брать из GET/POST.
 *   Здесь $catalogTable задаётся вручную (глобальная настройка).
 * - Все запросы используют prepared statements для защиты от SQL-инъекций.
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
    'start_session'   => true,          // запуск Session
    'csrf_token'      => true,          // генерация CSRF-токена
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../../../../admin/functions/init.php';

// Подключаем дополнительную инициализацию
require_once __DIR__ . '/../../functions/plugin_helper.php';         // Функция для автоопределения имени плагина
require_once __DIR__ . '/../../functions/category_path.php';         // Функция для построения полного пути категории
require_once __DIR__ . '/../../functions/pagination.php';            // Функция для генерации HTML пагинации
require_once __DIR__ . '/../../functions/get_record_avatar.php';     // Функция для получения изображения записи

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
$titlemeta = 'Новости';                            // Название заголовка H1 для раздела
$titlemetah3 = 'Управление каталогом';             // Название заголовка H2 для раздела
$catalogTable = 'news_categories';                 // Название таблицы
$categoryUrlPrefix = 'news-category';              // Префикс URL категории

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

// =============================================================================
// ПРОВЕРКА ДОСТУПА К ПЛАГИНУ
// =============================================================================
$pluginName = getPluginName();  // Автоматическое определение имени плагина из структуры директорий
$userDataAdmin = pluginAccessGuard($pdo, $pluginName);
$currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

// =============================================================================
// ПАРАМЕТРЫ/ФИЛЬТРЫ (GET) - Валидация и безопасная обработка
// =============================================================================

// Режим "корзины" - валидация значения
$isTrash = false;
if (isset($_GET['trash'])) {
    $trashValue = filter_var($_GET['trash'], FILTER_VALIDATE_INT);
    $isTrash = ($trashValue === 1);
}

// Поиск по названию - валидация и очистка
$search = '';
if (isset($_GET['search'])) {
    $searchResult = validateTextareaField($_GET['search'], 0, 100, 'Поиск по названию');
    if ($searchResult['valid']) {
        $search = trim($searchResult['value']);
    }
}

// Поиск по названию родительской категории - валидация и очистка
$search_catalog = '';
if (isset($_GET['search_catalog'])) {
    $searchCatalogResult = validateTextareaField($_GET['search_catalog'], 0, 100, 'Поиск по родительской категории');
    if ($searchCatalogResult['valid']) {
        $search_catalog = trim($searchCatalogResult['value']);
    }
}

// Пагинация - валидация номера страницы
$page = 1;
if (isset($_GET['page'])) {
    $pageResult = filter_var($_GET['page'], FILTER_VALIDATE_INT, [
        'options' => [
            'default' => 1,
            'min_range' => 1
        ]
    ]);
    $page = max(1, $pageResult);
}
$limit = 30;
$offset = ($page - 1) * $limit;

// Текущий user_id из сессии - безопасное получение
$currentUserId = 0;
if (isset($_SESSION['user_id'])) {
    $currentUserId = max(0, (int)$_SESSION['user_id']);
}

// =============================================================================
// ОБРАБОТКА МАССОВЫХ ДЕЙСТВИЙ (POST) - Валидация и безопасная обработка
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['user_ids'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Пожалуйста, обновите страницу.';
        logEvent(
            "Проверка CSRF-токена не пройдена — ID администратора: " . ($user['id'] ?? 'unknown') .
            " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            LOG_ERROR_ENABLED,
            'error'
        );
    } else {
        $action = null;
        if (is_string($_POST['action'])) {
            // Валидация поля action
            $actionRaw = trim($_POST['action']);
            $actionResult = validateTextareaField($actionRaw, 1, 20, 'Действие');
            if ($actionResult['valid']) {
                $action = $actionResult['value'];
            } else {
                $errors[] = $actionResult['error'];
            }
        }

    // Нормализуем и валидируем ID записей
    $userIds = [];
    if (is_array($_POST['user_ids'])) {
        foreach ($_POST['user_ids'] as $id) {
            $validatedId = filter_var($id, FILTER_VALIDATE_INT);
            if ($validatedId !== false && $validatedId > 0) {
                $userIds[] = (int)$validatedId;
            }
        }
    } else {
        $singleId = filter_var($_POST['user_ids'], FILTER_VALIDATE_INT);
        if ($singleId !== false && $singleId > 0) {
            $userIds[] = (int)$singleId;
        }
    }

    // Ограничение на количество обрабатываемых ID
    $userIds = array_slice($userIds, 0, 100); // Максимум 100 за раз

    if (!empty($userIds) && $action !== null) {
        // Проверка разрешенного действия
        $allowedActions = ['delete', 'restore', 'trash'];
        if (!in_array($action, $allowedActions)) {
            $errors[] = 'Недопустимое действие.';
        } else {
            try {
                $placeholders = str_repeat('?,', count($userIds) - 1) . '?';

                switch ($action) {
                    case 'delete':
                        if ($isTrash) {
                            // Полное удаление из корзины
                            foreach ($userIds as $userId) {
                                $stmt = $pdo->prepare("DELETE FROM {$catalogTable} WHERE id = ? AND status = 0 AND users_id = ?");
                                $stmt->execute([$userId, $currentUserId]);
                            }
                            $successMessages[] = 'Записи успешно удалены';
                        } else {
                            // Перемещение в корзину
                            $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 0 WHERE id IN ($placeholders) AND users_id = ?");
                            $stmt->execute(array_merge($userIds, [$currentUserId]));
                            $successMessages[] = 'Записи перемещены в корзину';
                        }
                        break;

                    case 'restore':
                        // Восстановление из корзины
                        $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 1 WHERE id IN ($placeholders) AND users_id = ?");
                        $stmt->execute(array_merge($userIds, [$currentUserId]));
                        $successMessages[] = 'Записи восстановлены';
                        break;

                    case 'trash':
                        // Добавление в корзину
                        $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 0 WHERE id IN ($placeholders) AND users_id = ?");
                        $stmt->execute(array_merge($userIds, [$currentUserId]));
                        $successMessages[] = 'Записи перемещены в корзину';
                        break;
                }

                // Логирование действий
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $adminId = $user['id'] ?? 'unknown';
                logEvent("Выполнено массовое действие '$action' администратором ID: $adminId над {$catalogTable} IDs: " . implode(',', $userIds) . " — users_id=$currentUserId — IP: $ip", LOG_INFO_ENABLED, 'info');
            } catch (PDOException $e) {
                // Проверка на ошибку foreign key constraint (код 23000 или 1451)
                $errorCode = $e->getCode();
                $errorInfo = $e->errorInfo;
                $mysqlErrorCode = $errorInfo[1] ?? null;
                
                // Код 1451 - Cannot delete or update a parent row: a foreign key constraint fails
                if ($errorCode === '23000' || $mysqlErrorCode === 1451) {
                    if ($action === 'delete' && $isTrash) {
                        $errors[] = 'Невозможно удалить категорию: на неё ссылаются новости или другие записи. Сначала удалите или переместите связанные записи.';
                    } else {
                        $errors[] = 'Ошибка ограничения внешнего ключа: на данную запись ссылаются другие записи.';
                    }
                } else {
                    $errors[] = 'Ошибка при выполнении операции';
                }
                logEvent("Ошибка БД при массовом действии '$action' ({$catalogTable}): " . $e->getMessage() . " — ID админа: " . ($user['id'] ?? 'unknown') . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
            }
        }
    } else {
        $errors[] = 'Не выбраны записи для действия.';
    }
    }
}

// =============================================================================
// ПОЛУЧЕНИЕ ДАННЫХ ИЗ ТАБЛИЦЫ (с фильтром related_table И users_id) - безопасная обработка
// =============================================================================

try {
    $query = "
        SELECT
            id,
            name,
            url,
            image,
            meta_title,
            created_at,
            parent_id
        FROM {$catalogTable}
        WHERE status = :status
          AND users_id = :users_id
    ";

    $countQuery = "
        SELECT COUNT(*)
        FROM {$catalogTable}
        WHERE status = :status
          AND users_id = :users_id
    ";

    $params = [
        ':status' => $isTrash ? 0 : 1,
        ':users_id' => $currentUserId,
    ];

    // Поиск по названию
    if ($search !== '') {
        $query .= " AND name LIKE :search";
        $countQuery .= " AND name LIKE :search";
        $params[':search'] = "%{$search}%";
    }

    // Поиск по родительской категории (родитель проверяем по users_id)
    if ($search_catalog !== '') {
        // Используем уникальные имена параметров для подзапроса
        $query .= " AND EXISTS (
                        SELECT 1
                        FROM {$catalogTable} p
                        WHERE p.id = {$catalogTable}.parent_id
                          AND p.status = 1
                          AND p.users_id = :users_id_search
                          AND p.name LIKE :search_catalog_search
                    )";
        $countQuery .= " AND EXISTS (
                            SELECT 1
                            FROM {$catalogTable} p
                            WHERE p.id = {$catalogTable}.parent_id
                              AND p.status = 1
                              AND p.users_id = :users_id_search
                              AND p.name LIKE :search_catalog_search
                        )";

        // Уникальные параметры для подзапроса
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
    // Подгружаем имена родителей (parent_id = ID родителя) ТОЛЬКО для текущего users_id
    // -------------------------------------------------------------------------
    $catalogMap = [];
    $catalogIds = [];

    foreach ($users as $u) {
        $pid = (int)($u['parent_id'] ?? 0);
        if ($pid > 0) {
            $catalogIds[] = $pid;
        }
    }
    $catalogIds = array_values(array_unique($catalogIds));

    if (!empty($catalogIds)) {
        $ph = implode(',', array_fill(0, count($catalogIds), '?'));
        $pStmt = $pdo->prepare("SELECT id, name FROM {$catalogTable} WHERE users_id = ? AND id IN ($ph) AND status = 1");
        $pStmt->execute(array_merge([$currentUserId], $catalogIds));

        while ($row = $pStmt->fetch(PDO::FETCH_ASSOC)) {
            $catalogMap[(int)$row['id']] = $row['name'];
        }
    }

    foreach ($users as &$u) {
        $pid = (int)($u['parent_id'] ?? 0);
        $u['catalog_name'] = $pid > 0 ? ($catalogMap[$pid] ?? '') : '';
        
        // Генерируем полный URL с учетом родительских категорий
        $u['full_url'] = buildNewsCategoryPath($pdo, (int)$u['id'], $currentUserId);
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
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../../../../admin/img/avatar.svg');
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

    <!-- Стили админки -->
    <link rel="stylesheet" href="../../../../admin/css/main.css">
    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
</head>
<body>
    <div class="container-fluid">
        <?php require_once __DIR__ . '/../../../../admin/template/sidebar.php'; ?>

        <main class="main-content">
            <?php require_once __DIR__ . '/../../../../admin/template/header.php'; ?>
            
            <div class="content-card table-card">
                <div class="row align-items-center mb-4">
                    <div class="col-lg-6">
                        <h3 class="card-title d-flex align-items-center gap-2 mb-0">
                            <i class="bi bi-grid"></i>
                            <?= escape($isTrash ? 'Корзина' : $titlemetah3) ?>
                        </h3>
                    </div>
                    <div class="col-lg-6 text-end">
                        <a href="add_category.php"
                           class="btn btn-outline-primary" 
                           title="Добавить новую запись">
                            <i class="bi bi-plus-circle"></i> Добавить
                        </a>
                    </div>
                </div>
                <!-- Отображение сообщений -->
                <?php displayAlerts(
                    $successMessages,  // Массив сообщений об успехе
                    $errors,           // Массив сообщений об ошибках
                    true               // Показывать сообщения как toast-уведомления
                ); 
                ?>

                <?php if (!$isTrash): ?>
                    <?php
                        $trashCountStmt = $pdo->prepare("SELECT COUNT(*) FROM {$catalogTable} WHERE status = 0 AND users_id = ?");
                        $trashCountStmt->execute([$currentUserId]);
                        $trashCount = (int)$trashCountStmt->fetchColumn();
                    ?>
                    <?php if ($trashCount > 0): ?>
                        <a href="?trash=1" class="btn btn-outline-danger mb-3">
                            <i class="bi bi-trash"></i> Корзина
                            <span class="badge bg-danger"><?= escape((string)$trashCount) ?></span>
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="category_list.php" class="btn btn-outline-primary mb-3">
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
                                        <a href="<?= escape($isTrash ? 'category_list.php?trash=1' : 'category_list.php') ?>"
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
                                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
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
                                <th width="60">Фото</th>
                                <th>Название</th>
                                <th>Родительская категория</th>
                                <th width="80" class="text-center">URL</th>
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
                                            <?= $isTrash ? 'Корзина пуста' : 'Записи не найдены' ?>
                                            <?php if ($currentUserId > 0): ?>
                                                <br><small class="text-muted">для пользователя ID: <?= escape((string)$currentUserId) ?></small>
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
                                            // Используем новую функцию getRecordAvatar для получения изображения
                                            $rowImage = getRecordAvatar(
                                                $pdo,                      // Объект PDO для подключения к базе данных
                                                (int)$row['id'],           // ID записи
                                                $currentUserId,            // ID пользователя
                                                $catalogTable              // Название таблицы (например: 'news_categories')
                                            );
                                        ?>

                                        <td>
                                            <div class="user-avatar" style="width: 40px; height: 40px;">
                                                <img src="<?= escape($rowImage) ?>"
                                                     alt="<?= escape($row['name'] ?? '') ?>"
                                                     style="width: 100%; height: 100%; object-fit: cover;">
                                            </div>
                                        </td>

                                        <td>
                                            <a href="add_category.php?id=<?= (int)$row['id'] ?>" class="user-link text-decoration-none">
                                                <?= escape($row['name'] ?? '') ?>
                                            </a>
                                            <span class="badge bg-success"><?= 'ID: ' . (int)$row['id'] ?></span>
                                            
                                            <?php if (!empty($row['meta_title'])): ?>
                                                <div class="text-muted small">SEO заголовок: <?= escape($row['meta_title']) ?></div>
                                            <?php endif; ?>
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
                                                —
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-center">
                                            <?php if (!empty($row['full_url'])): ?>
                                                <a href="/<?= escape($categoryUrlPrefix) ?>/<?= escape($row['full_url']) ?>"
                                                   target="_blank"
                                                   rel="noopener noreferrer"
                                                   class="btn btn-sm btn-link p-0 border-0 text-decoration-none fw-bold"
                                                   title="Открыть в новом окне">
                                                    <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= !empty($row['created_at']) ? escape(date('d.m.Y H:i', strtotime($row['created_at']))) : '' ?>
                                        </td>

                                        <td>
                                            <div class="btn-group" style="gap: 4px;">
                                                <a href="add_category.php?id=<?= (int)$row['id'] ?>"
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

                <?php 
                // Генерация HTML пагинации (Bootstrap 5) на основе текущей страницы, общего количества страниц
                // и GET-параметров поиска/фильтров. Функция renderPagination возвращает готовый HTML.
                echo renderPagination(
                    $page,                                           // Текущая страница
                    $totalPages,                                     // Общее количество страниц
                    array_filter([                                   // Массив GET-параметров для формирования ссылок пагинации. Можно менять
                        'trash' => $isTrash ? '1' : null,           // Фильтр: показывать корзину (1) или нет (null)
                        'search' => $search,                         // Фильтр: поиск по названию записи
                        'search_catalog' => $search_catalog,         // Фильтр: поиск по родительской категории
                    ])
                ); 
                ?>
            </div>
        </main>
    </div>

    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Модульный JS admin -->
    <script type="module" src="../../../../admin/js/main.js"></script>
</body>
</html>
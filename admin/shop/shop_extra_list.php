<?php
/**
 * Файл: /admin/shop/shop_extra_list.php
 *
 * Назначение:
 * - Админ-страница со списком дополнительных элементов каталога (таблица shop_extra).
 * - Поддерживает поиск по названию.
 * - Поддерживает "корзину" (status=0) и активные записи (status=1).
 * - Поддерживает массовые действия (в корзину / восстановить / удалить навсегда).
 * - ФИЛЬТРАЦИЯ ПО users_id = $_SESSION['user_id'] И author из GET параметра.
 *
 * Важно:
 * - Таблица shop_extra задаётся в настройках ($catalogTable) — меняется в одном месте.
 * - НЕТ фильтра related_table — все записи из этой таблицы.
 * - Изображение берётся из JSON-колонки data по ключу $.image (оператор ->> возвращает текст).
 * - Поле author используется для фильтрации из GET параметра author.
 *
 * МЕСТО ДЛЯ ДОБАВЛЕНИЯ НОВЫХ ПОЛЕЙ ИЗ JSON (data):
 * 1. В SELECT запросе добавить: (data ->> '$.your_key') AS your_alias
 * 2. В таблице вывести: $row['your_alias']
 * Пример:
 *   (data ->> '$.subtitle') AS subtitle
 *   (data ->> '$.color') AS color
 * Затем в HTML: <?= escape($row['subtitle']) ?>
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

// Имя таблицы shop_extra
$catalogTable = 'shop_extra';

// Имя таблицы для проверки author (Привязка записи к родительской таблице)
$authorCheckTable = 'shop';

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
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
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

    // ★★★ ПРОВЕРКА ПРАВ ДОСТУПА К МАГАЗИНУ ★★★
    $hasShopAccess = false;
    if ($userDataAdmin['author'] === 'admin' && ($adminData['allow_shop_admin'] ?? false) === true) {
        $hasShopAccess = true;
    } elseif ($userDataAdmin['author'] === 'user' && ($adminData['allow_shop_users'] ?? false) === true) {
        $hasShopAccess = true;
    }

    // Если доступ запрещен - ЛОГАУТ!
    if (!$hasShopAccess) {
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
// ПРОВЕРКА GET ПАРАМЕТРА AUTHOR
// =============================================================================

// Проверка обязательного параметра author
$authorId = isset($_GET['author']) ? (int)$_GET['author'] : 0;

// Если существует GET параметр author и он содержит значение (ссылку)
if ($authorId > 0) {
    try {
        // Проверяем существование записи в таблице $authorCheckTable
        $checkStmt = $pdo->prepare("SELECT id, url FROM {$authorCheckTable} WHERE id = ? AND users_id = ?");
        $checkStmt->execute([$authorId, $_SESSION['user_id']]);
        $authorExists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        // Если запись не найдена - редирект на product_list.php
        if (!$authorExists) {
            logEvent("Попытка доступа к несуществующему author ID: $authorId для users_id: {$_SESSION['user_id']} в таблице {$authorCheckTable} — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_INFO_ENABLED, 'info');
            // Закрываем соединение при завершении скрипта
            register_shutdown_function(function() {
                if (isset($pdo)) {
                    $pdo = null; 
                }
            });
            header("Location: product_list.php");
            exit;
        } else {
            $defaultUrl = $authorExists['url'] ?? '';
        }
    } catch (PDOException $e) {
        logEvent("Ошибка проверки author в таблице {$authorCheckTable}: " . $e->getMessage() . " — authorId: $authorId, users_id: {$_SESSION['user_id']} — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: product_list.php");
        exit;
    }
}

// =============================================================================
// ПАРАМЕТРЫ/ФИЛЬТРЫ (GET)
// =============================================================================

// Режим "корзины"
$isTrash = isset($_GET['trash']) && $_GET['trash'] == 1;

// Поиск по названию
$search = trim($_GET['search'] ?? '');

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

    // Нормализуем ID записей
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
                            $stmt = $pdo->prepare("DELETE FROM {$catalogTable} WHERE id = ? AND status = 0 AND users_id = ? AND author = ?");
                            $stmt->execute([$userId, $currentUserId, $authorId]);
                        }
                        $successMessages[] = 'Записи успешно удалены';
                    } else {
                        // Перемещение в корзину
                        $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 0 WHERE id IN ($placeholders) AND users_id = ? AND author = ?");
                        $stmt->execute(array_merge($userIds, [$currentUserId, $authorId]));
                        $successMessages[] = 'Записи перемещены в корзину';
                    }
                    break;

                case 'restore':
                    // Восстановление из корзины
                    $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 1 WHERE id IN ($placeholders) AND users_id = ? AND author = ?");
                    $stmt->execute(array_merge($userIds, [$currentUserId, $authorId]));
                    $successMessages[] = 'Записи восстановлены';
                    break;

                case 'trash':
                    // Добавление в корзину
                    $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 0 WHERE id IN ($placeholders) AND users_id = ? AND author = ?");
                    $stmt->execute(array_merge($userIds, [$currentUserId, $authorId]));
                    $successMessages[] = 'Записи перемещены в корзину';
                    break;

                default:
                    $errors[] = 'Недопустимое действие.';
                    break;
            }

            // Логирование действий
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $adminId = $user['id'] ?? 'unknown';
            logEvent("Выполнено массовое действие '$action' администратором ID: $adminId над {$catalogTable} IDs: " . implode(',', $userIds) . " — users_id=$currentUserId author=$authorId — IP: $ip", LOG_INFO_ENABLED, 'info');

        } catch (PDOException $e) {
            $errors[] = 'Ошибка при выполнении операции';
            logEvent("Ошибка БД при массовом действии '$action' ({$catalogTable}): " . $e->getMessage() . " — ID админа: " . ($user['id'] ?? 'unknown') . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
        }
    } else {
        $errors[] = 'Не выбраны записи для действия.';
    }
}

// =============================================================================
// ПОЛУЧЕНИЕ ДАННЫХ ИЗ ТАБЛИЦЫ shop_extra (с фильтром users_id И author)
// МЕСТО ДЛЯ ДОБАВЛЕНИЯ НОВЫХ ПОЛЕЙ ИЗ JSON:
// Добавляйте в SELECT: (data ->> '$.your_key') AS your_alias
// Пример: (data ->> '$.subtitle') AS subtitle
// =============================================================================
try {
    $query = "
        SELECT
            id,
            naime,
            (data ->> '$.image') AS image,
            (data ->> '$.style') AS style,
            created_at,
            author
        FROM {$catalogTable}
        WHERE status = :status
          AND users_id = :users_id
          AND author = :author
    ";

    $countQuery = "
        SELECT COUNT(*)
        FROM {$catalogTable}
        WHERE status = :status
          AND users_id = :users_id
          AND author = :author
    ";

    $params = [
        ':status' => $isTrash ? 0 : 1,
        ':users_id' => $currentUserId,
        ':author' => $authorId,
    ];

    // Поиск по названию
    if ($search !== '') {
        $query .= " AND naime LIKE :search";
        $countQuery .= " AND naime LIKE :search";
        $params[':search'] = "%{$search}%";
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

    $totalPages = (int)ceil($totalUsers / $limit);

} catch (PDOException $e) {
    $errors[] = 'Ошибка при загрузке данных каталога';
    logEvent("Ошибка базы данных при загрузке списка {$catalogTable}: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
}

// =============================================================================
// Подготовка шаблона
// =============================================================================
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
$titlemeta = 'Магазин';

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
        
        <!-- Меню настройки товара -->
        <?php 
            if (isset($_GET['author']) && !empty($_GET['author']) && is_numeric($_GET['author'])) { 
                require_once __DIR__ . '/header.php'; 
            } 
        ?>
        <div class="content-card table-card">
            <div class="row align-items-center mb-4">
                <div class="col-lg-6">
                    <h3 class="card-title d-flex align-items-center gap-2 mb-0">
                        <i class="bi bi-grid"></i>
                        <?= $isTrash ? 'Корзина' : 'Описание товара' ?>
                    </h3>
                </div>
                <div class="col-lg-6 text-end">
                    <a href="add_shop_extra.php?author=<?= $authorId ?>"
                        class="btn btn-outline-primary" 
                        title="Добавить новую запись">
                        <i class="bi bi-plus-circle"></i> Добавить
                    </a>
                </div>
            </div>

            <!-- Сообщения об ошибках/успехе -->
            <?php displayAlerts($successMessages, $errors); ?>

            <?php if (!$isTrash): ?>
                <?php
                    $trashCountStmt = $pdo->prepare("SELECT COUNT(*) FROM {$catalogTable} WHERE status = 0 AND users_id = ? AND author = ?");
                    $trashCountStmt->execute([$currentUserId, $authorId]);
                    $trashCount = (int)$trashCountStmt->fetchColumn();
                ?>
                <?php if ($trashCount > 0): ?>
                    <a href="?author=<?= $authorId ?>&trash=1" class="btn btn-outline-danger mb-3">
                        <i class="bi bi-trash"></i> Корзина
                        <span class="badge bg-danger"><?= escape((string)$trashCount) ?></span>
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <a href="?author=<?= $authorId ?>" class="btn btn-outline-primary mb-3">
                    <i class="bi bi-arrow-left"></i> Выйти из корзины
                </a>
            <?php endif; ?>


            <!-- Панель управления с поиском и действиями -->
            <div class="table-controls mb-3">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <!-- Форма поиска по названию -->
                        <form method="GET" class="d-flex mb-3">
                            <input type="hidden" name="author" value="<?= $authorId ?>">
                            <?php if ($isTrash): ?>
                                <input type="hidden" name="trash" value="1">
                            <?php endif; ?>
                            <div class="input-group">
                                <input type="text"
                                       name="search"
                                       class="form-control"
                                       placeholder="Поиск по названию..."
                                       value="<?= escape($search) ?>">
                                <button type="submit" class="btn btn-outline-primary">
                                    <i class="bi bi-search"></i>
                                </button>
                                <?php if (!empty($search)): ?>
                                    <!-- Кнопка сброса поиска -->
                                    <a href="?author=<?= $authorId ?><?= $isTrash ? '&trash=1' : '' ?>"
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

                                <!-- Чекбоксы для пользователей (внутри формы, но вне таблицы — для корректного submit) -->
                                <?php foreach ($users as $user): ?>
                                    <input type="hidden" name="user_ids[]" value="<?= (int)$user['id'] ?>" class="user-id-input">
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
                            <th>Стиль</th>
                            <th>Дата</th>
                            <th width="100">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <i class="bi bi-inbox display-4"></i>
                                    <p class="mt-2 auth-subtitle">
                                        <?= $isTrash ? 'Корзина пуста' : 'Записи не найдены' ?>
                                        <?php if ($currentUserId > 0): ?>
                                            <br><small class="text-muted">для пользователя ID: <?= $currentUserId ?> (author: <?= $authorId ?>)</small>
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
                                        <a href="add_shop_extra.php?id=<?= (int)$row['id'] ?>&author=<?= $authorId ?>" class="user-link text-decoration-none">
                                            <?= escape($row['naime'] ?? '') ?>
                                        </a>
                                        <span class="badge bg-success"><?= 'ID: ' . (int)$row['id'] ?></span>
                                    </td>

                                    <td>
                                        <?= escape((string)($row['style'] ?? '—')) ?>
                                    </td>

                                    <td>
                                        <?= !empty($row['created_at']) ? date('d.m.Y H:i', strtotime($row['created_at'])) : '' ?>
                                    </td>

                                    <td>
                                        <div class="btn-group" style="gap: 4px;">
                                            <a href="add_shop_extra.php?id=<?= (int)$row['id'] ?>&author=<?= $authorId ?>"
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
                                        'author' => $authorId,
                                        'trash'  => $_GET['trash'] ?? null,
                                        'search' => $search,
                                        'page'   => $i
                                    ])) ?>"><?= escape((string)$i) ?></a>
                                </li>
                            <?php endfor; ?>
                        <?php else: ?>
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link"
                                   href="<?= $page <= 1 ? '#' : '?' . http_build_query(array_filter([
                                       'author' => $authorId,
                                       'trash'  => $_GET['trash'] ?? null,
                                       'search' => $search,
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
                                        'author' => $authorId,
                                        'trash'  => $_GET['trash'] ?? null,
                                        'search' => $search,
                                        'page'   => $p
                                    ]));
                                    echo '">' . escape((string)$p) . '</a></li>';
                                    $last = $p;
                                }
                            ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link"
                                   href="<?= $page >= $totalPages ? '#' : '?' . http_build_query(array_filter([
                                       'author' => $authorId,
                                       'trash'  => $_GET['trash'] ?? null,
                                       'search' => $search,
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

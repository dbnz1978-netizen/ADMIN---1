<?php
/**
 * Файл: /plugins/news-plugin/pages/articles/extra_list.php
 *
 * Назначение:
 * - Админ-страница со списком дополнительных элементов новостей (таблица news_extra_content).
 * - Поддерживает поиск по названию.
 * - Поддерживает "корзину" (status=0) и активные записи (status=1).
 * - Поддерживает массовые действия (в корзину / восстановить / удалить навсегда).
 * - ФИЛЬТРАЦИЯ ПО users_id = $_SESSION['user_id'] И news_id из GET параметра.
 *
 * Важно:
 * - Таблица news_extra_content задаётся в настройках ($catalogTable) — меняется в одном месте.
 * - НЕТ фильтра related_table — все записи из этой таблицы.
 * - Изображение берётся из колонки image (прямая колонка, не JSON).
 * - Контент берётся из колонки content (прямая колонка, не JSON).
 * - Поле news_id используется для фильтрации из GET параметра news_id.
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
    'plugin_access'   => true,          // подключение систему управления доступом к плагинам
    'mime_validation' => true,          // подключаем систему для проверки MIME-типов файлов
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../../../../admin/functions/init.php';

// Подключаем дополнительную инициализацию
require_once __DIR__ . '/../../functions/plugin_helper.php';         // Функция для автоопределения имени плагина
require_once __DIR__ . '/../../functions/pagination.php';            // Функция для генерации HTML пагинации 
require_once __DIR__ . '/../../functions/get_record_avatar.php';     // Функция для получения изображения записи


// =============================================================================
// Проверка прав администратора
// =============================================================================
$adminData = getAdminData($pdo);
if ($adminData === false) {
    header("Location: ../../../../admin/logout.php");
    exit;
}

// === НАСТРОЙКИ ===
$titlemeta = 'Новости';                      // Название заголовка H1 для раздела
$titlemetah3 = 'Дополнительный контент';     // Название заголовка H2 для раздела
$catalogTable = 'news_extra_content';        // Название таблицы (подключение у записи)
$authorCheckTable = 'news_articles';         // Привязка записи к родительской таблице

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
// ПРОВЕРКА GET ПАРАМЕТРА NEWS_ID
// =============================================================================

// Проверка обязательного параметра news_id
$newsId = isset($_GET['news_id']) ? (int)$_GET['news_id'] : 0;
// Валидация newsId
if ($newsId < 0) {
    $newsId = 0;
}

// Если существует GET параметр news_id и он содержит значение (ссылку)
if ($newsId > 0) {
    try {
        // Проверяем существование записи в таблице $authorCheckTable
        $checkStmt = $pdo->prepare("SELECT id, url FROM {$authorCheckTable} WHERE id = ? AND users_id = ?");
        $checkStmt->execute([$newsId, $_SESSION['user_id']]);
        $authorExists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        // Если запись не найдена - редирект на article_list.php
        if (!$authorExists) {
            logEvent("Попытка доступа к несуществующему news_id: $newsId для users_id: {$_SESSION['user_id']} в таблице {$authorCheckTable} — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_INFO_ENABLED, 'info');
            header("Location: article_list.php");
            exit;
        } else {
            $defaultUrl = $authorExists['url'] ?? '';
        }
    } catch (PDOException $e) {
        logEvent("Ошибка проверки news_id в таблице {$authorCheckTable}: " . $e->getMessage() . " — newsId: $newsId, users_id: {$_SESSION['user_id']} — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
        header("Location: article_list.php");
        exit;
    }
}

// =============================================================================
// ПАРАМЕТРЫ/ФИЛЬТРЫ (GET)
// =============================================================================

// Режим "корзины"
$isTrash = isset($_GET['trash']) && filter_var($_GET['trash'], FILTER_VALIDATE_BOOLEAN);

// Поиск по названию
$search = '';
if (isset($_GET['search'])) {
    $searchResult = validateTextareaField($_GET['search'], 0, 100, 'Поиск по названию');
    if ($searchResult['valid']) {
        $search = trim($searchResult['value']);
    }
}

// Поиск по родительской категории
$search_catalog = '';
if (isset($_GET['search_catalog'])) {
    $searchCatalogResult = validateTextareaField($_GET['search_catalog'], 0, 100, 'Поиск по родительской категории');
    if ($searchCatalogResult['valid']) {
        $search_catalog = trim($searchCatalogResult['value']);
    }
}

// Пагинация
$page = max(1, (int)(isset($_GET['page']) ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : 1));
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
        $actionResult = validateTextareaField(trim($_POST['action'] ?? ''), 1, 20, 'Действие');
        $action = $actionResult['valid'] ? $actionResult['value'] : '';
        $allowedActions = ['delete', 'restore', 'trash'];
        
        if (!$actionResult['valid'] || !in_array($action, $allowedActions)) {
            $errors[] = 'Недопустимое действие.';
        } else {
            // Нормализуем ID записей
            $rawUserIds = $_POST['user_ids'];
            if (is_array($rawUserIds)) {
                $userIds = array_map(function($id) {
                    return (int)filter_var($id, FILTER_VALIDATE_INT);
                }, $rawUserIds);
                $userIds = array_filter($userIds, function($id) {
                    return $id > 0;
                });
            } else {
                $singleId = (int)filter_var($rawUserIds, FILTER_VALIDATE_INT);
                $userIds = $singleId > 0 ? [$singleId] : [];
            }

            if (!empty($userIds)) {
                try {
                    $placeholders = str_repeat('?,', count($userIds) - 1) . '?';

                    switch ($action) {
                        case 'delete':
                            if ($isTrash) {
                                // Полное удаление из корзины
                                foreach ($userIds as $userId) {
                                    $stmt = $pdo->prepare("DELETE FROM {$catalogTable} WHERE id = ? AND status = 0 AND users_id = ? AND news_id = ?");
                                    $stmt->execute([$userId, $currentUserId, $newsId]);
                                }
                                $successMessages[] = 'Записи успешно удалены';
                            } else {
                                // Перемещение в корзину
                                $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 0 WHERE id IN ($placeholders) AND users_id = ? AND news_id = ?");
                                $stmt->execute(array_merge($userIds, [$currentUserId, $newsId]));
                                $successMessages[] = 'Записи перемещены в корзину';
                            }
                            break;

                        case 'restore':
                            // Восстановление из корзины
                            $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 1 WHERE id IN ($placeholders) AND users_id = ? AND news_id = ?");
                            $stmt->execute(array_merge($userIds, [$currentUserId, $newsId]));
                            $successMessages[] = 'Записи восстановлены';
                            break;

                        case 'trash':
                            // Добавление в корзину
                            $stmt = $pdo->prepare("UPDATE {$catalogTable} SET status = 0 WHERE id IN ($placeholders) AND users_id = ? AND news_id = ?");
                            $stmt->execute(array_merge($userIds, [$currentUserId, $newsId]));
                            $successMessages[] = 'Записи перемещены в корзину';
                            break;

                        default:
                            $errors[] = 'Недопустимое действие.';
                            break;
                    }

                    // Логирование действий
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $adminId = $user['id'] ?? 'unknown';
                    logEvent("Выполнено массовое действие '$action' администратором ID: $adminId над {$catalogTable} IDs: " . implode(',', $userIds) . " — users_id=$currentUserId news_id=$newsId — IP: $ip", LOG_INFO_ENABLED, 'info');

                } catch (PDOException $e) {
                    $errors[] = 'Ошибка при выполнении операции';
                    logEvent("Ошибка БД при массовом действии '$action' ({$catalogTable}): " . $e->getMessage() . " — ID админа: " . ($user['id'] ?? 'unknown') . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
                }
            } else {
                $errors[] = 'Не выбраны записи для действия.';
            }
        }
    }
}

// =============================================================================
// ПОЛУЧЕНИЕ ДАННЫХ ИЗ ТАБЛИЦЫ news_extra_content (с фильтром users_id И news_id)
// =============================================================================

// Инициализация переменных для предотвращения ошибок "Undefined variable"
$users = [];
$totalPages = 0;

try {
    $query = "
        SELECT
            id,
            title,
            image,
            content,
            created_at,
            news_id
        FROM {$catalogTable}
        WHERE status = :status
          AND users_id = :users_id
          AND news_id = :news_id
    ";

    $countQuery = "
        SELECT COUNT(*)
        FROM {$catalogTable}
        WHERE status = :status
          AND users_id = :users_id
          AND news_id = :news_id
    ";

    $params = [
        ':status' => $isTrash ? 0 : 1,
        ':users_id' => $currentUserId,
        ':news_id' => $newsId,
    ];

    // Поиск по названию
    if ($search !== '') {
        $query .= " AND title LIKE :search";
        $countQuery .= " AND title LIKE :search";
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
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../../../../admin/img/avatar.svg');
$titlemeta = 'Новости';

// Закрываем соединение при завершении скрипта

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
    <link rel="stylesheet" href="../../../../admin/css/main.css">
    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
</head>
<body>
<div class="container-fluid">
    <?php require_once __DIR__ . '/../../../../admin/template/sidebar.php'; ?>

    <main class="main-content">
        <?php require_once __DIR__ . '/../../../../admin/template/header.php'; ?>
        
        <!-- Меню настройки товара -->
        <?php 
            if (isset($_GET['news_id']) && !empty($_GET['news_id']) && is_numeric($_GET['news_id'])) { 
                require_once __DIR__ . '/header.php'; 
            } 
        ?>
        <div class="content-card table-card">
            <div class="row align-items-center mb-4">
                <div class="col-lg-6">
                    <h3 class="card-title d-flex align-items-center gap-2 mb-0">
                        <i class="bi bi-grid"></i>
                        <?= escape($isTrash ? 'Корзина' : $titlemetah3) ?>
                    </h3>
                </div>
                <div class="col-lg-6 text-end">
                    <a href="add_extra.php?news_id=<?= $newsId ?>"
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
                    $trashCountStmt = $pdo->prepare("SELECT COUNT(*) FROM {$catalogTable} WHERE status = 0 AND users_id = ? AND news_id = ?");
                    $trashCountStmt->execute([$currentUserId, $newsId]);
                    $trashCount = (int)$trashCountStmt->fetchColumn();
                ?>
                <?php if ($trashCount > 0): ?>
                    <a href="?news_id=<?= $newsId ?>&trash=1" class="btn btn-outline-danger mb-3">
                        <i class="bi bi-trash"></i> Корзина
                        <span class="badge bg-danger"><?= escape((string)$trashCount) ?></span>
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <a href="?news_id=<?= $newsId ?>" class="btn btn-outline-primary mb-3">
                    <i class="bi bi-arrow-left"></i> Выйти из корзины
                </a>
            <?php endif; ?>

            <!-- Панель управления с поиском и действиями -->
            <div class="table-controls mb-3">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <!-- Форма поиска по названию -->
                        <form method="GET" class="d-flex mb-3">
                            <input type="hidden" name="news_id" value="<?= $newsId ?>">
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
                                    <a href="?news_id=<?= $newsId ?><?= $isTrash ? '&trash=1' : '' ?>"
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
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
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
                            <th width="60">Фото</th>
                            <th>Название</th>
                            <th>Контент</th>
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
                                            <br><small class="text-muted">для пользователя ID: <?= $currentUserId ?> (news_id: <?= $newsId ?>)</small>
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
                                            $pdo,                      //Объект PDO для подключения к базе данных
                                            (int)$row['id'],           //ID записи
                                            $currentUserId,            //ID пользователя
                                            $catalogTable              // Название таблицы (например: 'shop')
                                        );
                                    ?>

                                    <td>
                                        <div class="user-avatar" style="width: 40px; height: 40px;">
                                            <img src="<?= escape($rowImage) ?>"
                                                 alt="<?= escape($row['title'] ?? '') ?>"
                                                 style="width: 100%; height: 100%; object-fit: cover;">
                                        </div>
                                    </td>
                                    <td>
                                        <a href="add_extra.php?id=<?= (int)$row['id'] ?>&news_id=<?= $newsId ?>" class="user-link text-decoration-none">
                                            <?= escape($row['title'] ?? '') ?>
                                        </a>
                                        <span class="badge bg-success"><?= 'ID: ' . (int)$row['id'] ?></span>
                                    </td>

                                    <td>
                                        <?= escape(mb_substr((string)($row['content'] ?? '—'), 0, 50)) . (mb_strlen((string)($row['content'] ?? '')) > 50 ? '...' : '') ?>
                                    </td>

                                    <td>
                                        <?= !empty($row['created_at']) ? date('d.m.Y H:i', strtotime($row['created_at'])) : '' ?>
                                    </td>

                                    <td>
                                        <div class="btn-group" style="gap: 4px;">
                                            <a href="add_extra.php?id=<?= (int)$row['id'] ?>&news_id=<?= $newsId ?>"
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
                    'trash' => $_GET['trash'] ?? null,           // Фильтр: показывать корзину (1) или нет (null)
                    'search' => $search,                         // Фильтр: поиск по названию записи
                    'news_id' => $newsId,                        // Фильтр: поиск по родительскому автору
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

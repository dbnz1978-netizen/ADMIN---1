<?php
/**
 * Файл: /plugins/news-plugin/pages/articles/add_article.php
 *
 * Назначение:
 * - Добавление и редактирование новостей в админ-панели.
 * - Работает с таблицей news_articles.
 * - Связь с категорией хранится в колонке category_id (ID категории из news_categories).
 * - Данные хранятся в прямых колонках: title, meta_title, meta_description, content, image, url.
 * - Поле users_id автоматически заполняется $_SESSION['user_id'] при создании/редактировании.
 *
 * Структура данных:
 * - title               -> Название новости
 * - meta_title          -> SEO заголовок
 * - meta_description    -> SEO описание
 * - content             -> HTML содержимое новости (из редактора)
 * - image               -> ID(шки) изображения из медиа-библиотеки (строка "1,2,3" или "5")
 * - url                 -> URL новости
 * - category_id         -> ID категории из news_categories
 *
 * Важно по безопасности:
 * - Название таблицы нельзя передавать из пользователя. Здесь оно задаётся вручную в настройках.
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
    'jsondata'        => true,          // подключение обновления JSON данных пользователя
    'htmleditor'      => true,          // подключение редактора WYSIWYG
    'csrf_token'      => true,          // генерация CSRF-токена
    'image_sizes'     => true,          // подключение модуля управления размерами изображений
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../../../../admin/functions/init.php';

// Подключаем дополнительную инициализацию
require_once __DIR__ . '/../../functions/category_path.php'; // Функция для построения полного пути категории

// Подключаем систему управления доступом к плагинам
require_once __DIR__ . '/../../../../admin/functions/plugin_access.php';

// --- Очищаем flash-сообщения сразу после их чтения ---
// Загружаем flash-сообщения из сессии (если есть) и сразу удаляем их
if (!empty($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors = $_SESSION['flash_messages']['error'] ?? [];
    // Удаляем их, чтобы не показывались при повторной загрузке
    unset($_SESSION['flash_messages']);
}

// Обработка сообщений после перенаправления (если таковые остались)
if (!empty($_SESSION['alerts'])) {
    foreach ($_SESSION['alerts'] as $alert) {
        if ($alert['type'] === 'success') {
            $successMessages[] = $alert['message'];
        } else {
            $errors[] = $alert['message'];
        }
    }
    unset($_SESSION['alerts']);
}

// =============================================================================
// Проверка прав администратора
// =============================================================================
$adminData = getAdminData($pdo);
if ($adminData === false) {
    logEvent("Ошибка получения данных администратора", LOG_ERROR_ENABLED, 'error');
    header("Location: ../../../../admin/logout.php");
    exit;
}

// === НАСТРОЙКИ ===
$titlemeta = 'Новости';                       // Название заголовка H1 для раздела
$titlemetah3 = 'Редактирование новости';      // Название заголовка H2 для раздела
$titlemeta_h3 = 'Добавление новости';         // Название заголовка H2 для раздела
$catalogTable = 'news_articles';              // Название таблицы новостей
$categoryTable = 'news_categories';           // Название таблицы категорий
$categoryUrlPrefix = 'news';                  // Префикс URL категории
$maxDigits = 50;                              // Ограничение на количество изображений

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

// Текущий user_id из сессии
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// =============================================================================
// ПРОВЕРКА ДОСТУПА К ПЛАГИНУ
// =============================================================================
$userDataAdmin = pluginAccessGuard($pdo, 'news-plugin');
$currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

// =============================================================================
// CSRF helpers
// =============================================================================
function validateCsrfTokenFromHeader(): bool {
    $headersToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return isset($_SESSION['csrf_token']) && is_string($headersToken) && hash_equals($_SESSION['csrf_token'], $headersToken);
}

// =============================================================================
// Переменные страницы
// =============================================================================
$isEditMode = isset($_GET['id']);
$itemId = $isEditMode ? (int)$_GET['id'] : null;

// Значения по умолчанию (для формы)
$defaultTitle = '';
$defaultUrl = '';
$defaultMetaTitle = '';
$defaultMetaDescription = '';
$content = '';
$defaultSorting = 0;
$defaultStatus = 1;
$image = '';
$defaultCategoryId = null;
$defaultCategoryName = '';
$categoryFullPath = '';

// =============================================================================
// Загрузка записи для редактирования (+ фильтр users_id)
// =============================================================================
if ($isEditMode && $itemId) {
    try {
        // Читаем редактируемую запись (+ users_id)
        $stmt = $pdo->prepare("SELECT * FROM {$catalogTable} WHERE id = ? AND users_id = ? LIMIT 1");
        $stmt->execute([$itemId, $currentUserId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            // Простые поля таблицы
            $defaultTitle = $item['title'] ?? '';
            $defaultUrl = $item['url'] ?? '';
            $defaultMetaTitle = $item['meta_title'] ?? '';
            $defaultMetaDescription = $item['meta_description'] ?? '';
            $defaultSorting = (int)($item['sorting'] ?? 0);
            $defaultStatus = (int)($item['status'] ?? 1);

            // Категория (ID)
            $categoryRaw = $item['category_id'] ?? 0;
            $defaultCategoryId = is_numeric($categoryRaw) ? (int)$categoryRaw : 0;

            // HTML содержимое (из редактора)
            $content = sanitizeHtmlFromEditor((string)($item['content'] ?? ''));

            // ID/список ID изображений
            $image = (string)($item['image'] ?? '');

            // Строим полный путь категории
            if ($defaultCategoryId > 0) {
                $categoryFullPath = buildNewsCategoryPath(
                    $pdo,
                    $defaultCategoryId,
                    $currentUserId,
                    $maxDepth = 10
                );
            }

            // Подгружаем имя категории (для UI) — ВАЖНО: категория ищется по users_id + status = 1
            if ($defaultCategoryId > 0) {
                $stmtC = $pdo->prepare("SELECT name FROM {$categoryTable} WHERE id = ? AND users_id = ? AND status = 1 LIMIT 1");
                $stmtC->execute([$defaultCategoryId, $currentUserId]);
                $cRow = $stmtC->fetch(PDO::FETCH_ASSOC);
                if ($cRow && isset($cRow['name'])) {
                    $defaultCategoryName = (string)$cRow['name'];
                } else {
                    $defaultCategoryId = null;
                    $defaultCategoryName = '';
                }
            } else {
                $defaultCategoryId = null;
                $defaultCategoryName = '';
            }

            logEvent("Успешная загрузка новости для редактирования ID=$itemId", LOG_INFO_ENABLED, 'info');
        } else {
            $errors[] = 'Новость не найдена';
            logEvent("Новость не найдена ID=$itemId", LOG_ERROR_ENABLED, 'error');
            header("Location: article_list.php");
            exit;
        }
    } catch (PDOException $e) {
        $errors[] = 'Ошибка загрузки данных';
        logEvent("Ошибка загрузки новости ID=$itemId ({$catalogTable}): " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    }
}

// =============================================================================
// AJAX: поиск категорий (+ фильтр users_id + status = 1)
// GET add_article.php?action=category_search&q=...&exclude_id=...
// Ответ: {error:false, items:[{id,name,url},...]}
// =============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'category_search') {
    header('Content-Type: application/json; charset=utf-8');

    // Проверка метода
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        logEvent("Неверный метод запроса category_search", LOG_ERROR_ENABLED, 'error');
        echo json_encode(['error' => true, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверка CSRF
    if (!validateCsrfTokenFromHeader()) {
        http_response_code(403);
        logEvent("CSRF ошибка в category_search", LOG_ERROR_ENABLED, 'error');
        echo json_encode(['error' => true, 'message' => 'CSRF token invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Защита от частых запросов
    $rateLimitKey = 'category_search_' . $currentUserId;
    $lastRequestTime = $_SESSION[$rateLimitKey] ?? 0;
    $currentTime = time();
    if (($currentTime - $lastRequestTime) < 1) { // 1 секунда между запросами
        http_response_code(429);
        logEvent("Rate limit превышен для category_search", LOG_ERROR_ENABLED, 'error');
        echo json_encode(['error' => true, 'message' => 'Слишком частые запросы'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION[$rateLimitKey] = $currentTime;

    // Валидация и санитизация входных данных
    $q = trim((string)($_GET['q'] ?? ''));
    $excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;

    // ИСПОЛЬЗУЕМ ВАШУ ФУНКЦИЮ ВАЛИДАЦИИ
    $result = validateTextareaField($q, 2, 100, 'Поиск');
    if (!$result['valid']) {
        http_response_code(400);
        logEvent("Ошибка валидации category_search: " . $result['error'], LOG_ERROR_ENABLED, 'error');
        echo json_encode(['error' => true, 'message' => $result['error'], 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Получаем очищенное значение
    $q = $result['value'];

    // Логирование подозрительных запросов
    if (mb_strlen($q) > 50) {
        logEvent("Длинный поиск категории: " . mb_substr($q, 0, 100), LOG_INFO_ENABLED, 'info');
    }

    try {
        $like = '%' . $q . '%';

        // ВАЖНО: поиск категории идёт по news_categories + users_id + status = 1
        if ($excludeId > 0) {
            $stmt = $pdo->prepare("
                SELECT id, name, url
                FROM {$categoryTable}
                WHERE users_id = ?
                AND status = 1
                AND (name LIKE ? OR url LIKE ?)
                AND id != ?
                ORDER BY id DESC
                LIMIT 6
            ");
            $stmt->execute([$currentUserId, $like, $like, $excludeId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, name, url
                FROM {$categoryTable}
                WHERE users_id = ?
                AND status = 1
                AND (name LIKE ? OR url LIKE ?)
                ORDER BY id DESC
                LIMIT 6
            ");
            $stmt->execute([$currentUserId, $like, $like]);
        }

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        logEvent("Успешный поиск категорий: $q, найдено: " . count($items), LOG_INFO_ENABLED, 'info');
        echo json_encode(['error' => false, 'items' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        logEvent("Ошибка category_search ({$categoryTable}): " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
        echo json_encode(['error' => true, 'message' => 'DB error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// =============================================================================
// Обработка формы (создание/обновление) + users_id
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Обновите страницу.';
        logEvent("CSRF ошибка в add_article.php", LOG_ERROR_ENABLED, 'error');
    } else {
        // ---------------------------------------------------------------
        // 1) Валидация основных полей формы
        // ---------------------------------------------------------------

        // Название новости
        $title = trim($_POST['title'] ?? '');
        $result = validateTextareaField($title, 1, 200, 'Название новости');
        if ($result['valid']) {
            $title = $result['value'];
            logEvent("Успешная валидация поля 'Название новости'", LOG_INFO_ENABLED, 'info');
        } else {
            $errors[] = $result['error'];
            $title = false;
            logEvent("Ошибка валидации поля 'Название новости': " . $result['error'], LOG_ERROR_ENABLED, 'error');
        }

        // Meta Title (SEO)
        $metaTitle = trim($_POST['meta_title'] ?? '');
        if (empty($errors) && $metaTitle !== '') {
            $result = validateTextareaField($metaTitle, 1, 255, 'Meta Title (SEO)');
            if ($result['valid']) {
                $metaTitle = $result['value'];
                logEvent("Успешная валидация поля 'Meta Title (SEO)'", LOG_INFO_ENABLED, 'info');
            } else {
                $errors[] = $result['error'];
                $metaTitle = false;
                logEvent("Ошибка валидации поля 'Meta Title (SEO)': " . $result['error'], LOG_ERROR_ENABLED, 'error');
            }
        }

        // Meta Description (SEO)
        $metaDescription = trim($_POST['meta_description'] ?? '');
        if (empty($errors) && $metaDescription !== '') {
            $result = validateTextareaField($metaDescription, 1, 300, 'Meta Description (SEO)');
            if ($result['valid']) {
                $metaDescription = $result['value'];
                logEvent("Успешная валидация поля 'Meta Description (SEO)'", LOG_INFO_ENABLED, 'info');
            } else {
                $errors[] = $result['error'];
                $metaDescription = false;
                logEvent("Ошибка валидации поля 'Meta Description (SEO)': " . $result['error'], LOG_ERROR_ENABLED, 'error');
            }
        }

        // HTML содержимое
        $content = sanitizeHtmlFromEditor($_POST['content'] ?? '');

        // Прочие поля
        $url = trim($_POST['url'] ?? '');
        $sorting = (int)($_POST['sorting'] ?? 0);
        $status = isset($_POST['status']) ? 1 : 0;
        $category_id = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : 0;

        // Валидация категории (обязательное поле)
        if ($category_id <= 0) {
            $errors[] = 'Необходимо выбрать категорию';
            logEvent("Ошибка валидации: категория не выбрана", LOG_ERROR_ENABLED, 'error');
        }

        // Изображения (ID из медиа-библиотеки)
        $result_images = validateIdList(trim($_POST['image'] ?? ''), $maxDigits);
        if ($result_images['valid']) {
            $image = $result_images['value'];
            logEvent("Успешная валидация поля 'Изображения'", LOG_INFO_ENABLED, 'info');
        } else {
            $errors[] = $result_images['error'];
            $image = false;
            logEvent("Ошибка валидации поля 'Изображения': " . $result_images['error'], LOG_ERROR_ENABLED, 'error');
        }

        if ($sorting < 0) {
            $sorting = 0;
        }

        // ---------------------------------------------------------------
        // 2) Подготовка URL
        // ---------------------------------------------------------------
        if ($url === '') {
            $url = transliterate($title);
        } else {
            $url = transliterate($url);
        }

        if (strlen($url) < 2) {
            $errors[] = 'URL слишком короткий (минимум 2 символа после транслитерации)';
            logEvent("Ошибка: короткий URL после транслитерации", LOG_ERROR_ENABLED, 'error');
        }

        // ---------------------------------------------------------------
        // 3) Проверка категории (+ users_id + status = 1)
        // ВАЖНО: категория должна существовать в news_categories + users_id + status = 1
        // ---------------------------------------------------------------
        if ($category_id > 0) {
            $stmt = $pdo->prepare("SELECT id FROM {$categoryTable} WHERE id = ? AND users_id = ? AND status = 1 LIMIT 1");
            $stmt->execute([$category_id, $currentUserId]);
            if (!$stmt->fetch()) {
                $errors[] = 'Выбранная категория не найдена или неактивна';
                logEvent("Ошибка: категория не найдена ID=$category_id", LOG_ERROR_ENABLED, 'error');
            } else {
                logEvent("Успешная проверка категории ID=$category_id", LOG_INFO_ENABLED, 'info');
            }
        }

        // ---------------------------------------------------------------
        // 4) Проверка уникальности URL (только внутри news_articles + users_id)
        // ---------------------------------------------------------------
        if (empty($errors)) {
            $sql = "SELECT id FROM {$catalogTable} WHERE url = ? AND users_id = ?";
            $params = [$url, $currentUserId];
            if ($isEditMode && $itemId) {
                $sql .= " AND id != ?";
                $params[] = $itemId;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetch()) {
                $errors[] = 'Статья с таким URL уже существует';
                logEvent("Ошибка: дублирование URL: $url", LOG_ERROR_ENABLED, 'error');
            } else {
                logEvent("Успешная проверка уникальности URL: $url", LOG_INFO_ENABLED, 'info');
            }
        }

        // ---------------------------------------------------------------
        // 5) Сохранение в БД (+ users_id)
        // ---------------------------------------------------------------
        if (empty($errors)) {
            try {
                if ($isEditMode && $itemId) {
                    // UPDATE (+ users_id в WHERE)
                    $stmt = $pdo->prepare("
                        UPDATE {$catalogTable}
                        SET title = ?,
                            url = ?,
                            category_id = ?,
                            meta_title = ?,
                            meta_description = ?,
                            content = ?,
                            image = ?,
                            sorting = ?,
                            status = ?,
                            users_id = ?
                        WHERE id = ?
                        AND users_id = ?
                    ");
                    $stmt->execute([
                        $title,
                        $url,
                        $category_id > 0 ? $category_id : null,
                        $metaTitle,
                        $metaDescription,
                        $content,
                        $image,
                        $sorting,
                        $status,
                        $currentUserId, // Обновляем users_id
                        $itemId,
                        $currentUserId  // Проверка users_id
                    ]);

                    $successMessages[] = 'Новость успешно обновлена';
                    logEvent("Обновлена новость {$catalogTable} ID=$itemId users_id=$currentUserId", LOG_INFO_ENABLED, 'info');
                } else {
                    // INSERT (+ users_id)
                    $stmt = $pdo->prepare("
                        INSERT INTO {$catalogTable}
                            (title, url, category_id, meta_title, meta_description, content, image, sorting, status, created_at, users_id)
                        VALUES
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([
                        $title,
                        $url,
                        $category_id > 0 ? $category_id : null,
                        $metaTitle,
                        $metaDescription,
                        $content,
                        $image,
                        $sorting,
                        $status,
                        $currentUserId  // Устанавливаем users_id
                    ]);

                    $newId = (int)$pdo->lastInsertId();
                    $successMessages[] = 'Новость успешно создана';
                    logEvent("Создана новость {$catalogTable} ID=$newId users_id=$currentUserId", LOG_INFO_ENABLED, 'info');
                }

                // После успешного сохранения - перенаправляем
                // Сохраняем сообщения в сессию для отображения после редиректа
                $_SESSION['flash_messages'] = [
                    'success' => $successMessages,
                    'error'   => $errors
                ];

                if ($isEditMode && $itemId) {
                    header("Location: add_article.php?id=$itemId");
                } else {
                    header("Location: add_article.php?id=" . $newId);
                }
                exit; // ВАЖНО: завершаем выполнение скрипта после редиректа

            } catch (PDOException $e) {
                $errors[] = 'Ошибка сохранения данных';
                logEvent("Ошибка сохранения новости {$catalogTable}: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
            }
        }
    }
}

// =============================================================================
// Подготовка данных для шаблона
// =============================================================================
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../../../../admin/img/avatar.svg');

$formCategoryId = isset($_POST['category_id'])
    ? (int)($_POST['category_id'] === '' ? 0 : $_POST['category_id'])
    : (int)($defaultCategoryId ?? 0);

$formCategoryName = '';
if (isset($_POST['category_name'])) {
    $formCategoryName = trim((string)$_POST['category_name']);
} else {
    $formCategoryName = $defaultCategoryName;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= escape($_SESSION['csrf_token']) ?>">
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

    <!-- Стили админки -->
    <link rel="stylesheet" href="../../../../admin/css/main.css">

    <!-- WYSIWYG-редактор -->
    <link rel="stylesheet" href="../../../../admin/css/editor.css">

    <!-- Медиа-библиотека -->
    <link rel="stylesheet" href="../../../../admin/user_images/css/main.css">

    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
</head>
<body>
    <div class="container-fluid">
        <?php require_once __DIR__ . '/../../../../admin/template/sidebar.php'; ?>

        <main class="main-content">
            <?php require_once __DIR__ . '/../../../../admin/template/header.php'; ?>

            <!-- Меню настройки записи -->
            <?php
                if (isset($_GET['id']) && !empty($_GET['id']) && is_numeric($_GET['id'])) {
                    $headerFile = __DIR__ . '/header.php';
                    if (file_exists($headerFile)) {
                        require_once $headerFile;
                    }
                }
            ?>

            <form method="post">
                <div class="form-section">
                    <div class="row align-items-center mb-4">
                        <div class="col-lg-6">
                            <h3 class="card-title d-flex align-items-center gap-2 mb-0">
                                <i class="bi <?= $isEditMode ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i>
                                <?= escape($isEditMode ? $titlemetah3 : $titlemeta_h3) ?>
                            </h3>
                        </div>
                        <div class="col-lg-6 text-end">
                            <?php if ($isEditMode && $itemId && $categoryFullPath): ?>
                                <a href="/<?= escape($categoryUrlPrefix) ?>/<?= escape($categoryFullPath) ?>/<?= escape($defaultUrl) ?>" target="_blank"
                                    class="btn btn-outline-primary"
                                    title="Открыть страницу новости в новом окне">
                                    <i class="bi bi-box-arrow-up-right"></i> Просмотр
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Отображение сообщений -->
                    <?php displayAlerts(
                        $successMessages,  // Массив сообщений об успехе
                        $errors,           // Массив сообщений об ошибках
                        true               // Показывать сообщения как toast-уведомления
                    ); 
                    ?>

                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                    <!-- Название -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label">Название новости <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" required maxlength="255"
                                value="<?= escape($title ?? $defaultTitle) ?>">
                        </div>
                    </div>

                    <!-- Категория -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label">Категория <span class="text-danger">*</span></label>
                            <input type="hidden" name="category_id" id="category_id" value="<?= escape((string)$formCategoryId) ?>">
                            <input type="hidden" name="category_name" id="category_name" value="<?= escape((string)$formCategoryName) ?>">

                            <div class="parent-search-wrap"
                                id="categorySearchRoot"
                                data-exclude-id="0">
                                <div class="input-group">
                                    <input type="text"
                                        class="form-control"
                                        id="category_search"
                                        autocomplete="off"
                                        placeholder="Начните вводить название или URL категории…"
                                        value="<?= escape((string)$formCategoryName) ?>">
                                    <button type="button" class="btn btn-outline-secondary" id="category_clear" title="Сбросить категорию">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                                <div id="category_suggest" class="parent-suggest-box d-none"></div>
                            </div>

                            <div class="form-text">
                                Показывается до 6 совпадений. Обязательное поле.
                            </div>
                        </div>
                    </div>

                    <!-- URL -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label">URL (оставьте пустым для автоматической генерации)</label>
                            <input type="text" class="form-control" name="url" maxlength="255"
                                placeholder="primer-stati" value="<?= escape($url ?? $defaultUrl) ?>">
                            <div class="form-text">Будет автоматически транслитерирован и очищен от спецсимволов.</div>
                        </div>
                    </div>

                    <!-- Sorting -->
                    <div class="row mb-5">
                        <div class="col-12">
                            <label class="form-label">Сортировка (порядок сортировки)</label>
                            <input type="number" class="form-control" name="sorting"
                                value="<?= escape((string)($sorting ?? $defaultSorting)) ?>" step="1" min="0">
                        </div>
                    </div>

                    <!-- Изображение -->
                    <h3 class="card-title">
                        <i class="bi bi-card-image"></i>
                        Изображение
                    </h3>

                    <!---------------------------------------------------- Галерея №1 ---------------------------------------------------->
                    <?php
                        $sectionId = 'image';
                        $image_ids = $image;

                        $_SESSION['max_files_per_user'] = $adminData['image_limit'] ?? 0;

                        // Получаем глобальные настройки размеров изображений
                        $imageSizes = getGlobalImageSizes($pdo);

                        $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;
                    ?>

                    <input type="hidden" id="selectedImages_<?php echo $sectionId; ?>" name="<?php echo $sectionId; ?>"
                        value="<?php echo isset($image_ids) ? $image_ids : ''; ?>">

                    <div id="image-management-section-<?php echo $sectionId; ?>">
                        <div id="loading-content-<?php echo $sectionId; ?>"></div>
                        <div class="selected-images-section d-flex flex-wrap gap-2">
                            <div id="selectedImagesPreview_<?php echo $sectionId; ?>" class="selected-images-preview">
                                <!-- Индикатор загрузки -->
                                <div class="w-100 d-flex justify-content-center align-items-center" style="min-height: 170px;">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Загрузка...</span>
                                    </div>
                                </div>
                            </div>
                            <div class="form-text">Не более: <?= escape($maxDigits) ?> шт</div>
                        </div>
                    </div>

                    <div class="modal fade" id="<?php echo $sectionId; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-fullscreen-custom">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Библиотека файлов</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                                </div>
                                <div class="modal-body">
                                    <div id="notify_<?php echo $sectionId; ?>"></div>
                                    <div id="image-management-section_<?php echo $sectionId; ?>"></div>
                                    <input type="file" id="fileInput_<?php echo $sectionId; ?>" multiple accept="image/*" style="display: none;">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                                    <button type="button" id="saveButton" class="btn btn-primary"
                                            data-section-id="<?php echo escape($sectionId); ?>"
                                            onclick="handleSelectButtonClick()"
                                            data-bs-dismiss="modal">
                                        Выбрать
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!---------------------------------------------------- /Галерея №1 ---------------------------------------------------->
                </div>

                <!-- SEO -->
                <div class="form-section mb-5">
                    <h3 class="card-title">
                        <i class="bi bi-code-slash"></i>
                        Мета-теги SEO
                    </h3>

                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label">Meta Title (SEO)</label>
                            <input type="text" class="form-control" name="meta_title" maxlength="255"
                                value="<?= escape($metaTitle ?? $defaultMetaTitle) ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label">Meta Description (SEO)</label>
                            <textarea class="form-control" name="meta_description" rows="2" maxlength="300"><?= escape($metaDescription ?? $defaultMetaDescription) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Содержимое новости -->
                <div class="mb-5">
                    <h3 class="card-title">
                        <i class="bi bi-card-checklist"></i>
                        Содержимое новости
                    </h3>
                    <div class="form-text">Полное содержимое новости.</div>
                    <?php renderHtmlEditor('content', $content); ?>
                </div>

                <!-- Активность -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="status" id="status" value="1"
                                <?= ($status ?? $defaultStatus) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="status">Активна</label>
                        </div>
                        <div class="form-text">Показывать новость на сайте</div>
                    </div>
                </div>

                <!-- Сохранение -->
                <div class="mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> <?= $isEditMode ? 'Сохранить' : 'Создать' ?>
                    </button>
                    <a href="article_list.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Назад к списку
                    </a>
                </div>
            </form>
        </main>
    </div>

    <!-- Глобальное модальное окно с информацией о фотографии (используется всеми галереями) -->
    <?php if (!isset($GLOBALS['photo_info_included'])): ?>
        <?php require_once __DIR__ . '/../../../../admin/user_images/photo_info.php'; ?>
        <?php $GLOBALS['photo_info_included'] = true; ?>
    <?php endif; ?>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- Модульный JS admin -->
    <script type="module" src="../../../../admin/js/main.js"></script>

    <!-- WYSIWYG-редактор -->
    <script src="../../../../admin/js/editor.js"></script>

    <!-- Модульный JS галереи -->
    <script type="module" src="../../../../admin/user_images/js/main.js"></script>

    <!-- Поиск категории -->
    <script src="js/categorysearch.js"></script>

    <!-- Инициализация галереи -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Загружаем галерею при старте №1
            loadGallery('image');
            // Загружаем библиотеку файлов
            loadImageSection('image');
        });
    </script>
</body>
</html>

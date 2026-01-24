<?php
/**
 * Файл: /admin/shop/add_product.php
 *
 * Назначение:
 * - Добавление и редактирование записей (например: товаров/категорий) в админ-панели.
 * - Работает с одной таблицей (задаётся переменной $catalogTable).
 * - Родительская связь хранится в колонке author (ID родителя).
 * - Тип/контекст записи хранится в related_table (например: 'product').
 * - Расширенные данные (SEO, описание, изображения и любые будущие поля) хранятся в JSON-колонке data.
 * - Поле users_id автоматически заполняется $_SESSION['user_id'] при создании/редактировании.
 *
 * Как устроено хранение data (JSON):
 * - meta.title, meta.description  -> SEO поля
 * - category_description          -> HTML описание (из редактора)
 * - image                         -> ID(шки) изображения из медиа-библиотеки (строка "1,2,3" или "5")
 *
 * Где добавлять новые данные в JSON (data):
 * 1) В блоке загрузки (edit mode) — распаковывать новое поле из $dataArr[...] в переменную формы.
 * 2) В блоке POST — добавить новое поле в массив $dataPayload[...] перед json_encode().
 *
 * Важно по безопасности:
 * - Название таблицы нельзя передавать из пользователя. Здесь оно задаётся вручную в настройках.
 * - Для выборки строковых значений из JSON используется оператор ->> (возвращает значение как текст).
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/** 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

define('APP_ACCESS', true);
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

// =============================================================================
// Настройки (меняются в одном месте)
// =============================================================================

// Название таблицы (можно глобально поменять)
$catalogTable = 'shop';                 // например: 'catalog2', 'catalog', 'shop'

// related_table — “тип”/контекст текущих редактируемых записей
$relatedTable = 'product';              // например: 'product'

// related_table — фильтр ТОЛЬКО для выбора родительской записи (parent_search и проверка parent_id)
$parentRelatedTable = 'catalog';           // например: 'shop' (или 'category', 'catalog', и т.д.)

// Ограничение на количество изображений
$maxDigits = 5; 

// =============================================================================
// Подключаем системные компоненты
// =============================================================================
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования
require_once __DIR__ . '/../functions/jsondata.php';                 // Обновление JSON данных пользователя
require_once __DIR__ . '/../functions/display_alerts.php';           // Отображения сообщений
require_once __DIR__ . '/../functions/sanitization.php';             // Валидация / экранирование
require_once __DIR__ . '/../functions/htmleditor.php';               // WYSIWYG-редактор

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

// Текущий user_id из сессии
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

try {
    $user = requireAuth($pdo);
    if (!$user) {
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");
        exit;
    }

    $userDataAdmin = getUserData($pdo, $user['id']);
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        logEvent($userDataAdmin['message'], LOG_ERROR_ENABLED, 'error');
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
    logEvent("Ошибка инициализации product_list.php: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

// =============================================================================
// CSRF helpers
// =============================================================================
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function validateCsrfTokenFromHeader(): bool {
    $headersToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return isset($_SESSION['csrf_token']) && is_string($headersToken) && hash_equals($_SESSION['csrf_token'], $headersToken);
}

// =============================================================================
// Переменные страницы
// =============================================================================
$errors = [];
$successMessages = [];

$isEditMode = isset($_GET['id']);
$itemId = $isEditMode ? (int)$_GET['id'] : null;

// Значения по умолчанию (для формы)
$defaultNaime = '';
$defaultUrl = '';
$defaultTitle = '';
$defaultDescription = '';
$text = '';
$defaultSorting = 0;
$defaultStatus = 1;
$image = '';
$defaultParentId = null;
$defaultParentName = '';
$defaultParentUrl = '';

// =============================================================================
// Загрузка записи для редактирования (+ фильтр users_id)
// =============================================================================
if ($isEditMode && $itemId) {
    try {
        // Читаем редактируемую запись (текущий related_table + users_id)
        $stmt = $pdo->prepare("SELECT * FROM {$catalogTable} WHERE id = ? AND related_table = ? AND users_id = ? LIMIT 1");
        $stmt->execute([$itemId, $relatedTable, $currentUserId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            // Простые поля таблицы (не JSON)
            $defaultNaime = $item['naime'] ?? '';
            $defaultUrl = $item['url'] ?? '';
            $defaultSorting = (int)($item['sorting'] ?? 0);
            $defaultStatus = (int)($item['status'] ?? 1);

            // Родитель (ID)
            $authorRaw = $item['author'] ?? 0;
            $defaultParentId = is_numeric($authorRaw) ? (int)$authorRaw : 0;

            // -----------------------------------------------------------------
            // data (JSON): распаковываем поля, которые рисуются в форме
            // -----------------------------------------------------------------
            $dataArr = json_decode($item['data'] ?? '{}', true) ?: [];

            $defaultTitle = (string)($dataArr['meta']['title'] ?? '');
            $defaultDescription = (string)($dataArr['meta']['description'] ?? '');

            // HTML описание (из редактора)
            $text = sanitizeHtmlFromEditor((string)($dataArr['category_description'] ?? ''));

            // ID/список ID изображений
            $image = (string)($dataArr['image'] ?? '');

            // ---------------------------------------------------------------
            // ДОБАВЛЕНИЕ НОВОГО ПОЛЯ В JSON (data) — место №1:
            // Пример:
            // $defaultH1 = (string)($dataArr['meta']['h1'] ?? '');
            // ---------------------------------------------------------------

            // Подгружаем имя родителя (для UI) — ВАЖНО: родитель ищется по $parentRelatedTable + users_id
            if ($defaultParentId > 0) {
                $stmtP = $pdo->prepare("SELECT naime, url FROM {$catalogTable} WHERE id = ? AND related_table = ? AND users_id = ? LIMIT 1");
                $stmtP->execute([$defaultParentId, $parentRelatedTable, $currentUserId]);
                $pRow = $stmtP->fetch(PDO::FETCH_ASSOC);

                if ($pRow && isset($pRow['naime'])) {
                    $defaultParentName = (string)$pRow['naime'];
                    $defaultParentUrl = (string)$pRow['url'];
                } else {
                    $defaultParentId = null;
                    $defaultParentName = '';
                }
            } else {
                $defaultParentId = null;
                $defaultParentName = '';
            }

        } else {
            $errors[] = 'Раздел не найден';
            // Закрываем соединение при завершении скрипта
            register_shutdown_function(function() {
                if (isset($pdo)) {
                    $pdo = null; 
                }
            });
            header("Location: product_list.php");
            exit;
        }
    } catch (PDOException $e) {
        $errors[] = 'Ошибка загрузки данных';
        logEvent("Ошибка загрузки раздела ID=$itemId ({$catalogTable}): " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    }
}

// =============================================================================
// AJAX: поиск родительских категорий (+ фильтр users_id)
// GET product_list.php?action=parent_search&q=...&exclude_id=...
// Ответ: {error:false, items:[{id,naime,url},...]}
// =============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'parent_search') {
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => true, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        exit;
    }

    if (!validateCsrfTokenFromHeader()) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'CSRF token invalid'], JSON_UNESCAPED_UNICODE);
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        exit;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;

    if ($q === '' || mb_strlen($q) < 1) {
        echo json_encode(['error' => false, 'items' => []], JSON_UNESCAPED_UNICODE);
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        exit;
    }

    try {
        $like = '%' . $q . '%';

        // ВАЖНО: поиск родителя идёт по related_table = $parentRelatedTable + users_id
        if ($excludeId > 0) {
            $stmt = $pdo->prepare("
                SELECT id, naime, url
                FROM {$catalogTable}
                WHERE related_table = ?
                  AND users_id = ?
                  AND (naime LIKE ? OR url LIKE ?)
                  AND id != ?
                ORDER BY id DESC
                LIMIT 6
            ");
            $stmt->execute([$parentRelatedTable, $currentUserId, $like, $like, $excludeId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, naime, url
                FROM {$catalogTable}
                WHERE related_table = ?
                  AND users_id = ?
                  AND (naime LIKE ? OR url LIKE ?)
                ORDER BY id DESC
                LIMIT 6
            ");
            $stmt->execute([$parentRelatedTable, $currentUserId, $like, $like]);
        }

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['error' => false, 'items' => $items], JSON_UNESCAPED_UNICODE);
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        exit;

    } catch (PDOException $e) {
        http_response_code(500);
        logEvent("Ошибка parent_search ({$catalogTable}): " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
        echo json_encode(['error' => true, 'message' => 'DB error'], JSON_UNESCAPED_UNICODE);
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
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
        logEvent("CSRF ошибка в product_list.php", LOG_ERROR_ENABLED, 'error');
    } else {

        // ---------------------------------------------------------------
        // 1) Валидация основных полей формы
        // ---------------------------------------------------------------

        // Название раздела
        $naime = trim($_POST['naime'] ?? '');
        $result = validateTextareaField($naime, 1, 200, 'Название раздела');
        if ($result['valid']) {
            $naime = $result['value'];
        } else {
            $errors[] = $result['error'];
            $naime = false;
        }

        // Title (SEO)
        $title = trim($_POST['title'] ?? '');
        if (empty($errors) && $title !== '') {
            $result = validateTextareaField($title, 1, 255, 'Title (SEO)');
            if ($result['valid']) {
                $title = $result['value'];
            } else {
                $errors[] = $result['error'];
                $title = false;
            }
        }

        // Description (SEO)
        $description = trim($_POST['description'] ?? '');
        if (empty($errors) && $description !== '') {
            $result = validateTextareaField($description, 1, 300, 'Description (SEO)');
            if ($result['valid']) {
                $description = $result['value'];
            } else {
                $errors[] = $result['error'];
                $description = false;
            }
        }

        // HTML описание
        $text = sanitizeHtmlFromEditor($_POST['text'] ?? '');

        // Прочие поля
        $url = trim($_POST['url'] ?? '');
        $sorting = (int)($_POST['sorting'] ?? 0);
        $status = isset($_POST['status']) ? 1 : 0;

        // Родитель
        $parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : 0;

        // Изображения (ID из медиа-библиотеки)
        // $maxDigits = 5;
        $result_images = validateIdList(trim($_POST['image'] ?? ''), $maxDigits);
        if ($result_images['valid']) {
            $image = $result_images['value'];
        } else {
            $errors[] = $result_images['error'];
            $image = false;
        }

        if ($sorting < 0) {
            $sorting = 0;
        }

        // ---------------------------------------------------------------
        // 2) Подготовка URL
        // ---------------------------------------------------------------
        if ($url === '') {
            $url = transliterate($naime);
        } else {
            $url = transliterate($url);
        }

        if (strlen($url) < 2) {
            $errors[] = 'URL слишком короткий (минимум 2 символа после транслитерации)';
        }

        // ---------------------------------------------------------------
        // 3) Проверка родителя (+ users_id)
        // ВАЖНО: родитель должен существовать в related_table = $parentRelatedTable + users_id
        // ---------------------------------------------------------------
        if ($parent_id > 0) {
            if ($isEditMode && $itemId && $parent_id === $itemId) {
                $errors[] = 'Нельзя выбрать текущую категорию как родительскую';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM {$catalogTable} WHERE id = ? AND related_table = ? AND users_id = ? LIMIT 1");
                $stmt->execute([$parent_id, $parentRelatedTable, $currentUserId]);

                if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                    $errors[] = 'Выбранная родительская категория не найдена';
                }
            }
        }

        // ---------------------------------------------------------------
        // 4) Проверка уникальности URL (только внутри текущего $relatedTable + users_id)
        // ---------------------------------------------------------------
        if (empty($errors)) {
            $sql = "SELECT id FROM {$catalogTable} WHERE url = ? AND related_table = ? AND users_id = ?";
            $params = [$url, $relatedTable, $currentUserId];

            if ($isEditMode && $itemId) {
                $sql .= " AND id != ?";
                $params[] = $itemId;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->fetch()) {
                $errors[] = 'Раздел с таким URL уже существует';
            }
        }

        // ---------------------------------------------------------------
        // 5) Сбор JSON для колонки data
        // ---------------------------------------------------------------
        $dataPayload = [
            'meta' => [
                'title' => $title,
                'description' => $description,

                // -------------------------------------------------------
                // ДОБАВЛЕНИЕ НОВОГО ПОЛЯ В JSON (data) — место №2:
                // Пример:
                // 'h1' => $h1,
                // -------------------------------------------------------
            ],
            'category_description' => $text,
            'image' => $image,

            // -----------------------------------------------------------
            // ДОБАВЛЕНИЕ НОВОГО ПОЛЯ В JSON (data) — место №3:
            // Пример:
            // 'banner' => $bannerImageId,
            // 'seo_text' => $seoText,
            // -----------------------------------------------------------
        ];

        $dataJson = json_encode($dataPayload, JSON_UNESCAPED_UNICODE);

        // ---------------------------------------------------------------
        // 6) Сохранение в БД (+ users_id)
        // ---------------------------------------------------------------
        if (empty($errors)) {
            try {
                $author = $parent_id > 0 ? (int)$parent_id : 0;

                if ($isEditMode && $itemId) {
                    // UPDATE (+ users_id в WHERE)
                    $stmt = $pdo->prepare("
                        UPDATE {$catalogTable}
                        SET naime = ?, 
                            url = ?, 
                            author = ?, 
                            related_table = ?, 
                            data = ?, 
                            sorting = ?, 
                            status = ?,
                            users_id = ?
                        WHERE id = ? 
                          AND related_table = ? 
                          AND users_id = ?
                    ");
                    $stmt->execute([
                        $naime,
                        $url,
                        $author,
                        $relatedTable,
                        $dataJson,
                        $sorting,
                        $status,
                        $currentUserId, // Обновляем users_id
                        $itemId,
                        $relatedTable,
                        $currentUserId  // Проверка users_id
                    ]);

                    $successMessages[] = 'Раздел успешно обновлён';
                    logEvent("Обновлён раздел {$catalogTable} ID=$itemId related_table={$relatedTable} users_id=$currentUserId", LOG_INFO_ENABLED, 'info');
                    // Закрываем соединение при завершении скрипта
                    register_shutdown_function(function() {
                        if (isset($pdo)) {
                            $pdo = null; 
                        }
                    });
                    header("Location: add_product.php?id=$itemId");                    
                    exit;

                } else {
                    // INSERT (+ users_id)
                    $stmt = $pdo->prepare("
                        INSERT INTO {$catalogTable}
                            (naime, url, author, related_table, data, sorting, status, created_at, users_id)
                        VALUES
                            (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([
                        $naime,
                        $url,
                        $author,
                        $relatedTable,
                        $dataJson,
                        $sorting,
                        $status,
                        $currentUserId  // Устанавливаем users_id
                    ]);

                    $newId = (int)$pdo->lastInsertId();
                    $successMessages[] = 'Раздел успешно создан';
                    logEvent("Создан новый раздел {$catalogTable} ID=$newId related_table={$relatedTable} users_id=$currentUserId", LOG_INFO_ENABLED, 'info');
                    // Закрываем соединение при завершении скрипта
                    register_shutdown_function(function() {
                        if (isset($pdo)) {
                            $pdo = null; 
                        }
                    });
                    header("Location: add_product.php?id=" . $newId);
                    exit;
                }

            } catch (PDOException $e) {
                $errors[] = 'Ошибка сохранения данных';
                logEvent("Ошибка сохранения раздела {$catalogTable}: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
            }
        }
    }
}

// =============================================================================
// CSRF token генерация
// =============================================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =============================================================================
// Подготовка данных для шаблона
// =============================================================================
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
$titlemeta = 'Магазин';


$formParentId = isset($_POST['parent_id'])
    ? (int)($_POST['parent_id'] === '' ? 0 : $_POST['parent_id'])
    : (int)($defaultParentId ?? 0);

$formParentName = '';
if (isset($_POST['parent_name'])) {
    $formParentName = trim((string)$_POST['parent_name']);
} else {
    $formParentName = $defaultParentName;
}

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
    <link rel="stylesheet" href="../css/main.css">

    <!-- WYSIWYG-редактор -->
    <link rel="stylesheet" href="../css/editor.css">

    <!-- Медиа-библиотека -->
    <link rel="stylesheet" href="../user_images/css/main.css">

    <!-- Поиск родительской категории -->
    <link rel="stylesheet" href="css/authorsearch.css">

    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
</head>
<body>
<div class="container-fluid">
    <?php require_once __DIR__ . '/../template/sidebar.php'; ?>
    <main class="main-content">
        <?php require_once __DIR__ . '/../template/header.php'; ?>

        <!-- Меню настройки товара -->
        <?php 
            if (isset($_GET['id']) && !empty($_GET['id']) && is_numeric($_GET['id'])) { 
                require_once __DIR__ . '/header.php'; 
            } 
        ?>
        <form method="post">
            <div class="form-section">
                <div class="row align-items-center mb-4">
                    <div class="col-lg-6">
                        <h3 class="card-title d-flex align-items-center gap-2 mb-0">
                            <i class="bi <?= $isEditMode ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i>
                            <?= $isEditMode ? 'Редактировать товар' : 'Добавить товар' ?>
                        </h3>
                    </div>
                    <div class="col-lg-6 text-end">
                        <?php if ($isEditMode && $itemId && $defaultUrl): ?>
                            <a href="/<?= escape($relatedTable) ?><?= $defaultParentUrl ? '/' . escape($defaultParentUrl) . '/' . escape($defaultUrl) : '/' . escape($defaultUrl) ?>" target="_blank" 
                                class="btn btn-outline-primary" 
                                title="Открыть страницу категории в новом окне">
                                <i class="bi bi-box-arrow-up-right"></i> Просмотр
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Сообщения об ошибках/успехе -->
                <?php displayAlerts($successMessages, $errors); ?>

                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                <!-- Название -->
                <div class="row mb-3">
                    <div class="col-12">
                        <label class="form-label">Название <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="naime" required maxlength="255"
                               value="<?= escape($naime ?? $defaultNaime) ?>">
                    </div>
                </div>

                <!-- Родитель -->
                <div class="row mb-3">
                    <div class="col-12">
                        <label class="form-label">Родительская категория</label>

                        <input type="hidden" name="parent_id" id="parent_id" value="<?= escape((string)$formParentId) ?>">
                        <input type="hidden" name="parent_name" id="parent_name" value="<?= escape((string)$formParentName) ?>">

                        <div class="parent-search-wrap"
                             id="parentSearchRoot"
                             data-exclude-id="<?= (int)($itemId ?? 0) ?>">
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       id="parent_search"
                                       autocomplete="off"
                                       placeholder="Начните вводить название или URL…"
                                       value="<?= escape((string)$formParentName) ?>">
                                <button type="button" class="btn btn-outline-secondary" id="parent_clear" title="Сбросить родителя">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>

                            <div id="parent_suggest" class="parent-suggest-box d-none"></div>
                        </div>

                        <div class="form-text">
                            Показывается до 6 совпадений. Можно оставить пустым (без родителя).
                        </div>
                    </div>
                </div>

                <!-- URL -->
                <div class="row mb-3">
                    <div class="col-12">
                        <label class="form-label">URL (оставьте пустым для автоматической генерации)</label>
                        <input type="text" class="form-control" name="url" maxlength="255"
                               placeholder="primer-razdela" value="<?= escape($url ?? $defaultUrl) ?>">
                        <div class="form-text">Будет автоматически транслитерирован и очищен от спецсимволов.</div>
                    </div>
                </div>

                <!-- Sorting -->
                <div class="row mb-5">
                    <div class="col-12">
                        <label class="form-label">Sorting (порядок сортировки)</label>
                        <input type="number" class="form-control" name="sorting"
                               value="<?= escape((string)($sorting ?? $defaultSorting)) ?>" step="1" min="0">
                    </div>
                </div>

                <!-- Изображение -->
                <h3 class="card-title">
                    <i class="bi bi-card-image"></i>
                    Изображение (для витрины)
                </h3>

                <?php
                $sectionId = 'image';
                $image_ids = $image;

                $_SESSION['max_files_per_user'] = $adminData['image_limit'] ?? 0;

                $imageSizes = [
                    "thumbnail" => [100, 100, "cover"],
                    "small"     => [300, 'auto', "contain"],
                    "medium"    => [600, 'auto', "contain"],
                    "large"     => [1200, 'auto', "contain"]
                ];

                $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;
                ?>

                <input type="hidden" id="selectedImages_<?php echo $sectionId; ?>" name="<?php echo $sectionId; ?>"
                       value="<?php echo isset($image_ids) ? $image_ids : ''; ?>">

                <div id="image-management-section-<?php echo $sectionId; ?>">
                    <div id="loading-content-<?php echo $sectionId; ?>"></div>

                    <button type="button" class="btn btn-outline-primary load-more-files"
                            data-bs-toggle="modal" data-bs-target="#<?php echo $sectionId; ?>"
                            onclick="storeSectionId('<?php echo $sectionId; ?>')">
                        <i class="bi bi-plus-circle me-1"></i> Добавить медиа файл
                    </button>
                    <div class="form-text">Не более <?= escape($maxDigits) ?> шт</div>
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

                                <?php require_once __DIR__ . '/../user_images/photo_info.php'; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                                <button type="button" id="saveButton" class="btn btn-primary"
                                        data-section-id="<?php echo htmlspecialchars($sectionId, ENT_QUOTES); ?>"
                                        onclick="handleSelectButtonClick()"
                                        data-bs-dismiss="modal">
                                    Выбрать
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- SEO -->
            <div class="form-section">
                <h3 class="card-title">
                    <i class="bi bi-code-slash"></i>
                    Мета-теги SEO
                </h3>

                <div class="row mb-3">
                    <div class="col-12">
                        <label class="form-label">Title (SEO)</label>
                        <input type="text" class="form-control" name="title" maxlength="255"
                               value="<?= escape($title ?? $defaultTitle) ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-12">
                        <label class="form-label">Description (SEO)</label>
                        <textarea class="form-control" name="description" rows="2" maxlength="300"><?= escape($description ?? $defaultDescription) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Краткое описание -->
            <div class="mb-5">
                <h3 class="card-title">
                    <i class="bi bi-card-checklist"></i>
                    Краткое описание
                </h3>
                <div class="form-text">Отображается в списке.</div>
                <?php renderHtmlEditor('text', $text); ?>
            </div>

            <!-- Активность -->
            <div class="row mb-3">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="status" id="status" value="1"
                            <?= ($status ?? $defaultStatus) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="status"><?= 'Нет/Да' ?></label>
                    </div>
                    <div class="form-text"><?= 'Активная запись' ?></div>
                </div>
            </div>

            <!-- Сохранение -->
            <div class="mb-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> <?= $isEditMode ? 'Сохранить' : 'Создать' ?>
                </button>
            </div>
        </form>

    </main>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Popper.js -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

<!-- Модульный JS admin -->
<script type="module" src="../js/main.js"></script>

<!-- WYSIWYG-редактор -->
<script src="../js/editor.js"></script>

<!-- Модульный JS галереи -->
<script type="module" src="../user_images/js/main.js"></script>

<!-- Поиск родительской категории -->
<script src="js/authorsearch.js"></script>

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

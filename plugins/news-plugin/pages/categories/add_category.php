<?php
/**
 * Файл: /plugins/news-plugin/pages/categories/add_category.php
 *
 * Назначение:
 * - Добавление и редактирование категорий новостей в админ-панели.
 * - Работает с таблицей news_categories (задаётся переменной $catalogTable).
 * - Родительская связь хранится в колонке parent_id (ID родителя).
 * - Расширенные данные хранятся в отдельных колонках (не JSON): meta_title, meta_description, description, image.
 * - Поле users_id автоматически заполняется $_SESSION['user_id'] при создании/редактировании.
 *
 * Структура хранения данных:
 * - name                          -> Название категории
 * - url                           -> URL категории (уникальный)
 * - parent_id                     -> ID родительской категории
 * - meta_title, meta_description  -> SEO поля (отдельные колонки)
 * - description                   -> HTML описание из редактора (отдельная колонка)
 * - image                         -> ID изображения из медиа-библиотеки (отдельная колонка)
 * - sorting, status               -> Сортировка и статус (активность)
 *
 * Важно по безопасности:
 * - Название таблицы нельзя передавать от пользователя. Оно задаётся вручную в настройках.
 * - Все запросы используют prepared statements для защиты от SQL-инъекций.
 * - CSRF токен проверяется для всех POST запросов.
 */

// === КОНФИГУРАЦИЯ ===
$config = [
    'display_errors'  => true,         // включение отображения ошибок true/false
    'set_encoding'    => true,          // включение кодировки UTF-8
    'db_connect'      => true,          // подключение к базе
    'auth_check'      => true,          // подключение функций авторизации
    'file_log'        => true,          // подключение системы логирования
    'display_alerts'  => true,          // подключение отображения сообщений
    'sanitization'    => true,          // подключение валидации/экранирования
    'jsondata'        => true,          // подключение обновления JSON данных пользователя
    'htmleditor'      => true,          // подключение редактора WYSIWYG
    'csrf_token'      => true,          // генерация CSRF-токена
];

require_once __DIR__ . '/../../../../admin/functions/init.php';

// Подключаем дополнительную инициализацию
require_once __DIR__ . '/../../functions/category_path.php'; // Функция для построения полного пути категории

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
$titlemeta = 'Новости';                            // Название заголовка H1 для раздела
$titlemetah3 = 'Редактирование каталога';          // Название заголовка H2 для раздела
$titlemeta_h3 = 'Добавление каталога';             // Название заголовка H2 для раздела
$catalogTable = 'news_categories';                 // Название таблицы
$categoryUrlPrefix = 'news-category';              // Префикс URL категории
$maxDigits = 1;                                    // Ограничение на количество загружаемых изображений

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

// Текущий user_id из сессии
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

try {
    $user = requireAuth($pdo);
    if (!$user) {
        logEvent("Ошибка аутентификации пользователя", LOG_ERROR_ENABLED, 'error');
        header("Location: ../../../../admin/logout.php");
        exit;
    }
    
    $userDataAdmin = getUserData($pdo, $user['id']);
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        logEvent($userDataAdmin['message'], LOG_ERROR_ENABLED, 'error');
        header("Location: ../../../../admin/logout.php");
        exit;
    }
    
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];
} catch (Exception $e) {
    logEvent("Ошибка инициализации add_category.php: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    header("Location: ../../../../admin/logout.php");
    exit;
}

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

// === НОВЫЕ ПОЛЯ: ОБЪЯВЛЕНИЕ ПЕРЕМЕННЫХ ===
// Здесь объявляйте переменные для новых полей, которые будут отображаться в форме
// Пример:
// $defaultCustomField1 = '';
// $defaultCustomField2 = '';
// === КОНЕЦ НОВЫХ ПОЛЕЙ ===

// Полный путь категории для ссылки
$categoryFullPath = '';

// =============================================================================
// Загрузка записи для редактирования (+ фильтр users_id)
// =============================================================================
if ($isEditMode && $itemId) {
    try {
        // Читаем редактируемую запись (текущий related_table + users_id)
        $stmt = $pdo->prepare("SELECT * FROM {$catalogTable} WHERE id = ? AND users_id = ? LIMIT 1");
        $stmt->execute([$itemId, $currentUserId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($item) {
            // Простые поля таблицы (не JSON)
            $defaultNaime = $item['name'] ?? '';
            $defaultUrl = $item['url'] ?? '';
            $defaultSorting = (int)($item['sorting'] ?? 0);
            $defaultStatus = (int)($item['status'] ?? 1);
            
            // Родитель (ID)
            $authorRaw = $item['parent_id'] ?? 0;
            $defaultParentId = is_numeric($authorRaw) ? (int)$authorRaw : 0;
            
            // Строим полный путь категории
            $categoryFullPath = buildNewsCategoryPath(
                $pdo,                             // Объект подключения к базе данных PDO
                $itemId,                          // ID текущей категории, для которой строится путь
                $currentUserId,                   // ID текущего пользователя (для фильтрации по владельцу)
                $maxDepth = 10                    // Максимальная глубина рекурсии (защита от бесконечного цикла)
            );
            
            // -----------------------------------------------------------------
            // Отдельные колонки: читаем поля напрямую
            // -----------------------------------------------------------------
            $defaultTitle = (string)($item['meta_title'] ?? '');
            $defaultDescription = (string)($item['meta_description'] ?? '');
            
            // HTML описание (из редактора)
            $text = sanitizeHtmlFromEditor((string)($item['description'] ?? ''));
            
            // ID/список ID изображений
            $image = (string)($item['image'] ?? '');
            
            // === НОВЫЕ ПОЛЯ: ЗАГРУЗКА ИЗ КОЛОНОК ===
            // Здесь распаковывайте новые поля из колонок таблицы
            // Пример:
            // $defaultCustomField1 = (string)($dataArr['custom_field_1'] ?? '');
            // $defaultCustomField2 = (int)($dataArr['custom_field_2'] ?? 0);
            // === КОНЕЦ НОВЫХ ПОЛЕЙ ===
            
            // Подгружаем имя родителя (для UI) — ВАЖНО: родитель ищется по $parentRelatedTable + users_id + status = 1
            if ($defaultParentId > 0) {
                $stmtP = $pdo->prepare("SELECT name FROM {$catalogTable} WHERE id = ? AND users_id = ? AND status = 1 LIMIT 1");
                $stmtP->execute([$defaultParentId, $currentUserId]);
                $pRow = $stmtP->fetch(PDO::FETCH_ASSOC);
                
                if ($pRow && isset($pRow['name'])) {
                    $defaultParentName = (string)$pRow['name'];
                } else {
                    $defaultParentId = null;
                    $defaultParentName = '';
                }
            } else {
                $defaultParentId = null;
                $defaultParentName = '';
            }
            
            logEvent("Успешная загрузка записи для редактирования ID=$itemId", LOG_INFO_ENABLED, 'info');
        } else {
            $errors[] = 'Раздел не найден';
            logEvent("Раздел не найден ID=$itemId", LOG_ERROR_ENABLED, 'error');
            header("Location: category_list.php");
            exit;
        }
    } catch (PDOException $e) {
        $errors[] = 'Ошибка загрузки данных';
        logEvent("Ошибка загрузки раздела ID=$itemId ({$catalogTable}): " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    }
}

// =============================================================================
// AJAX: поиск родительских категорий (+ фильтр users_id + status = 1)
// GET record_list.php?action=parent_search&q=...&exclude_id=...
// Ответ: {error:false, items:[{id,name,url},...]}
// =============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'parent_search') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Проверка метода
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        logEvent("Неверный метод запроса parent_search", LOG_ERROR_ENABLED, 'error');
        echo json_encode(['error' => true, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Проверка CSRF
    if (!validateCsrfTokenFromHeader()) {
        http_response_code(403);
        logEvent("CSRF ошибка в parent_search", LOG_ERROR_ENABLED, 'error');
        echo json_encode(['error' => true, 'message' => 'CSRF token invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Защита от частых запросов
    $rateLimitKey = 'parent_search_' . $currentUserId;
    $lastRequestTime = $_SESSION[$rateLimitKey] ?? 0;
    $currentTime = time();
    
    if (($currentTime - $lastRequestTime) < 1) { // 1 секунда между запросами
        http_response_code(429);
        logEvent("Rate limit превышен для parent_search", LOG_ERROR_ENABLED, 'error');
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
        logEvent("Ошибка валидации parent_search: " . $result['error'], LOG_ERROR_ENABLED, 'error');
        echo json_encode(['error' => true, 'message' => $result['error'], 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Получаем очищенное значение
    $q = $result['value'];
    
    // Логирование подозрительных запросов
    if (mb_strlen($q) > 50) {
        logEvent("Длинный поиск родительской категории: " . mb_substr($q, 0, 100), LOG_INFO_ENABLED, 'info');
    }
    
    try {
        $like = '%' . $q . '%';
        
        // ВАЖНО: поиск родителя идёт по related_table = $parentRelatedTable + users_id + status = 1
        if ($excludeId > 0) {
            $stmt = $pdo->prepare("
                SELECT id, name, url
                FROM {$catalogTable}
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
                FROM {$catalogTable}
                WHERE users_id = ?
                AND status = 1
                AND (name LIKE ? OR url LIKE ?)
                ORDER BY id DESC
                LIMIT 6
            ");
            $stmt->execute([$currentUserId, $like, $like]);
        }
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        logEvent("Успешный поиск родительских категорий: $q, найдено: " . count($items), LOG_INFO_ENABLED, 'info');
        echo json_encode(['error' => false, 'items' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        logEvent("Ошибка parent_search ({$catalogTable}): " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
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
        logEvent("CSRF ошибка в add_category.php", LOG_ERROR_ENABLED, 'error');
    } else {
        // ---------------------------------------------------------------
        // 1) Валидация основных полей формы
        // ---------------------------------------------------------------
        
        // Название раздела
        $name = trim($_POST['name'] ?? '');
        $result = validateTextareaField($name, 1, 200, 'Название раздела');
        if ($result['valid']) {
            $name = $result['value'];
            logEvent("Успешная валидация поля 'Название раздела'", LOG_INFO_ENABLED, 'info');
        } else {
            $errors[] = $result['error'];
            $name = false;
            logEvent("Ошибка валидации поля 'Название раздела': " . $result['error'], LOG_ERROR_ENABLED, 'error');
        }
        
        // Title (SEO)
        $title = trim($_POST['title'] ?? '');
        if (empty($errors) && $title !== '') {
            $result = validateTextareaField($title, 1, 255, 'Title (SEO)');
            if ($result['valid']) {
                $title = $result['value'];
                logEvent("Успешная валидация поля 'Title (SEO)'", LOG_INFO_ENABLED, 'info');
            } else {
                $errors[] = $result['error'];
                $title = false;
                logEvent("Ошибка валидации поля 'Title (SEO)': " . $result['error'], LOG_ERROR_ENABLED, 'error');
            }
        }
        
        // Description (SEO)
        $description = trim($_POST['description'] ?? '');
        if (empty($errors) && $description !== '') {
            $result = validateTextareaField($description, 1, 300, 'Description (SEO)');
            if ($result['valid']) {
                $description = $result['value'];
                logEvent("Успешная валидация поля 'Description (SEO)'", LOG_INFO_ENABLED, 'info');
            } else {
                $errors[] = $result['error'];
                $description = false;
                logEvent("Ошибка валидации поля 'Description (SEO)': " . $result['error'], LOG_ERROR_ENABLED, 'error');
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
        
        // === НОВЫЕ ПОЛЯ: ВАЛИДАЦИЯ ===
        // Здесь добавляйте валидацию новых полей из формы
        // Пример:
        // $customField1 = trim($_POST['custom_field_1'] ?? '');
        // if (empty($errors)) {
        //     $result = validateTextareaField($customField1, 0, 500, 'Новое поле 1');
        //     if ($result['valid']) {
        //         $customField1 = $result['value'];
        //         logEvent("Успешная валидация поля 'Новое поле 1'", LOG_INFO_ENABLED, 'info');
        //     } else {
        //         $errors[] = $result['error'];
        //         $customField1 = false;
        //         logEvent("Ошибка валидации поля 'Новое поле 1': " . $result['error'], LOG_ERROR_ENABLED, 'error');
        //     }
        // }
        // === КОНЕЦ НОВЫХ ПОЛЕЙ ===
        
        // ---------------------------------------------------------------
        // 2) Подготовка URL
        // ---------------------------------------------------------------
        if ($url === '') {
            $url = transliterate($name);
        } else {
            $url = transliterate($url);
        }
        
        if (strlen($url) < 2) {
            $errors[] = 'URL слишком короткий (минимум 2 символа после транслитерации)';
            logEvent("Ошибка: короткий URL после транслитерации", LOG_ERROR_ENABLED, 'error');
        }
        
        // ---------------------------------------------------------------
        // 3) Проверка родителя (+ users_id + status = 1)
        // ВАЖНО: родитель должен существовать в related_table = $parentRelatedTable + users_id + status = 1
        // ---------------------------------------------------------------
        if ($parent_id > 0) {
            if ($isEditMode && $itemId && $parent_id === $itemId) {
                $errors[] = 'Нельзя выбрать текущую категорию как родительскую';
                logEvent("Ошибка: попытка выбрать текущую категорию как родительскую", LOG_ERROR_ENABLED, 'error');
            } else {
                $stmt = $pdo->prepare("SELECT id FROM {$catalogTable} WHERE id = ? AND users_id = ? AND status = 1 LIMIT 1");
                $stmt->execute([$parent_id, $currentUserId]);
                
                if (!$stmt->fetch()) {
                    $errors[] = 'Выбранная родительская категория не найдена или неактивна';
                    logEvent("Ошибка: родительская категория не найдена ID=$parent_id", LOG_ERROR_ENABLED, 'error');
                } else {
                    logEvent("Успешная проверка родительской категории ID=$parent_id", LOG_INFO_ENABLED, 'info');
                }
            }
        }
        
        // ---------------------------------------------------------------
        // 4) Проверка уникальности URL (только внутри текущего $relatedTable + users_id)
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
                $errors[] = 'Раздел с таким URL уже существует';
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
                $author = $parent_id > 0 ? (int)$parent_id : null;
                
                if ($isEditMode && $itemId) {
                    // UPDATE (+ users_id в WHERE)
                    $stmt = $pdo->prepare("
                        UPDATE {$catalogTable}
                        SET name = ?,
                            url = ?,
                            parent_id = ?,
                            meta_title = ?,
                            meta_description = ?,
                            description = ?,
                            image = ?,
                            sorting = ?,
                            status = ?,
                            updated_at = NOW()
                        WHERE id = ?
                        AND users_id = ?
                    ");
                    $stmt->execute([
                        $name,
                        $url,
                        $author,
                        $title,
                        $description,
                        $text,
                        $image,
                        $sorting,
                        $status,
                        $itemId,
                        $currentUserId  // Проверка users_id
                    ]);
                    
                    $successMessages[] = 'Раздел успешно обновлён';
                    logEvent("Обновлён раздел {$catalogTable} ID=$itemId users_id=$currentUserId", LOG_INFO_ENABLED, 'info');
                } else {
                    // INSERT (+ users_id)
                    $stmt = $pdo->prepare("
                        INSERT INTO {$catalogTable}
                        (name, url, parent_id, meta_title, meta_description, description, image, sorting, status, users_id, created_at)
                        VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $name,
                        $url,
                        $author,
                        $title,
                        $description,
                        $text,
                        $image,
                        $sorting,
                        $status,
                        $currentUserId  // Устанавливаем users_id
                    ]);
                    
                    $newId = (int)$pdo->lastInsertId();
                    $successMessages[] = 'Раздел успешно создан';
                    logEvent("Создан новый раздел {$catalogTable} ID=$newId users_id=$currentUserId", LOG_INFO_ENABLED, 'info');
                }
                
                // После успешного сохранения (или ошибки БД) - перенаправляем
                // Сохраняем сообщения в сессию для отображения после редиректа
                $_SESSION['flash_messages'] = [
                    'success' => $successMessages,
                    'error'   => $errors
                ];
                
                if ($isEditMode && $itemId) {
                    header("Location: add_category.php?id=$itemId");
                } else {
                    header("Location: add_category.php?id=" . $newId);
                }
                exit; // ВАЖНО: завершаем выполнение скрипта после редиректа
            } catch (PDOException $e) {
                $errors[] = 'Ошибка сохранения данных';
                logEvent("Ошибка сохранения раздела {$catalogTable}: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
            }
        }
        
        // Если были ошибки до БД или после запроса, они остаются в $errors и $successMessages
        // и будут отображены в шаблоне. $_SESSION['flash_messages'] не перезаписывается.
    }
    
    // Если в процессе обработки POST возникли ошибки (например, валидация или уникальность),
    // они остаются в $errors и будут отображены на текущей странице без редиректа.
    // Важно: $_SESSION['flash_messages'] для этих ошибок НЕ был установлен ранее в этом цикле,
    // но он мог быть установлен в предыдущем цикле и прочитан в начале скрипта.
    // Так как мы его уже удалили, старые сообщения не появятся снова.
    // Сообщения из этого цикла (если были ошибки БД) не сохраняются в сессию повторно.
    // Они отображаются на текущей странице.
    // Если ошибок нет, но не было редиректа (например, ошибка уникальности URL), то
    // сообщение об ошибке 'Раздел с таким URL уже существует' отобразится.
    // При следующем обновлении страницы это сообщение не появится, так как оно не
    // было помещено в сессию при текущем запросе, и старая сессия была очищена в начале.
}

// =============================================================================
// Подготовка данных для шаблона
// =============================================================================
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../../../../admin/img/avatar.svg');

$formParentId = isset($_POST['parent_id'])
    ? (int)($_POST['parent_id'] === '' ? 0 : $_POST['parent_id'])
    : (int)($defaultParentId ?? 0);

$formParentName = '';
if (isset($_POST['parent_name'])) {
    $formParentName = trim((string)$_POST['parent_name']);
} else {
    $formParentName = $defaultParentName;
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
                                <a href="/<?= escape($categoryUrlPrefix) ?>/<?= escape($categoryFullPath) ?>" target="_blank"
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
                            <input type="text" class="form-control" name="name" required maxlength="255"
                                   value="<?= escape($name ?? $defaultNaime) ?>">
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
                    
                    <!-- === НОВЫЕ ПОЛЯ: HTML ФОРМА === -->
                    <!-- Здесь добавляйте новые поля формы -->
                    <!-- Пример:
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label">Новое поле 1</label>
                            <input type="text" class="form-control" name="custom_field_1" maxlength="500"
                                   value="переменная">
                            <div class="form-text">Описание нового поля</div>
                        </div>
                    </div>
                    -->
                    <!-- === КОНЕЦ НОВЫХ ПОЛЕЙ === -->
                </div>
                
                <!-- SEO -->
                <div class="form-section mb-5">
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
                            <label class="form-check-label" for="status">Активна</label>
                        </div>
                        <div class="form-text">Показывать запись на сайте</div>
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
    <script src="/admin/js/editor.js"></script>
    
    <!-- Модульный JS галереи -->
    <script type="module" src="/admin/user_images/js/main.js"></script>
    
    <!-- Поиск родительской категории -->
    <script src="../../js/authorsearch.js"></script>
    
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
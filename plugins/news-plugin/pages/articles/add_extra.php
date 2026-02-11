<?php
/**
 * Файл: /plugins/news-plugin/pages/articles/add_extra.php
 *
 * Назначение:
 * - Добавление и редактирование записей в таблице с дополнительным контентом (news_extra_content).
 * - ФИЛЬТРАЦИЯ ПО users_id = $_SESSION['user_id'] при загрузке и users_id автоматически заполняется при создании.
 *
 * Важно:
 * - news_id (родительский ID) берётся из GET параметра: ?news_id=1
 * - news_id сохраняется в отдельной колонке news_id.
 * - users_id автоматически заполняется из $_SESSION['user_id'] при создании записи.
 * - Дополнительные данные сохраняются в отдельных колонках: content, image.
 *
 * Безопасность:
 * - Имя таблицы нельзя принимать из GET/POST — задаётся в настройках.
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
    'htmleditor'      => true,          // подключение редактора WYSIWYG
    'jsondata'        => true,          // подключение обновление JSON данных пользователя
    'start_session'   => true,          // запуск Session
    'image_sizes'     => true,          // подключение модуля управления размерами изображений
    'plugin_access'   => true,          // подключение систему управления доступом к плагинам
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../../../../admin/functions/init.php';

// Подключаем дополнительную инициализацию
require_once __DIR__ . '/../../functions/pagination.php';            // Функция для генерации HTML пагинации

// Подключаем функции для работы с настройками плагина
require_once __DIR__ . '/../../functions/plugin_settings.php';

// Подключаем функцию автоопределения имени плагина
require_once __DIR__ . '/../../functions/plugin_helper.php';

// =============================================================================
// Проверка прав администратора
// =============================================================================
$adminData = getAdminData($pdo);
if ($adminData === false) {
    header("Location: ../../../../admin/logout.php");
    exit;
}

// === НАСТРОЙКИ ===
$pluginName = getPluginName();                       // Автоматическое определение имени плагина из структуры директорий
$titlemeta = 'Новости';                          // Название заголовка H1 для раздела
$titlemetah3 = 'Редактировать описание';         // Название заголовка H2 для раздела
$titlemeta_h3 = 'Добавить описание';             // Название заголовка H2 для раздела
$catalogTable = 'news_extra_content';            // Название таблицы (подключение у записи)
$authorCheckTable = 'news_articles';             // Привязка записи к родительской таблице
$maxDigits = getPluginMaxDigits($pdo, $pluginName, 'add_extra', 50);  // Ограничение на количество изображений из настроек плагина

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

// =============================================================================
// ПРОВЕРКА ДОСТУПА К ПЛАГИНУ
// =============================================================================
$userDataAdmin = pluginAccessGuard($pdo, $pluginName);
$currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

// Текущий user_id из сессии
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($currentUserId <= 0) {
    header("Location: ../../../../admin/logout.php");
    exit;
}

// =============================================================================
// ПРОВЕРКА GET ПАРАМЕТРА NEWS_ID
// =============================================================================

// GET параметры: news_id (обязательный), id (опционально для редактирования)
$newsIdFromGet = isset($_GET['news_id']) ? (int)$_GET['news_id'] : 0;

// Если существует GET параметр news_id и он содержит значение (ссылку)
if ($newsIdFromGet > 0) {
    try {
        // Проверяем существование записи в таблице $authorCheckTable
        $checkStmt = $pdo->prepare("SELECT id, url FROM {$authorCheckTable} WHERE id = ? AND users_id = ?");
        $checkStmt->execute([$newsIdFromGet, $currentUserId]);
        $authorExists = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Если запись не найдена - редирект на list.php
        if (!$authorExists) {
            logEvent("Попытка доступа к несуществующему news_id ID: $newsIdFromGet для users_id: $currentUserId в таблице {$authorCheckTable} — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_INFO_ENABLED, 'info');
            header("Location: list.php");
            exit;
        } else {
            $defaultUrl = $authorExists['url'] ?? '';
        }
    } catch (PDOException $e) {
        logEvent("Ошибка проверки news_id в таблице {$authorCheckTable}: " . $e->getMessage() . " — newsId: $newsIdFromGet, users_id: $currentUserId — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
        header("Location: list.php");
        exit;
    }
}

if ($newsIdFromGet <= 0) {
    // news_id обязателен: именно его сохраняем в колонку news_id
    header("Location: list.php");
    exit;
}

$isEditMode = isset($_GET['id']);
$itemId = $isEditMode ? (int)$_GET['id'] : null;

// =============================================================================
// Переменные страницы
// =============================================================================
$errors = [];
$successMessages = [];

// Значения по умолчанию (для формы)
$defaultSorting = 0;
$defaultStatus = 1;

// Данные из колонок
$title = '';
$content = '';
$image = '';

// =============================================================================
// Загрузка записи для редактирования (+ фильтр users_id)
// =============================================================================
if ($isEditMode && $itemId) {
    try {
        // Читаем запись с проверкой users_id
        $stmt = $pdo->prepare("SELECT * FROM {$catalogTable} WHERE id = ? AND news_id = ? AND users_id = ? LIMIT 1");
        $stmt->execute([$itemId, $newsIdFromGet, $currentUserId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            $defaultSorting = (int)($item['sorting'] ?? 0);
            $defaultStatus = (int)($item['status'] ?? 1);

            // Заголовок — хранится в колонке title
            $title = trim((string)($item['title'] ?? ''));

            // Контент (HTML) — хранится в колонке content
            $content = sanitizeHtmlFromEditor((string)($item['content'] ?? ''));

            // Изображение (ID/список ID) — хранится в колонке image
            $image = (string)($item['image'] ?? '');
        } else {
            $errors[] = 'Запись не найдена (или не принадлежит вашему пользователю).';
            header("Location: extra_list.php?news_id=$newsIdFromGet");
            exit;
        }
    } catch (PDOException $e) {
        $errors[] = 'Ошибка загрузки данных';
        logEvent("Ошибка загрузки записи ID=$itemId ({$catalogTable}): " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    }
}

// =============================================================================
// CSRF helpers
// =============================================================================
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// =============================================================================
// Обработка формы (создание/обновление)
// =============================================================================
// Инициализация переменной для результата валидации (используется позже для form repopulation)
$resultTitle = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Обновите страницу.';
        logEvent("CSRF ошибка в add_extra.php", LOG_ERROR_ENABLED, 'error');
    } else {
        // Sorting / Status
        $sorting = (int)($_POST['sorting'] ?? 0);
        if ($sorting < 0) {
            $sorting = 0;
        }

        $status = isset($_POST['status']) ? 1 : 0;

        // Заголовок дополнительного контента
        $titlePost = trim($_POST['title'] ?? '');
        $resultTitle = validateTextareaField($titlePost, 1, 200, 'Заголовок дополнительного контента');
        if ($resultTitle['valid']) {
            $titlePost = $resultTitle['value'];
            logEvent("Успешная валидация поля 'Заголовок дополнительного контента'", LOG_INFO_ENABLED, 'info');
        } else {
            $errors[] = $resultTitle['error'];
            $titlePost = false;
            logEvent("Ошибка валидации поля 'Заголовок дополнительного контента': " . $resultTitle['error'], LOG_ERROR_ENABLED, 'error');
        }

        // HTML контент
        $contentPost = sanitizeHtmlFromEditor($_POST['content'] ?? '');

        // Изображения (ID из медиа-библиотеки)
        $result_images = validateIdList(trim($_POST['image'] ?? ''), $maxDigits);
        if ($result_images['valid']) {
            $imagePost = $result_images['value'];
        } else {
            $errors[] = $result_images['error'];
            $imagePost = false;
        }

        // ---------------------------------------------------------------
        // ПОВТОРНАЯ ПРОВЕРКА NEWS_ID ПЕРЕД СОХРАНЕНИЕМ
        // ---------------------------------------------------------------
        if (empty($errors) && $newsIdFromGet > 0) {
            try {
                // Проверяем существование записи в таблице $authorCheckTable ПЕРЕД сохранением
                $checkStmt = $pdo->prepare("SELECT id FROM {$authorCheckTable} WHERE id = ? AND users_id = ?");
                $checkStmt->execute([$newsIdFromGet, $currentUserId]);
                $authorExists = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$authorExists) {
                    $errors[] = 'Недействительный news_id параметр.';
                    logEvent("Попытка сохранения с несуществующим news_id ID: $newsIdFromGet для users_id: $currentUserId в таблице {$authorCheckTable}", LOG_INFO_ENABLED, 'info');
                }
            } catch (PDOException $e) {
                $errors[] = 'Ошибка проверки параметров.';
                logEvent("Ошибка проверки news_id перед сохранением в {$authorCheckTable}: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
            }
        }

        // ---------------------------------------------------------------
        // Сохранение в БД (с users_id)
        // created_at / updated_at выставятся автоматически по схеме таблицы.
        // ---------------------------------------------------------------
        if (empty($errors)) {
            try {
                if ($isEditMode && $itemId) {
                    // UPDATE с проверкой users_id
                    $stmt = $pdo->prepare("
                        UPDATE {$catalogTable}
                        SET title = ?, content = ?, image = ?, sorting = ?, status = ?
                        WHERE id = ? AND news_id = ? AND users_id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([
                        $titlePost,
                        $contentPost,
                        $imagePost,
                        $sorting,
                        $status,
                        $itemId,
                        $newsIdFromGet,
                        $currentUserId
                    ]);

                    $successMessages[] = 'Запись успешно обновлена';
                    logEvent("Обновлена запись {$catalogTable} ID=$itemId news_id={$newsIdFromGet} users_id={$currentUserId}", LOG_INFO_ENABLED, 'info');
                    header("Location: add_extra.php?news_id=$newsIdFromGet&id=$itemId");
                    exit;
                } else {
                    // INSERT с автоматическим заполнением users_id
                    $stmt = $pdo->prepare("
                        INSERT INTO {$catalogTable}
                            (news_id, title, content, image, sorting, status, users_id, created_at)
                        VALUES
                            (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $newsIdFromGet,
                        $titlePost,
                        $contentPost,
                        $imagePost,
                        $sorting,
                        $status,
                        $currentUserId
                    ]);

                    $newId = (int)$pdo->lastInsertId();
                    $successMessages[] = 'Запись успешно создана';
                    logEvent("Создана запись {$catalogTable} ID=$newId news_id={$newsIdFromGet} users_id={$currentUserId}", LOG_INFO_ENABLED, 'info');
                    header("Location: add_extra.php?news_id=$newsIdFromGet&id=$newId");
                    exit;
                }
            } catch (PDOException $e) {
                $errors[] = 'Ошибка сохранения данных';
                logEvent("Ошибка сохранения ({$catalogTable}): " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
            }
        }
    }
}

// =============================================================================
// Подготовка данных для шаблона
// =============================================================================
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../../../../admin/img/avatar.svg');

// Для повторного заполнения формы после ошибок
$formSorting = isset($_POST['sorting']) ? (int)$_POST['sorting'] : (int)$defaultSorting;
$formStatus = isset($_POST['status']) ? 1 : (int)$defaultStatus;

// Используем валидированное значение если POST, иначе значение из базы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title']) && $resultTitle !== null && $resultTitle['valid']) {
    // Если валидация прошла успешно, используем валидированное значение
    $formTitle = $resultTitle['value'];
} else {
    // В остальных случаях используем значение из базы (это безопаснее чем неvalidированные данные)
    $formTitle = $title;
}

$formContent = isset($_POST['content']) ? sanitizeHtmlFromEditor($_POST['content']) : $content;
$formImage = $_POST['image'] ?? $image;
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

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
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

            <!-- Меню настройки новости -->
            <?php 
                if (isset($_GET['news_id']) && !empty($_GET['news_id']) && is_numeric($_GET['news_id'])) { 
                    require_once __DIR__ . '/header.php'; 
                } 
            ?>

            <form method="post">
                <div class="form-section">
                    <h3 class="card-title">
                        <i class="bi <?= $isEditMode ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i>
                        <?= escape($isEditMode ? $titlemetah3 : $titlemeta_h3) ?>
                    </h3>

                    <!-- Отображение сообщений -->
                    <?php displayAlerts(
                        $successMessages,  // Массив сообщений об успехе
                        $errors,           // Массив сообщений об ошибках
                        true               // Показывать сообщения как toast-уведомления
                    ); 
                    ?>

                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                    <!-- Заголовок дополнительного контента -->
                    <div class="mb-3">
                        <label class="form-label">Название<span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" 
                               value="<?= escape($formTitle) ?>" 
                               placeholder="Введите заголовок" required>
                    </div>

                    <div class="row align-items-center mb-5">
                        <div class="col-md-12">
                            <!-- Sorting -->
                            <label class="form-label">Sorting (порядок сортировки)</label>
                            <input type="number" class="form-control" name="sorting"
                                   value="<?= escape((string)$formSorting) ?>" step="1" min="0">
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

                    // Получаем настройки размеров изображений с учётом переопределений плагина
                    $imageSizes = getPluginImageSizes($pdo, $pluginName);

                    $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;
                    ?>

                    <input type="hidden" id="selectedImages_<?php echo $sectionId; ?>" name="<?php echo $sectionId; ?>"
                           value="<?php echo isset($image_ids) ? $image_ids : ''; ?>">

                    <div class="mb-5" id="image-management-section-<?php echo $sectionId; ?>">
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

                    <!-- Содержимое блока -->
                    <div class="mb-5 mt-4">
                        <h3 class="card-title">
                            <i class="bi bi-card-checklist"></i>
                            Содержимое блока
                        </h3>
                        <?php renderHtmlEditor('content', $formContent); ?>
                    </div>

                    <!-- Активность -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="status" id="status" value="1"
                                       <?= $formStatus ? 'checked' : '' ?>>
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

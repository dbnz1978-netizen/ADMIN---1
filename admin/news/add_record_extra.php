<?php
/**
 * Файл: /admin/record/add_record_extra.php
 *
 * Назначение:
 * - Добавление и редактирование записей в таблице с дополнительной информацией (record_extra).
 * - Упрощённая версия: без полей "Родительская категория", без "URL", без "Мета-теги SEO".
 * - ФИЛЬТРАЦИЯ ПО users_id = $_SESSION['user_id'] при загрузке и users_id автоматически заполняется при создании.
 *
 * Важно:
 * - author (родительский ID) берётся из GET параметра: ?author=1
 * - author сохраняется в отдельной колонке author (НЕ в JSON).
 * - users_id автоматически заполняется из $_SESSION['user_id'] при создании записи.
 * - Дополнительные данные сохраняются в JSON колонке data (например: description, image, style, любые будущие поля).
 *
 * Безопасность:
 * - Имя таблицы нельзя принимать из GET/POST — задаётся в настройках.
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
// НАСТРОЙКИ (меняются в одном месте)
// =============================================================================
$catalogTable = 'news_extra'; // таблица record_extra

// Имя таблицы для проверки author (меняется в настройках)
$authorCheckTable = 'news';

// Ограничение на количество изображений
$maxDigits = 50; 

// =============================================================================
// Подключаем системные компоненты
// =============================================================================
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';           // База данных
require_once __DIR__ . '/../functions/auth_check.php';                // Авторизация
require_once __DIR__ . '/../functions/file_log.php';                  // Логи
require_once __DIR__ . '/../functions/jsondata.php';                  // Данные пользователя
require_once __DIR__ . '/../functions/display_alerts.php';            // Сообщения
require_once __DIR__ . '/../functions/sanitization.php';              // Валидация/экранирование
require_once __DIR__ . '/../functions/htmleditor.php';                // WYSIWYG-редактор

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
if ($currentUserId <= 0) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

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

    // ПРОВЕРКА ПРАВ ДОСТУПА
    $hasrecordAccess = false;
    if ($userDataAdmin['author'] === 'admin' && ($adminData['allow_catalog_admin'] ?? false) === true) {
        $hasrecordAccess = true;
    } elseif ($userDataAdmin['author'] === 'user' && ($adminData['allow_catalog_users'] ?? false) === true) {
        $hasrecordAccess = true;
    }

    // Если доступ запрещен - ЛОГАУТ!
    if (!$hasrecordAccess) {
        logEvent("Доступ к записи запрещен. Author: {$userDataAdmin['author']}, IP: {$_SERVER['REMOTE_ADDR']}", LOG_ERROR_ENABLED, 'error');
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
    logEvent("Ошибка инициализации add_record_extra.php: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
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
// ПРОВЕРКА GET ПАРАМЕТРА AUTHOR
// =============================================================================

// GET параметры: author (обязательный), id (опционально для редактирования)
$authorFromGet = isset($_GET['author']) ? (int)$_GET['author'] : 0;

// Если существует GET параметр author и он содержит значение (ссылку)
if ($authorFromGet > 0) {
    try {
        // Проверяем существование записи в таблице $authorCheckTable
        $checkStmt = $pdo->prepare("SELECT id, url FROM {$authorCheckTable} WHERE id = ? AND users_id = ?");
        $checkStmt->execute([$authorFromGet, $currentUserId]);
        $authorExists = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Если запись не найдена - редирект на record_list.php
        if (!$authorExists) {
            logEvent("Попытка доступа к несуществующему author ID: $authorFromGet для users_id: $currentUserId в таблице {$authorCheckTable} — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_INFO_ENABLED, 'info');
            // Закрываем соединение при завершении скрипта
            register_shutdown_function(function() {
                if (isset($pdo)) {
                    $pdo = null; 
                }
            });
            header("Location: record_list.php");
            exit;
        } else {
            $defaultUrl = $authorExists['url'] ?? '';
        }
    } catch (PDOException $e) {
        logEvent("Ошибка проверки author в таблице {$authorCheckTable}: " . $e->getMessage() . " — authorId: $authorFromGet, users_id: $currentUserId — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: record_list.php");
        exit;
    }
}

if ($authorFromGet <= 0) {
    // author обязателен: именно его сохраняем в колонку author
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: record_list.php");
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
$defaultNaime = '';
$defaultSorting = 0;
$defaultStatus = 1;
$defaultStyle = 'css_1'; // Значение по умолчанию для style

// Доп. данные (JSON)
$text = '';
$image = '';

// =============================================================================
// Загрузка записи для редактирования (+ фильтр users_id)
// =============================================================================
if ($isEditMode && $itemId) {
    try {
        // Читаем запись с проверкой users_id
        $stmt = $pdo->prepare("SELECT * FROM {$catalogTable} WHERE id = ? AND author = ? AND users_id = ? LIMIT 1");
        $stmt->execute([$itemId, $authorFromGet, $currentUserId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            $defaultNaime = $item['naime'] ?? '';
            $defaultSorting = (int)($item['sorting'] ?? 0);
            $defaultStatus = (int)($item['status'] ?? 1);

            $dataArr = json_decode($item['data'] ?? '{}', true) ?: [];

            // Описание (HTML) — хранится в JSON
            $text = sanitizeHtmlFromEditor((string)($dataArr['description'] ?? ''));

            // Изображение (ID/список ID) — хранится в JSON
            $image = (string)($dataArr['image'] ?? '');

            // Стиль блока — хранится в JSON
            $defaultStyle = (string)($dataArr['style'] ?? 'css_1');

            // ---------------------------------------------------------------
            // ДОБАВЛЕНИЕ НОВОГО ПОЛЯ В JSON (data) — место №1:
            // Пример:
            // $defaultSubtitle = (string)($dataArr['subtitle'] ?? '');
            // ---------------------------------------------------------------
        } else {
            $errors[] = 'Запись не найдена (или не принадлежит вашему пользователю).';
            // Закрываем соединение при завершении скрипта
            register_shutdown_function(function() {
                if (isset($pdo)) {
                    $pdo = null; 
                }
            });
            header("Location: record_list.php");
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Обновите страницу.';
        logEvent("CSRF ошибка в add_record_extra.php", LOG_ERROR_ENABLED, 'error');
    } else {

        // Название
        $naime = trim($_POST['naime'] ?? '');
        $result = validateTextareaField($naime, 1, 255, 'Название');
        if ($result['valid']) {
            $naime = $result['value'];
        } else {
            $errors[] = $result['error'];
            $naime = false;
        }

        // Sorting / Status
        $sorting = (int)($_POST['sorting'] ?? 0);
        if ($sorting < 0) $sorting = 0;

        $status = isset($_POST['status']) ? 1 : 0;

        // HTML описание (в JSON)
        $textPost = sanitizeHtmlFromEditor($_POST['text'] ?? '');

        // Изображения (ID из медиа-библиотеки) — в JSON
        $result_images = validateIdList(trim($_POST['image'] ?? ''), $maxDigits);
        if ($result_images['valid']) {
            $imagePost = $result_images['value'];
        } else {
            $errors[] = $result_images['error'];
            $imagePost = false;
        }

        // Стиль блока — валидация и очистка от инъекций
        $stylePost = trim($_POST['style'] ?? 'css_1');
        // Защита от инъекций: разрешаем только допустимые значения
        $allowedStyles = ['css_1', 'css_2'];
        if (!in_array($stylePost, $allowedStyles)) {
            $stylePost = 'css_1'; // Значение по умолчанию при некорректных данных
        }

        // ---------------------------------------------------------------
        // Сбор JSON для колонки data
        // ---------------------------------------------------------------
        $dataPayload = [
            'description' => $textPost,
            'image' => $imagePost,
            'style' => $stylePost, // Добавлено новое поле style

            // -----------------------------------------------------------
            // ДОБАВЛЕНИЕ НОВОГО ПОЛЯ В JSON (data) — место №2:
            // Пример:
            // 'subtitle' => $subtitle,
            // 'color' => $color,
            // -----------------------------------------------------------
        ];

        $dataJson = json_encode($dataPayload, JSON_UNESCAPED_UNICODE);

        // ---------------------------------------------------------------
        // ПОВТОРНАЯ ПРОВЕРКА AUTHOR ПЕРЕД СОХРАНЕНИЕМ
        // ---------------------------------------------------------------
        if (empty($errors) && $authorFromGet > 0) {
            try {
                // Проверяем существование записи в таблице $authorCheckTable ПЕРЕД сохранением
                $checkStmt = $pdo->prepare("SELECT id FROM {$authorCheckTable} WHERE id = ? AND users_id = ?");
                $checkStmt->execute([$authorFromGet, $currentUserId]);
                $authorExists = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$authorExists) {
                    $errors[] = 'Недействительный author параметр.';
                    logEvent("Попытка сохранения с несуществующим author ID: $authorFromGet для users_id: $currentUserId в таблице {$authorCheckTable}", LOG_INFO_ENABLED, 'info');
                }
            } catch (PDOException $e) {
                $errors[] = 'Ошибка проверки параметров.';
                logEvent("Ошибка проверки author перед сохранением в {$authorCheckTable}: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
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
                        SET naime = ?, data = ?, sorting = ?, status = ?
                        WHERE id = ? AND author = ? AND users_id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([
                        $naime,
                        $dataJson,
                        $sorting,
                        $status,
                        $itemId,
                        $authorFromGet,
                        $currentUserId
                    ]);

                    $successMessages[] = 'Запись успешно обновлена';
                    logEvent("Обновлена запись {$catalogTable} ID=$itemId author={$authorFromGet} users_id={$currentUserId}", LOG_INFO_ENABLED, 'info');
                    // Закрываем соединение при завершении скрипта
                    register_shutdown_function(function() {
                        if (isset($pdo)) {
                            $pdo = null; 
                        }
                    });
                    header("Location: add_record_extra.php?author=$authorFromGet&id=$itemId");
                    exit;

                } else {
                    // INSERT с автоматическим заполнением users_id
                    $stmt = $pdo->prepare("
                        INSERT INTO {$catalogTable}
                            (naime, author, data, sorting, status, users_id, created_at)
                        VALUES
                            (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $naime,
                        $authorFromGet,
                        $dataJson,
                        $sorting,
                        $status,
                        $currentUserId
                    ]);

                    $newId = (int)$pdo->lastInsertId();
                    $successMessages[] = 'Запись успешно создана';
                    logEvent("Создана запись {$catalogTable} ID=$newId author={$authorFromGet} users_id={$currentUserId}", LOG_INFO_ENABLED, 'info');
                    // Закрываем соединение при завершении скрипта
                    register_shutdown_function(function() {
                        if (isset($pdo)) {
                            $pdo = null; 
                        }
                    });
                    header("Location: add_record_extra.php?author=$authorFromGet&id=$newId");
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
// CSRF token генерация
// =============================================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =============================================================================
// Подготовка данных для шаблона
// =============================================================================
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
$titlemeta = 'Записи';

// Для повторного заполнения формы после ошибок
$formNaime = $_POST['naime'] ?? $defaultNaime;
$formSorting = isset($_POST['sorting']) ? (int)$_POST['sorting'] : (int)$defaultSorting;
$formStatus = isset($_POST['status']) ? 1 : (int)$defaultStatus;
$formText = isset($_POST['text']) ? sanitizeHtmlFromEditor($_POST['text']) : $text;
$formImage = $_POST['image'] ?? $image;
$formStyle = $_POST['style'] ?? $defaultStyle;

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});

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

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/main.css">

    <!-- WYSIWYG-редактор -->
    <link rel="stylesheet" href="../css/editor.css">

    <!-- Медиа-библиотека -->
    <link rel="stylesheet" href="../user_images/css/main.css">

    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
</head>
<body>
<div class="container-fluid">
    <?php require_once __DIR__ . '/../template/sidebar.php'; ?>

    <main class="main-content">
        <?php require_once __DIR__ . '/../template/header.php'; ?>

        <!-- Меню настройки записи -->
        <?php 
            if (isset($_GET['author']) && !empty($_GET['author']) && is_numeric($_GET['author'])) { 
                require_once __DIR__ . '/header.php'; 
            } 
        ?>

        <form method="post">
            <div class="form-section">
                <h3 class="card-title">
                    <i class="bi <?= $isEditMode ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i>
                    <?= $isEditMode ? 'Редактировать описание записи' : 'Добавить описание записи' ?>
                </h3>

                <!-- Сообщения об ошибках/успехе -->
                <?php displayAlerts($successMessages, $errors); ?>

                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                <!-- Название -->
                <div class="row mb-3">
                    <div class="col-12">
                        <label class="form-label">Название <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="naime" required maxlength="255"
                               value="<?= escape((string)$formNaime) ?>">
                    </div>
                </div>

                <div class="row align-items-center mb-5">
                    <div class="col-md-6">
                        <!-- Sorting -->
                        <label class="form-label">Sorting (порядок сортировки)</label>
                        <input type="number" class="form-control" name="sorting"
                            value="<?= escape((string)$formSorting) ?>" step="1" min="0">
                    </div>
                    <div class="col-md-6">
                        <!-- Cтиль блока -->
                        <label class="form-label">Cтиль блока</label>
                        <select name="style" class="form-select">
                            <option value="css_1" <?= ($formStyle === 'css_1') ? 'selected' : '' ?>><?= 'Полная ширина' ?></option>
                            <option value="css_2" <?= ($formStyle === 'css_2') ? 'selected' : '' ?>><?= 'Ограниченная ширина' ?></option>
                        </select>
                    </div>
                </div>

                <!-- Изображение -->
                <h3 class="card-title">
                    <i class="bi bi-card-image"></i>
                    Изображение
                </h3>

                <?php
                $sectionId = 'image';
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
                       value="<?php echo isset($formImage) ? $formImage : ''; ?>">

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

                <!-- Содержимое блока -->
                <div class="mb-4 mt-4">
                    <h3 class="card-title">
                        <i class="bi bi-card-checklist"></i>
                        Содержимое блока
                    </h3>
                    <?php renderHtmlEditor('text', $formText); ?>
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

<?php
/**
 * Файл: /admin/user/user_settings.php
 * 
 * Админ-панель - Настройки для пользователей
 * 
 * ОБНОВЛЕНИЕ 22.01.2026: НОВЫЕ НАСТРОЙКИ В 2 КОЛОНКИ (Магазин+Страницы | Каталог+Новости)
 * - Левая колонка: Магазин + Страницы
 * - Правая колонка: Каталог + Новости
 * 
 * МЕСТО ДЛЯ ДОБАВЛЕНИЯ НОВЫХ НАСТРОЕК:
 * 1. PHP: $allow_ВАШЕ_НАЗВАНИЕ = (bool)($currentSettings['allow_ВАШЕ_НАЗВАНИЕ'] ?? true);
 * 2. PHP: $allow_ВАШЕ_НАЗВАНИЕ = isset($_POST['allow_ВАШЕ_НАЗВАНИЕ']);
 * 3. PHP: 'allow_ВАШЕ_НАЗВАНИЕ' => $allow_ВАШЕ_НАЗВАНИЕ,
 * 4. HTML: Добавить в <div class="col-lg-6"> блок
 */

define('APP_ACCESS', true);
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

// Подключаем системные компоненты
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          
require_once __DIR__ . '/../functions/auth_check.php';               
require_once __DIR__ . '/../functions/file_log.php';                 
require_once __DIR__ . '/../functions/jsondata.php';                 
require_once __DIR__ . '/../functions/display_alerts.php';           
require_once __DIR__ . '/../functions/sanitization.php';             
require_once __DIR__ . '/../functions/htmleditor.php';               

startSessionSafe();

$adminData = getAdminData($pdo);
if ($adminData === false) {
    register_shutdown_function(function() { if (isset($pdo)) $pdo = null; });
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
        register_shutdown_function(function() { if (isset($pdo)) $pdo = null; });
        header("Location: $redirectTo");
        exit;
    }

    $userDataAdmin = getUserData($pdo, $user['id']);
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg = $userDataAdmin['message'];
        $level = $userDataAdmin['level'];
        $logEnabled = match($level) {'info'  => LOG_INFO_ENABLED, 'error' => LOG_ERROR_ENABLED, default => LOG_ERROR_ENABLED};
        logEvent($msg, $logEnabled, $level);
        register_shutdown_function(function() { if (isset($pdo)) $pdo = null; });
        header("Location: ../logout.php");
        exit;
    }

    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];
    
    if ($userDataAdmin['author'] !== 'admin') {
        register_shutdown_function(function() { if (isset($pdo)) $pdo = null; });
        header("Location: ../logout.php");
        exit;
    }

} catch (Exception $e) {
    logEvent("Ошибка при инициализации админ-панели: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    register_shutdown_function(function() { if (isset($pdo)) $pdo = null; });
    header("Location: ../logout.php");
    exit;
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

$errors = [];
$successMessages = [];

// Загрузка текущих настроек из базы данных
$currentSettings = [];
try {
    $stmt = $pdo->prepare("SELECT data FROM users WHERE id = ? AND author = 'admin' LIMIT 1");
    $stmt->execute([$user['id']]);
    $settingsData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($settingsData) {
        $currentSettings = json_decode($settingsData['data'] ?? '{}', true) ?? [];
    }
} catch (PDOException $e) {
    logEvent("Ошибка загрузки настроек: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
}

// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
// ОСНОВНЫЕ НАСТРОЙКИ
// ═══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
$allowRegistration = (bool)($currentSettings['allow_registration'] ?? true);
$allowPhotoUpload = (bool)($currentSettings['allow_photo_upload'] ?? true);
$allowOnlineChat = (bool)($currentSettings['allow_online_chat'] ?? true);
$log_info_enabled = (bool)($currentSettings['log_info_enabled'] ?? true);
$log_error_enabled = (bool)($currentSettings['log_error_enabled'] ?? true);
$notifications = (bool)($currentSettings['notifications'] ?? true);

// ★★★ НОВЫЕ НАСТРОЙКИ ★★★
$allowShopUsers = (bool)($currentSettings['allow_shop_users'] ?? true);           
$allowShopAdmin = (bool)($currentSettings['allow_shop_admin'] ?? true);           
$allowPagesUsers = (bool)($currentSettings['allow_pages_users'] ?? true);         
$allowPagesAdmin = (bool)($currentSettings['allow_pages_admin'] ?? true);         
$allowCatalogUsers = (bool)($currentSettings['allow_catalog_users'] ?? true);     
$allowCatalogAdmin = (bool)($currentSettings['allow_catalog_admin'] ?? true);     
$allowNewsUsers = (bool)($currentSettings['allow_news_users'] ?? true);           
$allowNewsAdmin = (bool)($currentSettings['allow_news_admin'] ?? true);           

$image_limit = !empty($currentSettings['image_limit']) ? (int)$currentSettings['image_limit'] : 0;
$AdminPanel = $currentSettings['AdminPanel'] ?? 'AdminPanel';
$profile_logo = $currentSettings['profile_logo'] ?? '';

$editor_1 = sanitizeHtmlFromEditor($currentSettings['editor_1'] ?? '');    
$terms = sanitizeHtmlFromEditor($currentSettings['terms'] ?? '');
$privacy = sanitizeHtmlFromEditor($currentSettings['privacy'] ?? '');

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Пожалуйста, обновите страницу.';
        logEvent("Проверка CSRF-токена не пройдена — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    } else {
        $allowRegistration = isset($_POST['allow_registration']);
        $allowPhotoUpload  = isset($_POST['allow_photo_upload']);
        $allowOnlineChat   = isset($_POST['allow_online_chat']);
        $log_info_enabled  = isset($_POST['log_info_enabled']);
        $log_error_enabled = isset($_POST['log_error_enabled']);
        $notifications = isset($_POST['notifications']);
        $image_limit = (int)($_POST['image_limit'] ?? 0);

        $allowShopUsers = isset($_POST['allow_shop_users']);
        $allowShopAdmin = isset($_POST['allow_shop_admin']);
        $allowPagesUsers = isset($_POST['allow_pages_users']);
        $allowPagesAdmin = isset($_POST['allow_pages_admin']);
        $allowCatalogUsers = isset($_POST['allow_catalog_users']);
        $allowCatalogAdmin = isset($_POST['allow_catalog_admin']);
        $allowNewsUsers = isset($_POST['allow_news_users']);
        $allowNewsAdmin = isset($_POST['allow_news_admin']);

        $editor_1 = sanitizeHtmlFromEditor($_POST['editor_1']);
        $terms = sanitizeHtmlFromEditor($_POST['terms']);
        $privacy = sanitizeHtmlFromEditor($_POST['privacy']);

        $maxDigits = 1;
        $result_images_logo = validateIdList(trim($_POST['profile_logo'] ?? ''), $maxDigits);
        if ($result_images_logo['valid']) {
            $profile_logo = $result_images_logo['value'];
        } else {
            $errors[] = $result_images_logo['error'];
            $profile_logo = false;
        }

        $adminPanelInput = trim($_POST['AdminPanel'] ?? '');
        $result = validateTextareaField($adminPanelInput, 1, 20, 'Название Админ-панели');
        if ($result['valid']) {
            $AdminPanel = $result['value'];
        } else {
            $errors[] = $result['error'];
            $AdminPanel = false;
        }

        if (empty($errors)) {
            try {
                $settingsData = [
                    'AdminPanel' => $AdminPanel,
                    'allow_registration' => $allowRegistration,
                    'allow_photo_upload' => $allowPhotoUpload,
                    'allow_online_chat'  => $allowOnlineChat,
                    'log_info_enabled'   => $log_info_enabled,
                    'log_error_enabled'  => $log_error_enabled,
                    'notifications'      => $notifications,
                    'image_limit'        => $image_limit,
                    'editor_1'           => $editor_1,
                    'terms'              => $terms,
                    'privacy'            => $privacy,
                    'profile_logo'       => $profile_logo,
                    'allow_shop_users' => $allowShopUsers,
                    'allow_shop_admin' => $allowShopAdmin,
                    'allow_pages_users' => $allowPagesUsers,
                    'allow_pages_admin' => $allowPagesAdmin,
                    'allow_catalog_users' => $allowCatalogUsers,
                    'allow_catalog_admin' => $allowCatalogAdmin,
                    'allow_news_users' => $allowNewsUsers,
                    'allow_news_admin' => $allowNewsAdmin
                ];

                $jsonData = updateUserJsonData($pdo, $user['id'], $settingsData);
                $update = $pdo->prepare("UPDATE users SET data = ?, updated_at = NOW() WHERE id = ?");
                $update->execute([$jsonData, $user['id']]);

                $successMessages[] = 'Настройки успешно сохранены!';
                logEvent("Настройки системы обновлены пользователем ID: " . $user['id'], LOG_INFO_ENABLED, 'info');

            } catch (PDOException $e) {
                $errors[] = 'Ошибка при сохранении настроек. Пожалуйста, попробуйте позже.';
                logEvent("Ошибка базы данных при обновлении настроек: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
            } catch (JsonException $e) {
                $errors[] = 'Ошибка при обработке данных. Пожалуйста, попробуйте позже.';
                logEvent("Ошибка кодирования JSON при обновлении настроек: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
            }
        }
    }

    register_shutdown_function(function() { if (isset($pdo)) $pdo = null; });
    header("Location: user_settings.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
$titlemeta = 'Настройки для пользователей';

register_shutdown_function(function() { if (isset($pdo)) $pdo = null; });
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
    <link rel="stylesheet" href="../css/editor.css">
    <link rel="stylesheet" href="../user_images/css/main.css">
    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
</head>

<body>
    <div class="container-fluid">
        <?php require_once __DIR__ . '/../template/sidebar.php'; ?>

        <main class="main-content">
            <?php require_once __DIR__ . '/../template/header.php'; ?>

            <?php displayAlerts($successMessages, $errors); ?>

            <form action="" method="post" id="postForm_editor1">
                <input type="hidden" name="csrf_token" value="<?= escape(generateCsrfToken()) ?>">

                <!-- НАЗВАНИЕ И ЛОГОТИП АДМИН-ПАНЕЛИ -->
                <div class="form-section">
                    <div class="row mb-5">
                        <div class="col-12">
                            <h3 class="card-title">
                                <i class="bi bi-pencil-square"></i>
                                Название Админ-панели
                            </h3>
                            <input type="text"
                                class="form-control"
                                id="AdminPanel"
                                name="AdminPanel"
                                minlength="1" maxlength="20"
                                value="<?= escape($AdminPanel) ?>">
                        </div>
                    </div>

                    <h3 class="card-title">
                        <i class="bi bi-card-image"></i>
                        Логотип Админ-панели
                    </h3>
                    <?php
                    $sectionId = 'profile_logo';
                    $image_ids = $profile_logo;
                    $_SESSION['max_files_per_user'] = $adminData['image_limit'] ?? 0; 
                    $imageSizes = [
                        "thumbnail" => [100, 100, "cover"],
                        "small"     => [300, 'auto', "contain"],
                        "medium"    => [600, 'auto', "contain"],
                        "large"     => [1200, 'auto', "contain"]
                    ];
                    $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;
                    ?>
                    <input type="hidden" id="selectedImages_<?php echo $sectionId; ?>" name="<?php echo $sectionId; ?>" value="<?php echo isset($image_ids) ? $image_ids : ''; ?>">
                    <div id="image-management-section-<?php echo $sectionId; ?>">
                        <div id="loading-content-<?php echo $sectionId; ?>"></div>
                        <button type="button" class="btn btn-outline-primary load-more-files" data-bs-toggle="modal" data-bs-target="#<?php echo $sectionId; ?>" onclick="storeSectionId('<?php echo $sectionId; ?>')">
                            <i class="bi bi-plus-circle me-1"></i> Добавить медиа файл
                        </button>
                        <div class="form-text">Не более 1 шт</div>
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
                                    <button type="button" id="saveButton" class="btn btn-primary" data-section-id="<?php echo htmlspecialchars($sectionId, ENT_QUOTES); ?>" onclick="handleSelectButtonClick()" data-bs-dismiss="modal">Выбрать</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ★★★ НОВЫЕ НАСТРОЙКИ: 2 КОЛОНКИ (ЛЕВАЯ | ПРАВАЯ) ★★★ -->
                <div class="row">
                    <!-- =================== ЛЕВАЯ КОЛОНКА: МАГАЗИН + СТРАНИЦЫ =================== -->
                    <div class="col-lg-6">
                        <div class="form-section mb-5">
                            <h4 class="card-title">
                                <i class="bi bi-shop"></i>
                                Магазин
                            </h4>
                            <div class="row col-example-row">
                                <div class="col-12">
                                    <div class="form-check form-switch mb-4">
                                        <input class="form-check-input" type="checkbox" id="allow_shop_users" name="allow_shop_users" <?= $allowShopUsers ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_shop_users">Для пользователей</label>
                                    </div>
                                    <div class="form-text mb-4">Включить магазин для пользователей</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allow_shop_admin" name="allow_shop_admin" <?= $allowShopAdmin ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_shop_admin">Для администратора</label>
                                    </div>
                                    <div class="form-text">Включить магазин для администратора</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section mb-5">
                            <h4 class="card-title">
                                <i class="bi bi-file-earmark-text"></i>
                                Страницы
                            </h4>
                            <div class="row col-example-row">
                                <div class="col-12">
                                    <div class="form-check form-switch mb-4">
                                        <input class="form-check-input" type="checkbox" id="allow_pages_users" name="allow_pages_users" <?= $allowPagesUsers ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_pages_users">Для пользователей</label>
                                    </div>
                                    <div class="form-text mb-4">Включить создание страниц для пользователей</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allow_pages_admin" name="allow_pages_admin" <?= $allowPagesAdmin ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_pages_admin">Для администратора</label>
                                    </div>
                                    <div class="form-text">Включить создание страниц для администратора</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- =================== ПРАВАЯ КОЛОНКА: КАТАЛОГ + НОВОСТИ =================== -->
                    <div class="col-lg-6">
                        <div class="form-section mb-5">
                            <h4 class="card-title">
                                <i class="bi bi-grid"></i>
                                Записи
                            </h4>
                            <div class="row col-example-row">
                                <div class="col-12">
                                    <div class="form-check form-switch mb-4">
                                        <input class="form-check-input" type="checkbox" id="allow_catalog_users" name="allow_catalog_users" <?= $allowCatalogUsers ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_catalog_users">Для пользователей</label>
                                    </div>
                                    <div class="form-text mb-4">Включить создание записей для пользователей</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allow_catalog_admin" name="allow_catalog_admin" <?= $allowCatalogAdmin ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_catalog_admin">Для администратора</label>
                                    </div>
                                    <div class="form-text">Включить создание записей для администратора</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-section mb-5">
                            <h4 class="card-title">
                                <i class="bi bi-newspaper"></i>
                                Новости
                            </h4>
                            <div class="row col-example-row">
                                <div class="col-12">
                                    <div class="form-check form-switch mb-4">
                                        <input class="form-check-input" type="checkbox" id="allow_news_users" name="allow_news_users" <?= $allowNewsUsers ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_news_users">Для пользователей</label>
                                    </div>
                                    <div class="form-text mb-4">Включить создание новостей для пользователей</div>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allow_news_admin" name="allow_news_admin" <?= $allowNewsAdmin ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_news_admin">Для администратора</label>
                                    </div>
                                    <div class="form-text">Включить создание новостей для администратора</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- СТАРЫЕ НАСТРОЙКИ -->
                <div class="form-section mb-5">
                    <h3 class="card-title">
                        <i class="bi bi-check2-square"></i>
                        Ограничения для пользователей
                    </h3>
                    <div class="row col-example-row">
                        <div class="col-3">
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allow_registration" name="allow_registration" <?= $allowRegistration ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_registration">Нет/Да</label>
                                    </div>
                                    <div class="form-text">Разрешить регистрацию пользователей</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allow_photo_upload" name="allow_photo_upload" <?= $allowPhotoUpload ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_photo_upload">Нет/Да</label>
                                    </div>
                                    <div class="form-text">Разрешить загрузку фотографий для пользователей</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allow_online_chat" name="allow_online_chat" <?= $allowOnlineChat ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="allow_online_chat">Нет/Да</label>
                                    </div>
                                    <div class="form-text">Разрешить чат онлайн с администратором</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="log_info_enabled" name="log_info_enabled" <?= $log_info_enabled ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="log_info_enabled">Нет/Да</label>
                                    </div>
                                    <div class="form-text">Включить логирование (успешные события)</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="log_error_enabled" name="log_error_enabled" <?= $log_error_enabled ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="log_error_enabled">Нет/Да</label>
                                    </div>
                                    <div class="form-text">Включить логирование (ошибки)</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row col-example-row">
                        <div class="col-3">
                            <div class="row mb-3">
                                <div class="col-12">
                                    <label for="imageLimit" class="form-label">Лимит файлов в <a href="main_images.php">библиотеке</a></label>
                                    <input type="number" class="form-control" id="imageLimit" name="image_limit" min="0" value="<?= (int)$image_limit ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- УВЕДОМЛЕНИЯ, УСЛОВИЯ, КОНФИДЕНЦИАЛЬНОСТЬ -->
                <div class="mb-5">
                    <h3 class="card-title">
                        <i class="bi bi-bell"></i>
                        Уведомления
                    </h3>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="notifications" name="notifications" <?= $notifications ? 'checked' : '' ?>>
                        <label class="form-check-label" for="notifications">Нет/Да</label>
                    </div>
                    <div class="form-text">Включить уведомления</div>
                    <?php renderHtmlEditor('editor_1', $editor_1); ?>
                </div>

                <div class="mb-5">
                    <h3 class="card-title">
                        <i class="bi bi-card-checklist"></i>
                        Условия использования
                    </h3>
                    <div class="form-text">Условия использования отображается на странице регистрации</div>
                    <?php renderHtmlEditor('terms', $terms); ?>
                </div>

                <div class="mb-5">
                    <h3 class="card-title">
                        <i class="bi bi-card-checklist"></i>
                        Политика конфиденциальности
                    </h3>
                    <div class="form-text">Политика конфиденциальности отображается на странице регистрации</div>
                    <?php renderHtmlEditor('privacy', $privacy); ?>
                </div>

                <div class="mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Сохранить настройки
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

    <!-- Инициализация галереи -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Загружаем галерею при старте №1
        loadGallery('profile_logo');

        // Загружаем библиотеку файлов
        loadImageSection('profile_logo');    
    });
    </script>
</body>
</html>

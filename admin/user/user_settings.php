<?php

/**
 * Название файла:      user_settings.php
 * Назначение:          Страница настроек админ-панели, управляющая глобальными параметрами системы:
 *                      - разрешение регистрации, загрузки фото, онлайн-чата
 *                      - логирование событий и ошибок
 *                      - уведомления, условия использования, политика конфиденциальности
 *                      - лимиты на количество изображений
 *                      - название и логотип админ-панели
 *                      
 *                      Особенности:
 *                      - Доступ только для пользователей с ролью 'admin'
 *                      - Поддержка CSRF-защиты и санитизации HTML-контента
 *                      - Интеграция с медиа-библиотекой через модальные окна и drag-and-drop
 *                      - Использует flash-сообщения через сессию для отображения результатов после редиректа
 *                      - Все изменения логируются
 *                      
 *                      МЕСТО ДЛЯ ДОБАВЛЕНИЯ НОВЫХ НАСТРОЕК:
 *                      1. PHP: $allowCustomSetting = (bool)($currentSettings['allowCustomSetting'] ?? true);
 *                      2. PHP: $allowCustomSetting = isset($_POST['allowCustomSetting']);
 *                      3. PHP: 'allowCustomSetting' => $allowCustomSetting,
 *                      4. HTML: Добавить в <div class="col-lg-6"> блок
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors'   => false,   // включение отображения ошибок true/false
    'set_encoding'     => true,    // включение кодировки UTF-8
    'db_connect'       => true,    // подключение к базе
    'auth_check'       => true,    // подключение функций авторизации
    'file_log'         => true,    // подключение системы логирования
    'display_alerts'   => true,    // подключение отображения сообщений
    'sanitization'     => true,    // подключение валидации/экранирования
    'htmleditor'       => true,    // подключение редактора WYSIWYG
    'csrf_token'       => true,    // генерация CSRF-токена
    'image_sizes'      => true,    // подключение модуля управления размерами изображений
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);

if ($adminData === false) {
    header("Location: ../logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ СТРАНИЦЫ
// ========================================

$maxDigits   = 2;                                     // Ограничение на количество изображений в логотипе
$titlemeta   = 'Настройки для пользователей';         // Название Админ-панели

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled'] ?? false) === true);  // Логировать успешные события
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true); // Логировать ошибки

// ========================================
// ЗАГРУЗКА FLASH-СООБЩЕНИЙ
// ========================================

// Инициализируем массивы для сообщений
$successMessages = [];
$errors          = [];

// Загружаем flash-сообщения из сессии (если есть)
if (!empty($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors          = $_SESSION['flash_messages']['error'] ?? [];
    
    // Удаляем их, чтобы не показывались при повторной загрузке
    unset($_SESSION['flash_messages']);
}

// ========================================
// АВТОРИЗАЦИЯ И ПРОВЕРКА ПРАВ
// ========================================

try {
    $user = requireAuth($pdo);
    
    if (!$user) {
        $redirectTo = '../logout.php';
        $logMessage = "Неавторизованный доступ — перенаправление на: $redirectTo — IP: "
            . "{$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}";
        logEvent($logMessage, LOG_INFO_ENABLED, 'info');
        header("Location: $redirectTo");
        exit;
    }

    $userDataAdmin = getUserData($pdo, $user['id']);
    
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg        = $userDataAdmin['message'];
        $level      = $userDataAdmin['level'];
        $logEnabled = match ($level) {
            'info'  => LOG_INFO_ENABLED,
            'error' => LOG_ERROR_ENABLED,
            default => LOG_ERROR_ENABLED,
        };
        
        logEvent($msg, $logEnabled, $level);
        header("Location: ../logout.php");
        exit;
    }
    
    // Декодируем JSON-данные администратора
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

    if ($userDataAdmin['author'] !== 'admin') {
        header("Location: ../logout.php");
        exit;
    }
} catch (Exception $e) {
    $logMessage = "Ошибка при инициализации админ-панели: " . $e->getMessage();
    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    header("Location: ../logout.php");
    exit;
}

// ========================================
// ЗАГРУЗКА ТЕКУЩИХ НАСТРОЕК
// ========================================

// Загрузка текущих настроек из базы данных (только для администратора)
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

// ========================================
// ИНИЦИАЛИЗАЦИЯ ОСНОВНЫХ НАСТРОЕК
// ========================================

$allowRegistration = (bool)($currentSettings['allow_registration'] ?? true);
$allowPhotoUpload  = (bool)($currentSettings['allow_photo_upload'] ?? true);
$allowOnlineChat   = (bool)($currentSettings['allow_online_chat'] ?? true);
$logInfoEnabled    = (bool)($currentSettings['log_info_enabled'] ?? true);
$logErrorEnabled   = (bool)($currentSettings['log_error_enabled'] ?? true);
$notifications     = (bool)($currentSettings['notifications'] ?? true);
$imageLimit   = !empty($currentSettings['image_limit']) ? (int)$currentSettings['image_limit'] : 0;
$adminPanel   = $currentSettings['AdminPanel'] ?? 'AdminPanel';
$profileLogo  = $currentSettings['profile_logo'] ?? '';

// Загружаем глобальные настройки размеров изображений или используем defaults
$currentImageSizes = isset($currentSettings['global_image_sizes']) && is_array($currentSettings['global_image_sizes'])
    ? $currentSettings['global_image_sizes']
    : getDefaultImageSizes();

// Санитизация HTML-полей из редактора
$editor1 = sanitizeHtmlFromEditor($currentSettings['editor_1'] ?? '');
$terms   = sanitizeHtmlFromEditor($currentSettings['terms'] ?? '');
$privacy = sanitizeHtmlFromEditor($currentSettings['privacy'] ?? '');

// ========================================
// ОБРАБОТКА POST-ЗАПРОСА
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ========================================
    // ПРОВЕРКА CSRF-ТОКЕНА
    // ========================================
    
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $errors[]   = 'Недействительная форма. Пожалуйста, обновите страницу.';
        $logMessage = "Проверка CSRF-токена не пройдена — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    } else {
        
        // ========================================
        // ОБНОВЛЕНИЕ БУЛЕВЫХ ФЛАГОВ
        // ========================================
        
        $allowRegistration = isset($_POST['allow_registration']) && $_POST['allow_registration'] === '1';
        $allowPhotoUpload  = isset($_POST['allow_photo_upload']) && $_POST['allow_photo_upload'] === '1';
        $allowOnlineChat   = isset($_POST['allow_online_chat']) && $_POST['allow_online_chat'] === '1';
        $logInfoEnabled    = isset($_POST['log_info_enabled']) && $_POST['log_info_enabled'] === '1';
        $logErrorEnabled   = isset($_POST['log_error_enabled']) && $_POST['log_error_enabled'] === '1';
        $notifications     = isset($_POST['notifications']) && $_POST['notifications'] === '1';
        $imageLimit        = (int)($_POST['image_limit'] ?? 0);

        // Санитизация HTML-редакторов
        $editor1 = sanitizeHtmlFromEditor($_POST['editor_1']);
        $terms   = sanitizeHtmlFromEditor($_POST['terms']);
        $privacy = sanitizeHtmlFromEditor($_POST['privacy']);

        // ========================================
        // ВАЛИДАЦИЯ ЛОГОТИПА
        // ========================================
        
        // Валидация ID логотипа (должен быть строкой из цифр, разделённых запятыми, максимум $maxDigits значений)
        $resultImagesLogo = validateIdList(trim($_POST['profile_logo'] ?? ''), $maxDigits);
        
        if ($resultImagesLogo['valid']) {
            $profileLogo = $resultImagesLogo['value'];
        } else {
            $errors[]    = $resultImagesLogo['error'];
        }

        // ========================================
        // ВАЛИДАЦИЯ НАЗВАНИЯ АДМИН-ПАНЕЛИ
        // ========================================
        
        // Валидация названия админ-панели (1–20 символов)
        $adminPanelInput = trim($_POST['AdminPanel'] ?? '');
        $result          = validateTextareaField($adminPanelInput, 1, 20, 'Название Админ-панели');
        
        if ($result['valid']) {
            $adminPanel = $result['value'];
        } else {
            $errors[]   = $result['error'];
        }

        // ========================================
        // ВАЛИДАЦИЯ НАСТРОЕК РАЗМЕРОВ ИЗОБРАЖЕНИЙ
        // ========================================
        
        $imageSizesResult = validateImageSizesFromPost($_POST);

        if (!$imageSizesResult['valid']) {
            $errors = array_merge($errors, $imageSizesResult['errors']);
        } else {
            $globalImageSizes = $imageSizesResult['sizes'];
        }

        // ========================================
        // СОХРАНЕНИЕ НАСТРОЕК
        // ========================================
        
        // Если ошибок нет — сохраняем
        if (empty($errors)) {
            try {
                $settingsData = [
                    'AdminPanel'         => $adminPanel,
                    'allow_registration' => $allowRegistration,
                    'allow_photo_upload' => $allowPhotoUpload,
                    'allow_online_chat'  => $allowOnlineChat,
                    'log_info_enabled'   => $logInfoEnabled,
                    'log_error_enabled'  => $logErrorEnabled,
                    'notifications'      => $notifications,
                    'image_limit'        => $imageLimit,
                    'editor_1'           => $editor1,
                    'terms'              => $terms,
                    'privacy'            => $privacy,
                    'profile_logo'       => $profileLogo,
                    'global_image_sizes' => $globalImageSizes,
                ];

                // ========================================
                // ОБЪЕДИНЕНИЕ И СОХРАНЕНИЕ
                // ========================================
                
                // Объединяем старые и новые настройки
                $updatedSettings = array_merge($currentSettings, $settingsData);
                $updatedSettings['updated_at'] = date('Y-m-d H:i:s');

                // Кодируем в JSON с проверкой ошибок
                $jsonData = json_encode($updatedSettings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                // Сохраняем в БД
                $update = $pdo->prepare(
                    "UPDATE users SET data = ?, updated_at = NOW() WHERE id = ?"
                );
                $update->execute([$jsonData, $user['id']]);

                $successMessages[] = 'Настройки успешно сохранены!';
                $logMessage        = "Настройки системы обновлены пользователем ID: " . $user['id'];
                logEvent($logMessage, LOG_INFO_ENABLED, 'info');
            } catch (PDOException $e) {
                $errors[] = 'Ошибка при сохранении настроек. Пожалуйста, попробуйте позже.';
                logEvent("Ошибка базы данных при обновлении настроек: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
            } catch (JsonException $e) {
                $errors[] = 'Ошибка при обработке данных. Пожалуйста, попробуйте позже.';
                logEvent("Ошибка кодирования JSON при обновлении настроек: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
            }
        }
    }

    // ========================================
    // СОХРАНЕНИЕ СООБЩЕНИЙ В СЕССИЮ
    // ========================================
    
    // Сохраняем сообщения в сессию для отображения после редиректа (PRG-паттерн)
    if (!empty($errors) || !empty($successMessages)) {
        $_SESSION['flash_messages'] = [
            'success' => $successMessages,
            'error'   => $errors
        ];
    }

    header("Location: user_settings.php");
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ ПУТИ К ЛОГОТИПУ
// ========================================

// Получаем путь к логотипу профиля для favicon
$adminUserId  = getAdminUserId($pdo);
$logoProfile  = getFileVersionFromList($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg', $adminUserId);

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
    
    <!-- ========================================
         МОДУЛЬ УПРАВЛЕНИЯ СВЕТЛОЙ/ТЁМНОЙ ТЕМОЙ
         ======================================== -->
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
    
    <!-- Локальные стили -->
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../css/editor.css">
    <link rel="stylesheet" href="../user_images/css/main.css">
    <link rel="icon" href="<?php echo escape($logoProfile); ?>" type="image/x-icon">
</head>
<body>
    <div class="container-fluid">
        <?php require_once __DIR__ . '/../template/sidebar.php'; ?>

        <main class="main-content">
            <?php require_once __DIR__ . '/../template/header.php'; ?>

            <!-- ========================================
                 ОТОБРАЖЕНИЕ СООБЩЕНИЙ
                 ======================================== -->
            <?php displayAlerts(
                $successMessages,      // Массив сообщений об успехе
                $errors,               // Массив сообщений об ошибках
                true                   // Показывать сообщения как toast-уведомления true/false
            ); ?>

            <form action="" method="post" id="postForm_editor1">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                <!-- ========================================
                     НАЗВАНИЕ И ЛОГОТИП АДМИН-ПАНЕЛИ
                     ======================================== -->
                <div class="form-section mb-5">
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
                                value="<?= escape($adminPanel) ?>">
                        </div>
                    </div>

                    <h3 class="card-title">
                        <i class="bi bi-card-image"></i>
                        Логотип Админ-панели
                    </h3>

                    <!-- ========================================
                         ГАЛЕРЕЯ №1 - ЛОГОТИП
                         ======================================== -->
                    <?php
                    $sectionId = 'profile_logo';                                      // Идентификатор секции галереи
                    $imageIds  = $profileLogo;                                        // Текущие сохранённые ID изображений

                    $_SESSION['max_files_per_user'] = $adminData['image_limit'] ?? 0; // Устанавливаем лимит файлов на пользователя в сессию

                    // Получаем глобальные настройки размеров изображений
                    // Режимы: "cover" — обрезка, "contain" — сохранение пропорций
                    $imageSizes = getGlobalImageSizes($pdo);

                    $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;              // Сохраняем настройки в сессии для JS-модуля
                    ?>

                    <input type="hidden" id="selectedImages_<?php echo $sectionId; ?>"
                           name="<?php echo $sectionId; ?>"
                           value="<?php echo escape(isset($imageIds) ? $imageIds : ''); ?>">

                    <div class="mb-5" id="image-management-section-<?php echo $sectionId; ?>">
                        <div id="loading-content-<?php echo $sectionId; ?>"></div>
                        <div class="selected-images-section d-flex flex-wrap gap-2">
                            <div id="selectedImagesPreview_<?php echo $sectionId; ?>" class="selected-images-preview">
                                <!-- Индикатор загрузки -->
                                <div class="w-100 d-flex justify-content-center align-items-center"
                                     style="min-height: 170px;">
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
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                                            aria-label="Закрыть"></button>
                                </div>
                                <div class="modal-body">
                                    <div id="notify_<?php echo $sectionId; ?>"></div>
                                    <div id="image-management-section_<?php echo $sectionId; ?>"></div>
                                    <input type="file" id="fileInput_<?php echo $sectionId; ?>" multiple
                                           accept="image/*" style="display: none;">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary"
                                            data-bs-dismiss="modal">Закрыть
                                    </button>
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
                    <!-- ========================================
                         /ГАЛЕРЕЯ №1 - ЛОГОТИП
                         ======================================== -->

                    <!-- ========================================
                         НАСТРОЙКИ СИСТЕМЫ
                         ======================================== -->
                    <div class="form-section">
                        <h3 class="card-title">
                            <i class="bi bi-check2-square"></i>
                            Ограничения для пользователей
                        </h3>
                        <div class="row col-example-row">
                            <div class="col-3">
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="allow_registration" name="allow_registration" value="1" <?= $allowRegistration ? 'checked' : '' ?>>
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
                                            <input class="form-check-input" type="checkbox" id="allow_photo_upload" name="allow_photo_upload" value="1" <?= $allowPhotoUpload ? 'checked' : '' ?>>
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
                                            <input class="form-check-input" type="checkbox" id="allow_online_chat" name="allow_online_chat" value="1" <?= $allowOnlineChat ? 'checked' : '' ?>>
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
                                            <input class="form-check-input" type="checkbox" id="log_info_enabled" name="log_info_enabled" value="1" <?= $logInfoEnabled ? 'checked' : '' ?>>
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
                                            <input class="form-check-input" type="checkbox" id="log_error_enabled" name="log_error_enabled" value="1" <?= $logErrorEnabled ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="log_error_enabled">Нет/Да</label>
                                        </div>
                                        <div class="form-text">Включить логирование (ошибки)</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <h3 class="card-title">
                            <i class="bi bi-images"></i>
                            Глобальные настройки размеров изображений
                        </h3>
                        <!-- ========================================
                             НАСТРОЙКИ РАЗМЕРОВ ИЗОБРАЖЕНИЙ
                             ======================================== -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <p class="text-muted small mb-3">
                                    Эти настройки применяются ко всем блокам загрузки изображений в системе.
                                    <br><strong>Режимы:</strong>
                                    <br>• <strong>cover</strong> — обрезка изображения с сохранением пропорций (заполняет весь размер)
                                    <br>• <strong>contain</strong> — вписывание изображения с сохранением пропорций (может быть меньше размера)
                                </p>

                                <?php
                                $sizeLabels = [
                                    'thumbnail' => 'Thumbnail (миниатюра)',
                                    'small'     => 'Small (маленький)',
                                    'medium'    => 'Medium (средний)',
                                    'large'     => 'Large (большой)'
                                ];

                                foreach ($sizeLabels as $sizeName => $sizeLabel):
                                    $sizeConfig = $currentImageSizes[$sizeName];
                                    $width = $sizeConfig[0];
                                    $height = $sizeConfig[1];
                                    $mode = $sizeConfig[2];
                                ?>
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold"><?= escape($sizeLabel) ?></label>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="img_<?= $sizeName ?>_width" class="form-label">Ширина</label>
                                        <input type="text" class="form-control" 
                                               id="img_<?= $sizeName ?>_width" 
                                               name="img_<?= $sizeName ?>_width" 
                                               value="<?= escape($width) ?>" 
                                               placeholder="число или 'auto'"
                                               <?= $sizeName === 'thumbnail' ? 'pattern="[0-9]+" title="Для thumbnail требуется число"' : '' ?>>
                                        <div class="form-text">px или 'auto'<?= $sizeName === 'thumbnail' ? ' (только число)' : '' ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="img_<?= $sizeName ?>_height" class="form-label">Высота</label>
                                        <input type="text" class="form-control" 
                                               id="img_<?= $sizeName ?>_height" 
                                               name="img_<?= $sizeName ?>_height" 
                                               value="<?= escape($height) ?>" 
                                               placeholder="число или 'auto'"
                                               <?= $sizeName === 'thumbnail' ? 'pattern="[0-9]+" title="Для thumbnail требуется число"' : '' ?>>
                                        <div class="form-text">px или 'auto'<?= $sizeName === 'thumbnail' ? ' (только число)' : '' ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <label for="img_<?= $sizeName ?>_mode" class="form-label">Режим</label>
                                        <select class="form-select" id="img_<?= $sizeName ?>_mode" name="img_<?= $sizeName ?>_mode">
                                            <option value="cover" <?= $mode === 'cover' ? 'selected' : '' ?>>cover (обрезка)</option>
                                            <option value="contain" <?= $mode === 'contain' ? 'selected' : '' ?>>contain (вписывание)</option>
                                        </select>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label for="imageLimit" class="form-label">Лимит файлов в <a href="main_images.php">библиотеке</a> только для пользователей</label>
                                        <input type="number" class="form-control" id="imageLimit" name="image_limit" min="0" value="<?= (int)$imageLimit ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================================
                     УВЕДОМЛЕНИЯ
                     ======================================== -->
                <div class="mb-5">
                    <h3 class="card-title">
                        <i class="bi bi-bell"></i>
                        Уведомления
                    </h3>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="notifications" name="notifications" value="1" <?= $notifications ? 'checked' : '' ?>>
                        <label class="form-check-label" for="notifications">Нет/Да</label>
                    </div>
                    <div class="form-text">Включить уведомления</div>
                    <?php renderHtmlEditor('editor_1', $editor1); ?>
                </div>

                <!-- ========================================
                     УСЛОВИЯ ИСПОЛЬЗОВАНИЯ
                     ======================================== -->
                <div class="mb-5">
                    <h3 class="card-title">
                        <i class="bi bi-card-checklist"></i>
                        Условия использования
                    </h3>
                    <div class="form-text">Условия использования отображается на странице регистрации</div>
                    <?php renderHtmlEditor('terms', $terms); ?>
                </div>

                <!-- ========================================
                     ПОЛИТИКА КОНФИДЕНЦИАЛЬНОСТИ
                     ======================================== -->
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

    <!-- ========================================
         ГЛОБАЛЬНОЕ МОДАЛЬНОЕ ОКНО С ИНФОРМАЦИЕЙ О ФОТОГРАФИИ
         ======================================== -->
    <?php if (!isset($GLOBALS['photo_info_included'])): ?>
        <?php defined('APP_ACCESS') || define('APP_ACCESS', true); ?>
        <?php require_once __DIR__ . '/../user_images/photo_info.php'; ?>
        <?php $GLOBALS['photo_info_included'] = true; ?>
    <?php endif; ?>

    <!-- ========================================
         ПОДКЛЮЧЕНИЕ СКРИПТОВ
         ======================================== -->
    
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

    <!-- ========================================
         ИНИЦИАЛИЗАЦИЯ ГАЛЕРЕИ
         ======================================== -->
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
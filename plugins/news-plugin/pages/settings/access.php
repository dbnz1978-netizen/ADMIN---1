<?php
/**
 * Файл: /plugins/news-plugin/pages/settings/access.php
 *
 * Назначение:
 * - Страница настроек доступа к плагину "Новости" по ролям пользователей
 * - Управление правами доступа для роли 'user'
 * - Доступ к странице только для пользователей с ролью 'admin'
 *
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-11
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
    'csrf_token'      => true,          // генерация CSRF-токена
    'image_sizes'     => true,          // подключение модуля управления размерами изображений
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../../../../admin/functions/init.php';

// Подключаем систему управления доступом к плагинам
require_once __DIR__ . '/../../../../admin/functions/plugin_access.php';

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
$pluginName = getPluginName();  // Автоматическое определение имени плагина из структуры директорий
$titlemeta = 'Настройки';
$titlemetah3 = 'Управление доступом к плагину "Новости"';

// Включаем/отключаем логирование
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

// =============================================================================
// АВТОРИЗАЦИЯ И ПРОВЕРКА ПРАВ
// =============================================================================

// Используем guard для проверки доступа (только admin может менять настройки)
$userDataAdmin = pluginAccessGuard($pdo, $pluginName, 'admin');

$currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

// =============================================================================
// ЗАГРУЗКА FLASH-СООБЩЕНИЙ
// =============================================================================

$successMessages = [];
$errors = [];

if (!empty($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors          = $_SESSION['flash_messages']['error'] ?? [];
    unset($_SESSION['flash_messages']);
}

// =============================================================================
// ОБРАБОТКА POST-ЗАПРОСА (СОХРАНЕНИЕ НАСТРОЕК)
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        logEvent(
            "Попытка сохранения настроек доступа к плагину '$pluginName' с невалидным CSRF токеном — ID: {$userDataAdmin['id']} — IP: {$_SERVER['REMOTE_ADDR']}",
            LOG_ERROR_ENABLED,
            'error'
        );
        
        $_SESSION['flash_messages']['error'][] = 'Ошибка проверки безопасности. Попробуйте снова.';
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $validationErrors = [];
    
    // Получаем значения из формы (чекбоксы)
    $allowUser = isset($_POST['allow_user']);
    
    // =============================================================================
    // ВАЛИДАЦИЯ НАСТРОЕК РАЗМЕРОВ ИЗОБРАЖЕНИЙ
    // =============================================================================
    
    $imageSizesResult = validateImageSizesFromPost($_POST);
    
    if (!$imageSizesResult['valid']) {
        $validationErrors = array_merge($validationErrors, $imageSizesResult['errors']);
    }
    
    $imageSizes = $imageSizesResult['sizes'] ?? [];
    
    // =============================================================================
    // ВАЛИДАЦИЯ ЛИМИТОВ MAXDIGITS
    // =============================================================================
    
    $maxDigitsAddCategory = trim($_POST['max_digits_add_category'] ?? '');
    $maxDigitsAddArticle = trim($_POST['max_digits_add_article'] ?? '');
    $maxDigitsAddExtra = trim($_POST['max_digits_add_extra'] ?? '');
    
    // Валидация maxDigits для add_category
    if (!ctype_digit($maxDigitsAddCategory) || (int)$maxDigitsAddCategory < 0 || (int)$maxDigitsAddCategory > 1000) {
        $validationErrors[] = 'Лимит изображений для категорий должен быть от 0 до 1000';
    }
    
    // Валидация maxDigits для add_article
    if (!ctype_digit($maxDigitsAddArticle) || (int)$maxDigitsAddArticle < 0 || (int)$maxDigitsAddArticle > 1000) {
        $validationErrors[] = 'Лимит изображений для добавления новости должен быть от 0 до 1000';
    }
    
    // Валидация maxDigits для add_extra
    if (!ctype_digit($maxDigitsAddExtra) || (int)$maxDigitsAddExtra < 0 || (int)$maxDigitsAddExtra > 1000) {
        $validationErrors[] = 'Лимит изображений для дополнительного контента должен быть от 0 до 1000';
    }
    
    // =============================================================================
    // СОХРАНЕНИЕ НАСТРОЕК
    // =============================================================================
    
    if (empty($validationErrors)) {
        // Получаем текущие настройки доступа
        $accessSettings = getPluginAccessSettings($pdo);
        
        if ($accessSettings === false) {
            $accessSettings = [];
        }
        
        // Обновляем настройки для плагина
        // Примечание: доступ для роли 'admin' всегда включён по дизайну системы.
        // Это гарантирует, что администраторы всегда могут управлять плагином и его настройками.
        $accessSettings[$pluginName] = [
            'user' => $allowUser,
            'admin' => true
        ];
        
        // Сохраняем настройки доступа
        $accessResult = savePluginAccessSettings($pdo, $accessSettings);
        
        // Формируем настройки плагина
        $pluginSettings = [
            'image_sizes' => $imageSizes,
            'limits' => [
                'add_category' => ['maxDigits' => (int)$maxDigitsAddCategory],
                'add_article' => ['maxDigits' => (int)$maxDigitsAddArticle],
                'add_extra' => ['maxDigits' => (int)$maxDigitsAddExtra]
            ]
        ];
        
        // Сохраняем настройки плагина
        $pluginResult = savePluginSettings($pdo, $pluginName, $pluginSettings);
        
        if ($accessResult && $pluginResult) {
            logEvent(
                "Настройки плагина '$pluginName' обновлены — user: " . ($allowUser ? 'да' : 'нет') . 
                ", image_sizes: " . json_encode($imageSizes) .
                ", limits: [add_category={$maxDigitsAddCategory}, add_article={$maxDigitsAddArticle}, add_extra={$maxDigitsAddExtra}] — ID: {$userDataAdmin['id']}",
                LOG_INFO_ENABLED,
                'info'
            );
            
            $_SESSION['flash_messages']['success'][] = 'Настройки успешно сохранены';
        } else {
            logEvent(
                "Ошибка сохранения настроек плагина '$pluginName' — ID: {$userDataAdmin['id']}",
                LOG_ERROR_ENABLED,
                'error'
            );
            
            $_SESSION['flash_messages']['error'][] = 'Ошибка при сохранении настроек';
        }
    } else {
        // Добавляем ошибки валидации
        foreach ($validationErrors as $error) {
            $_SESSION['flash_messages']['error'][] = $error;
        }
    }
    
    // Редирект для предотвращения повторной отправки формы
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// =============================================================================
// ЗАГРУЗКА ТЕКУЩИХ НАСТРОЕК
// =============================================================================

$accessSettings = getPluginAccessSettings($pdo);
if ($accessSettings === false) {
    $accessSettings = [];
}

// Получаем настройки для плагина (по умолчанию доступ разрешён)
$allowUser = $accessSettings[$pluginName]['user'] ?? true;

// Загружаем настройки плагина
$pluginSettings = getPluginSettings($pdo, $pluginName);

// Извлекаем настройки размеров изображений или используем значения по умолчанию из глобальных
if (!function_exists('getGlobalImageSizes')) {
    require_once __DIR__ . '/../../../../admin/functions/image_sizes.php';
}
$globalImageSizes = getGlobalImageSizes($pdo);

// Получаем настройки изображений из плагина, или используем глобальные как значения по умолчанию
// Всегда начинаем с глобальных настроек, затем переопределяем настройками плагина если есть
$currentImageSizes = $globalImageSizes;
if (isset($pluginSettings['image_sizes']) && is_array($pluginSettings['image_sizes'])) {
    // Переопределяем только те размеры, которые есть в настройках плагина
    foreach ($pluginSettings['image_sizes'] as $sizeName => $sizeConfig) {
        if (isset($globalImageSizes[$sizeName])) {
            $currentImageSizes[$sizeName] = $sizeConfig;
        }
    }
}

// Извлекаем лимиты maxDigits или используем значения по умолчанию
$maxDigitsAddCategory = $pluginSettings['limits']['add_category']['maxDigits'] ?? 1;
$maxDigitsAddArticle = $pluginSettings['limits']['add_article']['maxDigits'] ?? 50;
$maxDigitsAddExtra = $pluginSettings['limits']['add_extra']['maxDigits'] ?? 50;

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($titlemeta) ?> - Админ-панель</title>

    <!-- Модуль управления светлой/тёмной темой -->
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
    <link rel="stylesheet" href="/admin/css/main.css">
</head>
<body>
    <div class="container-fluid">
        <?php require_once __DIR__ . '/../../../../admin/template/sidebar.php'; ?>

        <!-- Основной контент -->
        <main class="main-content">
            <?php require_once __DIR__ . '/../../../../admin/template/header.php'; ?>

            <!-- Отображение сообщений -->
            <?php displayAlerts(
                $successMessages,  // Массив сообщений об успехе
                $errors,           // Массив сообщений об ошибках
                true               // Показывать сообщения как toast-уведомления
            ); 
            ?>

            <!-- Форма настроек доступа -->
            <form method="POST" action="<?= escape($_SERVER['PHP_SELF']) ?>">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                <!-- ========================================
                     НАСТРОЙКИ ДОСТУПА ПО РОЛЯМ
                     ======================================== -->
                <div class="form-section">
                    <h3 class="card-title">
                        <i class="bi bi-shield-lock"></i>
                        Настройки доступа по ролям
                    </h3>

                    <div class="mb-4">
                        <p>
                            <i class="bi bi-info-circle"></i>
                            Настройте доступ для пользователей с ролью "user" к разделам плагина "Новости".
                            При отключении доступа пользователи не увидят меню плагина и не смогут открыть страницы напрямую.
                            Администраторы (роль "admin") всегда имеют полный доступ к плагину.
                        </p>
                    </div>

                    <div class="row col-example-row">
                        <div class="col-6">
                            <div class="row mb-3">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input 
                                            class="form-check-input" 
                                            type="checkbox" 
                                            id="allowUser" 
                                            name="allow_user"
                                            <?= $allowUser ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label" for="allowUser">Нет/Да</label>
                                    </div>
                                    <div class="form-text">Разрешить доступ пользователям</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================================
                     НАСТРОЙКИ РАЗМЕРОВ ИЗОБРАЖЕНИЙ
                     ======================================== -->
                <div class="form-section">
                    <h3 class="card-title">
                        <i class="bi bi-image"></i>
                        Настройки размеров изображений
                    </h3>

                    <div class="mb-4">
                        <p>
                            <i class="bi bi-info-circle"></i>
                            Настройте размеры изображений для плагина. Если не указано, будут использоваться глобальные настройки.
                            Эти настройки применяются только к плагину "Новости" и не влияют на другие разделы системы.
                        </p>
                        <p>
                            <strong>Режимы обработки:</strong><br>
                            • <strong>cover</strong> — обрезка изображения с сохранением пропорций (заполняет весь размер)<br>
                            • <strong>contain</strong> — вписывание изображения с сохранением пропорций (может быть меньше размера)
                        </p>
                    </div>

                    <?php
                    $sizeLabels = [
                        'thumbnail' => 'Thumbnail (миниатюра)',
                        'small'     => 'Small (маленький)',
                        'medium'    => 'Medium (средний)',
                        'large'     => 'Large (большой)'
                    ];

                    foreach ($sizeLabels as $sizeName => $sizeLabel):
                        // Проверяем наличие размера в конфигурации
                        if (!isset($currentImageSizes[$sizeName]) || !is_array($currentImageSizes[$sizeName])) {
                            continue; // Пропускаем, если размер отсутствует или некорректен
                        }
                        
                        $sizeConfig = $currentImageSizes[$sizeName];
                        
                        // Проверяем, что массив содержит 3 элемента
                        if (count($sizeConfig) !== 3) {
                            continue; // Пропускаем некорректную конфигурацию
                        }
                        
                        $width = $sizeConfig[0];
                        $height = $sizeConfig[1];
                        $mode = $sizeConfig[2];
                    ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <label class="form-label fw-bold"><?= escape($sizeLabel) ?></label>
                        </div>
                        <div class="col-md-3">
                            <label for="img_<?= $sizeName ?>_width" class="form-label">Ширина (px)</label>
                            <input type="text" class="form-control" id="img_<?= $sizeName ?>_width"
                                   name="img_<?= $sizeName ?>_width" 
                                   value="<?= escape((string)$width) ?>" 
                                   placeholder="auto или число"
                                   <?= $sizeName === 'thumbnail' ? 'required' : '' ?>>
                            <?php if ($sizeName === 'thumbnail'): ?>
                            <div class="form-text">Только число для thumbnail</div>
                            <?php else: ?>
                            <div class="form-text">Число или 'auto'</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label for="img_<?= $sizeName ?>_height" class="form-label">Высота (px)</label>
                            <input type="text" class="form-control" id="img_<?= $sizeName ?>_height"
                                   name="img_<?= $sizeName ?>_height" 
                                   value="<?= escape((string)$height) ?>" 
                                   placeholder="auto или число"
                                   <?= $sizeName === 'thumbnail' ? 'required' : '' ?>>
                            <?php if ($sizeName === 'thumbnail'): ?>
                            <div class="form-text">Только число для thumbnail</div>
                            <?php else: ?>
                            <div class="form-text">Число или 'auto'</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label for="img_<?= $sizeName ?>_mode" class="form-label">Режим обработки</label>
                            <select class="form-select" id="img_<?= $sizeName ?>_mode" name="img_<?= $sizeName ?>_mode">
                                <option value="cover" <?= $mode === 'cover' ? 'selected' : '' ?>>cover (обрезка)</option>
                                <option value="contain" <?= $mode === 'contain' ? 'selected' : '' ?>>contain (вписывание)</option>
                            </select>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- ========================================
                     НАСТРОЙКИ ЛИМИТОВ ИЗОБРАЖЕНИЙ
                     ======================================== -->
                <div class="form-section mb-5">
                    <h3 class="card-title">
                        <i class="bi bi-image-fill"></i>
                        Лимиты количества изображений
                    </h3>

                    <div class="mb-4">
                        <p>
                            <i class="bi bi-info-circle"></i>
                            Настройте максимальное количество изображений, которое можно загрузить на каждой странице плагина.
                        </p>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Категории (add_category.php)</label>
                            <input type="number" class="form-control" name="max_digits_add_category" 
                                   min="0" max="1000" step="1" value="<?= escape((string)$maxDigitsAddCategory) ?>" required>
                            <div class="form-text">Максимум изображений при добавлении категории</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Добавление новости (add_article.php)</label>
                            <input type="number" class="form-control" name="max_digits_add_article" 
                                   min="0" max="1000" step="1" value="<?= escape((string)$maxDigitsAddArticle) ?>" required>
                            <div class="form-text">Максимум изображений при добавлении новости</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Дополнительный контент (add_extra.php)</label>
                            <input type="number" class="form-control" name="max_digits_add_extra" 
                                   min="0" max="1000" step="1" value="<?= escape((string)$maxDigitsAddExtra) ?>" required>
                            <div class="form-text">Максимум изображений в доп. контенте</div>
                        </div>
                    </div>
                </div>

                <!-- ========================================
                     КНОПКИ ДЕЙСТВИЙ
                     ======================================== -->
                <div class="mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Сохранить настройки
                    </button>
                    <a href="/plugins/news-plugin/pages/articles/article_list.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle"></i>
                        Отмена
                    </a>
                </div>
            </form>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script type="module" src="/admin/js/main.js"></script>
</body>
</html>

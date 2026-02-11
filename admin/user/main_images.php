<?php

/**
 * Название файла:      main_images.php
 * Назначение:          Отображает медиа-библиотеку (галерею изображений) для администратора
 *                      Поддерживает загрузку, просмотр и редактирование метаданных изображений
 * Автор:               User
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ СКРИПТА
// ========================================

$config = [
    'display_errors'  => false,  // Включение отображения ошибок (true/false)
    'set_encoding'    => true,   // Включение кодировки UTF-8
    'db_connect'      => true,   // Подключение к базе данных
    'auth_check'      => true,   // Подключение функций авторизации
    'file_log'        => true,   // Подключение системы логирования
    'display_alerts'  => true,   // Подключение отображения сообщений
    'sanitization'    => true,   // Подключение валидации/экранирования
    'csrf_token'      => true,   // Генерация CSRF-токена
    'image_sizes'     => true,   // Подключение модуля управления размерами изображений
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Ошибка: администратор не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ СТРАНИЦЫ
// ========================================

$titlemeta = 'Библиотека файлов';  // Заголовок страницы

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);  // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки true/false

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ И ПОЛУЧЕНИЕ ДАННЫХ ПОЛЬЗОВАТЕЛЯ
// ========================================

try {
    // Проверка авторизации
    $user = requireAuth($pdo);
    if (!$user) {
        logEvent(
            "Неавторизованный доступ — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}",
            LOG_INFO_ENABLED,
            'info'
        );
        header("Location: ../logout.php");
        exit;
    }

    // Получение данных пользователя
    $userDataAdmin = getUserData($pdo, $user['id']);
    
    // Проверка ошибки
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg        = $userDataAdmin['message'];
        $level      = $userDataAdmin['level'];
        $logEnabled = match($level) {
            'info'  => LOG_INFO_ENABLED,
            'error' => LOG_ERROR_ENABLED,
            default => LOG_ERROR_ENABLED
        };
        logEvent($msg, $logEnabled, $level);
        header("Location: ../logout.php");
        exit;
    }

    // Декодируем JSON-данные администратора
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

    // Разрешить загрузку фотографий для пользователей Нет/Да
    if (!$adminData['allow_photo_upload'] && $userDataAdmin['author'] !== 'admin') {
        header("Location: ../logout.php");
        exit;
    }

} catch (Exception $e) {
    logEvent("Ошибка при инициализации админ-панели: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    header("Location: ../logout.php");
    exit;
}

// ========================================
// ЗАГРУЗКА FLASH-СООБЩЕНИЙ ИЗ СЕССИИ
// ========================================

$successMessages = [];
$errors          = [];

if (!empty($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors          = $_SESSION['flash_messages']['error'] ?? [];
    // Удаляем их, чтобы не показывались при повторной загрузке
    unset($_SESSION['flash_messages']);
}

// ========================================
// ПОЛУЧЕНИЕ ЛОГОТИПА АДМИНИСТРАТОРА
// ========================================

$adminUserId  = getAdminUserId($pdo);
$logoProfile  = getFileVersionFromList(
    $pdo,
    $adminData['profile_logo'] ?? '',
    'thumbnail',
    '../img/avatar.svg',
    $adminUserId
);

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= escape($_SESSION['csrf_token']) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Админ-панель управления системой">
    <meta name="author" content="Админ-панель">
    <title><?php echo escape($titlemeta); ?></title>
    
    <!-- Автоматическое применение сохраненной темы -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    
    <!-- Подключение стилей -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../user_images/css/main.css">
    <link rel="icon" href="<?php echo escape($logoProfile); ?>" type="image/x-icon">
</head>
<body>
    <div class="container-fluid">
        <!-- Боковое меню -->
        <?php require_once __DIR__ . '/../template/sidebar.php'; ?>

        <!-- Основной контент -->
        <main class="main-content">
            <!-- Верхняя панель -->
            <?php require_once __DIR__ . '/../template/header.php'; ?>

            <!-- Динамический контент -->
            <div class="content-area">
                <!-- Отображение сообщений -->
                <?php displayAlerts(
                    $successMessages,  // Массив сообщений об успехе
                    $errors,           // Массив сообщений об ошибках
                    true               // Показывать сообщения как toast-уведомления
                );
                ?>

                <!-- ========================================
                     ГАЛЕРЕЯ №1
                     ======================================== -->
                <?php
                // ========================================
                // ПАРАМЕТРЫ ГАЛЕРЕИ
                // ========================================
                
                $sectionId = 'profile_images';  // Уникальное имя секции

                // Лимит загрузки файлов на пользователя
                $_SESSION['max_files_per_user'] = $adminData['image_limit'] ?? 0;

                // ========================================
                // НАСТРОЙКИ РАЗМЕРОВ ИЗОБРАЖЕНИЙ
                // ========================================
                
                // Получаем глобальные настройки размеров изображений
                // Режимы: "cover" — обрезка, "contain" — сохранение пропорций
                $imageSizes = getGlobalImageSizes($pdo);

                // Сохраняем настройки в сессии для использования в JS-модуле
                $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;
                ?>

                <!-- Контейнер для уведомлений (ошибки, успехи) -->
                <div id="notify_<?php echo $sectionId; ?>"></div>

                <!-- Контейнер галереи -->
                <div class="form-section">
                    <div id="image-management-section_<?php echo $sectionId; ?>">
                        <!-- Зона drag-and-drop будет добавлена здесь при инициализации -->
                        <!-- Индикатор загрузки (показывается до появления галереи) -->
                        <div class="w-100 d-flex justify-content-center align-items-center" style="min-height: 170px;">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Скрытый input для загрузки файлов (активируется через drag-and-drop или кнопку) -->
                <input type="file"
                       id="fileInput_<?php echo $sectionId; ?>"
                       multiple
                       accept="image/jpeg,image/png,image/gif,image/webp,image/avif,image/jxl"
                       style="display: none;">

                <!-- ========================================
                     ИНИЦИАЛИЗАЦИЯ ГАЛЕРЕИ И ЗАГРУЗКИ ФАЙЛОВ
                     ======================================== -->
                <script>
                    // Инициализация улучшенной загрузки файлов с прогресс-баром и drag-and-drop
                    document.addEventListener('DOMContentLoaded', function() {
                        if (typeof initEnhancedUpload === 'function') {
                            initEnhancedUpload('<?php echo $sectionId; ?>');
                        }

                        // Инициализация галереи после создания зоны загрузки
                        loadImageSection('<?php echo $sectionId; ?>');
                    });

                    // Переопределение функции loadImageSection для обеспечения сохранения зоны загрузки
                    // при обновлении галереи (например, после загрузки новых файлов)
                    const originalLoadImageSection = typeof loadImageSection !== 'undefined' ? loadImageSection : null;

                    if (originalLoadImageSection) {
                        window.originalLoadImageSection = originalLoadImageSection;

                        window.loadImageSection = function(sectionId, offset = 0) {
                            // Если это первая загрузка (offset = 0), то сохраняем зону загрузки перед обновлением
                            if (offset === 0) {
                                const dropZone = document.getElementById(`upload-drop-zone-${sectionId}`);
                                if (dropZone) {
                                    // Удаляем зону загрузки перед обновлением галереи
                                    dropZone.remove();
                                }

                                // Выполняем оригинальную загрузку
                                originalLoadImageSection(sectionId, offset);

                                // После загрузки снова инициализируем зону загрузки
                                setTimeout(() => {
                                    if (typeof initEnhancedUpload === 'function') {
                                        initEnhancedUpload(sectionId);
                                    }
                                }, 100);
                            } else {
                                // Для дозагрузки (бесконечная прокрутка) просто используем оригинальную функцию
                                originalLoadImageSection(sectionId, offset);
                            }
                        };
                    }
                </script>

                <!-- Модальное окно редактирования метаданных фото -->
                <?php defined('APP_ACCESS') || define('APP_ACCESS', true); ?>
                <?php require_once __DIR__ . '/../user_images/photo_info.php'; ?>
                <!-- /Галерея №1 -->

            </div>
        </main>
    </div>

    <!-- ========================================
         ПОДКЛЮЧЕНИЕ JAVASCRIPT БИБЛИОТЕК
         ======================================== -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script type="module" src="../js/main.js"></script>
    <script type="module" src="../user_images/js/main.js"></script>

    <!-- Инициализация галереи (дублируется как fallback, если не сработало выше) -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            loadImageSection('profile_images');
        });
    </script>
</body>
</html>
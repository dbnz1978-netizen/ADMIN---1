<?php
/**
 * Файл: /admin/user/main_images.php
 * 
 * Админ-панель — Библиотека файлов
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/** 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// Устанавливаем кодировку
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

// Подключаем системные компоненты
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования
require_once __DIR__ . '/../functions/display_alerts.php';           // Отображения сообщений
require_once __DIR__ . '/../functions/sanitization.php';             // Валидация экранирование 

// Получаем настройки администратора
$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    exit;
}

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

try {
    // Проверка авторизации
    $user = requireAuth($pdo);
    if (!$user) {
        logEvent("Неавторизованный доступ — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}", LOG_INFO_ENABLED, 'info');
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");
        exit;
    }

    // Получение данных пользователя
    $userDataAdmin = getUserData($pdo, $user['id']);
    // Проверка ошибки
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg = $userDataAdmin['message'];
        $level = $userDataAdmin['level']; // 'info' или 'error'
        $logEnabled = match($level) {'info'  => LOG_INFO_ENABLED, 'error' => LOG_ERROR_ENABLED, default => LOG_ERROR_ENABLED};
        logEvent($msg, $logEnabled, $level);
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");
        exit;
    }

    // Успех
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];
    
    // Разрешить загрузку фотографий для пользователей Нет/Да
    if (!$adminData['allow_photo_upload'] && $userDataAdmin['author'] !== 'admin') {
        header("Location: ../logout.php");
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        exit;
    }

} catch (Exception $e) {
    logEvent("Ошибка при инициализации админ-панели: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

// Проверка CSRF токена для AJAX запросов
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Получает логотип
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
$titlemeta = 'Библиотека файлов';

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
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Админ-панель управления системой">
    <meta name="author" content="Админ-панель">
    <title><?php echo $titlemeta; ?></title>
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
    <!-- Медиа-библиотека -->
    <link rel="stylesheet" href="../user_images/css/main.css">
    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
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

                <!-- Галерея №1 -->
                <?php
                // === Параметры галереи ===
                $sectionId = 'profile_images'; // Уникальное имя секции

                // Лимит загрузки файлов на пользователя
                $_SESSION['max_files_per_user'] = $adminData['image_limit'] ?? 0; 

                // Настройки размеров изображений: [ширина, высота, режим]
                $imageSizes = [
                    "thumbnail" => [100, 100, "cover"],
                    "small"     => [300, 'auto', "contain"], // Обязательное имя small
                    "medium"    => [600, 'auto', "contain"],
                    "large"     => [1200, 'auto', "contain"]
                ];

                // Сохраняем настройки в сессии
                $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;
                ?>

                <!-- Контейнер для уведомлений -->
                <div id="notify_<?php echo $sectionId; ?>"></div>

                <!-- Контейнер галереи -->
                <div id="image-management-section_<?php echo $sectionId; ?>"></div>

                <!-- Скрытый input для загрузки файлов -->
                <input type="file" id="fileInput_<?php echo $sectionId; ?>" multiple accept="image/*" style="display: none;">

                <!-- Модальное окно редактирования метаданных фото -->
                <?php require_once __DIR__ . '/../user_images/photo_info.php'; ?>
                <!-- /Галерея №1 -->

            </div>
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
    <!-- Модульный JS галереи -->
    <script type="module" src="../user_images/js/main.js"></script>

    <!-- Инициализация галереи -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        loadImageSection('profile_images');
    });
    </script>
</body>
</html>

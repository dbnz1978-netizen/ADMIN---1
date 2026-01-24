<?php
/**
 * Файл: /admin/user/index.php
 * 
 * Админ-панель - Главная страница управления
 * 
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
require_once __DIR__ . '/../functions/jsondata.php';                 // Обновление JSON данных пользователя
require_once __DIR__ . '/../functions/display_alerts.php';           // Отображения сообщений
require_once __DIR__ . '/../functions/sanitization.php';             // Валидация экранирование 

// Получаем настройки администратора
$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    logEvent("Ошибка: admin не найден / ошибка БД / некорректный JSON", LOG_ERROR_ENABLED, 'error'); // всегда логируем критичные ошибки
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
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
        $redirectTo = '../logout.php';
        logEvent("Неавторизованный доступ — перенаправление на: $redirectTo — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}", LOG_INFO_ENABLED, 'info');
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: $redirectTo");
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
    
} catch (Exception $e) {
    logEvent("Ошибка при инициализации админ-панели: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error'); // всегда логируем критичные ошибки
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

// Получает логотип
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
// Название раздела
$titlemeta = 'Админ-панель';

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Админ-панель управления системой">
    <meta name="author" content="Админ-панель">
    <title><?php echo $titlemeta; ?></title>
    <!-- Модуль управления светлой/тёмной темой -->
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
    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
</head>


<body>
    <div class="container-fluid">

        <!-- Боковое меню -->
        <?php 
            require_once '../template/sidebar.php';
        ?>

        <!-- Основной контент -->
        <main class="main-content">

            <!-- Верхняя панель -->
            <?php 
                require_once '../template/header.php';
            ?>

            <!-- Динамический контент -->
            <div class="content-area">

                <?php if ($adminData['notifications'] === true ) { 
                    // Валидация HTML
                    $editor1 = sanitizeHtmlFromEditor($adminData['editor_1'] ?? '');
                ?>
                <div class="alert alert-primary" role="alert">
                    <i class="bi bi-info-circle"></i> Уведомления
                    <div class="m-1">
                        <?= $editor1 ?>
                    </div>
                </div>
            <?php } ?>
            </div>
        </main>
    </div>

    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Модульный JS admin -->
    <script type="module" src="../js/main.js"></script>

</body>
</html>
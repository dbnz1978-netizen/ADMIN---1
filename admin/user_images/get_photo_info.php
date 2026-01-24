<?php
/**
 * Файл: /admin/user_images/get_photo_info.php
 *
 * Обработчик AJAX-запроса для получения информации о фотографии из таблицы `media_files`.
 * Используется в интерфейсе админки для отображения данных выбранной фотографии
 * в модальном окне редактирования (SEO-оптимизация: заголовок, описание, alt-текст).
 *
 * Принимает:
 *   - `photoId` (int): ID записи в таблице `media_files`
 *
 * Возвращает:
 *   - HTML-разметку модального окна с превью изображения и формой редактирования метаданных.
 *   - При ошибке — HTML-блок с уведомлением об ошибке (с использованием Bootstrap-классов).
 *
 * Безопасность:
 *   - Все пользовательские данные экранируются с помощью `htmlspecialchars`.
 *   - Используется подготовленный запрос к БД.
 *
 * Требования:
 *   - Должен вызываться методом POST.
 *   - Файл должен находиться в корне проекта или корректно подключать `/connect/db.php`.
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
header('Content-Type: application/json');

// === Подключение зависимостей ===
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';             // База данных
require_once __DIR__ . '/../functions/auth_check.php';                  // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                    // Система логирования
require_once __DIR__ . '/../functions/sanitization.php';                // Валидация экранирование 

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


// Проверяем метод запроса
if ($_SERVER['REQUEST_TYPE'] ?? $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo 'Метод запроса должен быть POST';
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

// Проверяем наличие обязательных параметров
if (!isset($_POST['photoId']) || empty($_POST['photoId'])) {
    $error_message = 'Photo ID is required';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    echo '<div class="alert alert-danger">' . escape($error_message) . '</div>';
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

$photoId = intval($_POST['photoId']);

// Дополнительная защита: убеждаемся, что $photoId положительный
if ($photoId <= 0) {
    $error_message = 'Invalid Photo ID (must be positive integer)';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    echo '<div class="alert alert-danger">Некорректный ID фотографии</div>';
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}


try {
    // Получаем информацию о фотографии из БД
    $stmt = $pdo->prepare("SELECT * FROM `media_files` WHERE `id` = ?");
    $stmt->execute([$photoId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$photo) {
        $error_message = 'Photo not found (ID: ' . $photoId . ')';
        logEvent($error_message, LOG_ERROR_ENABLED, 'error');
        echo '<div class="alert alert-danger">Фотография не найдена</div>';
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        exit;
    }
    
    // Декодируем JSON-поле file_versions
    $fileVersions = json_decode($photo['file_versions'], true);
    
    // Проверка корректности JSON
    if ($fileVersions === null && json_last_error() !== JSON_ERROR_NONE) {
        $error_message = 'JSON decode error for file_versions (Photo ID: ' . $photoId . ') - ' . json_last_error_msg();
        logEvent($error_message, LOG_ERROR_ENABLED, 'error');
        $fileVersions = [];
    }
    
    // Убедимся, что fileVersions — массив
    if (!is_array($fileVersions)) {
        $fileVersions = [];
    }
    
    // Определяем путь к изображению для превью: сначала small, затем original
    $mediumImagePath = '';
    if (isset($fileVersions['small']) && is_array($fileVersions['small']) && isset($fileVersions['small']['path'])) {
        $mediumImagePath = $fileVersions['small']['path'];
    } elseif (isset($fileVersions['original']) && is_array($fileVersions['original']) && isset($fileVersions['original']['path'])) {
        $mediumImagePath = $fileVersions['original']['path'];
    }
    
    // Экранируем все значения для безопасного вывода
    $photoIdValue = escape($photo['id']);
    $titleValue = escape($photo['title']);
    $descriptionValue = escape($photo['description']);
    $altTextValue = escape($photo['alt_text']);
    $mediumImagePathSafe = escape($mediumImagePath);
    
    // Формируем HTML-ответ
    $html = <<<HTML
    <div class="modal-body">
        <div class="mb-4">
            <div class="mb-4">
                <img src="/uploads/{$mediumImagePathSafe}" alt="{$altTextValue}" class="img-fluid w-100 shadow-sm" style="max-height: 60vh; object-fit: contain;">
            </div>
            <div>
                <h5 class="modal-title">SEO-оптимизация картинок</h5>
                <div id="photo-form" data-photo-id="{$photoIdValue}">
                    <!-- Скрытое поле с ID записи из таблицы media_files -->
                    <input type="hidden" name="id" value="{$photoIdValue}">
                    <div class="mb-3">
                        <label for="title" class="form-label">Заголовок</label>
                        <input type="text" class="form-control form-control-sm" id="title" 
                            maxlength="100"
                            name="title" value="{$titleValue}">
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Описание</label>
                        <textarea class="form-control form-control-sm" id="description" 
                            maxlength="255"
                            name="description" rows="3">{$descriptionValue}</textarea>
                    </div>
                    <div class="mb-3">
                        <label for="alt_text" class="form-label">Alt текст</label>
                        <input type="text" class="form-control form-control-sm" id="alt_text" 
                            maxlength="200"
                            name="alt_text" value="{$altTextValue}">
                    </div>
                </div>
            </div>
        </div>
    </div>
HTML;
    
    echo $html;
    
} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage() . ' (Photo ID: ' . $photoId . ')';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    echo '<div class="alert alert-danger">Ошибка базы данных: ' . escapeHtml($e->getMessage()) . '</div>';
}

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
?>
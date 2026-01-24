<?php
/**
 * Файл: /admin/user_images/update_photo_info.php
 * Обработчик AJAX-запроса для обновления метаданных фотографии (заголовка, описания и alt-текста).
 * 
 * (Описание без изменений — см. исходный комментарий)
 */

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// Устанавливаем кодировку
header('Content-Type: application/json');

// === Подключение зависимостей ===
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования
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

// Проверяем, что запрос сделан методом POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $msg = 'Method not allowed: ' . $_SERVER['REQUEST_METHOD'];
    logEvent("VALIDATION_ERROR: $msg", LOG_ERROR_ENABLED, 'error');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод запроса должен быть POST']);
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
    exit;
}

// Проверяем наличие и корректность ID фотографии
if (!isset($_POST['id']) || !is_numeric($_POST['id']) || intval($_POST['id']) <= 0) {
    $msg = 'Photo ID is required and must be a positive integer (received: ' . ($_POST['id'] ?? 'null') . ')';
    logEvent("VALIDATION_ERROR: $msg", LOG_ERROR_ENABLED, 'error');
    echo json_encode(['success' => false, 'message' => 'Неверный или отсутствующий ID фотографии']);
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
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

$photoId = intval($_POST['id']);
$title = isset($_POST['title']) ? trim($_POST['title']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$alt_text = isset($_POST['alt_text']) ? trim($_POST['alt_text']) : '';

if (!empty($title)) {
    $title = substr($title, 0, 100);
    // Валидация текстового поля
    $resultield = validateTextareaField($title, 1, 100, 'Заголовок');
    if ($resultield['valid']) {
        $title = ($resultield['value']);
    } else {
        $title = false;
    }
}

if (!empty($description)) {
    $description = substr($description, 0, 255);
    // Валидация текстового поля
    $resultield = validateTextareaField($description, 1, 255, 'Описание');
    if ($resultield['valid']) {
        $description = ($resultield['value']);
    } else {
        $description = false;
    }
}

if (!empty($alt_text)) {
    $alt_text = substr($alt_text, 0, 200);
    // Валидация текстового поля
    $resultield = validateTextareaField($alt_text, 1, 200, 'Alt текст');
    if ($resultield['valid']) {
        $alt_text = ($resultield['value']);
    } else {
        $alt_text = false;
    }
}

try {
    // Подготавливаем SQL-запрос для обновления записи
    $stmt = $pdo->prepare("UPDATE `media_files` SET 
                          `title` = ?, 
                          `description` = ?, 
                          `alt_text` = ?
                          WHERE `id` = ?");

    $result = $stmt->execute([$title, $description, $alt_text, $photoId]);

    if ($result && $stmt->rowCount() > 0) {
        logEvent("INFO: Photo metadata updated — ID: $photoId", LOG_ERROR_ENABLED, 'error');
        echo json_encode([
            'success' => true, 
            'message' => 'Данные успешно обновлены'
        ]);
    } else {
        // Запись не найдена или данные не изменились
        logEvent("INFO: No changes or photo not found — ID: $photoId", LOG_ERROR_ENABLED, 'error');
        echo json_encode([
            'success' => false, 
            'message' => 'Данные не были обновлены (возможно, запись не найдена или значения не изменились)'
        ]);
    }

} catch (Exception $e) {
    $error_message = 'Database error: ' . $e->getMessage() . ' (Photo ID: ' . $photoId . ')';
    logEvent("DB_ERROR: $error_message", LOG_ERROR_ENABLED, 'error');
    // Для безопасности не возвращаем детали ошибки пользователю в продакшене
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка при обновлении данных']);
}

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
?>
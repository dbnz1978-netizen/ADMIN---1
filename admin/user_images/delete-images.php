<?php
/**
 * Файл: /admin/user_images/delete-images.php
 * Обработчик массового удаления изображений и их файлов
 *
 * Принимает:
 * - sectionId
 * - image_ids (строка "1,2,3")
 * - csrf_token (или заголовок X-CSRF-Token)
 *
 * Возвращает JSON.
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// Устанавливаем кодировку
header('Content-Type: application/json; charset=utf-8');

// === Подключение зависимостей ===
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';             // База данных
require_once __DIR__ . '/../functions/auth_check.php';                  // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                    // Система логирования

// Безопасный запуск сессии (ВАЖНО: до любых $_SESSION проверок)
startSessionSafe();

// Запрет прямого доступа через браузер — только POST и только AJAX
if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

// Получаем настройки администратора (для флагов логирования)
$adminData = getAdminData($pdo);
if ($adminData === false) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Сессия недействительна'], JSON_UNESCAPED_UNICODE);
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

// === CSRF ЗАЩИТА ===
$csrf_token_session = $_SESSION['csrf_token'] ?? '';
$csrf_token_request = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (empty($csrf_token_session) || empty($csrf_token_request) || !hash_equals($csrf_token_session, $csrf_token_request)) {
    $error_message = 'CSRF атака заблокирована (user_id: ' . ($_SESSION['user_id'] ?? 'unknown') . ')';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Запрос не подтверждён. Обновите страницу.'], JSON_UNESCAPED_UNICODE);
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

try {
    $sectionId = isset($_POST['sectionId']) ? trim((string)$_POST['sectionId']) : '';
    $image_ids_str = isset($_POST['image_ids']) ? trim((string)$_POST['image_ids']) : '';
    $user_id = (int)($_SESSION['user_id'] ?? 0);

    if ($user_id <= 0) {
        $msg = 'Некорректный user_id';
        logEvent("SECURITY: $msg", LOG_ERROR_ENABLED, 'error');
        throw new Exception($msg);
    }

    if ($sectionId === '' || $image_ids_str === '') {
        $missing = [];
        if ($sectionId === '') $missing[] = 'sectionId';
        if ($image_ids_str === '') $missing[] = 'image_ids';
        $msg = 'Недостаточно данных для удаления: ' . implode(', ', $missing);
        logEvent("VALIDATION_ERROR: $msg", LOG_ERROR_ENABLED, 'error');
        throw new Exception($msg);
    }

    // Парсим "1,2,3" -> [1,2,3]
    $image_ids_raw = explode(',', $image_ids_str);
    $image_ids = array_filter(array_map('trim', $image_ids_raw), function ($id) {
        return is_numeric($id) && (int)$id > 0;
    });

    if (empty($image_ids)) {
        $msg = 'Нет корректных ID изображений для удаления';
        logEvent("VALIDATION_WARNING: $msg", LOG_ERROR_ENABLED, 'error');
        throw new Exception($msg);
    }

    $image_ids = array_map('intval', $image_ids);
    $upload_dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/';

    // Получаем только те записи, которые принадлежат текущему пользователю
    $placeholders = str_repeat('?,', count($image_ids) - 1) . '?';
    $select_sql = "SELECT id, file_versions FROM `media_files` WHERE id IN ($placeholders) AND user_id = ?";
    $select_stmt = $pdo->prepare($select_sql);

    $params = array_merge($image_ids, [$user_id]);
    $select_stmt->execute($params);
    $images_to_delete = $select_stmt->fetchAll(PDO::FETCH_ASSOC);

    $valid_image_ids = array_column($images_to_delete, 'id');

    if (empty($valid_image_ids)) {
        logEvent(
            "SECURITY: Попытка удаления — не найдено изображений, принадлежащих user_id=$user_id (запрошены ID: " . implode(',', $image_ids) . ")",
            LOG_ERROR_ENABLED,
            'error'
        );

        echo json_encode([
            'success' => true,
            'message' => 'Нет изображений для удаления (не найдены или нет прав)',
            'deleted_count' => 0,
            'deleted_files' => 0,
            'deleted_ids' => []
        ], JSON_UNESCAPED_UNICODE);
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        exit;
    }

    // Удаляем файлы с сервера
    $deleted_files = 0;
    $file_errors = [];

    foreach ($images_to_delete as $image) {
        $image_id = (int)$image['id'];
        $file_versions_json = (string)$image['file_versions'];

        $file_versions = json_decode($file_versions_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            logEvent(
                "DATA_ERROR: Некорректный JSON file_versions для ID=$image_id — " . json_last_error_msg(),
                LOG_ERROR_ENABLED,
                'error'
            );
            continue;
        }

        if (!is_array($file_versions)) {
            logEvent(
                "DATA_WARNING: file_versions не массив для ID=$image_id — тип: " . gettype($file_versions),
                LOG_ERROR_ENABLED,
                'error'
            );
            continue;
        }

        // Универсально: и для индексных, и для ассоциативных
        $versions_to_process = array_values($file_versions);

        foreach ($versions_to_process as $version) {
            if (is_array($version) && isset($version['path']) && !empty($version['path'])) {
                $relative_path = ltrim((string)$version['path'], '/');
                $file_path = $upload_dir . $relative_path;

                if (file_exists($file_path)) {
                    if (@unlink($file_path)) {
                        $deleted_files++;
                    } else {
                        $error_msg = "Не удалось удалить файл: $relative_path (ID=$image_id)";
                        $file_errors[] = $error_msg;
                        logEvent("FS_ERROR: $error_msg", LOG_ERROR_ENABLED, 'error');
                    }
                } else {
                    logEvent("FS_WARNING: Файл не существует: $file_path (ID=$image_id)", LOG_ERROR_ENABLED, 'error');
                }
            }
        }
    }

    // Удаляем записи из БД (только те, которые реально принадлежат пользователю)
    $placeholders_valid = str_repeat('?,', count($valid_image_ids) - 1) . '?';
    $delete_sql = "DELETE FROM `media_files` WHERE id IN ($placeholders_valid) AND user_id = ?";
    $delete_stmt = $pdo->prepare($delete_sql);
    $delete_params = array_merge($valid_image_ids, [$user_id]);
    $delete_stmt->execute($delete_params);

    $deleted_count = $delete_stmt->rowCount();

    if (empty($file_errors)) {
        echo json_encode([
            'success' => true,
            'message' => "Успешно удалено $deleted_count изображений",
            'deleted_count' => $deleted_count,
            'deleted_files' => $deleted_files,
            'deleted_ids' => $valid_image_ids
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Произошли ошибки при удалении некоторых файлов',
            'deleted_count' => $deleted_count,
            'deleted_files' => $deleted_files,
            'errors' => $file_errors,
            'deleted_ids' => $valid_image_ids
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    $msg = "Ошибка базы данных: " . $e->getMessage();
    logEvent("DB_ERROR: $msg", LOG_ERROR_ENABLED, 'error');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка базы данных'], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $msg = "Общая ошибка: " . $e->getMessage();
    logEvent("EXCEPTION: $msg", LOG_ERROR_ENABLED, 'error');
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ошибка при удалении: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
?>

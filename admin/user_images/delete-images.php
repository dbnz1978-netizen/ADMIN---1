<?php

/**
 * Название файла:      delete-images.php
 * Назначение:          Обработчик массового удаления изображений и их файлов.
 *                      Поддерживает форматы: JPEG, PNG, GIF, WebP, AVIF, JPEG XL.
 *                      Принимает: sectionId, image_ids (строка "1,2,3"), csrf_token.
 *                      Возвращает JSON-ответ.
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// ИНИЦИАЛИЗАЦИЯ
// ========================================

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// ========================================
// КОНФИГУРАЦИЯ СКРИПТА
// ========================================

$config = [
    'display_errors'   => false,   // Включение отображения ошибок (true/false)
    'set_encoding'     => true,    // Включение кодировки UTF-8
    'db_connect'       => true,    // Подключение к базе данных
    'auth_check'       => true,    // Подключение функций авторизации
    'file_log'         => true,    // Подключение системы логирования
    'csrf_token'       => true,    // Генерация и проверка CSRF-токена
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// ПРОВЕРКА ТИПА ЗАПРОСА
// ========================================

// Запрет прямого доступа через браузер — только POST и только AJAX
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

// Получаем настройки администратора (для флагов логирования)
$adminData = getAdminData($pdo);

if ($adminData === false) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Сессия недействительна'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

// ========================================
// ПРОВЕРКА CSRF-ТОКЕНА
// ========================================

$csrfTokenSession = $_SESSION['csrf_token'] ?? '';
$csrfTokenRequest = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (
    empty($csrfTokenSession) ||
    empty($csrfTokenRequest) ||
    !hash_equals($csrfTokenSession, $csrfTokenRequest)
) {
    $errorMessage = 'CSRF атака заблокирована (user_id: ' . ($_SESSION['user_id'] ?? 'unknown') . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Запрос не подтверждён. Обновите страницу.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========================================
// ОСНОВНАЯ ЛОГИКА УДАЛЕНИЯ
// ========================================

try {
    
    // ========================================
    // ВАЛИДАЦИЯ ВХОДНЫХ ДАННЫХ
    // ========================================
    
    $sectionId    = isset($_POST['sectionId']) ? trim((string)$_POST['sectionId']) : '';
    $imageIdsStr  = isset($_POST['image_ids']) ? trim((string)$_POST['image_ids']) : '';
    $userId       = (int)($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        $msg = 'Некорректный user_id';
        logEvent("SECURITY: $msg", LOG_ERROR_ENABLED, 'error');
        throw new Exception($msg);
    }

    if ($sectionId === '' || $imageIdsStr === '') {
        $missing = [];
        
        if ($sectionId === '') {
            $missing[] = 'sectionId';
        }
        
        if ($imageIdsStr === '') {
            $missing[] = 'image_ids';
        }
        
        $msg = 'Недостаточно данных для удаления: ' . implode(', ', $missing);
        logEvent("VALIDATION_ERROR: $msg", LOG_ERROR_ENABLED, 'error');
        throw new Exception($msg);
    }

    // Парсим "1,2,3" -> [1,2,3]
    $imageIdsRaw = explode(',', $imageIdsStr);
    $imageIds    = array_filter(
        array_map('trim', $imageIdsRaw),
        function ($id) {
            return is_numeric($id) && (int)$id > 0;
        }
    );

    if (empty($imageIds)) {
        $msg = 'Нет корректных ID изображений для удаления';
        logEvent("VALIDATION_WARNING: $msg", LOG_ERROR_ENABLED, 'error');
        throw new Exception($msg);
    }

    $imageIds   = array_map('intval', $imageIds);
    $uploadDir  = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/';

    // ========================================
    // ПОЛУЧЕНИЕ ИЗОБРАЖЕНИЙ ИЗ БАЗЫ ДАННЫХ
    // ========================================
    
    // Получаем только те записи, которые принадлежат текущему пользователю
    $placeholders = str_repeat('?,', count($imageIds) - 1) . '?';
    $selectSql    = "SELECT id, file_versions FROM `media_files` WHERE id IN ($placeholders) AND user_id = ?";
    $selectStmt   = $pdo->prepare($selectSql);

    $params          = array_merge($imageIds, [$userId]);
    $selectStmt->execute($params);
    $imagesToDelete  = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

    $validImageIds = array_column($imagesToDelete, 'id');

    if (empty($validImageIds)) {
        logEvent(
            "SECURITY: Попытка удаления — не найдено изображений, принадлежащих user_id=$userId (запрошены ID: "
                . implode(',', $imageIds) . ")",
            LOG_ERROR_ENABLED,
            'error'
        );

        echo json_encode([
            'success'       => true,
            'message'       => 'Нет изображений для удаления (не найдены или нет прав)',
            'deleted_count' => 0,
            'deleted_files' => 0,
            'deleted_ids'   => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ========================================
    // УДАЛЕНИЕ ФАЙЛОВ С СЕРВЕРА
    // ========================================
    
    $deletedFiles = 0;
    $fileErrors   = [];

    foreach ($imagesToDelete as $image) {
        $imageId          = (int)$image['id'];
        $fileVersionsJson = (string)$image['file_versions'];

        $fileVersions = json_decode($fileVersionsJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            logEvent(
                "DATA_ERROR: Некорректный JSON file_versions для ID=$imageId — " . json_last_error_msg(),
                LOG_ERROR_ENABLED,
                'error'
            );
            continue;
        }

        if (!is_array($fileVersions)) {
            logEvent(
                "DATA_WARNING: file_versions не массив для ID=$imageId — тип: " . gettype($fileVersions),
                LOG_ERROR_ENABLED,
                'error'
            );
            continue;
        }

        // Универсально: и для индексных, и для ассоциативных
        $versionsToProcess = array_values($fileVersions);

        foreach ($versionsToProcess as $version) {
            if (is_array($version) && isset($version['path']) && !empty($version['path'])) {
                $relativePath = ltrim((string)$version['path'], '/');
                $filePath     = $uploadDir . $relativePath;

                if (file_exists($filePath)) {
                    if (@unlink($filePath)) {
                        $deletedFiles++;
                    } else {
                        $errorMessage = "Не удалось удалить файл: $relativePath (ID=$imageId)";
                        $fileErrors[] = $errorMessage;
                        logEvent("FS_ERROR: $errorMessage", LOG_ERROR_ENABLED, 'error');
                    }
                } else {
                    logEvent("FS_WARNING: Файл не существует: $filePath (ID=$imageId)", LOG_ERROR_ENABLED, 'error');
                }
            }
        }
    }

    // ========================================
    // УДАЛЕНИЕ ЗАПИСЕЙ ИЗ БАЗЫ ДАННЫХ
    // ========================================
    
    // Удаляем записи из БД (только те, которые реально принадлежат пользователю)
    $placeholdersValid = str_repeat('?,', count($validImageIds) - 1) . '?';
    $deleteSql         = "DELETE FROM `media_files` WHERE id IN ($placeholdersValid) AND user_id = ?";
    $deleteStmt        = $pdo->prepare($deleteSql);
    $deleteParams      = array_merge($validImageIds, [$userId]);
    $deleteStmt->execute($deleteParams);

    $deletedCount = $deleteStmt->rowCount();

    // ========================================
    // ФОРМИРОВАНИЕ ОТВЕТА
    // ========================================
    
    if (empty($fileErrors)) {
        echo json_encode([
            'success'       => true,
            'message'       => "Успешно удалено $deletedCount изображений",
            'deleted_count' => $deletedCount,
            'deleted_files' => $deletedFiles,
            'deleted_ids'   => $validImageIds
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success'       => false,
            'error'         => 'Произошли ошибки при удалении некоторых файлов',
            'deleted_count' => $deletedCount,
            'deleted_files' => $deletedFiles,
            'errors'        => $fileErrors,
            'deleted_ids'   => $validImageIds
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

// ========================================
// ЗАВЕРШЕНИЕ СКРИПТА
// ========================================

// Закрываем соединение при завершении скрипта
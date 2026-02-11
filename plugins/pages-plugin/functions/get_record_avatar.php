<?php
/**
 * Файл: functions/get_record_avatar.php
 * 
 * Функция получения изображения записи из медиа-файлов
 *
 * @param PDO $pdo              Объект PDO для подключения к базе данных
 * @param int $recordId         ID записи
 * @param int $userId           ID пользователя (для проверки прав доступа)
 * @param string $tableName     Название таблицы (например: 'pages_categories')
 * @param string $defaultImage  Путь к изображению по умолчанию
 * @return string               Путь к изображению
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

function getRecordAvatar(PDO $pdo, int $recordId, int $userId, string $tableName, string $defaultImage = '../../../../admin/img/galereya.svg'): string {
    if ($recordId <= 0) {
        return $defaultImage;
    }

    // Validate table name against allowlist to prevent SQL injection
    // Use explicit query selection instead of string interpolation
    $query = null;
    switch ($tableName) {
        case 'pages_categories':
            $query = "SELECT image FROM pages_categories WHERE id = ? AND users_id = ?";
            break;
        case 'pages_content':
            $query = "SELECT image FROM pages_content WHERE id = ? AND users_id = ?";
            break;
        case 'pages_extra_content':
            $query = "SELECT image FROM pages_extra_content WHERE id = ? AND users_id = ?";
            break;
        default:
            if (defined('LOG_ERROR_ENABLED')) {
                logEvent("Попытка использования недопустимого имени таблицы: {$tableName}", LOG_ERROR_ENABLED, 'error');
            }
            return $defaultImage;
    }

    try {
        // Получаем изображение из записи
        $stmt = $pdo->prepare($query);
        $stmt->execute([$recordId, $userId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record || empty($record['image'])) {
            return $defaultImage;
        }

        // Берём первый ID из списка изображений
        $imageIds = explode(',', (string)$record['image']);
        $firstImageId = (int)($imageIds[0] ?? 0);
        
        if ($firstImageId <= 0) {
            return $defaultImage;
        }

        // Ищем запись в media_files
        $mediaStmt = $pdo->prepare("SELECT file_versions FROM media_files WHERE id = ?");
        $mediaStmt->execute([$firstImageId]);
        $mediaFile = $mediaStmt->fetch(PDO::FETCH_ASSOC);

        if ($mediaFile && !empty($mediaFile['file_versions'])) {
            $fileVersions = json_decode($mediaFile['file_versions'], true);
            
            // Проверяем thumbnail путь
            if (!empty($fileVersions['thumbnail']['path'])) {
                $path = $fileVersions['thumbnail']['path'];
                // Валидация пути для предотвращения path traversal
                // Проверяем на запрещенные последовательности
                if (strpos($path, '..') === false && 
                    strpos($path, "\0") === false && 
                    strpos($path, '//') === false &&
                    !preg_match('/[<>"|?*]/', $path)) {
                    return '/uploads/' . $path;
                }
            }
            
            // Проверяем original путь
            if (!empty($fileVersions['original']['path'])) {
                $path = $fileVersions['original']['path'];
                // Валидация пути для предотвращения path traversal
                // Проверяем на запрещенные последовательности
                if (strpos($path, '..') === false && 
                    strpos($path, "\0") === false && 
                    strpos($path, '//') === false &&
                    !preg_match('/[<>"|?*]/', $path)) {
                    return '/uploads/' . $path;
                }
            }
        }
    } catch (PDOException $e) {
        if (defined('LOG_ERROR_ENABLED')) {
            logEvent("Ошибка загрузки изображения записи: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
        }
    }

    return $defaultImage;
}

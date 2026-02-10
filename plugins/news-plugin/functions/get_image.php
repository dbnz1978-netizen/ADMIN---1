<?php
/**
 * Файл: functions/get_image.php
 * 
 * Функция получения изображения из медиа-файлов для новостей
 *
 * @param PDO $pdo              Объект PDO для подключения к базе данных
 * @param string $imageIds      ID изображений через запятую
 * @param string $defaultImage  Путь к изображению по умолчанию
 * @return string               Путь к изображению
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

function getNewsImage(PDO $pdo, ?string $imageIds, string $defaultImage = '../img/galereya.svg'): string {
    if (empty($imageIds)) {
        return $defaultImage;
    }

    try {
        // Берём первый ID из списка
        $imageIdsArray = explode(',', (string)$imageIds);
        $firstImageId = (int)($imageIdsArray[0] ?? 0);
        
        if ($firstImageId <= 0) {
            return $defaultImage;
        }

        // Ищем запись в media_files
        $mediaStmt = $pdo->prepare("SELECT file_versions FROM media_files WHERE id = ?");
        $mediaStmt->execute([$firstImageId]);
        $mediaFile = $mediaStmt->fetch(PDO::FETCH_ASSOC);

        if ($mediaFile && !empty($mediaFile['file_versions'])) {
            $fileVersions = json_decode($mediaFile['file_versions'], true);
            if (!empty($fileVersions['thumbnail']['path'])) {
                return '/uploads/' . $fileVersions['thumbnail']['path'];
            }
            if (!empty($fileVersions['original']['path'])) {
                return '/uploads/' . $fileVersions['original']['path'];
            }
        }
    } catch (PDOException $e) {
        if (defined('LOG_ERROR_ENABLED')) {
            logEvent("Ошибка загрузки изображения: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
        }
    }

    return $defaultImage;
}

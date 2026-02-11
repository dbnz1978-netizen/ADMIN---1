<?php
/**
 * Файл: /plugins/news-plugin/functions/get_user_avatar.php
 *
 * Назначение:
 * - Функция для получения URL изображения (аватара) из media_files по ID.
 * - Используется для отображения превью изображений в списках статей.
 *
 * Использование:
 * - getUserAvatar($pdo, $images, $defaultAvatar)
 *   где $images может быть строкой с одним или несколькими ID через запятую (например, "1,2,3")
 *
 * @param PDO $pdo - объект подключения к БД
 * @param string|null $images - ID изображения или список ID через запятую
 * @param string $defaultAvatar - путь к изображению по умолчанию
 * @return string - URL изображения или путь к изображению по умолчанию
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

function getUserAvatar($pdo, $images, $defaultAvatar = '../../../../admin/img/galereya.svg') {
    if (empty($images)) {
        return $defaultAvatar;
    }

    $imageIds = explode(',', (string)$images);
    $lastImageId = end($imageIds);
    $lastImageId = (int)$lastImageId;

    if ($lastImageId <= 0) {
        return $defaultAvatar;
    }

    try {
        $mediaStmt = $pdo->prepare("SELECT file_versions FROM media_files WHERE id = ?");
        $mediaStmt->execute([$lastImageId]);
        $mediaFile = $mediaStmt->fetch(PDO::FETCH_ASSOC);

        if ($mediaFile && !empty($mediaFile['file_versions'])) {
            $fileVersions = json_decode($mediaFile['file_versions'], true);
            if (isset($fileVersions['thumbnail']['path'])) {
                return '/uploads/' . $fileVersions['thumbnail']['path'];
            }
        }
    } catch (PDOException $e) {
        logEvent("Ошибка загрузки аватара (media_files) для изображения ID $lastImageId: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    }

    return $defaultAvatar;
}

<?php
/**
 * Файл: /admin/functions/get_user_avatar.php
 *
 * Назначение:
 * - Функция для получения аватара пользователя из медиа-библиотеки по ID изображения
 * - Используется для отображения аватаров пользователей в админ-панели
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

function getUserAvatar($pdo, $images, $defaultAvatar = 'img/photo.svg') {
    // Если нет изображений - возвращаем аватар по умолчанию
    if (empty($images)) {
        return $defaultAvatar;
    }
    
    // Разбираем строку с ID изображений
    $imageIds    = explode(',', $images);
    $lastImageId = end($imageIds);
    $lastImageId = (int)$lastImageId;
    
    // Если ID невалидный - возвращаем аватар по умолчанию
    if ($lastImageId <= 0) {
        return $defaultAvatar;
    }
    
    try {
        // Получаем информацию о файле из медиа-библиотеки
        $mediaStmt = $pdo->prepare("SELECT file_versions FROM media_files WHERE id = ?");
        $mediaStmt->execute([$lastImageId]);
        $mediaFile = $mediaStmt->fetch(PDO::FETCH_ASSOC);
        
        // Если файл найден и есть версии
        if ($mediaFile && !empty($mediaFile['file_versions'])) {
            $fileVersions = json_decode($mediaFile['file_versions'], true);
            // Используем thumbnail версию для аватара (150x150)
            if (isset($fileVersions['thumbnail']['path'])) {
                return '/uploads/' . $fileVersions['thumbnail']['path'];
            }
        }
    } catch (PDOException $e) {
        // В случае ошибки возвращаем аватар по умолчанию + логируем
        logEvent(
            "Ошибка загрузки аватара пользователя для изображения ID $lastImageId: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            LOG_ERROR_ENABLED,
            'error'
        );
    }
    
    return $defaultAvatar;
}

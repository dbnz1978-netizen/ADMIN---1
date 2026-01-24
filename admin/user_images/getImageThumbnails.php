<?php
/**
 * Файл: /admin/user_images/getImageThumbnails.php
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}
/**
 * Функция для получения ссылок на изображения из базы данных
 * 
 * @param string $image_ids Строка с ID изображений через запятую
 * @param PDO $pdo Объект PDO для подключения к базе данных
 * @return array Массив с ссылками на thumbnail изображения
 */

function getImageThumbnails($image_ids, $pdo) {
    // Проверяем подключение к базе данных
    if (!$pdo) {
        $error = "Отсутствует подключение к базе данных";
        return [
            'success' => false,
            'error' => $error,
            'images' => []
        ];
    }
    
    // Проверяем, что переданы ID изображений
    if (empty($image_ids)) {
        return [
            'success' => true,
            'images' => [],
            'count' => 0
        ];
    }

    // Получаем ID текущего пользователя из сессии
    $userId = $_SESSION['user_id'] ?? null;
    
    // Если пользователь не авторизован — не возвращаем данные
    if ($userId === null) {
        return [
            'success' => false,
            'error' => 'Пользователь не авторизован',
            'images' => [],
            'count' => 0
        ];
    }

    // Преобразуем строку ID в массив и очищаем от пробелов
    $image_ids_array = array_map('trim', explode(',', $image_ids));
    $image_ids_array = array_filter($image_ids_array);
    
    if (empty($image_ids_array)) {
        return [
            'success' => true,
            'images' => [],
            'count' => 0
        ];
    }

    try {
        // Создаем плейсхолдеры для подготовленного запроса
        $placeholders = str_repeat('?,', count($image_ids_array) - 1) . '?';
        
        // SQL запрос с фильтрацией по user_id — обеспечиваем принадлежность файлов
        $sql = "SELECT id, file_versions FROM media_files WHERE id IN ($placeholders) AND user_id = ?";
        $stmt = $pdo->prepare($sql);
        
        // Добавляем user_id в конец параметров
        $params = array_merge($image_ids_array, [$userId]);
        $stmt->execute($params);
        
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Создаем ассоциативный массив для быстрого поиска по id
        $images_by_id = [];
        foreach ($images as $image) {
            $images_by_id[$image['id']] = $image;
        }
        
        // Восстанавливаем порядок согласно исходному массиву id и обрабатываем файлы
        $ordered_images = [];
        $thumbnail_urls = [];
        
        foreach ($image_ids_array as $id) {
            if (isset($images_by_id[$id])) {
                $image_data = $images_by_id[$id];
                
                // Декодируем JSON с версиями файлов
                $file_versions = json_decode($image_data['file_versions'], true);
                
                // Пропускаем если ошибка декодирования JSON
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                
                // Пропускаем если отсутствует thumbnail версия
                if (!isset($file_versions['thumbnail']['path'])) {
                    continue;
                }
                
                $thumbnail_path = $file_versions['thumbnail']['path'];
                
                // Формируем полный URL к файлу (для вывода)
                $thumbnail_url = '/uploads/' . $thumbnail_path;
                
                // Формируем локальный путь к файлу (для проверки)
                $local_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $thumbnail_path;
                
                // ПРОВЕРЯЕМ ЛОКАЛЬНЫЙ ФАЙЛ - если файл не существует, пропускаем
                if (!file_exists($local_path) || !is_file($local_path)) {
                    continue;
                }
                
                // Дополнительная проверка, что файл не пустой
                $file_size = filesize($local_path);
                if ($file_size <= 0) {
                    continue;
                }
                
                // Если все проверки пройдены - добавляем в результат
                $ordered_images[] = [
                    'id' => $id,
                    'thumbnail_url' => $thumbnail_url,
                    'local_path' => $local_path,
                    'file_size' => $file_size,
                    'file_data' => $file_versions['thumbnail']
                ];
                
                $thumbnail_urls[] = $thumbnail_url;
            }
            // Если изображение не найдено (в т.ч. не принадлежит пользователю) — пропускаем
        }
        
        $result = [
            'success' => true,
            'images' => $ordered_images,
            'thumbnail_urls' => $thumbnail_urls,
            'count' => count($ordered_images),
            'requested_count' => count($image_ids_array),
            'processed_count' => count($ordered_images)
        ];
        
        return $result;
        
    } catch (PDOException $e) {
        $error = "Ошибка базы данных: " . $e->getMessage();
        return [
            'success' => false,
            'error' => $error,
            'images' => [],
            'count' => 0
        ];
    } catch (Exception $e) {
        $error = "Общая ошибка: " . $e->getMessage();
        return [
            'success' => false,
            'error' => $error,
            'images' => [],
            'count' => 0
        ];
    }
}

/**
 * Функция для получения ссылок на изображения из базы данных
 * 
// Пример использования с обработкой ошибок и логированием
$image_ids = '775,774,773,772,770,769,771,768,767,766,765,764';
$result = getImageThumbnails($image_ids, $pdo);

if ($result['success']) {
    if (!empty($result['images'])) {
        echo "Найдено изображений: " . $result['count'] . "<br><br>";
        
        foreach ($result['images'] as $image) {
            echo "<div style='margin-bottom: 10px; padding: 10px; border: 1px solid #ccc;'>";
            echo "<strong>ID:</strong> " . $image['id'] . "<br>";
            echo "<strong>URL:</strong> " . $image['thumbnail_url'] . "<br>";
            echo "<strong>Размер:</strong> " . $image['file_size'] . " байт<br>";
            echo "<strong>Локальный путь:</strong> " . $image['local_path'] . "<br>";
            echo "</div>";
        }
    } else {
        echo "Нет доступных изображений для отображения";
    }
} else {
    // ЕСЛИ ПРОИЗОШЛА ОШИБКА - ЛОГИРУЕМ И ВЫВОДИМ СООБЩЕНИЕ
    $error_message = $result['error'];
    
    // Логируем ошибку
    logError($error_message);
    
    // Выводим сообщение об ошибке
    echo "Произошла ошибка: " . $error_message . "<br>";
    echo "Ошибка была записана в лог-файл.";
}
 */
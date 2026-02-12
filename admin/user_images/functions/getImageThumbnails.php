<?php

/**
 * Название файла:      getImageThumbnails.php
 * Назначение:          Функция для получения ссылок на изображения из базы данных.
 *                      Возвращает информацию о миниатюрах (thumbnail) изображений,
 *                      включая URL, локальный путь и размер файла.
 *                      Поддерживает форматы: JPEG, PNG, GIF, WebP, AVIF, JPEG XL.
 *                      
 *                      Особенности:
 *                      - Фильтрация изображений по принадлежности к пользователю (user_id)
 *                      - Восстановление порядка изображений согласно переданному списку ID
 *                      - Проверка существования файлов на сервере
 *                      - Обработка ошибок декодирования JSON
 *                      - Безопасное получение данных через подготовленные запросы
 *                      
 *                      Возвращаемый массив:
 *                      - success: true/false результат операции
 *                      - images: массив изображений с данными
 *                      - thumbnail_urls: массив только с URL миниатюр
 *                      - count: количество обработанных изображений
 *                      - requested_count: количество запрошенных ID
 *                      - processed_count: количество успешно обработанных
 *                      - error: сообщение об ошибке (при наличии)
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// ПРОВЕРКА ДОСТУПА
// ========================================

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

// ========================================
// ФУНКЦИЯ ПОЛУЧЕНИЯ МИНИАТЮР ИЗОБРАЖЕНИЙ
// ========================================


/**
 * Получение ссылок на миниатюры изображений из базы данных
 *
 * @param string $imageIds  Строка с ID изображений через запятую
 * @param PDO    $pdo       Объект PDO для подключения к базе данных
 * @return array            Массив с результатом операции
 */
function getImageThumbnails(string $imageIds, PDO $pdo): array
{
    // ========================================
    // ПРОВЕРКА ПОДКЛЮЧЕНИЯ К БАЗЕ ДАННЫХ
    // ========================================
    
    if (!$pdo) {
        $error = "Отсутствует подключение к базе данных";
        
        return [
            'success' => false,
            'error'   => $error,
            'images'  => [],
        ];
    }
    
    // ========================================
    // ПРОВЕРКА ВХОДНЫХ ДАННЫХ
    // ========================================
    
    // Проверяем, что переданы ID изображений
    if (empty($imageIds)) {
        return [
            'success' => true,
            'images'  => [],
            'count'   => 0,
        ];
    }

    // Получаем ID текущего пользователя из сессии
    $userId = $_SESSION['user_id'] ?? null;
    
    // Если пользователь не авторизован — не возвращаем данные
    if ($userId === null) {
        return [
            'success' => false,
            'error'   => 'Пользователь не авторизован',
            'images'  => [],
            'count'   => 0,
        ];
    }

    // Преобразуем строку ID в массив и очищаем от пробелов
    $imageIdsArray = array_map('trim', explode(',', $imageIds));
    $imageIdsArray = array_filter($imageIdsArray);
    
    if (empty($imageIdsArray)) {
        return [
            'success' => true,
            'images'  => [],
            'count'   => 0,
        ];
    }

    // ========================================
    // ВЫПОЛНЕНИЕ ЗАПРОСА К БАЗЕ ДАННЫХ
    // ========================================
    
    try {
        
        // Создаем плейсхолдеры для подготовленного запроса
        $placeholders = str_repeat('?,', count($imageIdsArray) - 1) . '?';
        
        // SQL запрос с фильтрацией по user_id — обеспечиваем принадлежность файлов
        $sql  = "SELECT id, file_versions FROM media_files WHERE id IN ($placeholders) AND user_id = ?";
        $stmt = $pdo->prepare($sql);
        
        // Добавляем user_id в конец параметров
        $params = array_merge($imageIdsArray, [$userId]);
        $stmt->execute($params);
        
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // ========================================
        // ОБРАБОТКА РЕЗУЛЬТАТОВ
        // ========================================
        
        // Создаем ассоциативный массив для быстрого поиска по id
        $imagesById = [];
        
        foreach ($images as $image) {
            $imagesById[$image['id']] = $image;
        }
        
        // Восстанавливаем порядок согласно исходному массиву id и обрабатываем файлы
        $orderedImages  = [];
        $thumbnailUrls  = [];
        
        foreach ($imageIdsArray as $id) {
            if (isset($imagesById[$id])) {
                $imageData = $imagesById[$id];
                
                // Декодируем JSON с версиями файлов
                $fileVersions = json_decode($imageData['file_versions'], true);
                
                // Пропускаем если ошибка декодирования JSON
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                
                // Пропускаем если отсутствует thumbnail версия
                if (!isset($fileVersions['thumbnail']['path'])) {
                    continue;
                }
                
                $thumbnailPath = $fileVersions['thumbnail']['path'];
                
                // Формируем полный URL к файлу (для вывода)
                $thumbnailUrl = '/uploads/' . $thumbnailPath;
                
                // Формируем локальный путь к файлу (для проверки)
                $localPath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $thumbnailPath;
                
                // ========================================
                // ПРОВЕРКА СУЩЕСТВОВАНИЯ ФАЙЛА
                // ========================================
                
                // ПРОВЕРЯЕМ ЛОКАЛЬНЫЙ ФАЙЛ - если файл не существует, пропускаем
                if (!file_exists($localPath) || !is_file($localPath)) {
                    continue;
                }
                
                // Дополнительная проверка, что файл не пустой
                $fileSize = filesize($localPath);
                
                if ($fileSize <= 0) {
                    continue;
                }
                
                // Если все проверки пройдены - добавляем в результат
                $orderedImages[] = [
                    'id'            => $id,
                    'thumbnail_url' => $thumbnailUrl,
                    'local_path'    => $localPath,
                    'file_size'     => $fileSize,
                    'file_data'     => $fileVersions['thumbnail'],
                ];
                
                $thumbnailUrls[] = $thumbnailUrl;
            }
            // Если изображение не найдено (в т.ч. не принадлежит пользователю) — пропускаем
        }
        
        // ========================================
        // ФОРМИРОВАНИЕ ОТВЕТА
        // ========================================
        
        $result = [
            'success'         => true,
            'images'          => $orderedImages,
            'thumbnail_urls'  => $thumbnailUrls,
            'count'           => count($orderedImages),
            'requested_count' => count($imageIdsArray),
            'processed_count' => count($orderedImages),
        ];
        
        return $result;
        
    } catch (PDOException $e) {
        $error = "Ошибка базы данных: " . $e->getMessage();
        
        return [
            'success' => false,
            'error'   => $error,
            'images'  => [],
            'count'   => 0,
        ];
    } catch (Exception $e) {
        $error = "Общая ошибка: " . $e->getMessage();
        
        return [
            'success' => false,
            'error'   => $error,
            'images'  => [],
            'count'   => 0,
        ];
    }
}
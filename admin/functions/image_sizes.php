<?php
/**
 * Файл: /admin/functions/image_sizes.php
 * Модуль для управления глобальными настройками размеров генерируемых изображений
 * 
 * Предоставляет единое место для хранения и получения настроек размеров изображений
 * (thumbnail, small, medium, large), которые применяются ко всем блокам загрузки файлов.
 * 
 * Настройки хранятся в JSON-данных администратора (users.data) под ключом 'global_image_sizes'.
 * 
 * Режимы обработки изображений:
 * - "cover"   — Обрезка изображения с сохранением пропорций (заполняет весь размер)
 * - "contain" — Вписывание изображения с сохранением пропорций (может быть меньше размера)
 * 
 * Формат настроек: [ширина, высота, режим]
 * - ширина/высота: положительное целое число или 'auto'
 * - режим: 'cover' или 'contain'
 * 
 * @version 1.0
 * @date 2026-02-11
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

/**
 * Возвращает настройки размеров изображений по умолчанию
 * 
 * Эти настройки используются когда:
 * - Администратор ещё не настроил глобальные размеры
 * - Произошла ошибка при чтении настроек из БД
 * - Настройки в БД повреждены или некорректны
 * 
 * @return array Ассоциативный массив с настройками размеров
 *               Ключи: thumbnail, small, medium, large
 *               Значения: [ширина, высота, режим]
 */
function getDefaultImageSizes() {
    return [
        "thumbnail" => [100, 100, "cover"],
        "small"     => [300, 'auto', "contain"],
        "medium"    => [600, 'auto', "contain"],
        "large"     => [1200, 'auto', "contain"]
    ];
}

/**
 * Получает глобальные настройки размеров изображений из данных администратора
 * 
 * Читает настройки из JSON-поля 'data' пользователя admin,
 * ищет ключ 'global_image_sizes'. Если настройки не найдены
 * или некорректны, возвращает значения по умолчанию.
 * 
 * @param PDO $pdo Объект подключения к базе данных
 * @return array Ассоциативный массив с настройками размеров
 */
function getGlobalImageSizes($pdo) {
    try {
        // Получаем данные администратора
        $adminData = getAdminData($pdo);
        
        // Если данные админа не получены, используем defaults
        if ($adminData === false || !is_array($adminData)) {
            return getDefaultImageSizes();
        }
        
        // Проверяем наличие настроек размеров
        if (isset($adminData['global_image_sizes']) && is_array($adminData['global_image_sizes'])) {
            $customSizes = $adminData['global_image_sizes'];
            
            // Валидируем и нормализуем настройки
            $validatedSizes = validateImageSizes($customSizes);
            
            if ($validatedSizes !== false) {
                return $validatedSizes;
            }
        }
        
        // Если настройки не найдены или некорректны, возвращаем defaults
        return getDefaultImageSizes();
        
    } catch (Exception $e) {
        // В случае любой ошибки возвращаем defaults
        $logEnabled = defined('LOG_ERROR_ENABLED') ? LOG_ERROR_ENABLED : false;
        if (function_exists('logEvent')) {
            logEvent("Ошибка при получении глобальных настроек размеров изображений: " . $e->getMessage(), $logEnabled, 'error');
        }
        return getDefaultImageSizes();
    }
}

/**
 * Валидирует и нормализует настройки размеров изображений
 * 
 * Проверяет корректность структуры и значений настроек.
 * Обязательные размеры: thumbnail, small, medium, large.
 * 
 * @param array $sizes Массив настроек для валидации
 * @return array|false Валидированный массив или false при ошибке
 */
function validateImageSizes($sizes) {
    if (!is_array($sizes)) {
        return false;
    }
    
    $requiredSizes = ['thumbnail', 'small', 'medium', 'large'];
    $validatedSizes = [];
    
    foreach ($requiredSizes as $sizeName) {
        // Проверяем наличие обязательного размера
        if (!isset($sizes[$sizeName]) || !is_array($sizes[$sizeName])) {
            return false;
        }
        
        $sizeConfig = $sizes[$sizeName];
        
        // Проверяем структуру: должно быть [ширина, высота, режим]
        if (count($sizeConfig) !== 3) {
            return false;
        }
        
        list($width, $height, $mode) = $sizeConfig;
        
        // Валидируем режим
        if (!in_array($mode, ['cover', 'contain'], true)) {
            return false;
        }
        
        // Валидируем ширину
        if (!validateDimension($width)) {
            return false;
        }
        
        // Валидируем высоту
        if (!validateDimension($height)) {
            return false;
        }
        
        // Для thumbnail требуем оба числа (не 'auto')
        if ($sizeName === 'thumbnail' && ($width === 'auto' || $height === 'auto')) {
            return false;
        }
        
        $validatedSizes[$sizeName] = [$width, $height, $mode];
    }
    
    return $validatedSizes;
}

/**
 * Валидирует одну размерность (ширину или высоту)
 * 
 * Допустимые значения:
 * - Строка 'auto'
 * - Положительное целое число
 * 
 * @param mixed $dimension Значение для проверки
 * @return bool true если значение валидно
 */
function validateDimension($dimension) {
    // Разрешаем 'auto'
    if ($dimension === 'auto') {
        return true;
    }
    
    // Разрешаем положительные целые числа
    if (is_int($dimension) && $dimension > 0) {
        return true;
    }
    
    // Разрешаем строковое представление положительных целых чисел
    if (is_string($dimension) && ctype_digit($dimension) && (int)$dimension > 0) {
        return true;
    }
    
    return false;
}

/**
 * Валидирует настройки размеров изображений из POST-запроса
 * 
 * Обрабатывает данные из формы настроек и проверяет корректность
 * всех параметров перед сохранением в базу данных.
 * 
 * @param array $postData Данные из $_POST
 * @return array ['valid' => bool, 'sizes' => array|null, 'errors' => array]
 */
function validateImageSizesFromPost($postData) {
    $errors = [];
    $sizes = [];
    $sizeNames = ['thumbnail', 'small', 'medium', 'large'];
    
    foreach ($sizeNames as $sizeName) {
        // Получаем значения из POST
        $width = $postData["img_{$sizeName}_width"] ?? '';
        $height = $postData["img_{$sizeName}_height"] ?? '';
        $mode = $postData["img_{$sizeName}_mode"] ?? '';
        
        // Валидация режима
        if (!in_array($mode, ['cover', 'contain'], true)) {
            $errors[] = "Некорректный режим для размера '{$sizeName}'. Допустимы только 'cover' или 'contain'.";
            continue;
        }
        
        // Нормализация ширины
        $width = trim($width);
        if ($width === 'auto') {
            $normalizedWidth = 'auto';
        } elseif (ctype_digit($width) && (int)$width > 0) {
            $normalizedWidth = (int)$width;
        } else {
            $errors[] = "Некорректная ширина для размера '{$sizeName}'. Допустимы только положительные числа или 'auto'.";
            continue;
        }
        
        // Нормализация высоты
        $height = trim($height);
        if ($height === 'auto') {
            $normalizedHeight = 'auto';
        } elseif (ctype_digit($height) && (int)$height > 0) {
            $normalizedHeight = (int)$height;
        } else {
            $errors[] = "Некорректная высота для размера '{$sizeName}'. Допустимы только положительные числа или 'auto'.";
            continue;
        }
        
        // Для thumbnail требуем оба числа
        if ($sizeName === 'thumbnail' && ($normalizedWidth === 'auto' || $normalizedHeight === 'auto')) {
            $errors[] = "Для thumbnail ширина и высота должны быть числами (не 'auto').";
            continue;
        }
        
        $sizes[$sizeName] = [$normalizedWidth, $normalizedHeight, $mode];
    }
    
    // Проверяем, что все размеры были валидированы
    if (count($sizes) !== count($sizeNames)) {
        return [
            'valid' => false,
            'sizes' => null,
            'errors' => $errors
        ];
    }
    
    return [
        'valid' => true,
        'sizes' => $sizes,
        'errors' => []
    ];
}

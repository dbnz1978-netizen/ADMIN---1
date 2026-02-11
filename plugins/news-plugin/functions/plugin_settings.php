<?php
/**
 * Файл: /plugins/news-plugin/functions/plugin_settings.php
 * 
 * Функции для работы с настройками плагина news-plugin, хранящимися в таблице plugins.
 * 
 * Настройки хранятся в колонке settings (TEXT, JSON) для записи с name='news-plugin'.
 * Структура JSON:
 * {
 *   "image_sizes": {
 *     "thumbnail": [100, 100, "cover"],
 *     "small": [300, "auto", "contain"],
 *     "medium": [600, "auto", "contain"],
 *     "large": [1200, "auto", "contain"]
 *   },
 *   "limits": {
 *     "add_category": { "maxDigits": 1 },
 *     "add_article": { "maxDigits": 50 },
 *     "add_extra": { "maxDigits": 50 }
 *   }
 * }
 * 
 * Примечания:
 * - В image_sizes значения ширины/высоты могут быть числом или строкой "auto"
 * - Для thumbnail оба значения должны быть числами (не "auto")
 * - Режим может быть "cover" или "contain"
 * 
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-11
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

/**
 * Получает настройки плагина из таблицы plugins
 * 
 * @param PDO $pdo Объект подключения к БД
 * @param string $pluginName Имя плагина (например: 'news-plugin')
 * @return array|null Массив настроек или null при ошибке/отсутствии
 */
function getPluginSettings($pdo, $pluginName) {
    try {
        $stmt = $pdo->prepare("SELECT settings FROM plugins WHERE name = ? LIMIT 1");
        $stmt->execute([$pluginName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        $settings = $row['settings'];
        
        // Если settings пустой или NULL
        if (empty($settings)) {
            return [];
        }
        
        // Декодируем JSON
        $decoded = json_decode($settings, true);
        
        // Проверяем валидность JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logEnabled = defined('LOG_ERROR_ENABLED') ? LOG_ERROR_ENABLED : false;
            if (function_exists('logEvent')) {
                logEvent(
                    "Ошибка декодирования JSON настроек плагина '$pluginName': " . json_last_error_msg(),
                    $logEnabled,
                    'error'
                );
            }
            return [];
        }
        
        return is_array($decoded) ? $decoded : [];
        
    } catch (PDOException $e) {
        $logEnabled = defined('LOG_ERROR_ENABLED') ? LOG_ERROR_ENABLED : false;
        if (function_exists('logEvent')) {
            logEvent(
                "Ошибка получения настроек плагина '$pluginName': " . $e->getMessage(),
                $logEnabled,
                'error'
            );
        }
        return null;
    }
}

/**
 * Сохраняет настройки плагина в таблицу plugins
 * 
 * @param PDO $pdo Объект подключения к БД
 * @param string $pluginName Имя плагина (например: 'news-plugin')
 * @param array $settings Массив настроек для сохранения
 * @return bool Успешность операции
 */
function savePluginSettings($pdo, $pluginName, $settings) {
    try {
        // Кодируем настройки в JSON
        $jsonSettings = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($jsonSettings === false) {
            $logEnabled = defined('LOG_ERROR_ENABLED') ? LOG_ERROR_ENABLED : false;
            if (function_exists('logEvent')) {
                logEvent(
                    "Ошибка кодирования настроек плагина '$pluginName' в JSON",
                    $logEnabled,
                    'error'
                );
            }
            return false;
        }
        
        // Обновляем настройки в БД
        $stmt = $pdo->prepare("UPDATE plugins SET settings = ? WHERE name = ?");
        $result = $stmt->execute([$jsonSettings, $pluginName]);
        
        return $result;
        
    } catch (PDOException $e) {
        $logEnabled = defined('LOG_ERROR_ENABLED') ? LOG_ERROR_ENABLED : false;
        if (function_exists('logEvent')) {
            logEvent(
                "Ошибка сохранения настроек плагина '$pluginName': " . $e->getMessage(),
                $logEnabled,
                'error'
            );
        }
        return false;
    }
}

/**
 * Получает размеры изображений с учётом переопределений плагина
 * 
 * Возвращает глобальные размеры изображений, переопределённые настройками плагина
 * (если они есть). Плагин может переопределить любой из размеров:
 * thumbnail, small, medium, large.
 * 
 * @param PDO $pdo Объект подключения к БД
 * @param string $pluginName Имя плагина (например: 'news-plugin')
 * @return array Массив размеров изображений
 */
function getPluginImageSizes($pdo, $pluginName) {
    // Загружаем функцию получения глобальных размеров, если ещё не загружена
    if (!function_exists('getGlobalImageSizes')) {
        require_once __DIR__ . '/../../../admin/functions/image_sizes.php';
    }
    
    // Получаем глобальные настройки
    $imageSizes = getGlobalImageSizes($pdo);
    
    // Получаем настройки плагина
    $pluginSettings = getPluginSettings($pdo, $pluginName);
    
    // Если настройки плагина существуют и содержат переопределения размеров
    if (is_array($pluginSettings) && 
        isset($pluginSettings['image_sizes']) && 
        is_array($pluginSettings['image_sizes'])) {
        
        $sizeNames = ['thumbnail', 'small', 'medium', 'large'];
        
        foreach ($sizeNames as $sizeName) {
            if (isset($pluginSettings['image_sizes'][$sizeName]) && 
                is_array($pluginSettings['image_sizes'][$sizeName])) {
                
                $sizeOverride = $pluginSettings['image_sizes'][$sizeName];
                
                // Валидируем переопределение
                if (count($sizeOverride) === 3) {
                    list($width, $height, $mode) = $sizeOverride;
                    
                    // Проверяем валидность ширины
                    $isValidWidth = ($width === 'auto') || (is_int($width) && $width > 0);
                    // Проверяем валидность высоты
                    $isValidHeight = ($height === 'auto') || (is_int($height) && $height > 0);
                    // Проверяем валидность режима
                    $isValidMode = in_array($mode, ['cover', 'contain'], true);
                    
                    // Для thumbnail требуем оба числа (не 'auto')
                    if ($sizeName === 'thumbnail' && ($width === 'auto' || $height === 'auto')) {
                        continue;
                    }
                    
                    if ($isValidWidth && $isValidHeight && $isValidMode) {
                        // Применяем переопределение
                        $imageSizes[$sizeName] = $sizeOverride;
                    }
                }
            }
        }
    }
    
    return $imageSizes;
}

/**
 * Получает лимит maxDigits для конкретной страницы плагина
 * 
 * @param PDO $pdo Объект подключения к БД
 * @param string $pluginName Имя плагина (например: 'news-plugin')
 * @param string $pageName Имя страницы (например: 'add_article', 'add_extra')
 * @param int $defaultValue Значение по умолчанию
 * @return int Лимит maxDigits
 */
function getPluginMaxDigits($pdo, $pluginName, $pageName, $defaultValue = 50) {
    // Получаем настройки плагина
    $pluginSettings = getPluginSettings($pdo, $pluginName);
    
    // Если настройки плагина существуют и содержат лимит для страницы
    if (is_array($pluginSettings) && 
        isset($pluginSettings['limits'][$pageName]['maxDigits'])) {
        
        $maxDigits = $pluginSettings['limits'][$pageName]['maxDigits'];
        
        // Валидируем значение
        if (is_int($maxDigits) && $maxDigits >= 0) {
            return $maxDigits;
        }
    }
    
    // Возвращаем значение по умолчанию
    return $defaultValue;
}

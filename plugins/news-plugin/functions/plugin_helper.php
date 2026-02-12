<?php
/**
 * Файл: /plugins/news-plugin/functions/plugin_helper.php
 * 
 * Вспомогательные функции для автоматического определения имени плагина.
 * Это позволяет дублировать плагин без изменения кода.
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
 * Автоматически определяет имя плагина на основе структуры директорий
 * 
 * Функция анализирует путь к текущему файлу и извлекает имя директории плагина.
 * Это позволяет дублировать плагин, просто переименовав его директорию.
 * 
 * @return string Имя плагина (например: 'news-plugin')
 * 
 * @example
 * // Для файла /plugins/news-plugin/pages/articles/add_article.php
 * $pluginName = getPluginName(); // Вернёт 'news-plugin'
 * 
 * // Для файла /plugins/my-custom-plugin/pages/settings/access.php  
 * $pluginName = getPluginName(); // Вернёт 'my-custom-plugin'
 */
function getPluginName() {
    // Получаем путь к текущему файлу
    $currentFile = __FILE__;
    
    // Нормализуем разделители путей
    $currentFile = str_replace('\\', '/', $currentFile);
    
    // Ищем сегмент '/plugins/' в пути
    $parts = explode('/plugins/', $currentFile);
    
    if (count($parts) < 2) {
        // Если структура не соответствует ожидаемой, возвращаем значение по умолчанию
        return 'news-plugin';
    }
    
    // Берём часть после '/plugins/' и извлекаем первый сегмент (имя директории плагина)
    $afterPlugins = $parts[1];
    $pluginParts = explode('/', $afterPlugins);
    
    // Первый сегмент - это имя плагина
    $pluginName = $pluginParts[0];
    
    return $pluginName;
}

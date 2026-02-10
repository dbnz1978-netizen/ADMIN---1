<?php

/**
 * Название файла:      plugin_manager.php
 * Назначение:          Менеджер плагинов — управление установкой, активацией и настройками плагинов
 *                      Основные функции:
 *                      - Сканирование директории /plugins на наличие плагинов
 *                      - Чтение манифестов плагинов (plugin.json)
 *                      - Установка/удаление плагинов
 *                      - Активация/деактивация плагинов
 *                      - Управление настройками плагинов
 *                      - Загрузка конфигурации меню плагинов
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// ПРОВЕРКА ДОСТУПА
// ========================================

if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Доступ запрещён');
}


// ========================================
// ФУНКЦИИ РАБОТЫ С ПЛАГИНАМИ
// ========================================

/**
 * Сканирование директории плагинов и получение списка всех доступных плагинов
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @return array Массив плагинов с их информацией
 */
function scanPlugins($pdo)
{
    $pluginsDir = __DIR__ . '/../../plugins';
    $plugins    = [];

    if (!is_dir($pluginsDir)) {
        return $plugins;
    }

    // Получаем все установленные плагины из базы данных
    $stmt            = $pdo->query("SELECT * FROM plugins");
    $installedPlugins = [];
    
    while ($row = $stmt->fetch()) {
        $installedPlugins[$row['name']] = $row;
    }

    // Сканируем директорию плагинов
    $dirs = array_diff(scandir($pluginsDir), ['.', '..']);
    
    foreach ($dirs as $dir) {
        $pluginPath     = $pluginsDir . '/' . $dir;
        $manifestPath   = $pluginPath . '/plugin.json';
        
        if (is_dir($pluginPath) && file_exists($manifestPath)) {
            $manifestContent = file_get_contents($manifestPath);
            $manifest        = json_decode($manifestContent, true);
            
            if ($manifest && isset($manifest['name'])) {
                $pluginData = [
                    'name'         => $manifest['name'],
                    'display_name' => $manifest['display_name'] ?? $manifest['name'],
                    'version'      => $manifest['version'] ?? '1.0.0',
                    'author'       => $manifest['author'] ?? 'Unknown',
                    'description'  => $manifest['description'] ?? '',
                    'requires_php' => $manifest['requires_php'] ?? '7.4',
                    'requires_db'  => $manifest['requires_db'] ?? false,
                    'has_settings_page' => $manifest['has_settings_page'] ?? false,
                    'is_installed' => false,
                    'is_enabled'   => false,
                    'delete_tables_on_uninstall' => false,
                    'path'         => $pluginPath
                ];
                
                // Если плагин установлен, обновляем данные из БД
                if (isset($installedPlugins[$manifest['name']])) {
                    $dbData                = $installedPlugins[$manifest['name']];
                    $pluginData['id']      = $dbData['id'];
                    $pluginData['is_installed'] = (bool) $dbData['is_installed'];
                    $pluginData['is_enabled']   = (bool) $dbData['is_enabled'];
                    $pluginData['delete_tables_on_uninstall'] = (bool) $dbData['delete_tables_on_uninstall'];
                    $pluginData['installed_at'] = $dbData['installed_at'];
                    $pluginData['settings']     = $dbData['settings'] ? json_decode($dbData['settings'], true) : [];
                }
                
                $plugins[] = $pluginData;
            }
        }
    }

    return $plugins;
}


/**
 * Получение информации о конкретном плагине
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param string $pluginName Имя плагина
 * @return array|null Данные плагина или null
 */
function getPlugin($pdo, $pluginName)
{
    $plugins = scanPlugins($pdo);
    
    foreach ($plugins as $plugin) {
        if ($plugin['name'] === $pluginName) {
            return $plugin;
        }
    }
    
    return null;
}


/**
 * Установка плагина
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param string $pluginName Имя плагина
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function installPlugin($pdo, $pluginName)
{
    try {
        $plugin = getPlugin($pdo, $pluginName);
        
        if (!$plugin) {
            return ['success' => false, 'message' => 'Плагин не найден'];
        }
        
        if ($plugin['is_installed']) {
            return ['success' => false, 'message' => 'Плагин уже установлен'];
        }
        
        // Выполняем скрипт установки плагина, если он существует
        $installScript = $plugin['path'] . '/install.php';
        
        if (file_exists($installScript)) {
            require_once $installScript;
            
            // Ожидаем функцию plugin_install($pdo)
            if (function_exists('plugin_install')) {
                $installResult = plugin_install($pdo);
                
                if (!$installResult['success']) {
                    return $installResult;
                }
            }
        }
        
        // Добавляем плагин в базу данных
        $stmt = $pdo->prepare(
            "INSERT INTO plugins 
            (name, display_name, version, author, description, is_installed, is_enabled, installed_at) 
            VALUES 
            (:name, :display_name, :version, :author, :description, 1, 0, NOW())"
        );
        
        $stmt->execute([
            'name'         => $plugin['name'],
            'display_name' => $plugin['display_name'],
            'version'      => $plugin['version'],
            'author'       => $plugin['author'],
            'description'  => $plugin['description']
        ]);
        
        logEvent(
            "Плагин '{$plugin['display_name']}' успешно установлен",
            LOG_INFO_ENABLED,
            'info'
        );
        
        return ['success' => true, 'message' => 'Плагин успешно установлен'];
        
    } catch (Exception $e) {
        logEvent(
            "Ошибка при установке плагина '$pluginName': " . $e->getMessage(),
            LOG_ERROR_ENABLED,
            'error'
        );
        
        return ['success' => false, 'message' => 'Ошибка при установке плагина: ' . $e->getMessage()];
    }
}


/**
 * Удаление плагина
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param string $pluginName Имя плагина
 * @param bool $deleteFiles Удалить файлы плагина с диска
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function uninstallPlugin($pdo, $pluginName, $deleteFiles = false)
{
    try {
        $plugin = getPlugin($pdo, $pluginName);
        
        if (!$plugin || !$plugin['is_installed']) {
            return ['success' => false, 'message' => 'Плагин не установлен'];
        }
        
        // Выполняем скрипт удаления плагина, если он существует
        $uninstallScript = $plugin['path'] . '/uninstall.php';
        
        if (file_exists($uninstallScript) && $plugin['delete_tables_on_uninstall']) {
            require_once $uninstallScript;
            
            // Ожидаем функцию plugin_uninstall($pdo)
            if (function_exists('plugin_uninstall')) {
                $uninstallResult = plugin_uninstall($pdo);
                
                if (!$uninstallResult['success']) {
                    return $uninstallResult;
                }
            }
        }
        
        // Удаляем плагин из базы данных
        $stmt = $pdo->prepare("DELETE FROM plugins WHERE name = :name");
        $stmt->execute(['name' => $pluginName]);
        
        // Удаляем файлы плагина с диска, если требуется
        if ($deleteFiles && is_dir($plugin['path'])) {
            deleteDirectory($plugin['path']);
        }
        
        logEvent(
            "Плагин '{$plugin['display_name']}' успешно удален",
            LOG_INFO_ENABLED,
            'info'
        );
        
        return ['success' => true, 'message' => 'Плагин успешно удален'];
        
    } catch (Exception $e) {
        logEvent(
            "Ошибка при удалении плагина '$pluginName': " . $e->getMessage(),
            LOG_ERROR_ENABLED,
            'error'
        );
        
        return ['success' => false, 'message' => 'Ошибка при удалении плагина: ' . $e->getMessage()];
    }
}


/**
 * Активация плагина
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param string $pluginName Имя плагина
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function enablePlugin($pdo, $pluginName)
{
    try {
        $plugin = getPlugin($pdo, $pluginName);
        
        if (!$plugin || !$plugin['is_installed']) {
            return ['success' => false, 'message' => 'Плагин не установлен'];
        }
        
        if ($plugin['is_enabled']) {
            return ['success' => false, 'message' => 'Плагин уже включен'];
        }
        
        $stmt = $pdo->prepare("UPDATE plugins SET is_enabled = 1 WHERE name = :name");
        $stmt->execute(['name' => $pluginName]);
        
        logEvent(
            "Плагин '{$plugin['display_name']}' включен",
            LOG_INFO_ENABLED,
            'info'
        );
        
        return ['success' => true, 'message' => 'Плагин успешно включен'];
        
    } catch (Exception $e) {
        logEvent(
            "Ошибка при включении плагина '$pluginName': " . $e->getMessage(),
            LOG_ERROR_ENABLED,
            'error'
        );
        
        return ['success' => false, 'message' => 'Ошибка при включении плагина'];
    }
}


/**
 * Деактивация плагина
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param string $pluginName Имя плагина
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function disablePlugin($pdo, $pluginName)
{
    try {
        $plugin = getPlugin($pdo, $pluginName);
        
        if (!$plugin || !$plugin['is_installed']) {
            return ['success' => false, 'message' => 'Плагин не установлен'];
        }
        
        if (!$plugin['is_enabled']) {
            return ['success' => false, 'message' => 'Плагин уже выключен'];
        }
        
        $stmt = $pdo->prepare("UPDATE plugins SET is_enabled = 0 WHERE name = :name");
        $stmt->execute(['name' => $pluginName]);
        
        logEvent(
            "Плагин '{$plugin['display_name']}' выключен",
            LOG_INFO_ENABLED,
            'info'
        );
        
        return ['success' => true, 'message' => 'Плагин успешно выключен'];
        
    } catch (Exception $e) {
        logEvent(
            "Ошибка при выключении плагина '$pluginName': " . $e->getMessage(),
            LOG_ERROR_ENABLED,
            'error'
        );
        
        return ['success' => false, 'message' => 'Ошибка при выключении плагина'];
    }
}


/**
 * Обновление настроек плагина
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param string $pluginName Имя плагина
 * @param array $settings Массив настроек
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function updatePluginSettings($pdo, $pluginName, $settings)
{
    try {
        $plugin = getPlugin($pdo, $pluginName);
        
        if (!$plugin || !$plugin['is_installed']) {
            return ['success' => false, 'message' => 'Плагин не установлен'];
        }
        
        $stmt = $pdo->prepare("UPDATE plugins SET settings = :settings WHERE name = :name");
        $stmt->execute([
            'name'     => $pluginName,
            'settings' => json_encode($settings)
        ]);
        
        logEvent(
            "Настройки плагина '{$plugin['display_name']}' обновлены",
            LOG_INFO_ENABLED,
            'info'
        );
        
        return ['success' => true, 'message' => 'Настройки успешно обновлены'];
        
    } catch (Exception $e) {
        logEvent(
            "Ошибка при обновлении настроек плагина '$pluginName': " . $e->getMessage(),
            LOG_ERROR_ENABLED,
            'error'
        );
        
        return ['success' => false, 'message' => 'Ошибка при обновлении настроек'];
    }
}


/**
 * Получение конфигурации меню всех включенных плагинов
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @return array Массив конфигураций меню плагинов
 */
function getPluginMenus($pdo)
{
    $plugins = scanPlugins($pdo);
    $menus   = [];
    
    foreach ($plugins as $plugin) {
        if (!$plugin['is_enabled']) {
            continue;
        }
        
        $navConfigPath = $plugin['path'] . '/nav-config.json';
        
        if (file_exists($navConfigPath)) {
            $navConfigContent = file_get_contents($navConfigPath);
            $navConfig        = json_decode($navConfigContent, true);
            
            if ($navConfig) {
                $navConfig['plugin_name'] = $plugin['name'];
                $menus[] = $navConfig;
            }
        }
    }
    
    return $menus;
}


/**
 * Рекурсивное удаление директории
 *
 * @param string $dir Путь к директории
 * @return bool Результат удаления
 */
function deleteDirectory($dir)
{
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}


/**
 * Обновление настройки "Удалять таблицы при удалении"
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param string $pluginName Имя плагина
 * @param bool $deleteTables Удалять таблицы (true/false)
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function updatePluginDeleteTablesOption($pdo, $pluginName, $deleteTables)
{
    try {
        $plugin = getPlugin($pdo, $pluginName);
        
        if (!$plugin || !$plugin['is_installed']) {
            return ['success' => false, 'message' => 'Плагин не установлен'];
        }
        
        $stmt = $pdo->prepare("UPDATE plugins SET delete_tables_on_uninstall = :delete_tables WHERE name = :name");
        $stmt->execute([
            'name'          => $pluginName,
            'delete_tables' => $deleteTables ? 1 : 0
        ]);
        
        return ['success' => true, 'message' => 'Настройка успешно обновлена'];
        
    } catch (Exception $e) {
        logEvent(
            "Ошибка при обновлении настройки удаления таблиц для плагина '$pluginName': " . $e->getMessage(),
            LOG_ERROR_ENABLED,
            'error'
        );
        
        return ['success' => false, 'message' => 'Ошибка при обновлении настройки'];
    }
}


/**
 * Загрузка и установка плагина из ZIP-архива
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param array $uploadedFile Массив $_FILES с загруженным файлом
 * @return array Результат операции ['success' => bool, 'message' => string, 'plugin_name' => string]
 */
function uploadPlugin($pdo, $uploadedFile)
{
    try {
        // Проверка наличия файла
        if (!isset($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
            return ['success' => false, 'message' => 'Файл не был загружен'];
        }
        
        // Проверка ошибок загрузки
        if (isset($uploadedFile['error']) && $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE   => 'Размер файла превышает upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE  => 'Размер файла превышает MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL    => 'Файл был загружен частично',
                UPLOAD_ERR_NO_FILE    => 'Файл не был загружен',
                UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная директория',
                UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
                UPLOAD_ERR_EXTENSION  => 'Загрузка файла остановлена расширением'
            ];
            
            $errorMessage = $errorMessages[$uploadedFile['error']] ?? 'Неизвестная ошибка загрузки';
            return ['success' => false, 'message' => $errorMessage];
        }
        
        // Проверка размера файла (макс 10MB)
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($uploadedFile['size'] > $maxSize) {
            return ['success' => false, 'message' => 'Размер файла превышает 10MB'];
        }
        
        // Проверка расширения файла
        $fileName = $uploadedFile['name'];
        $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if ($fileExt !== 'zip') {
            return ['success' => false, 'message' => 'Разрешены только ZIP-архивы'];
        }
        
        // Создаем временную директорию для распаковки
        $tempDir = sys_get_temp_dir() . '/plugin_upload_' . uniqid();
        
        if (!mkdir($tempDir, 0755, true)) {
            return ['success' => false, 'message' => 'Не удалось создать временную директорию'];
        }
        
        // Распаковываем архив
        $zip = new ZipArchive();
        
        if ($zip->open($uploadedFile['tmp_name']) !== true) {
            deleteDirectory($tempDir);
            return ['success' => false, 'message' => 'Не удалось открыть ZIP-архив'];
        }
        
        // Проверка на ZIP-бомбу (слишком много файлов)
        $maxFiles = 1000;
        if ($zip->numFiles > $maxFiles) {
            $zip->close();
            deleteDirectory($tempDir);
            return ['success' => false, 'message' => 'Архив содержит слишком много файлов'];
        }
        
        // Проверка на подозрительные файлы
        $dangerousExtensions = ['php5', 'phtml', 'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'vbs', 'js', 'jar', 'ps1', 'msi', 'app', 'deb', 'rpm'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // Проверка на path traversal в именах файлов (включая абсолютные пути)
            if (strpos($filename, '..') !== false || $filename[0] === '/' || strpos($filename, '\\') !== false) {
                $zip->close();
                deleteDirectory($tempDir);
                return ['success' => false, 'message' => 'Архив содержит подозрительные пути файлов'];
            }
            
            // Предупреждение о подозрительных расширениях (не блокируем .php, т.к. плагины могут их содержать)
            if (in_array($ext, $dangerousExtensions)) {
                $zip->close();
                deleteDirectory($tempDir);
                return ['success' => false, 'message' => 'Архив содержит файлы с запрещенным расширением: .' . $ext];
            }
        }
        
        $zip->extractTo($tempDir);
        $zip->close();
        
        // Ищем plugin.json в распакованных файлах
        $pluginJsonPath = null;
        $pluginDir      = null;
        
        // Проверяем корневую директорию
        if (file_exists($tempDir . '/plugin.json')) {
            $pluginJsonPath = $tempDir . '/plugin.json';
            $pluginDir      = $tempDir;
        } else {
            // Проверяем поддиректории (плагин может быть в папке внутри архива)
            $dirs = array_diff(scandir($tempDir), ['.', '..']);
            
            foreach ($dirs as $dir) {
                $path = $tempDir . '/' . $dir;
                
                if (is_dir($path) && file_exists($path . '/plugin.json')) {
                    $pluginJsonPath = $path . '/plugin.json';
                    $pluginDir      = $path;
                    break;
                }
            }
        }
        
        if (!$pluginJsonPath) {
            deleteDirectory($tempDir);
            return ['success' => false, 'message' => 'В архиве не найден файл plugin.json'];
        }
        
        // Читаем и валидируем plugin.json
        $manifestContent = file_get_contents($pluginJsonPath);
        $manifest        = json_decode($manifestContent, true);
        
        if (!$manifest || !isset($manifest['name'])) {
            deleteDirectory($tempDir);
            return ['success' => false, 'message' => 'Некорректный формат plugin.json'];
        }
        
        $pluginName = $manifest['name'];
        
        // Валидация имени плагина (безопасность: предотвращение path traversal)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $pluginName)) {
            deleteDirectory($tempDir);
            return ['success' => false, 'message' => 'Некорректное имя плагина. Разрешены только буквы, цифры, дефис и подчеркивание'];
        }
        
        // Проверяем, не установлен ли уже плагин с таким именем
        $existingPlugin = getPlugin($pdo, $pluginName);
        
        if ($existingPlugin) {
            deleteDirectory($tempDir);
            return ['success' => false, 'message' => 'Плагин с именем "' . $pluginName . '" уже существует'];
        }
        
        // Копируем плагин в директорию /plugins
        $pluginsDir     = __DIR__ . '/../../plugins';
        $targetDir      = $pluginsDir . '/' . $pluginName;
        
        if (file_exists($targetDir)) {
            deleteDirectory($tempDir);
            return ['success' => false, 'message' => 'Директория плагина уже существует'];
        }
        
        // Копируем директорию
        if (!copyDirectory($pluginDir, $targetDir)) {
            deleteDirectory($tempDir);
            return ['success' => false, 'message' => 'Не удалось скопировать файлы плагина'];
        }
        
        // Удаляем временную директорию
        deleteDirectory($tempDir);
        
        // Устанавливаем плагин (с отключенным статусом)
        $result = installPlugin($pdo, $pluginName);
        
        if (!$result['success']) {
            // Если установка не удалась, удаляем скопированные файлы
            deleteDirectory($targetDir);
            return $result;
        }
        
        logEvent(
            "Плагин '$pluginName' успешно загружен и установлен из архива",
            LOG_INFO_ENABLED,
            'info'
        );
        
        return [
            'success'     => true,
            'message'     => 'Плагин успешно загружен и установлен (статус: отключен)',
            'plugin_name' => $pluginName
        ];
        
    } catch (Exception $e) {
        // Очищаем временные файлы при ошибке
        if (isset($tempDir) && is_dir($tempDir)) {
            deleteDirectory($tempDir);
        }
        
        logEvent(
            "Ошибка при загрузке плагина: " . $e->getMessage(),
            LOG_ERROR_ENABLED,
            'error'
        );
        
        return ['success' => false, 'message' => 'Ошибка при загрузке плагина: ' . $e->getMessage()];
    }
}


/**
 * Рекурсивное копирование директории
 *
 * @param string $source Исходная директория
 * @param string $destination Целевая директория
 * @return bool Результат копирования
 */
function copyDirectory($source, $destination)
{
    if (!is_dir($source)) {
        return false;
    }
    
    // Создаем целевую директорию, если она не существует
    if (!is_dir($destination)) {
        if (!mkdir($destination, 0755, true)) {
            return false;
        }
        
        // Проверяем, что директория действительно создана
        if (!is_dir($destination)) {
            return false;
        }
    }
    
    $dir = opendir($source);
    
    if (!$dir) {
        return false;
    }
    
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $srcPath  = $source . '/' . $file;
        $destPath = $destination . '/' . $file;
        
        if (is_dir($srcPath)) {
            if (!copyDirectory($srcPath, $destPath)) {
                closedir($dir);
                return false;
            }
        } else {
            if (!copy($srcPath, $destPath)) {
                closedir($dir);
                return false;
            }
        }
    }
    
    closedir($dir);
    return true;
}

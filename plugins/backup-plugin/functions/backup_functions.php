<?php

/**
 * Название файла:      backup_functions.php
 * Назначение:          Функции для создания резервной копии сайта
 *                      - Экспорт таблиц БД в SQL файл
 *                      - Архивирование файлов и папок
 *                      - Создание PHP установщика
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-12
 * Последнее изменение: 2026-02-12
 */

if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Доступ запрещён');
}

// ========================================
// КОНФИГУРАЦИЯ РЕЗЕРВНОГО КОПИРОВАНИЯ
// ========================================

// Максимальный размер резервной копии в байтах (500 МБ по умолчанию)
// Можно изменить в зависимости от размера сайта и доступных ресурсов сервера
if (!defined('MAX_BACKUP_SIZE')) {
    define('MAX_BACKUP_SIZE', 500 * 1024 * 1024); // 500 MB
}

/**
 * Получение размера директории
 *
 * @param string $path Путь к директории
 * @return int Размер в байтах
 */
function getDirectorySize($path)
{
    $totalSize = 0;
    
    if (!is_dir($path)) {
        return 0;
    }
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalSize += $file->getSize();
            }
        }
    } catch (Exception $e) {
        error_log("Error calculating directory size for $path: " . $e->getMessage());
    }
    
    return $totalSize;
}

/**
 * Валидация имени файла резервной копии
 *
 * @param string $fileName Имя файла (должно быть уже обработано через basename)
 * @return bool True если имя файла валидно, иначе false
 */
function isValidBackupFileName($fileName)
{
    // Проверяем формат: backup_YYYY-MM-DD_HH-MM-SS.zip
    return preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $fileName) === 1;
}

/**
 * Форматирование размера файла
 *
 * @param int $bytes Размер в байтах
 * @return string Форматированный размер
 */
function formatFileSize($bytes)
{
    $bytes = (int)$bytes;
    
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' ГБ';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' МБ';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' КБ';
    } else {
        return $bytes . ' Б';
    }
}

/**
 * Получение имени плагина из пути
 *
 * @param string|null $path Путь к директории (по умолчанию __DIR__)
 * @return string Имя плагина
 */
function getPluginNameFromPath($path = null)
{
    if ($path === null) {
        $path = __DIR__;
    }
    $parts = explode('/', str_replace('\\', '/', $path));
    foreach ($parts as $i => $part) {
        if ($part === 'plugins' && isset($parts[$i + 1])) {
            return $parts[$i + 1];
        }
    }
    return 'unknown';
}

/**
 * Создание резервной копии сайта
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param array $selectedTables Массив имён выбранных таблиц
 * @param array $selectedFolders Массив имён выбранных папок
 * @return array Результат операции ['success' => bool, 'message' => string, 'download_url' => string]
 */
function createBackup($pdo, $selectedTables, $selectedFolders)
{
    try {
        // Создаём директорию для хранения резервных копий в ../backups
        // Используем realpath для корректного разрешения символических ссылок
        // и работы независимо от структуры сервера (с public_html или без)
        $rootPath = realpath(__DIR__ . '/../../..');
        // Изменяем путь для хранения резервных копий на уровень выше корня сайта
        $backupDir = dirname($rootPath) . '/backups';
        
        // Проверяем существование и права на запись директории резервных копий
        if (!is_dir($backupDir)) {
            if (!@mkdir($backupDir, 0755, true)) {
                $lastError = error_get_last();
                $errorMsg = $lastError ? $lastError['message'] : 'неизвестная ошибка';
                return [
                    'success' => false,
                    'message' => "Не удалось создать директорию для резервных копий ($errorMsg). Проверьте права доступа к родительской директории."
                ];
            }
        }
        
        if (!is_writable($backupDir)) {
            return [
                'success' => false,
                'message' => 'Директория резервных копий не доступна для записи. Установите права 0755 или 0777 на директорию ' . $backupDir
            ];
        }
        
        // Генерируем уникальное имя для резервной копии
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = 'backup_' . $timestamp;
        $tempDir = $backupDir . '/' . $backupName;
        
        if (!@mkdir($tempDir, 0755, true)) {
            $lastError = error_get_last();
            $errorMsg = $lastError ? $lastError['message'] : 'неизвестная ошибка';
            return [
                'success' => false,
                'message' => "Не удалось создать временную директорию для резервной копии ($errorMsg). Проверьте права доступа к директории $backupDir"
            ];
        }
        
        // 1. Экспортируем базу данных
        $sqlFile = $tempDir . '/database.sql';
        $sqlResult = exportDatabase($pdo, $selectedTables, $sqlFile);
        
        if (!$sqlResult['success']) {
            rrmdir($tempDir);
            return $sqlResult;
        }
        
        // IMPROVEMENT: Track skipped tables for reporting
        $skippedTables = $sqlResult['skipped_tables'] ?? [];
        
        // 2. Копируем выбранные папки
        // Всегда добавляем папки admin и connect
        if (!in_array('admin', $selectedFolders)) {
            $selectedFolders[] = 'admin';
        }
        if (!in_array('connect', $selectedFolders)) {
            $selectedFolders[] = 'connect';
        }
        if (!in_array('plugins', $selectedFolders)) {
            $selectedFolders[] = 'plugins';
        }
        
        $filesDir = $tempDir . '/files';
        mkdir($filesDir, 0755, true);
        
        // Определяем путь к директории backups для исключения
        // OPTIMIZATION: Cache realpath result to avoid repeated filesystem calls
        $backupDirToExclude = realpath($backupDir);
        if ($backupDirToExclude === false) {
            // Если realpath не сработал, используем абсолютный путь напрямую
            $backupDirToExclude = $backupDir;
        }
        
        // OPTIMIZATION: Cache root path realpath for reuse in recursiveCopy
        $cachedRootPath = realpath($rootPath);
        if ($cachedRootPath === false) {
            $cachedRootPath = $rootPath;
        }
        
        $skippedFolders = []; // Массив пропущенных папок для отчета
        $copiedFolders = []; // Массив скопированных папок
        
        foreach ($selectedFolders as $folder) {
            // Валидация: проверяем что имя папки не содержит недопустимые символы
            if (preg_match('/[\/\\\\\.]{2,}/', $folder) || strpos($folder, '..') !== false) {
                $skippedFolders[] = "$folder (содержит недопустимые символы)";
                continue; // Пропускаем папки с путями траверсала
            }
            
            // Удаляем возможные слеши в начале и конце
            $folder = trim($folder, '/\\');
            
            // Проверяем что папка не пустая после trim
            if (empty($folder)) {
                $skippedFolders[] = "(пустое имя папки)";
                continue;
            }
            
            // Проверяем что папка не содержит путь (только имя папки)
            if (strpos($folder, '/') !== false || strpos($folder, '\\') !== false) {
                $skippedFolders[] = "$folder (путь вместо имени папки)";
                continue; // Пропускаем если это путь, а не имя папки
            }
            
            $sourcePath = $rootPath . '/' . $folder;
            $destPath = $filesDir . '/' . $folder;
            
            // Проверяем что директория существует
            if (!is_dir($sourcePath)) {
                $skippedFolders[] = "$folder (директория не найдена)";
                continue;
            }
            
            // Проверяем что директория доступна для чтения
            if (!is_readable($sourcePath)) {
                $skippedFolders[] = "$folder (нет прав на чтение)";
                continue;
            }
            
            // Получаем реальный путь и проверяем что он внутри корневой директории
            $realSourcePath = realpath($sourcePath);
            if ($realSourcePath === false) {
                $skippedFolders[] = "$folder (ошибка определения реального пути)";
                continue;
            }
            
            if (strpos($realSourcePath, $rootPath) !== 0) {
                $skippedFolders[] = "$folder (вне корневой директории)";
                continue; // Пропускаем если путь вне корневой директории
            }
            
            // Копируем директорию (используем кэшированный rootPath)
            recursiveCopy($sourcePath, $destPath, $backupDirToExclude, $cachedRootPath);
            $copiedFolders[] = $folder;
        }
        
        // 3. Создаём установщик
        $installerFile = $tempDir . '/install.php';
        $installerResult = createInstaller($installerFile);
        
        if (!$installerResult['success']) {
            rrmdir($tempDir);
            return $installerResult;
        }
        
        // 4. Создаём README файл
        $readmeFile = $tempDir . '/README.txt';
        createReadme($readmeFile, $selectedTables, $selectedFolders);
        
        // 5. Упаковываем всё в ZIP
        $zipFile = $backupDir . '/' . $backupName . '.zip';
        $zipResult = createZipArchive($tempDir, $zipFile);
        
        if (!$zipResult['success']) {
            rrmdir($tempDir);
            return $zipResult;
        }
        
        // IMPROVEMENT: Validate backup size after creation
        $backupSize = filesize($zipFile);
        if ($backupSize === false) {
            rrmdir($tempDir);
            @unlink($zipFile);
            return [
                'success' => false,
                'message' => 'Не удалось определить размер резервной копии'
            ];
        }
        
        if ($backupSize > MAX_BACKUP_SIZE) {
            rrmdir($tempDir);
            @unlink($zipFile);
            $maxSizeMB = round(MAX_BACKUP_SIZE / (1024 * 1024), 2);
            $actualSizeMB = round($backupSize / (1024 * 1024), 2);
            return [
                'success' => false,
                'message' => "Размер резервной копии ($actualSizeMB МБ) превышает максимально допустимый ($maxSizeMB МБ). Попробуйте выбрать меньше папок или таблиц."
            ];
        }
        
        // 6. Удаляем временную директорию
        rrmdir($tempDir);
        
        // 7. Возвращаем результат с именем файла для скачивания и информацией о папках
        $message = 'Резервная копия успешно создана';
        if (!empty($copiedFolders)) {
            $message .= '. Скопировано папок: ' . count($copiedFolders);
        }
        
        // IMPROVEMENT: Report all skipped items (tables and folders)
        $allSkipped = array_merge($skippedTables, $skippedFolders);
        if (!empty($allSkipped)) {
            $message .= '. ВНИМАНИЕ: Некоторые элементы не были включены в архив: ' . implode(', ', $allSkipped);
        }
        
        return [
            'success' => true,
            'message' => $message,
            'backup_file' => $backupName . '.zip'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Ошибка при создании резервной копии: ' . $e->getMessage()
        ];
    }
}

/**
 * Экспорт таблиц базы данных в SQL файл
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @param array $tables Массив имён таблиц для экспорта
 * @param string $outputFile Путь к выходному SQL файлу
 * @return array Результат операции ['success' => bool, 'message' => string, 'skipped_tables' => array]
 */
function exportDatabase($pdo, $tables, $outputFile)
{
    try {
        $output = "-- Резервная копия базы данных\n";
        $output .= "-- Создано: " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $output .= "SET time_zone = \"+00:00\";\n\n";
        
        // Получаем список всех существующих таблиц для валидации
        $stmt = $pdo->query("SHOW TABLES");
        $validTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // IMPROVEMENT: Track skipped tables for user feedback
        $skippedTables = [];
        $exportedTables = [];
        
        foreach ($tables as $table) {
            // Валидация: проверяем что таблица существует
            if (!in_array($table, $validTables)) {
                $skippedTables[] = "$table (таблица не существует)";
                continue; // Пропускаем несуществующие таблицы
            }
            
            // Дополнительная валидация: проверяем что имя таблицы содержит только допустимые символы
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $skippedTables[] = "$table (недопустимые символы в имени)";
                continue; // Пропускаем таблицы с недопустимыми символами
            }
            
            // Получаем структуру таблицы
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch();
            
            $output .= "\n\n-- --------------------------------------------------------\n";
            $output .= "-- Структура таблицы `$table`\n";
            $output .= "-- --------------------------------------------------------\n\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $row['Create Table'] . ";\n\n";
            
            // Получаем данные таблицы
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rowCount = 0;
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($rowCount === 0) {
                    $output .= "-- Данные таблицы `$table`\n\n";
                }
                
                $output .= "INSERT INTO `$table` (";
                $output .= implode(', ', array_map(function($key) {
                    return "`$key`";
                }, array_keys($row)));
                $output .= ") VALUES (";
                
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = $pdo->quote($value);
                    }
                }
                $output .= implode(', ', $values);
                $output .= ");\n";
                
                $rowCount++;
            }
            
            if ($rowCount > 0) {
                $output .= "\n";
            }
            
            $exportedTables[] = $table;
        }
        
        // Записываем в файл
        if (file_put_contents($outputFile, $output) === false) {
            return [
                'success' => false,
                'message' => 'Не удалось записать SQL файл',
                'skipped_tables' => $skippedTables
            ];
        }
        
        // IMPROVEMENT: Return detailed information about export
        $message = 'База данных успешно экспортирована';
        if (!empty($exportedTables)) {
            $message .= ' (' . count($exportedTables) . ' таблиц)';
        }
        
        return [
            'success' => true,
            'message' => $message,
            'skipped_tables' => $skippedTables,
            'exported_tables' => $exportedTables
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Ошибка при экспорте базы данных: ' . $e->getMessage(),
            'skipped_tables' => []
        ];
    }
}

/**
 * Рекурсивное копирование директории
 *
 * @param string $source Исходная директория
 * @param string $dest Целевая директория
 * @param string $excludePath Путь для исключения из копирования (по умолчанию - директория backups)
 * @param string $rootPath Корневой путь сайта (для определения специальных файлов)
 * @throws Exception Если произошла ошибка при копировании
 */
function recursiveCopy($source, $dest, $excludePath = null, $rootPath = null)
{
    if (!is_dir($dest)) {
        if (!mkdir($dest, 0755, true)) {
            throw new Exception("Не удалось создать директорию: $dest");
        }
    }
    
    // SECURITY FIX: Add error handling for opendir
    $dir = @opendir($source);
    if ($dir === false) {
        throw new Exception("Не удалось открыть директорию: $source");
    }
    
    try {
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $sourcePath = $source . '/' . $file;
            $destPath = $dest . '/' . $file;
            
            // Пропускаем директорию backups, чтобы избежать рекурсии
            if ($excludePath !== null) {
                $realSourcePath = realpath($sourcePath);
                $realExcludePath = realpath($excludePath);
                
                // Сравниваем пути, обрабатывая случай когда realpath возвращает false
                if ($realSourcePath !== false && $realExcludePath !== false) {
                    if ($realSourcePath === $realExcludePath) {
                        continue;
                    }
                } else {
                    // Если realpath не работает, используем нормализованное сравнение путей
                    $normalizedSource = str_replace('\\', '/', $sourcePath);
                    $normalizedExclude = str_replace('\\', '/', $excludePath);
                    if ($normalizedSource === $normalizedExclude) {
                        continue;
                    }
                }
            }
            
            if (is_dir($sourcePath)) {
                recursiveCopy($sourcePath, $destPath, $excludePath, $rootPath);
            } else {
                // Специальная обработка для connect/db.php - очищаем учетные данные БД
                if ($rootPath !== null && $file === 'db.php') {
                    $realSourcePath = realpath($sourcePath);
                    $realRootPath = realpath($rootPath);
                    if ($realSourcePath !== false && $realRootPath !== false) {
                        // Безопасное получение относительного пути с проверкой длины
                        $rootLength = strlen($realRootPath);
                        if (substr($realSourcePath, 0, $rootLength) === $realRootPath) {
                            $relativePath = substr($realSourcePath, $rootLength);
                            $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
                            
                            if ($relativePath === 'connect/db.php') {
                                // Читаем файл и очищаем учетные данные
                                $content = file_get_contents($sourcePath);
                                
                                // Заменяем значения учетных данных на пустые строки
                                // Примечание: Эти регулярные выражения работают для стандартного формата PHP
                                // и предполагают, что значения не содержат экранированных кавычек внутри строк
                                // Формат: private static $variable = 'value'; или private static $variable = "value";
                                $originalContent = $content;
                                
                                $content = preg_replace(
                                    '/private static \$host\s*=\s*[\'"][^\'";]*[\'"];/',
                                    "private static \$host = 'localhost';",
                                    $content
                                );
                                $content = preg_replace(
                                    '/private static \$dbName\s*=\s*[\'"][^\'";]*[\'"];/',
                                    "private static \$dbName = '';",
                                    $content
                                );
                                $content = preg_replace(
                                    '/private static \$userName\s*=\s*[\'"][^\'";]*[\'"];/',
                                    "private static \$userName = '';",
                                    $content
                                );
                                $content = preg_replace(
                                    '/private static \$password\s*=\s*[\'"][^\'";]*[\'"];/',
                                    "private static \$password = '';",
                                    $content
                                );
                                
                                // Проверяем, что очистка прошла успешно (содержимое изменилось)
                                // и что мы можем записать модифицированный файл
                                if ($content !== $originalContent && file_put_contents($destPath, $content) !== false) {
                                    // Успешно сохранили очищенный файл
                                    continue;
                                } else {
                                    // ВАЖНО: Для безопасности НЕ копируем оригинальный файл с учетными данными
                                    // Вместо этого создаем заглушку с комментарием
                                    $placeholderContent = "<?php\n/**\n * ВАЖНО: Этот файл был исключен из резервной копии по соображениям безопасности.\n * При восстановлении сайта необходимо создать файл connect/db.php вручную\n * с корректными учетными данными базы данных.\n */\n\nif (!defined('APP_ACCESS')) {\n    http_response_code(403);\n    exit('Доступ запрещён');\n}\n";
                                    file_put_contents($destPath, $placeholderContent);
                                    continue;
                                }
                            }
                        }
                    }
                }
                
                // SECURITY FIX: Add error handling for copy operations
                if (!@copy($sourcePath, $destPath)) {
                    $lastError = error_get_last();
                    $errorMsg = $lastError ? $lastError['message'] : 'неизвестная ошибка';
                    // Log warning but continue with other files
                    error_log("Warning: Failed to copy file: $sourcePath to $destPath ($errorMsg)");
                }
            }
        }
    } finally {
        closedir($dir);
    }
}

/**
 * Рекурсивное удаление директории
 *
 * @param string $dir Путь к директории
 * @throws Exception Если произошла ошибка при удалении
 */
function rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    
    // SECURITY FIX: Add error handling for scandir
    $objects = @scandir($dir);
    if ($objects === false) {
        throw new Exception("Не удалось прочитать директорию: $dir");
    }
    
    foreach ($objects as $object) {
        if ($object === '.' || $object === '..') {
            continue;
        }
        
        $path = $dir . '/' . $object;
        
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            if (!@unlink($path)) {
                error_log("Warning: Failed to delete file: $path");
            }
        }
    }
    
    if (!@rmdir($dir)) {
        throw new Exception("Не удалось удалить директорию: $dir");
    }
}

/**
 * Создание PHP установщика
 *
 * @param string $outputFile Путь к выходному файлу
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function createInstaller($outputFile)
{
    $installerContent = '<?php
/**
 * Установщик сайта
 * Создано автоматически системой резервного копирования
 */

// Проверка отправки формы
if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\') {
    $dbHost = $_POST[\'db_host\'] ?? \'\';
    $dbName = $_POST[\'db_name\'] ?? \'\';
    $dbUser = $_POST[\'db_user\'] ?? \'\';
    $dbPass = $_POST[\'db_pass\'] ?? \'\';
    
    $errors = [];
    
    // Проверка заполнения полей
    if (empty($dbHost)) $errors[] = \'Укажите хост базы данных\';
    if (empty($dbName)) $errors[] = \'Укажите имя базы данных\';
    if (empty($dbUser)) $errors[] = \'Укажите пользователя базы данных\';
    
    // Валидация имени базы данных (только буквы, цифры и подчёркивание)
    if (!empty($dbName) && !preg_match(\'/^[a-zA-Z0-9_]+$/\', $dbName)) {
        $errors[] = \'Имя базы данных может содержать только буквы, цифры и подчёркивание\';
    }
    
    // Валидация хоста (базовая проверка)
    if (!empty($dbHost) && !preg_match(\'/^[a-zA-Z0-9.\\-_]+$/\', $dbHost)) {
        $errors[] = \'Недопустимый хост базы данных\';
    }
    
    if (empty($errors)) {
        try {
            // Подключение к базе данных
            $dsn = "mysql:host=$dbHost;charset=utf8";
            $pdo = new PDO($dsn, $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // SECURITY FIX: Properly escape database name using identifier quoting
            // Since MySQL doesn\'t support parameterized identifiers, we use validated input
            // Input is already validated by regex to contain only [a-zA-Z0-9_]
            $escapedDbName = str_replace(\'`\', \'``\', $dbName);
            
            // Создание базы данных если не существует
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$escapedDbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$escapedDbName`");
            
            // Импорт SQL файла с проверкой успешности
            $sqlFile = __DIR__ . \'/database.sql\';
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                if ($sql !== false && !empty($sql)) {
                    // Разбиваем на отдельные запросы для лучшей обработки ошибок
                    $statements = array_filter(array_map(\'trim\', explode(\';\', $sql)));
                    foreach ($statements as $statement) {
                        if (!empty($statement)) {
                            $pdo->exec($statement . \';\');
                        }
                    }
                } else {
                    throw new Exception(\'Не удалось прочитать SQL файл\');
                }
            }
            
            // Копирование файлов с валидацией пути
            $filesDir = __DIR__ . \'/files\';
            $targetDir = realpath(__DIR__ . \'/..\');
            if ($targetDir === false) {
                throw new Exception(\'Не удалось определить целевую директорию\');
            }
            if (is_dir($filesDir)) {
                recursiveCopy($filesDir, $targetDir);
            }
            
            // SECURITY FIX: Properly escape credentials before writing to PHP file
            // Use var_export for complete protection against code injection
            // var_export ensures values are properly escaped for PHP context
            $escapedHostForConfig = var_export($dbHost, true);
            $escapedDbNameForConfig = var_export($dbName, true);
            $escapedUserForConfig = var_export($dbUser, true);
            $escapedPassForConfig = var_export($dbPass, true);
            
            // Обновление файла конфигурации базы данных
            $configFile = __DIR__ . \'/../connect/db.php\';
            if (file_exists($configFile)) {
                $configContent = file_get_contents($configFile);
                $configContent = preg_replace(\'/private static \\\$host\\s*=\\s*[^;]+;/\', "private static \\\$host = $escapedHostForConfig;", $configContent);
                $configContent = preg_replace(\'/private static \\\$dbName\\s*=\\s*[^;]+;/\', "private static \\\$dbName = $escapedDbNameForConfig;", $configContent);
                $configContent = preg_replace(\'/private static \\\$userName\\s*=\\s*[^;]+;/\', "private static \\\$userName = $escapedUserForConfig;", $configContent);
                $configContent = preg_replace(\'/private static \\\$password\\s*=\\s*[^;]+;/\', "private static \\\$password = $escapedPassForConfig;", $configContent);
                file_put_contents($configFile, $configContent);
            }
            
            $success = true;
            $successMessage = \'Сайт успешно установлен!\';
            
        } catch (PDOException $e) {
            $errors[] = \'Ошибка при установке: \' . $e->getMessage();
        } catch (Exception $e) {
            $errors[] = \'Ошибка при установке: \' . $e->getMessage();
        }
    }
}

/**
 * Рекурсивное копирование директории
 */
function recursiveCopy($source, $dest) {
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }
    
    $dir = opendir($source);
    while (($file = readdir($dir)) !== false) {
        if ($file === \'.\' || $file === \'..\') continue;
        
        $sourcePath = $source . \'/\' . $file;
        $destPath = $dest . \'/\' . $file;
        
        if (is_dir($sourcePath)) {
            recursiveCopy($sourcePath, $destPath);
        } else {
            copy($sourcePath, $destPath);
        }
    }
    closedir($dir);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка сайта</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="bi bi-cloud-download"></i> Установка сайта
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($success) && $success): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle"></i>
                                <?= htmlspecialchars($successMessage) ?>
                            </div>
                            <p class="text-center">
                                <a href="../admin/" class="btn btn-primary">
                                    <i class="bi bi-house-door"></i> Перейти в админ-панель
                                </a>
                            </p>
                        <?php else: ?>
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <strong>Ошибки:</strong>
                                    <ul class="mb-0 mt-2">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?= htmlspecialchars($error) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            
                            <p class="text-muted mb-4">
                                Введите данные для подключения к базе данных на новом хостинге:
                            </p>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="db_host" class="form-label">
                                        <i class="bi bi-server"></i> Хост базы данных
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="db_host" 
                                           name="db_host" 
                                           value="<?= htmlspecialchars($_POST[\'db_host\'] ?? \'localhost\') ?>" 
                                           required>
                                    <small class="form-text text-muted">Обычно: localhost</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="db_name" class="form-label">
                                        <i class="bi bi-database"></i> Имя базы данных
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="db_name" 
                                           name="db_name" 
                                           value="<?= htmlspecialchars($_POST[\'db_name\'] ?? \'\') ?>" 
                                           required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="db_user" class="form-label">
                                        <i class="bi bi-person"></i> Пользователь базы данных
                                    </label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="db_user" 
                                           name="db_user" 
                                           value="<?= htmlspecialchars($_POST[\'db_user\'] ?? \'\') ?>" 
                                           required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="db_pass" class="form-label">
                                        <i class="bi bi-key"></i> Пароль базы данных
                                    </label>
                                    <input type="password" 
                                           class="form-control" 
                                           id="db_pass" 
                                           name="db_pass" 
                                           value="<?= htmlspecialchars($_POST[\'db_pass\'] ?? \'\') ?>">
                                    <small class="form-text text-muted">Оставьте пустым если нет пароля</small>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-download"></i> Установить сайт
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
    
    if (file_put_contents($outputFile, $installerContent) === false) {
        return [
            'success' => false,
            'message' => 'Не удалось создать установщик'
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Установщик успешно создан'
    ];
}

/**
 * Создание README файла
 *
 * @param string $outputFile Путь к выходному файлу
 * @param array $tables Массив таблиц
 * @param array $folders Массив папок
 */
function createReadme($outputFile, $tables, $folders)
{
    $content = "========================================\n";
    $content .= "РЕЗЕРВНАЯ КОПИЯ САЙТА\n";
    $content .= "========================================\n\n";
    $content .= "Дата создания: " . date('Y-m-d H:i:s') . "\n\n";
    
    $content .= "СОДЕРЖИМОЕ АРХИВА:\n";
    $content .= "- database.sql - дамп базы данных\n";
    $content .= "- files/ - папки и файлы сайта\n";
    $content .= "- install.php - установщик для нового хостинга\n\n";
    
    $content .= "ТАБЛИЦЫ В РЕЗЕРВНОЙ КОПИИ (" . count($tables) . "):\n";
    foreach ($tables as $table) {
        $content .= "  - " . $table . "\n";
    }
    
    $content .= "\nПАПКИ В РЕЗЕРВНОЙ КОПИИ (" . count($folders) . "):\n";
    foreach ($folders as $folder) {
        $content .= "  - " . $folder . "\n";
    }
    
    $content .= "\n========================================\n";
    $content .= "ИНСТРУКЦИЯ ПО УСТАНОВКЕ:\n";
    $content .= "========================================\n\n";
    $content .= "1. Распакуйте архив на новом хостинге\n";
    $content .= "2. Откройте в браузере файл install.php\n";
    $content .= "3. Введите данные подключения к базе данных\n";
    $content .= "4. Нажмите \"Установить сайт\"\n";
    $content .= "5. После успешной установки удалите файл install.php\n\n";
    
    file_put_contents($outputFile, $content);
}

/**
 * Создание ZIP архива
 *
 * @param string $sourceDir Исходная директория
 * @param string $zipFile Путь к выходному ZIP файлу
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function createZipArchive($sourceDir, $zipFile)
{
    if (!extension_loaded('zip')) {
        return [
            'success' => false,
            'message' => 'Расширение ZIP не установлено на сервере'
        ];
    }
    
    $zip = new ZipArchive();
    
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return [
            'success' => false,
            'message' => 'Не удалось создать ZIP архив'
        ];
    }
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($files as $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }
    
    $zip->close();
    
    return [
        'success' => true,
        'message' => 'ZIP архив успешно создан'
    ];
}

/**
 * Получение списка резервных копий
 *
 * @return array Список резервных копий
 */
function getBackupsList()
{
    // Определяем путь к директории с резервными копиями в ../backups
    // Используем realpath для корректного разрешения символических ссылок
    // и работы независимо от структуры сервера (с public_html или без)
    $rootPath = realpath(__DIR__ . '/../../..');
    // Изменяем путь для хранения резервных копий на уровень выше корня сайта
    $backupDir = dirname($rootPath) . '/backups';
    
    $backups = [];
    
    if (!is_dir($backupDir)) {
        return $backups;
    }
    
    $files = scandir($backupDir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $filePath = $backupDir . '/' . $file;
        
        if (is_file($filePath) && isValidBackupFileName($file)) {
            $backups[] = [
                'name' => $file,
                'size' => filesize($filePath),
                'date' => filemtime($filePath),
                'path' => $filePath
            ];
        }
    }
    
    // Сортируем по дате создания (новые сверху)
    usort($backups, function($a, $b) {
        return $b['date'] - $a['date'];
    });
    
    return $backups;
}

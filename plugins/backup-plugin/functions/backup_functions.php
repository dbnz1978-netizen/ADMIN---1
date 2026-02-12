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
        // Создаём директорию для хранения резервных копий
        $backupDir = __DIR__ . '/../../../../admin/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Генерируем уникальное имя для резервной копии
        $timestamp = date('Y-m-d_H-i-s');
        $backupName = 'backup_' . $timestamp;
        $tempDir = $backupDir . '/' . $backupName;
        
        if (!mkdir($tempDir, 0755, true)) {
            return [
                'success' => false,
                'message' => 'Не удалось создать временную директорию для резервной копии'
            ];
        }
        
        // 1. Экспортируем базу данных
        $sqlFile = $tempDir . '/database.sql';
        $sqlResult = exportDatabase($pdo, $selectedTables, $sqlFile);
        
        if (!$sqlResult['success']) {
            rrmdir($tempDir);
            return $sqlResult;
        }
        
        // 2. Копируем выбранные папки
        // Всегда добавляем папку admin
        if (!in_array('admin', $selectedFolders)) {
            $selectedFolders[] = 'admin';
        }
        
        $rootPath = realpath(__DIR__ . '/../../../../');
        $filesDir = $tempDir . '/files';
        mkdir($filesDir, 0755, true);
        
        foreach ($selectedFolders as $folder) {
            $sourcePath = $rootPath . '/' . $folder;
            $destPath = $filesDir . '/' . $folder;
            
            if (is_dir($sourcePath)) {
                recursiveCopy($sourcePath, $destPath);
            }
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
        
        // 6. Удаляем временную директорию
        rrmdir($tempDir);
        
        // 7. Возвращаем результат с ссылкой на скачивание
        $downloadUrl = '../../../admin/backups/' . $backupName . '.zip';
        
        return [
            'success' => true,
            'message' => 'Резервная копия успешно создана',
            'download_url' => $downloadUrl
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
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function exportDatabase($pdo, $tables, $outputFile)
{
    try {
        $output = "-- Резервная копия базы данных\n";
        $output .= "-- Создано: " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $output .= "SET time_zone = \"+00:00\";\n\n";
        
        foreach ($tables as $table) {
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
        }
        
        // Записываем в файл
        if (file_put_contents($outputFile, $output) === false) {
            return [
                'success' => false,
                'message' => 'Не удалось записать SQL файл'
            ];
        }
        
        return [
            'success' => true,
            'message' => 'База данных успешно экспортирована'
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Ошибка при экспорте базы данных: ' . $e->getMessage()
        ];
    }
}

/**
 * Рекурсивное копирование директории
 *
 * @param string $source Исходная директория
 * @param string $dest Целевая директория
 */
function recursiveCopy($source, $dest)
{
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }
    
    $dir = opendir($source);
    
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $sourcePath = $source . '/' . $file;
        $destPath = $dest . '/' . $file;
        
        if (is_dir($sourcePath)) {
            recursiveCopy($sourcePath, $destPath);
        } else {
            copy($sourcePath, $destPath);
        }
    }
    
    closedir($dir);
}

/**
 * Рекурсивное удаление директории
 *
 * @param string $dir Путь к директории
 */
function rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    
    $objects = scandir($dir);
    
    foreach ($objects as $object) {
        if ($object === '.' || $object === '..') {
            continue;
        }
        
        $path = $dir . '/' . $object;
        
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            unlink($path);
        }
    }
    
    rmdir($dir);
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
    
    if (empty($errors)) {
        try {
            // Подключение к базе данных
            $dsn = "mysql:host=$dbHost;charset=utf8";
            $pdo = new PDO($dsn, $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Создание базы данных если не существует
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbName`");
            
            // Импорт SQL файла
            $sqlFile = __DIR__ . \'/database.sql\';
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                $pdo->exec($sql);
            }
            
            // Копирование файлов
            $filesDir = __DIR__ . \'/files\';
            if (is_dir($filesDir)) {
                recursiveCopy($filesDir, __DIR__ . \'/..\');
            }
            
            // Обновление файла конфигурации базы данных
            $configFile = __DIR__ . \'/../connect/db.php\';
            if (file_exists($configFile)) {
                $configContent = file_get_contents($configFile);
                $configContent = preg_replace(\'/private static \\\$host\\s*=\\s*[\\\'\\"][^\\\'\\"]*[\\\'\\"];/\', "private static \\\$host = \'$dbHost\';\", $configContent);
                $configContent = preg_replace(\'/private static \\\$dbName\\s*=\\s*[\\\'\\"][^\\\'\\"]*[\\\'\\"];/\', "private static \\\$dbName = \'$dbName\';\", $configContent);
                $configContent = preg_replace(\'/private static \\\$userName\\s*=\\s*[\\\'\\"][^\\\'\\"]*[\\\'\\"];/\', "private static \\\$userName = \'$dbUser\';\", $configContent);
                $configContent = preg_replace(\'/private static \\\$password\\s*=\\s*[\\\'\\"][^\\\'\\"]*[\\\'\\"];/\', "private static \\\$password = \'$dbPass\';\", $configContent);
                file_put_contents($configFile, $configContent);
            }
            
            $success = true;
            $successMessage = \'Сайт успешно установлен!\';
            
        } catch (PDOException $e) {
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

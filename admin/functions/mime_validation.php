<?php
/**
 * Файл: /admin/functions/mime_validation.php
 * 
 * Утилиты для проверки MIME-типов файлов
 */

// Запретить прямой доступ ко всем .php файлам
if (!defined('APP_ACCESS')) {
    define('APP_ACCESS', true);
}

/**
 * Проверяет MIME-тип файла с использованием нескольких методов для безопасности
 * 
 * @param string $filePath Путь к файлу для проверки
 * @param array $allowedMimeTypes Разрешенные MIME-типы
 * @return array ['valid' => bool, 'real_mime_type' => string, 'error' => string]
 */
function validateMimeType($filePath, $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']) {
    // Проверяем существование файла
    if (!file_exists($filePath)) {
        return [
            'valid'          => false,
            'real_mime_type' => '',
            'error'          => 'Файл не существует: ' . $filePath,
        ];
    }

    // Проверяем, что это действительно файл (а не директория)
    if (!is_file($filePath)) {
        return [
            'valid'          => false,
            'real_mime_type' => '',
            'error'          => 'Указанный путь не является файлом: ' . $filePath,
        ];
    }

    // Проверяем, что файл не пустой
    if (filesize($filePath) <= 0) {
        return [
            'valid'          => false,
            'real_mime_type' => '',
            'error'          => 'Файл пустой: ' . $filePath,
        ];
    }

    // Получаем реальный MIME-тип файла с помощью finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return [
            'valid'          => false,
            'real_mime_type' => '',
            'error'          => 'Не удалось инициализировать проверку MIME-типа',
        ];
    }

    $realMimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);

    if (!$realMimeType) {
        return [
            'valid'          => false,
            'real_mime_type' => '',
            'error'          => 'Не удалось определить MIME-тип файла: ' . $filePath,
        ];
    }

    // Проверяем, разрешен ли этот MIME-тип
    if (!in_array($realMimeType, $allowedMimeTypes)) {
        return [
            'valid'          => false,
            'real_mime_type' => $realMimeType,
            'error'          => 'Недопустимый MIME-тип файла: ' . $realMimeType . '. Разрешены: '
                . implode(', ', $allowedMimeTypes),
        ];
    }

    // Дополнительная проверка с использованием getimagesize() для изображений
    if (strpos($realMimeType, 'image/') === 0) {
        $imageInfo = getimagesize($filePath);
        if ($imageInfo === false) {
            return [
                'valid'          => false,
                'real_mime_type' => $realMimeType,
                'error'          => 'Файл не является действительным изображением: ' . $filePath,
            ];
        }
        
        // Проверяем, соответствует ли MIME-тип, определенный getimagesize, тому что определил finfo
        $imageMimeFromGetimagesize = $imageInfo['mime'];
        if ($realMimeType !== $imageMimeFromGetimagesize) {
            return [
                'valid'          => false,
                'real_mime_type' => $realMimeType,
                'error'          => 'Несоответствие MIME-типов: finfo указал ' . $realMimeType
                    . ', а getimagesize - ' . $imageMimeFromGetimagesize,
            ];
        }
    }

    return [
        'valid'          => true,
        'real_mime_type' => $realMimeType,
        'error'          => '',
    ];
}


/**
 * Проверяет расширение файла
 * 
 * @param string $filePath Путь к файлу
 * @param array $allowedExtensions Разрешенные расширения (без точки)
 * @return bool
 */
function validateFileExtension($filePath, $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp']) {
    $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    return in_array($fileExtension, $allowedExtensions);
}


/**
 * Проверяет безопасность пути к файлу (защита от directory traversal)
 * 
 * @param string $filePath Путь к файлу
 * @param string $baseDirectory Базовая директория, в которой должны находиться файлы
 * @return bool
 */
function validateFilePathSecurity($filePath, $baseDirectory) {
    // Очищаем потенциальные попытки directory traversal
    $cleanPath = str_replace(['../', '..\\'], '', $filePath);
    
    // Для проверки безопасности пути используем нормализацию без проверки существования файла
    $normalizedFilePath = preg_replace('/[\/]+/', '/', $cleanPath);
    $normalizedBaseDir = preg_replace('/[\/]+/', '/', rtrim($baseDirectory, '/') . '/');
    
    // Проверяем, что нормализованный путь начинается с базовой директории
    return strpos($normalizedFilePath, $normalizedBaseDir) === 0;
}

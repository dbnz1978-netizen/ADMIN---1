<?php
/**
 * Файл: /admin/functions/image_utils.php
 * 
 * Утилиты для работы с изображениями
 * 
 * Этот файл содержит вспомогательные функции для:
 * - Валидации изображений
 * - Конвертации форматов
 * - Изменения размера
 * - Оптимизации
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

class ImageUtils {
    
    /**
     * Валидация файла изображения
     * 
     * @param array $file Массив файла из $_FILES
     * @param int $maxSize Максимальный размер файла в байтах (по умолчанию 10MB)
     * @return array Результат валидации
     */
    public static function validateImage($file, $maxSize = 10485760) {
        $result = [
            'valid' => false,
            'errors' => []
        ];
        
        // Проверка на наличие ошибок загрузки
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'Размер файла превышает разрешенный директивой upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'Размер файла превышает разрешенный значением MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'Файл был загружен только частично',
                UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
                UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
                UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
                UPLOAD_ERR_EXTENSION => 'PHP расширение остановило загрузку файла'
            ];
            
            $result['errors'][] = $errorMessages[$file['error']] ?? 'Неизвестная ошибка загрузки';
            return $result;
        }
        
        // Проверка типа файла с использованием нескольких методов для безопасности
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        
        // Проверка через finfo (наиболее надежный способ)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($actualMimeType, $allowedTypes)) {
            $result['errors'][] = 'Недопустимый тип файла. Разрешены: JPEG, PNG, GIF, WebP';
            return $result;
        }
        
        // Дополнительная проверка через getimagesize
        $imageInfo = getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            $result['errors'][] = 'Файл не является действительным изображением';
            return $result;
        }
        
        // Сравниваем MIME-типы из finfo и getimagesize для дополнительной безопасности
        if ($actualMimeType !== $imageInfo['mime']) {
            $result['errors'][] = 'Обнаружено несоответствие MIME-типа файла';
            return $result;
        }
        
        // Проверка размера файла
        if ($file['size'] > $maxSize) {
            $result['errors'][] = 'Размер файла превышает допустимый лимит (' . self::formatBytes($maxSize) . ')';
            return $result;
        }
        
        $result['valid'] = true;
        return $result;
    }
    
    /**
     * Конвертация изображения в WebP формат
     * 
     * @param string $sourcePath Путь к исходному файлу
     * @param string $destinationPath Путь для сохранения WebP файла
     * @param int $quality Качество (0-100)
     * @return bool Успешность конвертации
     */
    public static function convertToWebP($sourcePath, $destinationPath, $quality = 80) {
        if (!file_exists($sourcePath)) {
            error_log('Исходный файл не найден для преобразования в WebP: ' . $sourcePath);
            return false;
        }
        
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            error_log('Не удается получить информацию об изображении для преобразования в WebP: ' . $sourcePath);
            return false;
        }

        $mimeType = $imageInfo['mime'];
        
        switch ($mimeType) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $image = imagecreatefrompng($sourcePath);
                // Сохраняем прозрачность для PNG
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                // Если уже WebP, просто копируем
                return copy($sourcePath, $destinationPath);
            default:
                error_log('Неподдерживаемый MIME-тип для преобразования в WebP: ' . $mimeType);
                return false;
        }

        if (!$image) {
            error_log('Не удается создать графический ресурс для преобразования в WebP: ' . $sourcePath);
            return false;
        }

        // Конвертируем в WebP
        $result = imagewebp($image, $destinationPath, $quality);
        imagedestroy($image);
        
        if (!$result) {
            error_log('Не удалось выполнить преобразование WebP: ' . $sourcePath . ' -> ' . $destinationPath);
        }
        
        return $result;
    }
    
    /**
     * Изменение размера изображения
     * 
     * @param string $sourcePath Путь к исходному файлу
     * @param string $destinationPath Путь для сохранения
     * @param int $targetWidth Ширина
     * @param int $targetHeight Высота
     * @param string $mode Режим ('contain' или 'cover')
     * @param int $quality Качество (0-100)
     * @return bool Успешность изменения размера
     */
    public static function resizeImage($sourcePath, $destinationPath, $targetWidth, $targetHeight, $mode = 'contain', $quality = 75) {
        if (!file_exists($sourcePath)) {
            error_log('Исходный файл не найден для изменения размера: ' . $sourcePath);
            return false;
        }
        
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            error_log('Не удается получить информацию об изображении для изменения размера: ' . $sourcePath);
            return false;
        }

        list($originalWidth, $originalHeight) = $imageInfo;
        
        // Для режима 'contain' - вписываем с сохранением пропорций
        if ($mode === 'contain') {
            $ratioOriginal = $originalWidth / $originalHeight;
            $ratioTarget = $targetWidth / $targetHeight;
            
            if ($ratioTarget > $ratioOriginal) {
                $newWidth = $targetHeight * $ratioOriginal;
                $newHeight = $targetHeight;
            } else {
                $newWidth = $targetWidth;
                $newHeight = $targetWidth / $ratioOriginal;
            }
            
            $srcX = 0;
            $srcY = 0;
            $srcW = $originalWidth;
            $srcH = $originalHeight;
            
            $dstX = round(($targetWidth - $newWidth) / 2);
            $dstY = round(($targetHeight - $newHeight) / 2);
            $dstW = round($newWidth);
            $dstH = round($newHeight);
            
        } 
        // Для режима 'cover' - заполняем область без искажений (с обрезкой)
        else if ($mode === 'cover') {
            $ratioOriginal = $originalWidth / $originalHeight;
            $ratioTarget = $targetWidth / $targetHeight;
            
            if ($ratioTarget > $ratioOriginal) {
                // Обрезаем по высоте
                $srcH = $originalWidth / $ratioTarget;
                $srcY = ($originalHeight - $srcH) / 2;
                $srcX = 0;
                $srcW = $originalWidth;
            } else {
                // Обрезаем по ширине
                $srcW = $originalHeight * $ratioTarget;
                $srcX = ($originalWidth - $srcW) / 2;
                $srcY = 0;
                $srcH = $originalHeight;
            }
            
            $dstX = 0;
            $dstY = 0;
            $dstW = $targetWidth;
            $dstH = $targetHeight;
        } else {
            // Если режим не поддерживается, используем contain
            return self::resizeImage($sourcePath, $destinationPath, $targetWidth, $targetHeight, 'contain', $quality);
        }
        
        // Создаем новое изображение
        $newImage = imagecreatetruecolor($targetWidth, $targetHeight);
        
        // Сохраняем прозрачность для PNG/GIF
        if ($imageInfo['mime'] == 'image/png' || $imageInfo['mime'] == 'image/gif') {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        } else {
            // Для JPEG создаем белый фон
            $white = imagecolorallocate($newImage, 255, 255, 255);
            imagefill($newImage, 0, 0, $white);
        }
        
        // Загружаем исходное изображение
        switch ($imageInfo['mime']) {
            case 'image/jpeg':
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                $sourceImage = imagecreatefromwebp($sourcePath);
                break;
            default:
                error_log('Неподдерживаемый MIME-тип для изменения размера: ' . $imageInfo['mime']);
                return false;
        }
        
        if (!$sourceImage) {
            error_log('Не удается создать исходный ресурс изображения для изменения размера: ' . $sourcePath);
            return false;
        }
        
        // Ресайзим изображение
        imagecopyresampled($newImage, $sourceImage, $dstX, $dstY, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($sourceImage);
        
        // Сохраняем в WebP
        $result = imagewebp($newImage, $destinationPath, $quality);
        imagedestroy($newImage);
        
        if (!$result) {
            error_log('Не удалось создать WebP с измененным размером: ' . $destinationPath);
        }
        
        return $result;
    }
    
    /**
     * Получение информации об изображении
     * 
     * @param string $filePath Путь к файлу
     * @return array|null Информация об изображении или null
     */
    public static function getImageInfo($filePath) {
        if (!file_exists($filePath)) {
            return null;
        }
        
        $imageInfo = getimagesize($filePath);
        if (!$imageInfo) {
            return null;
        }
        
        return [
            'width' => $imageInfo[0],
            'height' => $imageInfo[1],
            'mimetype' => $imageInfo['mime'],
            'size' => filesize($filePath),
            'extension' => self::getMimeTypeExtension($imageInfo['mime'])
        ];
    }
    
    /**
     * Получение расширения файла по MIME-типу
     * 
     * @param string $mimeType MIME-тип
     * @return string Расширение файла
     */
    public static function getMimeTypeExtension($mimeType) {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff'
        ];
        
        return $extensions[$mimeType] ?? 'неизвестно';
    }
    
    /**
     * Форматирование байтов в человекочитаемый вид
     * 
     * @param int $bytes Число байтов
     * @param int $precision Точность после запятой
     * @return string Форматированная строка
     */
    public static function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Создание миниатюры для быстрого предварительного просмотра
     * 
     * @param string $sourcePath Путь к исходному файлу
     * @param string $destinationPath Путь для сохранения миниатюры
     * @param int $maxWidth Максимальная ширина миниатюры
     * @param int $maxHeight Максимальная высота миниатюры
     * @param int $quality Качество (0-100)
     * @return bool Успешность создания миниатюры
     */
    public static function createThumbnail($sourcePath, $destinationPath, $maxWidth = 150, $maxHeight = 150, $quality = 80) {
        if (!file_exists($sourcePath)) {
            error_log('Исходный файл не найден для создания миниатюры: ' . $sourcePath);
            return false;
        }
        
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            error_log('Не удается получить информацию об изображении для создания миниатюры: ' . $sourcePath);
            return false;
        }

        list($originalWidth, $originalHeight) = $imageInfo;
        
        // Вычисляем новые размеры с сохранением пропорций
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        $newWidth = (int)($originalWidth * $ratio);
        $newHeight = (int)($originalHeight * $ratio);
        
        // Создаем новое изображение
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        
        // Сохраняем прозрачность для PNG/GIF
        if ($imageInfo['mime'] == 'image/png' || $imageInfo['mime'] == 'image/gif') {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
            $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
            imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
        } else {
            // Для JPEG создаем белый фон
            $white = imagecolorallocate($newImage, 255, 255, 255);
            imagefill($newImage, 0, 0, $white);
        }
        
        // Загружаем исходное изображение
        switch ($imageInfo['mime']) {
            case 'image/jpeg':
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                $sourceImage = imagecreatefromwebp($sourcePath);
                break;
            default:
                error_log('Неподдерживаемый MIME-тип для создания миниатюры: ' . $imageInfo['mime']);
                return false;
        }
        
        if (!$sourceImage) {
            error_log('Не удается создать исходный ресурс изображения для создания миниатюры: ' . $sourcePath);
            return false;
        }
        
        // Ресайзим изображение
        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
        imagedestroy($sourceImage);
        
        // Сохраняем в WebP
        $result = imagewebp($newImage, $destinationPath, $quality);
        imagedestroy($newImage);
        
        if (!$result) {
            error_log('Не удалось создать WebP миниатюру: ' . $destinationPath);
        }
        
        return $result;
    }
    
    /**
     * Создание безопасного имени файла
     * 
     * @param string $filename Оригинальное имя файла
     * @return string Безопасное имя файла
     */
    public static function sanitizeFilename($filename) {
        // Удаляем опасные символы
        $filename = preg_replace('~[^\\pL0-9_]+~u', '-', $filename);
        
        // Ограничиваем длину
        $filename = mb_substr($filename, 0, 200);
        
        // Удаляем дублирующиеся дефисы
        $filename = preg_replace('/-+/', '-', $filename);
        
        // Удаляем дефисы в начале и конце
        $filename = trim($filename, '-');
        
        return $filename ?: 'file';
    }
}
<?php

/**
 * Название файла:      upload-handler.php
 * Назначение:          Обработчик загрузки изображений через AJAX.
 *                      Выполняет валидацию файлов (формат, размер, тип),
 *                      конвертирует изображения в формат WebP для оптимизации,
 *                      создает несколько версий изображений согласно настройкам размеров,
 *                      сохраняет информацию о файлах в базу данных,
 *                      возвращает JSON ответ с результатом обработки.
 *                      
 *                      Особенности обработки:
 *                      - Автоматическая конвертация JPEG, PNG, GIF в WebP
 *                      - Создание ресайзнутых версий: thumbnail, small, medium, large
 *                      - Поддержка режимов ресайза: 'contain' (вписать) и 'cover' (заполнить)
 *                      - Организация файлов по дате (год/месяц/)
 *                      - Валидация прав доступа к директориям
 *                      - Обработка ошибок с откатом изменений
 *                      - Защита от дублирования через уникальные имена файлов
 *                      
 *                      Параметры запроса:
 *                      - user_id: ID пользователя для привязки файла
 *                      - file: загружаемый файл изображения
 *                      - image_sizes: JSON с настройками размеров для ресайза
 *                      
 *                      Возвращаемый JSON:
 *                      - success: true/false результат операции
 *                      - message: текстовое сообщение
 *                      - file_id: ID созданной записи в БД
 *                      - file_data: информация о файле из БД
 *                      - webp_versions: пути к созданным WebP файлам
 *                      - created_sizes: список созданных размеров
 *                      
 *                      Требования:
 *                      - Подключение к базе данных через db.php
 *                      - Директория uploads/ с правами на запись
 *                      - Поддержка GD library для работы с изображениями
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// ИНИЦИАЛИЗАЦИЯ
// ========================================

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// ========================================
// КОНФИГУРАЦИЯ СКРИПТА
// ========================================

$config = [
    'display_errors'   => false,   // Включение отображения ошибок (true/false)
    'set_encoding'     => true,    // Включение кодировки UTF-8
    'db_connect'       => true,    // Подключение к базе данных
    'auth_check'       => true,    // Подключение функций авторизации
    'file_log'         => true,    // Подключение системы логирования
    'sanitization'     => true,    // Подключение валидации/экранирования
    'csrf_token'       => true,    // Генерация и проверка CSRF-токена
    'image_sizes'      => true,    // Подключение модуля управления размерами изображений
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);

if ($adminData === false) {
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    exit;
}

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

// ========================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ========================================


/**
 * Отправка JSON-ответа с результатом операции
 *
 * @param bool   $success  true при успешной операции, false при ошибке
 * @param array  $data     Массив данных для ответа
 * @return void
 */
function sendResponse(bool $success, array $data = []): void
{
    $response = [
        'success' => $success
    ];

    if ($success) {
        $response = array_merge($response, $data);
    } else {
        // При ошибке сохраняем 'error' и все остальные поля
        $response['error'] = $data['error'] ?? 'Неизвестная ошибка';
        unset($data['error']); // удаляем, чтобы не дублировать
        $response = array_merge($response, $data);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/**
 * Конвертация изображения в формат WebP
 *
 * @param string  $sourcePath       Путь к исходному файлу
 * @param string  $destinationPath  Путь для сохранения WebP
 * @param int     $quality          Качество WebP (0-100)
 * @return bool   true при успехе, false при ошибке
 */
function convertToWebP(string $sourcePath, string $destinationPath, int $quality = 80): bool
{
    if (!file_exists($sourcePath)) {
        logEvent('Source file not found for WebP conversion: ' . $sourcePath, LOG_ERROR_ENABLED, 'error');
        return false;
    }
    
    $imageInfo = getimagesize($sourcePath);
    
    if (!$imageInfo) {
        logEvent('Не удается получить информацию об изображении для преобразования в WebP: ' . $sourcePath, LOG_ERROR_ENABLED, 'error');
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
        case 'image/avif':
            // Проверяем наличие поддержки AVIF
            if (function_exists('imagecreatefromavif')) {
                $image = imagecreatefromavif($sourcePath);
            } else {
                logEvent('Поддержка AVIF не установлена для преобразования в WebP: ' . $sourcePath, LOG_ERROR_ENABLED, 'error');
                return false;
            }
            break;
        case 'image/jxl':
            // Проверяем наличие поддержки JPEG XL
            if (function_exists('imagecreatefromjxl')) {
                $image = imagecreatefromjxl($sourcePath);
            } else {
                logEvent('Поддержка JPEG XL не установлена для преобразования в WebP: ' . $sourcePath, LOG_ERROR_ENABLED, 'error');
                return false;
            }
            break;
        default:
            logEvent('Неподдерживаемый MIME-тип для преобразования в WebP: ' . $mimeType, LOG_ERROR_ENABLED, 'error');
            return false;
    }

    if (!$image) {
        logEvent('Не удается создать графический ресурс для преобразования в WebP: ' . $sourcePath, LOG_ERROR_ENABLED, 'error');
        return false;
    }

    // Конвертируем в WebP
    $result = imagewebp($image, $destinationPath, $quality);
    imagedestroy($image);
    
    if (!$result) {
        logEvent('Не удалось выполнить преобразование WebP: ' . $sourcePath . ' -> ' . $destinationPath, LOG_ERROR_ENABLED, 'error');
    }
    
    return $result;
}

/**
 * Создание ресайзнутой версии изображения
 *
 * @param string  $sourcePath       Путь к исходному файлу
 * @param string  $destinationPath  Путь для сохранения ресайзнутого изображения
 * @param int|string  $targetWidth  Целевая ширина или 'auto'
 * @param int|string  $targetHeight Целевая высота или 'auto'
 * @param string  $mode             Режим ресайза: 'contain' или 'cover'
 * @param int     $quality          Качество WebP (0-100)
 * @return bool   true при успехе, false при ошибке
 */
function createResizedVersion(string $sourcePath, string $destinationPath, $targetWidth, $targetHeight, string $mode = 'contain', int $quality = 75): bool
{
    if (!file_exists($sourcePath)) {
        logEvent('Исходный файл не найден для изменения размера: ' . $sourcePath, LOG_ERROR_ENABLED, 'error');
        return false;
    }
    
    $imageInfo = getimagesize($sourcePath);
    
    if (!$imageInfo) {
        logEvent('Не удается получить информацию об изображении для изменения размера: ' . $sourcePath, LOG_ERROR_ENABLED, 'error');
        return false;
    }

    list($originalWidth, $originalHeight) = $imageInfo;
    
    // Обрабатываем значения 'auto'
    if ($targetWidth === 'auto' && $targetHeight === 'auto') {
        $targetWidth  = $originalWidth;
        $targetHeight = $originalHeight;
    } elseif ($targetWidth === 'auto') {
        $targetWidth = round($originalWidth * ($targetHeight / $originalHeight));
    } elseif ($targetHeight === 'auto') {
        $targetHeight = round($originalHeight * ($targetWidth / $originalWidth));
    }
    
    // Для режима 'contain' - вписываем с сохранением пропорций
    if ($mode === 'contain') {
        $ratioOriginal = $originalWidth / $originalHeight;
        $ratioTarget   = $targetWidth / $targetHeight;
        
        if ($ratioTarget > $ratioOriginal) {
            $newWidth  = $targetHeight * $ratioOriginal;
            $newHeight = $targetHeight;
        } else {
            $newWidth  = $targetWidth;
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
    else {
        $ratioOriginal = $originalWidth / $originalHeight;
        $ratioTarget   = $targetWidth / $targetHeight;
        
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
            logEvent('Неподдерживаемый MIME-тип для изменения размера: ' . $imageInfo['mime'], LOG_ERROR_ENABLED, 'error');
            return false;
    }
    
    if (!$sourceImage) {
        logEvent('Не удается создать исходный ресурс изображения для изменения размера: ' . $sourcePath, LOG_ERROR_ENABLED, 'error');
        return false;
    }
    
    // Ресайзим изображение
    imagecopyresampled($newImage, $sourceImage, $dstX, $dstY, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH);
    imagedestroy($sourceImage);
    
    // Сохраняем в WebP
    $result = imagewebp($newImage, $destinationPath, $quality);
    imagedestroy($newImage);
    
    if (!$result) {
        logEvent('Не удалось создать WebP с измененным размером: ' . $destinationPath, LOG_ERROR_ENABLED, 'error');
    }
    
    return $result;
}

// ========================================
// ПРОВЕРКА МЕТОДА ЗАПРОСА
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errorMessage = 'Метод не поддерживается: ' . $_SERVER['REQUEST_METHOD'];
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Метод не поддерживается']);
}

// ========================================
// ПРОВЕРКА CSRF-ТОКЕНА
// ========================================

$csrfTokenSession = $_SESSION['csrf_token'] ?? '';
$csrfTokenRequest = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (
    empty($csrfTokenSession) ||
    empty($csrfTokenRequest) ||
    !hash_equals($csrfTokenSession, $csrfTokenRequest)
) {
    $errorMessage = 'CSRF проверка не пройдена (user_id: ' . ($_SESSION['user_id'] ?? 'guest') . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Безопасность запроса не подтверждена. Обновите страницу и попробуйте снова.']);
}

// ========================================
// ПОЛУЧЕНИЕ И ВАЛИДАЦИЯ ВХОДНЫХ ДАННЫХ
// ========================================

// Получаем user_id и файл
$sectionId = trim((string)($_POST['section_id'] ?? ''));
$userId    = (int)$_SESSION['user_id'] ?? 0;
$file      = $_FILES['file'] ?? null;

// Валидация section_id
$sectionResult = validateSectionId($sectionId, 'ID секции');

if (!$sectionResult['valid']) {
    $errorMessage = $sectionResult['error'] ?? 'Некорректный ID секции';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => $errorMessage]);
}

$sectionId = $sectionResult['value'];

// Валидация user_id
if (!$userId || !is_numeric($userId)) {
    $errorMessage = 'Неверный user_id: ' . $userId;
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Неверный user_id']);
}

// ========================================
// ПРОВЕРКА ЛИМИТА ФАЙЛОВ НА ПОЛЬЗОВАТЕЛЯ
// ========================================

$maxFilesPerUser = (int)$_SESSION['max_files_per_user'] ?? 0;

// Применяем проверку ТОЛЬКО если лимит задан как положительное целое число
if (is_int($maxFilesPerUser) && $maxFilesPerUser > 0) {
    try {
        $countSql   = "SELECT COUNT(*) FROM media_files WHERE user_id = ?";
        $countStmt  = $pdo->prepare($countSql);
        $countStmt->execute([$userId]);
        $currentFileCount = (int)$countStmt->fetchColumn();

        if ($currentFileCount >= $maxFilesPerUser) {
            $errorMessage = "Превышен лимит файлов для пользователя (user_id: $userId). Максимум: $maxFilesPerUser, текущее количество: $currentFileCount";
            sendResponse(false, [
                'error'            => "Вы достигли лимита загрузок. Удалите старые файлы.",
                'max_files_per_user' => $maxFilesPerUser
            ]);
        }
    } catch (Exception $e) {
        $errorMessage = 'Ошибка проверки лимита файлов (user_id: ' . $userId . '): ' . $e->getMessage();
        logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
        sendResponse(false, ['error' => 'Служебная ошибка при проверке лимита. Попробуйте позже.']);
    }
}

// ========================================
// ВАЛИДАЦИЯ ФАЙЛА
// ========================================

// Проверка наличия файла
if (!$file) {
    $errorMessage = 'Файл не получен (user_id: ' . $userId . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Файл не получен']);
}

// Проверка ошибок загрузки
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE   => 'Размер файла превышает разрешенный директивой upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE  => 'Размер файла превышает разрешенный значением MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL    => 'Файл был загружен только частично',
        UPLOAD_ERR_NO_FILE    => 'Файл не был загружен',
        UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
        UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
        UPLOAD_ERR_EXTENSION  => 'PHP расширение остановило загрузку файла'
    ];
    
    $errorMessage = $errorMessages[$file['error']] ?? 'Неизвестная ошибка загрузки (код: ' . $file['error'] . ')';
    $logMessage   = 'Ошибка загрузки файла: ' . $errorMessage . ' (user_id: ' . $userId . ', файл: ' . ($file['name'] ?? 'unknown') . ')';
    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => $errorMessage]);
}

// Проверка MIME-типа через fileinfo
$finfo       = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/jxl'];

if (!in_array($detectedMime, $allowedMimes)) {
    $errorMessage = 'Недопустимый MIME-тип файла: ' . $detectedMime . ' (user_id: ' . $userId . ', файл: ' . $file['name'] . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Недопустимый MIME-тип файла. Разрешены: JPEG, PNG, GIF, WebP, AVIF, JPEG XL']);
}

// Проверка оригинального типа из $_FILES
if (!in_array($file['type'], $allowedMimes)) {
    $errorMessage = 'Недопустимый тип файла из $_FILES: ' . $file['type'] . ' (user_id: ' . $userId . ', файл: ' . $file['name'] . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Недопустимый тип файла. Разрешены: JPEG, PNG, GIF, WebP, AVIF, JPEG XL']);
}

// Проверка расширения файла
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'avifs', 'jxl'];
$fileExtension     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($fileExtension, $allowedExtensions)) {
    $errorMessage = 'Недопустимое расширение файла: ' . $fileExtension . ' (user_id: ' . $userId . ', файл: ' . $file['name'] . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Недопустимое расширение файла. Разрешены: JPG, JPEG, PNG, GIF, WebP, AVIF, AVIFS, JXL']);
}

// Проверка, что файл является изображением
$imageInfo = getimagesize($file['tmp_name']);

if ($imageInfo === false) {
    $errorMessage = 'Файл не является допустимым изображением: ' . $file['name'] . ' (user_id: ' . $userId . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Файл не является допустимым изображением']);
}

// Проверка размера файла (максимум 10MB)
$maxFileSize = 10 * 1024 * 1024;

if ($file['size'] > $maxFileSize) {
    $errorMessage = 'Размер файла превышает 10MB: ' . round($file['size'] / 1024 / 1024, 2) . 'MB (user_id: ' . $userId . ', файл: ' . $file['name'] . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Размер файла превышает 10MB']);
}

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК РАЗМЕРОВ ИЗОБРАЖЕНИЙ
// ========================================

$imageSizes = $_SESSION["imageSizes_{$sectionId}"] ?? null;

if (is_string($imageSizes)) {
    $decodedSizes = json_decode($imageSizes, true);
    
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedSizes)) {
        $imageSizes = $decodedSizes;
    }
}

// Если настройки отсутствуют, используем глобальные настройки из БД или значения по умолчанию
if (!is_array($imageSizes) || empty($imageSizes)) {
    $imageSizes = getGlobalImageSizes($pdo);
}

// ========================================
// НАСТРОЙКА ДИРЕКТОРИИ ЗАГРУЗОК
// ========================================

// Определяем директорию для загрузок - используем существующую структуру
$uploadDir     = $_SERVER['DOCUMENT_ROOT'] . '/uploads/';
$dateDir       = date('Y/m/');
$fullUploadDir = $uploadDir . $dateDir;

// Проверка существования основной директории uploads
if (!is_dir($uploadDir)) {
    $errorMessage = 'Директория загрузок не существует: ' . $uploadDir . ' (user_id: ' . $userId . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Директория загрузок не существует. Обратитесь к администратору.']);
}

// Проверка прав на запись в основную директорию
if (!is_writable($uploadDir)) {
    $errorMessage = 'Нет прав на запись в директорию загрузок: ' . $uploadDir . ' (user_id: ' . $userId . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Нет прав на запись в директорию загрузок. Обратитесь к администратору.']);
}

// Создание поддиректории по дате если не существует
if (!is_dir($fullUploadDir)) {
    if (!mkdir($fullUploadDir, 0755, true)) {
        $errorMessage = 'Не удалось создать поддиректорию: ' . $fullUploadDir . ' (user_id: ' . $userId . ')';
        logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
        // Если не удалось создать, пробуем использовать основную директорию
        $fullUploadDir = $uploadDir;
        $dateDir       = '';
    }
}

// Проверка прав на запись в целевую директорию
if (!is_writable($fullUploadDir)) {
    $errorMessage = 'Нет прав на запись в целевую директорию: ' . $fullUploadDir . ' (user_id: ' . $userId . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Нет прав на запись в целевую директорию. Обратитесь к администратору.']);
}

// ========================================
// ГЕНЕРАЦИЯ УНИКАЛЬНОГО ИМЕНИ ФАЙЛА
// ========================================

$baseName      = uniqid() . '_' . time();
$originalName  = $baseName . '.webp';

// ========================================
// ОСНОВНАЯ ЛОГИКА ЗАГРУЗКИ И ОБРАБОТКИ
// ========================================

try {
    
    // ========================================
    // ПОДГОТОВКА ВРЕМЕННОГО ФАЙЛА
    // ========================================
    
    // Временный путь для оригинального файла
    $tempPath = $file['tmp_name'];
    
    // Проверка временного файла
    if (!file_exists($tempPath)) {
        throw new Exception('Временный файл не найден: ' . $tempPath);
    }
    
    // ========================================
    // КОНВЕРТАЦИЯ В WEBP
    // ========================================
    
    // Пути для WebP версий
    $originalWebpPath = $dateDir . $originalName;
    $fileVersions     = [];
    $webpVersions     = ['original' => $originalWebpPath];
    
    // Создаем оригинальную WebP версию
    if (!convertToWebP($tempPath, $uploadDir . $originalWebpPath, 90)) {
        throw new Exception('Ошибка конвертации в WebP для файла: ' . $file['name']);
    }
    
    // Проверка созданного файла
    if (!file_exists($uploadDir . $originalWebpPath)) {
        throw new Exception('Не удалось создать WebP версию: ' . $uploadDir . $originalWebpPath);
    }
    
    // Получение размеров оригинального изображения
    $originalImageInfo = getimagesize($uploadDir . $originalWebpPath);
    
    if (!$originalImageInfo) {
        throw new Exception('Не удалось получить информацию об изображении: ' . $uploadDir . $originalWebpPath);
    }
    
    $originalDimensions = $originalImageInfo[0] . 'x' . $originalImageInfo[1];
    $originalSize       = filesize($uploadDir . $originalWebpPath);
    
    // Добавляем оригинал в версии
    $fileVersions['original'] = [
        'path'       => $originalWebpPath,
        'size'       => $originalSize,
        'dimensions' => $originalDimensions
    ];
    
    // ========================================
    // СОЗДАНИЕ РЕСАЙЗНУТЫХ ВЕРСИЙ
    // ========================================
    
    // Создаем ресайзнутые версии для каждого размера из массива
    foreach ($imageSizes as $sizeName => $sizeConfig) {
        if (!is_array($sizeConfig) || count($sizeConfig) < 3) {
            logEvent('Некорректные настройки размера: ' . $sizeName . ' - ' . json_encode($sizeConfig), LOG_ERROR_ENABLED, 'error');
            continue; // Пропускаем некорректные настройки
        }
        
        list($width, $height, $mode) = $sizeConfig;
        
        $resizedWebpPath = $dateDir . $baseName . '_' . $sizeName . '.webp';
        
        // Создаем ресайзнутую версию
        if (createResizedVersion($tempPath, $uploadDir . $resizedWebpPath, $width, $height, $mode, 85)) {
            $resizedSize       = filesize($uploadDir . $resizedWebpPath);
            $resizedImageInfo  = getimagesize($uploadDir . $resizedWebpPath);
            $resizedDimensions = $resizedImageInfo[0] . 'x' . $resizedImageInfo[1];
            
            $fileVersions[$sizeName] = [
                'path'       => $resizedWebpPath,
                'size'       => $resizedSize,
                'dimensions' => $resizedDimensions,
                'mode'       => $mode
            ];
            
            $webpVersions[$sizeName] = $resizedWebpPath;
        } else {
            // Если не удалось создать ресайзнутую версию, используем оригинал
            logEvent('Не удалось создать ресайзнутую версию: ' . $sizeName . ', используется оригинал', LOG_ERROR_ENABLED, 'error');
            $fileVersions[$sizeName] = [
                'path'       => $originalWebpPath,
                'size'       => $originalSize,
                'dimensions' => $originalDimensions,
                'mode'       => 'original'
            ];
            
            $webpVersions[$sizeName] = $originalWebpPath;
        }
    }
    
    // ========================================
    // СОХРАНЕНИЕ В БАЗУ ДАННЫХ
    // ========================================
    
    // Проверка подключения к базе данных
    if (!isset($pdo)) {
        throw new Exception('Ошибка подключения к базе данных');
    }
    
    // Сохраняем в базу данных
    $sql  = "INSERT INTO media_files (user_id, file_versions, upload_date) VALUES (?, ?, NOW())";
    $stmt = $pdo->prepare($sql);
    
    if (!$stmt->execute([$userId, json_encode($fileVersions)])) {
        $errorInfo = $stmt->errorInfo();
        throw new Exception('Ошибка сохранения в базу данных: ' . ($errorInfo[2] ?? 'unknown error'));
    }
    
    $fileId = $pdo->lastInsertId();
    
    // Получаем информацию о созданном файле для ответа
    $selectSql   = "SELECT * FROM media_files WHERE id = ?";
    $selectStmt  = $pdo->prepare($selectSql);
    $selectStmt->execute([$fileId]);
    $fileData = $selectStmt->fetch(PDO::FETCH_ASSOC);
    
    // ========================================
    // ОТПРАВКА УСПЕШНОГО ОТВЕТА
    // ========================================
    
    sendResponse(true, [
        'message'       => 'Файл успешно загружен',
        'file_id'       => $fileId,
        'file_data'     => $fileData,
        'webp_versions' => $webpVersions,
        'created_sizes' => array_keys($imageSizes)
    ]);
    
} catch (Exception $e) {
    
    // ========================================
    // ОБРАБОТКА ОШИБОК И ОТКАТ ИЗМЕНЕНИЙ
    // ========================================
    
    // Логирование ошибки
    $errorMessage = 'Upload error (user_id: ' . $userId . ', file: ' . ($file['name'] ?? 'unknown') . '): ' . $e->getMessage();
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    
    // Удаление загруженных файлов в случае ошибки
    $filesToDelete = [];
    
    if (isset($originalWebpPath) && file_exists($uploadDir . $originalWebpPath)) {
        $filesToDelete[] = $uploadDir . $originalWebpPath;
    }
    
    // Удаляем все созданные ресайзнутые версии
    if (isset($imageSizes)) {
        foreach ($imageSizes as $sizeName => $sizeConfig) {
            $resizedPath = $uploadDir . $dateDir . $baseName . '_' . $sizeName . '.webp';
            
            if (file_exists($resizedPath)) {
                $filesToDelete[] = $resizedPath;
            }
        }
    }
    
    foreach ($filesToDelete as $filePath) {
        if (file_exists($filePath)) {
            if (@unlink($filePath)) {
                logEvent('Удален файл после ошибки: ' . $filePath, LOG_ERROR_ENABLED, 'error');
            } else {
                logEvent('Не удалось удалить файл после ошибки: ' . $filePath, LOG_ERROR_ENABLED, 'error');
            }
        }
    }
    
    sendResponse(false, ['error' => $e->getMessage()]);
}
<?php
/**
 * Файл: /admin/user_images/upload-handler.php
 * ОБРАБОТЧИК ЗАГРУЗКИ ИЗОБРАЖЕНИЙ - UPLOAD-HANDLER.PHP
 * 
 * Назначение:
 * - Принимает и обрабатывает загружаемые изображения через AJAX
 * - Выполняет валидацию файлов (формат, размер, тип)
 * - Конвертирует изображения в формат WebP для оптимизации
 * - Создает multiple версии изображений согласно переданным настройкам размеров
 * - Сохраняет информацию о файлах в базу данных
 * - Возвращает JSON ответ с результатом обработки
 * 
 * Особенности обработки:
 * - Автоматическая конвертация JPEG, PNG, GIF в WebP
 * - Создание ресайзнутых версий: thumbnail, small, medium, large
 * - Поддержка режимов ресайза: 'contain' (вписать) и 'cover' (заполнить)
 * - Организация файлов по дате (год/месяц/)
 * - Валидация прав доступа к директориям
 * - Обработка ошибок с откатом изменений
 * - Защита от дублирования через уникальные имена файлов
 * 
 * Параметры запроса:
 * - user_id: ID пользователя для привязки файла
 * - file: загружаемый файл изображения
 * - image_sizes: JSON с настройками размеров для ресайза
 * 
 * Возвращаемый JSON:
 * - success: true/false результат операции
 * - message: текстовое сообщение
 * - file_id: ID созданной записи в БД
 * - file_data: информация о файле из БД
 * - webp_versions: пути к созданным WebP файлам
 * - created_sizes: список созданных размеров
 * 
 * Требования:
 * - Подключение к базе данных через db.php
 * - Директория uploads/ с правами на запись
 * - Поддержка GD library для работы с изображениями
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/**
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
 */


// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// Устанавливаем кодировку
header('Content-Type: application/json');

// === Подключение зависимостей ===
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';             // База данных
require_once __DIR__ . '/../functions/auth_check.php';                  // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                    // Система логирования

// Безопасный запуск сессии
startSessionSafe();

// Получаем настройки администратора
$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    exit;
}

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

// Функция для отправки JSON ответа
function sendResponse($success, $data = []) {
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
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error_message = 'Метод не поддерживается: ' . $_SERVER['REQUEST_METHOD'];
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Метод не поддерживается']);
}

// === CSRF ЗАЩИТА ===
$csrf_token_session = $_SESSION['csrf_token'] ?? '';
$csrf_token_request = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (empty($csrf_token_session) || empty($csrf_token_request) || !hash_equals($csrf_token_session, $csrf_token_request)) {
    $error_message = 'CSRF проверка не пройдена (user_id: ' . ($_SESSION['user_id'] ?? 'guest') . ')';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Безопасность запроса не подтверждена. Обновите страницу и попробуйте снова.']);
}

// Получаем user_id и файл
$section_id = trim($_POST['section_id']);
$user_id = (int)$_SESSION['user_id'] ?? 0;
$file = $_FILES['file'] ?? null;

// Валидация user_id
if (!$user_id || !is_numeric($user_id)) {
    $error_message = 'Неверный user_id: ' . $user_id;
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Неверный user_id']);
}

// =============================
// ЛИМИТ КОЛИЧЕСТВА ФАЙЛОВ НА ПОЛЬЗОВАТЕЛЯ
// =============================
$max_files_per_user = (int)$_SESSION['max_files_per_user'] ?? 0;

// Применяем проверку ТОЛЬКО если лимит задан как положительное целое число
if (is_int($max_files_per_user) && $max_files_per_user > 0) {
    try {
        $count_sql = "SELECT COUNT(*) FROM media_files WHERE user_id = ?";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$user_id]);
        $current_file_count = (int)$count_stmt->fetchColumn();

        if ($current_file_count >= $max_files_per_user) {
            $error_message = "Превышен лимит файлов для пользователя (user_id: $user_id). Максимум: $max_files_per_user, текущее количество: $current_file_count";
            sendResponse(false, [
                'error' => "Вы достигли лимита загрузок. Удалите старые файлы.",
                'max_files_per_user' => $max_files_per_user
            ]);
        }
    } catch (Exception $e) {
        $error_message = 'Ошибка проверки лимита файлов (user_id: ' . $user_id . '): ' . $e->getMessage();
        logEvent($error_message, LOG_ERROR_ENABLED, 'error');
        sendResponse(false, ['error' => 'Служебная ошибка при проверке лимита. Попробуйте позже.']);
    }
}

// Валидация файла
if (!$file) {
    $error_message = 'Файл не получен (user_id: ' . $user_id . ')';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Файл не получен']);
}

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
    
    $errorMessage = $errorMessages[$file['error']] ?? 'Неизвестная ошибка загрузки (код: ' . $file['error'] . ')';
    $log_message = 'Ошибка загрузки файла: ' . $errorMessage . ' (user_id: ' . $user_id . ', файл: ' . ($file['name'] ?? 'unknown') . ')';
    logEvent($log_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => $errorMessage]);
}

// Проверяем тип файла
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($file['type'], $allowed_types)) {
    $error_message = 'Недопустимый тип файла: ' . $file['type'] . ' (user_id: ' . $user_id . ', файл: ' . $file['name'] . ')';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Недопустимый тип файла. Разрешены: JPEG, PNG, GIF, WebP']);
}

// Проверяем размер файла (максимум 10MB)
$maxFileSize = 10 * 1024 * 1024;
if ($file['size'] > $maxFileSize) {
    $error_message = 'Размер файла превышает 10MB: ' . round($file['size'] / 1024 / 1024, 2) . 'MB (user_id: ' . $user_id . ', файл: ' . $file['name'] . ')';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Размер файла превышает 10MB']);
}

// Получаем настройки размеров изображений
$image_sizes = $_SESSION["imageSizes_{$section_id}"];

if (json_last_error() !== JSON_ERROR_NONE) {
    $error_message = 'Ошибка декодирования настроек размеров: ' . json_last_error_msg() . ' (user_id: ' . $user_id . ', JSON: ' . $imageSizesJson . ')';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Ошибка декодирования настроек размеров']);
}

// Определяем директорию для загрузок - используем существующую структуру
$upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/';
$date_dir = date('Y/m/');
$full_upload_dir = $upload_dir . $date_dir;

// Проверяем существование основной директории uploads
if (!is_dir($upload_dir)) {
    $error_message = 'Директория загрузок не существует: ' . $upload_dir . ' (user_id: ' . $user_id . ')';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Директория загрузок не существует. Обратитесь к администратору.']);
}

// Проверяем права на запись в основную директорию
if (!is_writable($upload_dir)) {
    $error_message = 'Нет прав на запись в директорию загрузок: ' . $upload_dir . ' (user_id: ' . $user_id . ')';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Нет прав на запись в директорию загрузок. Обратитесь к администратору.']);
}

// Создаем поддиректорию по дате если не существует
if (!is_dir($full_upload_dir)) {
    if (!mkdir($full_upload_dir, 0755, true)) {
        $error_message = 'Не удалось создать поддиректорию: ' . $full_upload_dir . ' (user_id: ' . $user_id . ')';
        logEvent($error_message, LOG_ERROR_ENABLED, 'error');
        // Если не удалось создать, пробуем использовать основную директорию
        $full_upload_dir = $upload_dir;
        $date_dir = '';
    }
}

// Проверяем права на запись в целевую директорию
if (!is_writable($full_upload_dir)) {
    $error_message = 'Нет прав на запись в целевую директорию: ' . $full_upload_dir . ' (user_id: ' . $user_id . ')';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    sendResponse(false, ['error' => 'Нет прав на запись в целевую директорию. Обратитесь к администратору.']);
}

// Генерируем уникальное имя файла
$base_name = uniqid() . '_' . time();
$original_name = $base_name . '.webp';

// Функция для конвертации изображения в WebP
function convertToWebP($source_path, $destination_path, $quality = 80) {
    global $logFile;
    if (!file_exists($source_path)) {
        logEvent('Source file not found for WebP conversion: ' . $source_path, LOG_ERROR_ENABLED, 'error');
        return false;
    }
    
    $image_info = getimagesize($source_path);
    if (!$image_info) {
        logEvent('Не удается получить информацию об изображении для преобразования в WebP: ' . $source_path, LOG_ERROR_ENABLED, 'error');
        return false;
    }

    $mime_type = $image_info['mime'];
    
    switch ($mime_type) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source_path);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source_path);
            // Сохраняем прозрачность для PNG
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source_path);
            break;
        case 'image/webp':
            // Если уже WebP, просто копируем
            return copy($source_path, $destination_path);
        default:
            logEvent('Неподдерживаемый MIME-тип для преобразования в WebP: ' . $mime_type, LOG_ERROR_ENABLED, 'error');
            return false;
    }

    if (!$image) {
        logEvent('Не удается создать графический ресурс для преобразования в WebP: ' . $source_path, LOG_ERROR_ENABLED, 'error');
        return false;
    }

    // Конвертируем в WebP
    $result = imagewebp($image, $destination_path, $quality);
    imagedestroy($image);
    
    if (!$result) {
        logEvent('Не удалось выполнить преобразование WebP: ' . $source_path . ' -> ' . $destination_path, LOG_ERROR_ENABLED, 'error');
    }
    
    return $result;
}

// Функция для создания ресайзнутой версии изображения
function createResizedVersion($source_path, $destination_path, $target_width, $target_height, $mode = 'contain', $quality = 75) {
    global $logFile;
    if (!file_exists($source_path)) {
        logEvent('Исходный файл не найден для изменения размера: ' . $source_path, LOG_ERROR_ENABLED, 'error');
        return false;
    }
    
    $image_info = getimagesize($source_path);
    if (!$image_info) {
        logEvent('Не удается получить информацию об изображении для изменения размера: ' . $source_path, LOG_ERROR_ENABLED, 'error');
        return false;
    }

    list($original_width, $original_height) = $image_info;
    
    // Обрабатываем значения 'auto'
    if ($target_width === 'auto' && $target_height === 'auto') {
        $target_width = $original_width;
        $target_height = $original_height;
    } elseif ($target_width === 'auto') {
        $target_width = round($original_width * ($target_height / $original_height));
    } elseif ($target_height === 'auto') {
        $target_height = round($original_height * ($target_width / $original_width));
    }
    
    // Для режима 'contain' - вписываем с сохранением пропорций
    if ($mode === 'contain') {
        $ratio_original = $original_width / $original_height;
        $ratio_target = $target_width / $target_height;
        
        if ($ratio_target > $ratio_original) {
            $new_width = $target_height * $ratio_original;
            $new_height = $target_height;
        } else {
            $new_width = $target_width;
            $new_height = $target_width / $ratio_original;
        }
        
        $src_x = 0;
        $src_y = 0;
        $src_w = $original_width;
        $src_h = $original_height;
        
        $dst_x = round(($target_width - $new_width) / 2);
        $dst_y = round(($target_height - $new_height) / 2);
        $dst_w = round($new_width);
        $dst_h = round($new_height);
        
    } 
    // Для режима 'cover' - заполняем область без искажений (с обрезкой)
    else if ($mode === 'cover') {
        $ratio_original = $original_width / $original_height;
        $ratio_target = $target_width / $target_height;
        
        if ($ratio_target > $ratio_original) {
            // Обрезаем по высоте
            $src_h = $original_width / $ratio_target;
            $src_y = ($original_height - $src_h) / 2;
            $src_x = 0;
            $src_w = $original_width;
        } else {
            // Обрезаем по ширине
            $src_w = $original_height * $ratio_target;
            $src_x = ($original_width - $src_w) / 2;
            $src_y = 0;
            $src_h = $original_height;
        }
        
        $dst_x = 0;
        $dst_y = 0;
        $dst_w = $target_width;
        $dst_h = $target_height;
    }
    
    // Создаем новое изображение
    $new_image = imagecreatetruecolor($target_width, $target_height);
    
    // Сохраняем прозрачность для PNG/GIF
    if ($image_info['mime'] == 'image/png' || $image_info['mime'] == 'image/gif') {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
        imagefilledrectangle($new_image, 0, 0, $target_width, $target_height, $transparent);
    } else {
        // Для JPEG создаем белый фон
        $white = imagecolorallocate($new_image, 255, 255, 255);
        imagefill($new_image, 0, 0, $white);
    }
    
    // Загружаем исходное изображение
    switch ($image_info['mime']) {
        case 'image/jpeg':
            $source_image = imagecreatefromjpeg($source_path);
            break;
        case 'image/png':
            $source_image = imagecreatefrompng($source_path);
            break;
        case 'image/gif':
            $source_image = imagecreatefromgif($source_path);
            break;
        case 'image/webp':
            $source_image = imagecreatefromwebp($source_path);
            break;
        default:
            logEvent('Неподдерживаемый MIME-тип для изменения размера: ' . $image_info['mime'], LOG_ERROR_ENABLED, 'error');
            return false;
    }
    
    if (!$source_image) {
        logEvent('Не удается создать исходный ресурс изображения для изменения размера: ' . $source_path, LOG_ERROR_ENABLED, 'error');
        return false;
    }
    
    // Ресайзим изображение
    imagecopyresampled($new_image, $source_image, $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h);
    imagedestroy($source_image);
    
    // Сохраняем в WebP
    $result = imagewebp($new_image, $destination_path, $quality);
    imagedestroy($new_image);
    
    if (!$result) {
        logEvent('Не удалось создать WebP с измененным размером: ' . $destination_path, LOG_ERROR_ENABLED, 'error');
    }
    
    return $result;
}

try {
    // Временный путь для оригинального файла
    $temp_path = $file['tmp_name'];
    
    // Проверяем временный файл
    if (!file_exists($temp_path)) {
        throw new Exception('Временный файл не найден: ' . $temp_path);
    }
    
    // Пути для WebP версий
    $original_webp_path = $date_dir . $original_name;
    $file_versions = [];
    $webp_versions = ['original' => $original_webp_path];
    
    // Создаем оригинальную WebP версию
    if (!convertToWebP($temp_path, $upload_dir . $original_webp_path, 90)) {
        throw new Exception('Ошибка конвертации в WebP для файла: ' . $file['name']);
    }
    
    // Проверяем созданный файл
    if (!file_exists($upload_dir . $original_webp_path)) {
        throw new Exception('Не удалось создать WebP версию: ' . $upload_dir . $original_webp_path);
    }
    
    // Получаем размеры оригинального изображения
    $original_image_info = getimagesize($upload_dir . $original_webp_path);
    if (!$original_image_info) {
        throw new Exception('Не удалось получить информацию об изображении: ' . $upload_dir . $original_webp_path);
    }
    
    $original_dimensions = $original_image_info[0] . 'x' . $original_image_info[1];
    $original_size = filesize($upload_dir . $original_webp_path);
    
    // Добавляем оригинал в версии
    $file_versions['original'] = [
        'path' => $original_webp_path,
        'size' => $original_size,
        'dimensions' => $original_dimensions
    ];
    
    // Создаем ресайзнутые версии для каждого размера из массива
    foreach ($image_sizes as $size_name => $size_config) {
        if (!is_array($size_config) || count($size_config) < 3) {
            logEvent('Некорректные настройки размера: ' . $size_name . ' - ' . json_encode($size_config), LOG_ERROR_ENABLED, 'error');
            continue; // Пропускаем некорректные настройки
        }
        
        list($width, $height, $mode) = $size_config;
        
        $resized_webp_path = $date_dir . $base_name . '_' . $size_name . '.webp';
        
        // Создаем ресайзнутую версию
        if (createResizedVersion($temp_path, $upload_dir . $resized_webp_path, $width, $height, $mode, 85)) {
            $resized_size = filesize($upload_dir . $resized_webp_path);
            $resized_image_info = getimagesize($upload_dir . $resized_webp_path);
            $resized_dimensions = $resized_image_info[0] . 'x' . $resized_image_info[1];
            
            $file_versions[$size_name] = [
                'path' => $resized_webp_path,
                'size' => $resized_size,
                'dimensions' => $resized_dimensions,
                'mode' => $mode
            ];
            
            $webp_versions[$size_name] = $resized_webp_path;
        } else {
            // Если не удалось создать ресайзнутую версию, используем оригинал
            logEvent('Не удалось создать ресайзнутую версию: ' . $size_name . ', используется оригинал', LOG_ERROR_ENABLED, 'error');
            $file_versions[$size_name] = [
                'path' => $original_webp_path,
                'size' => $original_size,
                'dimensions' => $original_dimensions,
                'mode' => 'original'
            ];
            
            $webp_versions[$size_name] = $original_webp_path;
        }
    }
    
    // Проверяем подключение к базе данных
    if (!isset($pdo)) {
        throw new Exception('Ошибка подключения к базе данных');
    }
    
    // Сохраняем в базу данных
    $sql = "INSERT INTO media_files (user_id, file_versions, upload_date) VALUES (?, ?, NOW())";
    $stmt = $pdo->prepare($sql);
    
    if (!$stmt->execute([$user_id, json_encode($file_versions)])) {
        $error_info = $stmt->errorInfo();
        throw new Exception('Ошибка сохранения в базу данных: ' . ($error_info[2] ?? 'unknown error'));
    }
    
    $file_id = $pdo->lastInsertId();
    
    // Получаем информацию о созданном файле для ответа
    $select_sql = "SELECT * FROM media_files WHERE id = ?";
    $select_stmt = $pdo->prepare($select_sql);
    $select_stmt->execute([$file_id]);
    $file_data = $select_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Логируем успешную загрузку для отладки (закомментировано по умолчанию)
    // logEvent('SUCCESS: Файл успешно загружен - ID: ' . $file_id . ', user_id: ' . $user_id . ', файл: ' . $file['name'], $logFile);
    
    sendResponse(true, [
        'message' => 'Файл успешно загружен',
        'file_id' => $file_id,
        'file_data' => $file_data,
        'webp_versions' => $webp_versions,
        'created_sizes' => array_keys($image_sizes)
    ]);
    
} catch (Exception $e) {
    // Логируем ошибку
    $error_message = 'Upload error (user_id: ' . $user_id . ', file: ' . ($file['name'] ?? 'unknown') . '): ' . $e->getMessage();
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    
    // Удаляем загруженные файлы в случае ошибки
    $files_to_delete = [];
    
    if (isset($original_webp_path) && file_exists($upload_dir . $original_webp_path)) {
        $files_to_delete[] = $upload_dir . $original_webp_path;
    }
    
    // Удаляем все созданные ресайзнутые версии
    if (isset($image_sizes)) {
        foreach ($image_sizes as $size_name => $size_config) {
            $resized_path = $upload_dir . $date_dir . $base_name . '_' . $size_name . '.webp';
            if (file_exists($resized_path)) {
                $files_to_delete[] = $resized_path;
            }
        }
    }
    
    foreach ($files_to_delete as $file_path) {
        if (file_exists($file_path)) {
            if (@unlink($file_path)) {
                logEvent('Удален файл после ошибки: ' . $file_path, LOG_ERROR_ENABLED, 'error');
            } else {
                logEvent('Не удалось удалить файл после ошибки: ' . $file_path, LOG_ERROR_ENABLED, 'error');
            }
        }
    }
    
    sendResponse(false, ['error' => $e->getMessage()]);
}

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
?>
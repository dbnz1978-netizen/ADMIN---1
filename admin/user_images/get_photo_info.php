<?php

/**
 * Название файла:      get_photo_info.php
 * Назначение:          Обработчик AJAX-запроса для получения информации о фотографии
 *                      из таблицы `media_files`. Используется в интерфейсе админки для
 *                      отображения данных выбранной фотографии в модальном окне редактирования
 *                      (SEO-оптимизация: заголовок, описание, alt-текст).
 *                      Поддерживает форматы: JPEG, PNG, GIF, WebP, AVIF, JPEG XL
 * Автор:               User
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// ========================================
// КОНФИГУРАЦИЯ СКРИПТА
// ========================================

$config = [
    'display_errors'   => false,  // Включение отображения ошибок (true/false)
    'set_encoding'     => true,   // Включение кодировки UTF-8
    'db_connect'       => true,   // Подключение к базе данных
    'auth_check'       => true,   // Подключение функций авторизации
    'file_log'         => true,   // Подключение системы логирования
    'sanitization'     => true,   // Подключение валидации/экранирования
    'csrf_token'       => true,   // Генерация и проверка CSRF-токена
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Ошибка: администратор не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    exit;
}

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);  // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки true/false

// ========================================
// ПРОВЕРКА МЕТОДА ЗАПРОСА
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);  // Method Not Allowed
    echo 'Метод запроса должен быть POST';
    exit;
}

// ========================================
// ПРОВЕРКА CSRF-ТОКЕНА
// ========================================

$csrfTokenSession = $_SESSION['csrf_token'] ?? '';
$csrfTokenRequest = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($csrfTokenSession) || empty($csrfTokenRequest) || !hash_equals($csrfTokenSession, $csrfTokenRequest)) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Недействительный CSRF-токен. Обновите страницу.</div>';
    exit;
}

// ========================================
// ВАЛИДАЦИЯ ВХОДНЫХ ПАРАМЕТРОВ
// ========================================

// Проверяем наличие обязательных параметров
if (!isset($_POST['photoId']) || empty($_POST['photoId'])) {
    $errorMessage = 'Photo ID is required';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    echo '<div class="alert alert-danger">' . escape($errorMessage) . '</div>';
    exit;
}

$photoId = intval($_POST['photoId']);

// Дополнительная защита: убеждаемся, что $photoId положительный
if ($photoId <= 0) {
    $errorMessage = 'Invalid Photo ID (must be positive integer)';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    echo '<div class="alert alert-danger">Некорректный ID фотографии</div>';
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ ИНФОРМАЦИИ О ФОТОГРАФИИ ИЗ БД
// ========================================

try {
    // Получаем информацию о фотографии из базы данных
    $stmt = $pdo->prepare("SELECT * FROM `media_files` WHERE `id` = ?");
    $stmt->execute([$photoId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$photo) {
        $errorMessage = 'Photo not found (ID: ' . $photoId . ')';
        logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
        echo '<div class="alert alert-danger">Фотография не найдена</div>';
        exit;
    }
    
    // ========================================
    // ОБРАБОТКА JSON-ДАННЫХ ФАЙЛА
    // ========================================
    
    // Декодируем JSON-поле file_versions
    $fileVersions = json_decode($photo['file_versions'], true);
    
    // Проверка корректности JSON
    if ($fileVersions === null && json_last_error() !== JSON_ERROR_NONE) {
        $errorMessage = 'JSON decode error for file_versions (Photo ID: ' . $photoId . ') - '
            . json_last_error_msg();
        logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
        $fileVersions = [];
    }
    
    // Убедимся, что fileVersions — массив
    if (!is_array($fileVersions)) {
        $fileVersions = [];
    }
    
    // ========================================
    // ОПРЕДЕЛЕНИЕ ПУТИ К ИЗОБРАЖЕНИЮ
    // ========================================
    
    // Определяем путь к изображению для превью: сначала small, затем original
    $mediumImagePath = '';
    if (isset($fileVersions['small'])
        && is_array($fileVersions['small'])
        && isset($fileVersions['small']['path'])
    ) {
        $mediumImagePath = $fileVersions['small']['path'];
    } elseif (isset($fileVersions['original'])
        && is_array($fileVersions['original'])
        && isset($fileVersions['original']['path'])
    ) {
        $mediumImagePath = $fileVersions['original']['path'];
    }

    // ========================================
    // ПРОВЕРКА СУЩЕСТВОВАНИЯ ФАЙЛА
    // ========================================
    
    // Проверяем существование файла и устанавливаем заглушку при необходимости
    $uploadBaseUrl  = '/uploads/';
    $fullImagePath  = $mediumImagePath;
    if ($mediumImagePath) {
        $fullImagePath  = $uploadBaseUrl . ltrim($mediumImagePath, '/');
        $physicalPath   = $_SERVER['DOCUMENT_ROOT'] . $fullImagePath;
        
        if (!file_exists($physicalPath) || !is_file($physicalPath)) {
            // Файл не существует, используем заглушку
            $fullImagePath = '../user_images/img/no_pictures.svg';
        }
    } else {
        // Если вообще нет пути к изображению, используем заглушку
        $fullImagePath = '../user_images/img/no_pictures.svg';
    }
    
    // ========================================
    // ЭКРАНИРОВАНИЕ ДАННЫХ ДЛЯ БЕЗОПАСНОГО ВЫВОДА
    // ========================================
    
    $photoIdValue       = escape($photo['id']);
    $titleValue         = escape($photo['title']);
    $descriptionValue   = escape($photo['description']);
    $altTextValue       = escape($photo['alt_text']);
    $fullImagePathSafe  = escape($fullImagePath);
    
    // ========================================
    // ФОРМИРОВАНИЕ HTML-ОТВЕТА
    // ========================================
    
    $html = <<<HTML
    <div class="modal-body">
        <div class="mb-4">
            <div class="mb-4">
                <img src="{$fullImagePathSafe}" alt="{$altTextValue}" class="img-fluid w-100 shadow-sm" style="max-height: 60vh; object-fit: contain; border-radius: 6px;">
            </div>
            <div>
                <h5 class="modal-title">SEO-оптимизация картинок</h5>
                <div id="photo-form" data-photo-id="{$photoIdValue}">
                    <!-- Скрытое поле с ID записи из таблицы media_files -->
                    <input type="hidden" name="id" value="{$photoIdValue}">
                    <div class="mb-3">
                        <label for="title" class="form-label">Заголовок</label>
                        <input type="text" class="form-control form-control-sm" id="title" 
                            maxlength="100"
                            name="title" value="{$titleValue}">
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Описание</label>
                        <textarea class="form-control form-control-sm" id="description" 
                            maxlength="255"
                            name="description" rows="3">{$descriptionValue}</textarea>
                    </div>
                    <div class="mb-3">
                        <label for="alt_text" class="form-label">Alt текст</label>
                        <input type="text" class="form-control form-control-sm" id="alt_text" 
                            maxlength="200"
                            name="alt_text" value="{$altTextValue}">
                    </div>
                </div>
            </div>
        </div>
    </div>
HTML;
    
    echo $html;
    
} catch (Exception $e) {
    $errorMessage = 'Database error: ' . $e->getMessage() . ' (Photo ID: ' . $photoId . ')';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    echo '<div class="alert alert-danger">Ошибка базы данных: ' . escape($e->getMessage()) . '</div>';
}
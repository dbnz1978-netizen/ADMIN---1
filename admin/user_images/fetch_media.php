<?php

/**
 * Название файла:      fetch_media.php
 * Назначение:          Скрипт динамической подгрузки медиафайлов пользователя в галерею
 *                      Используется в AJAX-запросах для отображения превью файлов с поддержкой:
 *                      - пагинации (по 25 элементов за раз)
 *                      - множественного выбора
 *                      - удаления и просмотра информации о файле
 *                      - поддержки различных форматов: JPEG, PNG, GIF, WebP, AVIF, JPEG XL
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// ПРОВЕРКА ТИПА ЗАПРОСА
// ========================================

// Запрет прямого доступа через браузер только через AJAX
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || !isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    exit;
}

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
// ПРОВЕРКА CSRF-ТОКЕНА
// ========================================

$csrfTokenSession = $_SESSION['csrf_token'] ?? '';
$csrfTokenRequest = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

if (
    empty($csrfTokenSession)
    || empty($csrfTokenRequest)
    || !hash_equals($csrfTokenSession, $csrfTokenRequest)
) {
    http_response_code(403);
    echo '<p>Недействительный CSRF-токен. Обновите страницу.</p>';
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

// Получаем настройки администратора
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
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);  // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки true/false

// ========================================
// ВАЛИДАЦИЯ ВХОДНЫХ ДАННЫХ
// ========================================

// Проверяем, передан ли идентификатор секции и авторизован ли пользователь
if (!isset($_POST['sectionId']) || !isset($_SESSION['user_id'])) {
    echo '<p>Недостаточно данных для загрузки галереи.</p>';
    exit;
}

// Получаем и очищаем ID секции (строка, например: 'avatar', 'portfolio_main')
$sectionId = trim($_POST['sectionId']);

// Получаем ID текущего пользователя из сессии, приводим к целому числу
$userId = (int)$_SESSION['user_id'];

// Получаем смещение для пагинации (по умолчанию — 0, т.е. первая страница)
$offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;

// Дополнительная проверка корректности user_id
if ($userId === false || $userId <= 0) {
    http_response_code(400); // Bad Request
    echo '<p>Некорректный ID пользователя.</p>';
    exit;
}

// Защита от инъекций в sectionId: разрешаем только буквы, цифры и подчёркивания
// (например, 'gallery_1', 'logo', но не 'gallery<script>')
if (!preg_match('/^[a-zA-Z0-9_]+$/', $sectionId)) {
    echo '<p>Недопустимое имя секции.</p>';
    exit;
}

// Проверяем, что подключение к БД успешно установлено
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $errorMessage = 'Database connection failed';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    echo '<div class="alert alert-danger">Ошибка подключения к базе данных</div>';
    exit;
}

// ========================================
// ПАРАМЕТРЫ ПАГИНАЦИИ
// ========================================

$limit = 60; // Количество элементов на одну "порцию"

// ========================================
// ПОДСЧЁТ ОБЩЕГО ЧИСЛА МЕДИАФАЙЛОВ
// ========================================

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM media_files WHERE user_id = :user_id");
$countStmt->execute(['user_id' => $userId]);
$totalCount = (int)$countStmt->fetchColumn(); // Общее количество записей

// ========================================
// ОСНОВНОЙ ЗАПРОС: ПОЛУЧЕНИЕ ФАЙЛОВ
// ========================================

$stmt = $pdo->prepare("
    SELECT id, alt_text, file_versions 
    FROM media_files 
    WHERE user_id = :user_id 
    ORDER BY id DESC 
    LIMIT :limit OFFSET :offset
");

$stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$mediaItems = $stmt->fetchAll(PDO::FETCH_ASSOC); // Получаем все строки как ассоциативные массивы

// Базовый URL для доступа к загруженным файлам (относительно корня сайта)
$uploadBaseUrl = '/uploads/';

// ========================================
// ОТОБРАЖЕНИЕ ГАЛЕРЕИ
// ========================================

?>

<!-- Основной контейнер галереи -->
<div class="media-gallery">

    <!-- Панель управления галереей -->
    <div class="gallery-toolbar">
        <!-- Кнопка добавления медиа -->
        <button 
            class="btn btn-outline-primary load-more-files"
            type="button"
            onclick='uploadFiles(<?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
            data-section-files="<?php echo escape($sectionId); ?>">
            <i class="bi bi-plus-circle me-1"></i> Добавить медиа файл
        </button>

        <!-- Кнопка "Выделить всё" -->
        <button 
            class="btn btn-outline-secondary" 
            type="button"
            data-all="gallery-all_<?php echo escape($sectionId); ?>" 
            onclick='selectAll(<?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
            <i class="bi bi-check-all me-1"></i> Выделить всё
        </button>

        <!-- Кнопка удаления (изначально скрыта, появляется при выборе хотя бы одного элемента) -->
        <button 
            class="btn btn-outline-danger" 
            type="button"
            id="deleteBtn_<?php echo escape($sectionId); ?>" 
            onclick='deleteSelectedPhotos(<?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
            style="display: none;">
            <i class="bi bi-trash me-1"></i> Удалить
        </button>
    </div>

    <!-- Сетка изображений -->
    <div 
        class="gallery-grid" 
        id="gallery_<?php echo escape($sectionId); ?>">
        
        <?php foreach ($mediaItems as $item): ?>
            <?php
            // Распаковка JSON-поля `file_versions`, хранящего пути к разным версиям файла (оригинал, thumbnail и т.д.)
            $fileVersions = json_decode($item['file_versions'], true);

            // Пропускаем элемент, если JSON повреждён или отсутствует миниатюра
            if (!is_array($fileVersions) || !isset($fileVersions['thumbnail']['path'])) {
                continue;
            }

            // Формируем путь к миниатюре: убираем возможный лидирующий `/`, чтобы избежать двойного слеша
            $thumbnailPath = ltrim($fileVersions['thumbnail']['path'], '/');
            $fullImageUrl  = $uploadBaseUrl . $thumbnailPath;

            // Физический путь на сервере для проверки существования файла
            $physicalPath = $_SERVER['DOCUMENT_ROOT'] . $fullImageUrl;

            // Проверяем: существует ли файл и является ли он обычным файлом (не директорией)
            if (!file_exists($physicalPath) || !is_file($physicalPath)) {
                // Если файл не найден — используем заглушку
                $fullImageUrl = '../user_images/img/no_pictures.svg';
            }

            // Безопасное экранирование alt-текста
            $altText = escape($item['alt_text'] ?: 'Media');
            ?>

            <!-- Элемент галереи -->
            <div 
                class="gallery-item" 
                id="galleryid_<?php echo (int)$item['id']; ?>"
                data-gallery="gallery_<?php echo escape($sectionId); ?>"
                onclick='updateDeleteButtonVisibility(<?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo (int)$item['id']; ?>, event)'>
                
                <!-- Превью изображения -->
                <img 
                    src="<?php echo escape($fullImageUrl); ?>" 
                    alt="<?php echo $altText; ?>">

                <!-- Индикатор выбора (галочка при выделении) -->
                <div class="focus-indicator"><i class="bi bi-check"></i></div>
                <!-- Наложение с кнопкой информации -->
                <div class="overlay">
                    <button 
                        class="btn-icon photoInfo" 
                        onclick="photoInfo(<?php echo (int)$item['id']; ?>)" data-open-custom-modal>
                        <i class="bi bi-info-circle"></i>
                    </button>
                </div>
            </div>

        <?php endforeach; ?>
    </div>

    <!-- Блок пагинации и счётчика элементов -->
    <div class="load-more-section">
        <?php
        // Сколько элементов уже загружено (с учётом текущего offset)
        $loadedCount = $offset + count($mediaItems);
        // Есть ли ещё элементы для загрузки?
        $hasMore     = ($loadedCount < $totalCount);
        ?>

        <!-- Кнопка "Загрузить ещё", если есть ещё данные -->
        <?php if ($hasMore): ?>
            <button 
                class="btn btn-outline-secondary load-more-btn"
                onclick='clickAll(<?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo $offset + $limit; ?>)'
                data-section="<?php echo escape($sectionId); ?>">
                <i class="bi bi-arrow-clockwise me-1"></i> Загрузить ещё
            </button>
        <?php endif; ?>

        <!-- Текстовый счётчик: "Отображение X из Y" -->
        <div class="items-count">
            Отображение <?php echo min($loadedCount, $totalCount); ?> из <?php echo $totalCount; ?> медиа элементов
        </div>
    </div>
</div>
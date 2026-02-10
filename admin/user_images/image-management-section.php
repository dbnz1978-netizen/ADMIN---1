<?php

/**
 * Название файла:      image-management-section.php
 * Назначение:          Обработчик AJAX-запросов для управления галереей изображений.
 *                      Основные функции:
 *                      1. Принимает POST запрос с параметрами sectionId и image_ids
 *                      2. Валидирует полученные данные
 *                      3. Подключается к базе данных и получает информацию об изображениях
 *                      4. Возвращает HTML разметку для отображения галереи
 *                      5. Обрабатывает ошибки и логирует их
 *                      6. Генерирует JavaScript для инициализации функционала перетаскивания
 *                      Поддерживаемые форматы: JPEG, PNG, GIF, WebP, AVIF, JPEG XL
 *                      Используется для динамической загрузки галерей изображений через AJAX
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// ПРОВЕРКА ТИПА ЗАПРОСА
// ========================================

// Запрет прямого доступа через браузер — только через AJAX
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
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
    'display_errors'   => false,   // Включение отображения ошибок (true/false)
    'set_encoding'     => true,    // Включение кодировки UTF-8
    'db_connect'       => true,    // Подключение к базе данных
    'auth_check'       => true,    // Подключение функций авторизации
    'file_log'         => true,    // Подключение системы логирования
    'sanitization'     => true,    // Подключение валидации/экранирования
    'csrf_token'       => true,    // Генерация и проверка CSRF-токена
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// Подключаем функцию получения ссылок на изображения из базы данных
require_once 'functions/getImageThumbnails.php';

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
    http_response_code(403);
    echo '<p>Недействительный CSRF-токен. Обновите страницу.</p>';
    exit;
}

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Получаем настройки администратора (для флагов логирования)
$adminData = getAdminData($pdo);

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

// ========================================
// КОНСТАНТЫ
// ========================================

// Максимальное количество ID изображений, обрабатываемых за раз
define('MAX_IMAGE_IDS', 500);

// ========================================
// ВАЛИДАЦИЯ ВХОДНЫХ ДАННЫХ
// ========================================

// Проверяем, что запрос пришел методом POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Получаем данные из POST запроса
    $sectionId = isset($_POST['sectionId']) ? (string)$_POST['sectionId'] : '';
    $imageIds  = isset($_POST['image_ids']) ? (string)$_POST['image_ids'] : '';
    
    // Валидация данных
    $sectionResult = validateSectionId($sectionId, 'ID секции');
    
    if (!$sectionResult['valid']) {
        $errorMessage = $sectionResult['error'] ?? 'Некорректный ID секции';
        logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
        echo '<p>Ошибка: ' . escape($errorMessage) . '</p>';
        exit;
    }

    $sectionId = $sectionResult['value'];

    if ($imageIds !== '') {
        $imageResult = validateIdList($imageIds, MAX_IMAGE_IDS);
        
        if ($imageResult['valid']) {
            $imageIds = $imageResult['value'];
        } else {
            $errorMessage = $imageResult['error'] ?? 'Некорректные ID изображений';
            logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
            echo '<p>Ошибка: ' . escape($errorMessage) . '</p>';
            exit;
        }
    }
} else {
    // Если запрос не POST, логируем ошибку и выводим сообщение
    $errorMessage = 'Неверный метод запроса';
    logEvent($errorMessage, LOG_ERROR_ENABLED, 'error');
    echo '<p>Ошибка: ' . escape($errorMessage) . '</p>';
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ ИЗОБРАЖЕНИЙ
// ========================================

// Если image_ids не пустой, получаем изображения
if (!empty($imageIds)) {
    $result = getImageThumbnails($imageIds, $pdo);
} else {
    // Если image_ids пустой, создаем пустой результат
    $result = [
        'success' => true,
        'images'  => [],
        'count'   => 0
    ];
}

// Логируем ошибки из результата получения изображений
if (!$result['success']) {
    $errorMessage = isset($result['error']) ? $result['error'] : 'Неизвестная ошибка при получении изображений';
    logEvent($errorMessage . ' (Section: ' . $sectionId . ')', LOG_ERROR_ENABLED, 'error');
}

// ========================================
// ВЫВОД КОНТЕНТА
// ========================================

?>

<div class="mt-0">
    <?php if ($result['success']): ?>
        <?php if (!empty($result['images']) && $result['count'] > 0): ?>
            <!-- Есть изображения - показываем галерею -->
            <div class="selected-images-section" id="selectedSection_<?php echo escape($sectionId); ?>">
                <div class="selected-images-count">
                    Выбрано изображений: <span id="selectedImagesCount_<?php echo escape($sectionId); ?>"><?php echo escape((string)$result['count']); ?></span>
                </div>
                <div id="selectedImagesList_<?php echo escape($sectionId); ?>" class="selected-images-preview">
                    <div class="media-upload-square" 
                         data-bs-toggle="modal" data-bs-target="#<?php echo escape($sectionId); ?>"
                         onclick='storeSectionId(<?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                        <i class="bi bi-plus-lg"></i>
                    </div>
                    <?php foreach ($result['images'] as $image): ?>
                        <div class="selected-image-item" data-image-id="<?php echo escape((string)$image['id']); ?>" draggable="true">
                            <img src="<?php echo escape($image['thumbnail_url']); ?>" alt="Image <?php echo escape((string)$image['id']); ?>">
                            <button type="button" class="selected-image-remove" data-section="<?php echo escape($sectionId); ?>" data-image-id="<?php echo escape((string)$image['id']); ?>">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Нет изображений - показываем заглушку -->
            <div class="selected-images-section" id="selectedSection_<?php echo escape($sectionId); ?>">
                <div class="selected-images-count">
                    Выбрано изображений: <span id="selectedImagesCount_<?php echo escape($sectionId); ?>">0</span>
                </div>
                <div id="selectedImagesList_<?php echo escape($sectionId); ?>" class="selected-images-preview">
                    <div class="media-upload-square" 
                         data-bs-toggle="modal" data-bs-target="#<?php echo escape($sectionId); ?>"
                         onclick='storeSectionId(<?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                        <i class="bi bi-plus-lg"></i>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <?php
        // ЕСЛИ ПРОИЗОШЛА ОШИБКА - УЖЕ ЗАЛОГИРОВАНО ВЫШЕ
        $errorMessage = $result['error'];
        ?>
        <div class="alert alert-danger">
            <h4>Произошла ошибка</h4>
            <p><?php echo escape($errorMessage); ?></p>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================
     ИНИЦИАЛИЗАЦИЯ ФУНКЦИОНАЛА ГАЛЕРЕИ
     ======================================== -->
<script>
/**
 * Инициализация функционала галереи после загрузки контента через AJAX
 * 
 * Этот скрипт обеспечивает:
 * 1. Немедленную инициализацию перетаскивания после загрузки DOM
 * 2. Отложенную инициализацию для случаев, когда скрипты загружаются асинхронно
 * 3. Совместимость с основным gallery-manager.js
 * 4. Автоматическую привязку к конкретной секции галереи
 */

// Инициализация сразу после загрузки контента через AJAX (загружаем правильно gallery-manager.js)
document.addEventListener('DOMContentLoaded', function() {
    // Если скрипт уже загружен, инициализируем сразу
    if (typeof initDrag === 'function') {
        console.log('Initializing gallery for section: ' + <?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        initDrag(<?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        // initRemoveButtons уже вызывается автоматически в gallery-manager.js
    } else {
        console.log('Gallery Manager functions not available yet');
    }
});

// Альтернативная инициализация для AJAX контента
setTimeout(function() {
    if (typeof initDrag === 'function') {
        console.log('Delayed initialization for section: ' + <?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        initDrag(<?php echo json_encode($sectionId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
    }
}, 100);
</script>
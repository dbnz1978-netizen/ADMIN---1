<?php
/**
 * Файл: /admin/user_images/image-management-section.php
 * 
 * Этот скрипт обрабатывает AJAX запросы для управления галереей изображений.
 * Основные функции:
 * 1. Принимает POST запрос с параметрами sectionId и image_ids
 * 2. Валидирует полученные данные
 * 3. Подключается к базе данных и получает информацию об изображениях
 * 4. Возвращает HTML разметку для отображения галереи
 * 5. Обрабатывает ошибки и логирует их
 * 6. Генерирует JavaScript для инициализации функционала перетаскивания
 * 
 * Используется для динамической загрузки галерей изображений через AJAX
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/** 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Запрет прямого доступа через браузер только через AJAX
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || 
    !isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit;
}

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// === Подключение зависимостей ===
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования
require_once '../user_images/getImageThumbnails.php';                // Подключаем получения ссылок на изображения из базы данных

// Безопасный запуск сессии
startSessionSafe();

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

// Проверяем, что запрос пришел методом POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Получаем данные из POST запроса
    $sectionId = isset($_POST['sectionId']) ? $_POST['sectionId'] : '';
    $image_ids = isset($_POST['image_ids']) ? $_POST['image_ids'] : '';
    
    // Валидация данных
    if (empty($sectionId)) {
        $error_message = 'Не передан sectionId';
        logEvent($error_message, LOG_ERROR_ENABLED, 'error');
        echo '<p>Ошибка: ' . $error_message . '</p>';
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        exit;
    }
} else {
    // Если запрос не POST, логируем ошибку и выводим сообщение
    $error_message = 'Неверный метод запроса';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    echo '<p>Ошибка: ' . $error_message . '</p>';
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

// Если image_ids не пустой, получаем изображения
if (!empty($image_ids)) {
    $result = getImageThumbnails($image_ids, $pdo);
} else {
    // Если image_ids пустой, создаем пустой результат
    $result = [
        'success' => true,
        'images' => [],
        'count' => 0
    ];
}

// Логируем ошибки из результата получения изображений
if (!$result['success']) {
    $error_message = isset($result['error']) ? $result['error'] : 'Неизвестная ошибка при получении изображений';
    logEvent($error_message . ' (Section: ' . $sectionId . ')', LOG_ERROR_ENABLED, 'error');
}

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
?>

<div class="mt-4">
    <?php if ($result['success']): ?>
        <?php if (!empty($result['images']) && $result['count'] > 0): ?>
            <!-- Есть изображения - показываем галерею -->
            <div class="selected-images-section" id="selectedSection_<?php echo $sectionId; ?>">
                <div class="selected-images-count">
                    Выбрано изображений: <span id="selectedImagesCount_<?php echo $sectionId; ?>"><?php echo $result['count']; ?></span>
                </div>
                <div id="selectedImagesList_<?php echo $sectionId; ?>" class="selected-images-preview">
                    <?php foreach ($result['images'] as $image): ?>
                        <div class="selected-image-item" data-image-id="<?php echo $image['id']; ?>" draggable="true">
                            <img src="<?php echo $image['thumbnail_url']; ?>" alt="Image <?php echo $image['id']; ?>">
                            <button type="button" class="selected-image-remove" data-section="<?php echo $sectionId; ?>" data-image-id="<?php echo $image['id']; ?>">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Нет изображений - показываем заглушку -->
            <div class="selected-images-section" id="selectedSection_<?php echo $sectionId; ?>">
                <div class="selected-images-count">
                    Выбрано изображений: <span id="selectedImagesCount_<?php echo $sectionId; ?>">0</span>
                </div>
                <div id="selectedImagesList_<?php echo $sectionId; ?>" class="selected-images-preview"></div>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <?php
        // ЕСЛИ ПРОИЗОШЛА ОШИБКА - УЖЕ ЗАЛОГИРОВАНО ВЫШЕ
        $error_message = $result['error'];
        ?>
        <div class="alert alert-danger">
            <h4>Произошла ошибка</h4>
            <p><?php echo $error_message; ?></p>
        </div>
    <?php endif; ?>
</div>

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

// Инициализация сразу после загрузки контента через AJAX (загружаем правельно gallery-manager.js)
document.addEventListener('DOMContentLoaded', function() {
    // Если скрипт уже загружен, инициализируем сразу
    if (typeof initDrag === 'function') {
        console.log('Initializing gallery for section: <?php echo $sectionId; ?>');
        initDrag("<?php echo $sectionId; ?>");
        // initRemoveButtons уже вызывается автоматически в gallery-manager.js
    } else {
        console.log('Gallery Manager functions not available yet');
    }
});

// Альтернативная инициализация для AJAX контента
setTimeout(function() {
    if (typeof initDrag === 'function') {
        console.log('Delayed initialization for section: <?php echo $sectionId; ?>');
        initDrag("<?php echo $sectionId; ?>");
    }
}, 100);
</script>
<?php
// Файл: /admin/user_images/fetch_media.php
// -----------------------------------------------------------
// Скрипт динамической подгрузки медиафайлов пользователя в галерею.
// Используется в AJAX-запросах для отображения превью файлов с поддержкой:
// - пагинации (по 25 элементов за раз),
// - множественного выбора,
// - удаления и просмотра информации о файле.
// -----------------------------------------------------------

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

// === Валидация входных данных ===
// Проверяем, передан ли идентификатор секции и авторизован ли пользователь
if (!isset($_POST['sectionId']) || !isset($_SESSION['user_id'])) {
    echo '<p>Недостаточно данных для загрузки галереи.</p>';
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

// Получаем и очищаем ID секции (строка, например: 'avatar', 'portfolio_main')
$sectionId = trim($_POST['sectionId']);
// Получаем ID текущего пользователя из сессии, приводим к целому числу
$user_id = (int)$_SESSION['user_id'];
// Получаем смещение для пагинации (по умолчанию — 0, т.е. первая страница)
$offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;

// Дополнительная проверка корректности user_id
if ($user_id === false || $user_id <= 0) {
    http_response_code(400); // Bad Request
    echo '<p>Некорректный ID пользователя.</p>';
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

// Защита от инъекций в sectionId: разрешаем только буквы, цифры и подчёркивания
// (например, 'gallery_1', 'logo', но не 'gallery<script>')
if (!preg_match('/^[a-zA-Z0-9_]+$/', $sectionId)) {
    echo '<p>Недопустимое имя секции.</p>';
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

// Проверяем, что подключение к БД успешно установлено
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $error_message = 'Database connection failed';
    logEvent($error_message, LOG_ERROR_ENABLED, 'error');
    echo '<div class="alert alert-danger">Ошибка подключения к базе данных</div>';
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    exit;
}

// === Параметры пагинации ===
$limit = 60; // Количество элементов на одну "порцию"

// === Подсчёт общего числа медиафайлов пользователя (не зависит от смещения) ===
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM media_files WHERE user_id = :user_id");
$countStmt->execute(['user_id' => $user_id]);
$totalCount = (int)$countStmt->fetchColumn(); // Общее количество записей

// === Основной запрос: получаем файлы с сортировкой по убыванию ID и пагинацией ===
$stmt = $pdo->prepare("
    SELECT id, alt_text, file_versions 
    FROM media_files 
    WHERE user_id = :user_id 
    ORDER BY id DESC 
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$mediaItems = $stmt->fetchAll(PDO::FETCH_ASSOC); // Получаем все строки как ассоциативные массивы

// Базовый URL для доступа к загруженным файлам (относительно корня сайта)
$uploadBaseUrl = '/uploads/';

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
?>

<!-- Основной контейнер галереи -->
<div class="media-gallery">

    <!-- Панель управления галереей -->
    <div class="gallery-toolbar">
        <!-- Кнопка добавления медиа -->
        <button 
            class="btn btn-outline-primary load-more-files"
            type="button"
            onclick="uploadFiles('<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>')"
            data-section-files="<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-plus-circle me-1"></i> Добавить медиа файл
        </button>

        <!-- Кнопка "Выделить всё" -->
        <button 
            class="btn btn-outline-secondary" 
            type="button"
            data-all="gallery-all_<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>" 
            onclick="selectAll('<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>')">
            <i class="bi bi-check-all me-1"></i> Выделить всё
        </button>

        <!-- Кнопка удаления (изначально скрыта, появляется при выборе хотя бы одного элемента) -->
        <button 
            class="btn btn-outline-danger" 
            type="button"
            id="deleteBtn_<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>" 
            onclick="deleteSelectedPhotos('<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>')"
            style="display: none;">
            <i class="bi bi-trash me-1"></i> Удалить
        </button>
    </div>

    <!-- Сетка изображений -->
    <div 
        class="gallery-grid" 
        id="gallery_<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>">
        
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
            $fullImageUrl = $uploadBaseUrl . $thumbnailPath;

            // Физический путь на сервере для проверки существования файла
            $physicalPath = $_SERVER['DOCUMENT_ROOT'] . $fullImageUrl;

            // Проверяем: существует ли файл и является ли он обычным файлом (не директорией)
            if (!file_exists($physicalPath) || !is_file($physicalPath)) {
                // Если файл не найден — используем заглушку
                $fullImageUrl = '../user_images/img/no_pictures.svg';
            }

            // Безопасное экранирование alt-текста
            $altText = htmlspecialchars($item['alt_text'] ?: 'Media', ENT_QUOTES, 'UTF-8');
            ?>

            <!-- Элемент галереи -->
            <div 
                class="gallery-item" 
                id="galleryid_<?php echo (int)$item['id']; ?>"
                data-gallery="gallery_<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>"
                onclick="updateDeleteButtonVisibility('<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>', <?php echo (int)$item['id']; ?>)">
                
                <!-- Превью изображения -->
                <img 
                    src="<?php echo htmlspecialchars($fullImageUrl, ENT_QUOTES, 'UTF-8'); ?>" 
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
        $hasMore = ($loadedCount < $totalCount);
        ?>

        <!-- Кнопка "Загрузить ещё", если есть ещё данные -->
        <?php if ($hasMore): ?>
            <button 
                class="btn btn-outline-secondary load-more-btn"
                onclick="clickAll('<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>', <?php echo $offset + $limit; ?>)"
                data-section="<?php echo htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-arrow-clockwise me-1"></i> Загрузить ещё
            </button>
        <?php endif; ?>

        <!-- Текстовый счётчик: "Отображение X из Y" -->
        <div class="items-count">
            Отображение <?php echo min($loadedCount, $totalCount); ?> из <?php echo $totalCount; ?> медиа элементов
        </div>
    </div>
</div>
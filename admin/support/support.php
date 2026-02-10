<?php

/**
 * Название файла:      support.php
 * Назначение:          Модуль обратной связи для авторизованных ПОЛЬЗОВАТЕЛЕЙ
 *                      Несмотря на расположение в /admin/, доступ имеют ТОЛЬКО обычные пользователи (не админы)
 *                      Администраторы автоматически перенаправляются в index.php
 *                      Исправления:
 *                      1. ПОЛНОСТЬЮ УДАЛЕНА кнопка "Закрыть обращение"
 *                      2. УДАЛЕНА вся PHP логика обработки action='close'
 *                      3. УДАЛЕНА HTML кнопка закрытия и связанный с ней код
 *                      4. УДАЛЕНА проверка $existingTicket['status'] !== 'closed'
 *                      5. ОСТАЛАСЬ только отправка новых обращений и ответов
 *                      6. ДОБАВЛЕНА СТАНДАРТНАЯ ПРОВЕРКА CSRF-ТОКЕНА (как в /admin/registration.php)
 *                      Возможности:
 *                      - отправка нового обращения
 *                      - переписка с поддержкой
 *                      - прикрепление файлов
 *                      Файлы сохраняются в защищённой директории вне public_html
 *                      Использует функции из /admin/functions/
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors' => false,  // включение отображения ошибок true/false
    'set_encoding'   => true,   // включение кодировки UTF-8
    'db_connect'     => true,   // подключение к базе
    'auth_check'     => true,   // подключение функций авторизации
    'file_log'       => true,   // подключение системы логирования
    'mailer'         => true,   // подключение отправка email уведомлений
    'display_alerts' => true,   // подключение отображения сообщений
    'sanitization'   => true,   // подключение валидации/экранирования
    'csrf_token'     => true,   // генерация CSRF-токена
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// НАСТРОЙКА ПАПКИ ДЛЯ ФАЙЛОВ
// ========================================

// Путь вне public_html — например: /home/user/support_files/
// Можно изменить имя папки — главное, чтобы она была НЕ в DOCUMENT_ROOT
define('SUPPORT_ATTACHMENTS_DIR', $_SERVER['DOCUMENT_ROOT'] . '/../support/');

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

// Получаем настройки администратора
$adminData = getAdminData($pdo);

if ($adminData === false) {
    header("Location: ../logout.php");
    exit;
}

// Название Админ-панели
$adminPanel = $adminData['AdminPanel'] ?? 'AdminPanel';

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled'] ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

// ========================================
// ЗАГРУЗКА FLASH-СООБЩЕНИЙ ИЗ СЕССИИ
// ========================================

// Загружаем flash-сообщения из сессии (если есть)
if (!empty($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors          = $_SESSION['flash_messages']['error'] ?? [];
    // Удаляем их, чтобы не показывались при повторной загрузке
    unset($_SESSION['flash_messages']);
}

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ И ПОЛУЧЕНИЕ ДАННЫХ ПОЛЬЗОВАТЕЛЯ
// ========================================

try {
    $user = requireAuth($pdo);
    
    if (!$user) {
        $redirectTo = '../logout.php';
        $logMessage = "Неавторизованный доступ — перенаправление на: $redirectTo — IP: "
            . "{$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}";
        logEvent($logMessage, LOG_INFO_ENABLED, 'info');
        
        header("Location: $redirectTo");
        exit;
    }
    
    $userDataAdmin = getUserData($pdo, $user['id']);
    
    if ($userDataAdmin['author'] === 'admin') {
        header("Location: index.php");
        exit;
    }
    
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg        = $userDataAdmin['message'];
        $level      = $userDataAdmin['level'];
        $logEnabled = match ($level) {
            'info'  => LOG_INFO_ENABLED,
            'error' => LOG_ERROR_ENABLED,
            default => LOG_ERROR_ENABLED
        };
        
        logEvent($msg, $logEnabled, $level);
        
        header("Location: ../logout.php");
        exit;
    }
    
    // Декодируем JSON-данные администратора
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];
    
    // Разрешить чат онлайн с администратором Нет/Да
    if (!$adminData['allow_online_chat']) {
        header("Location: ../logout.php");
        exit;
    }
    
} catch (Exception $e) {
    logEvent(
        "Ошибка при инициализации: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        LOG_ERROR_ENABLED,
        'error'
    );
    
    header("Location: ../logout.php");
    exit;
}

// ========================================
// ЗАГРУЗКА ПОСЛЕДНЕГО ОБРАЩЕНИЯ
// ========================================

// Загружаем последнее обращение
$stmt          = $pdo->prepare("SELECT * FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$user['id']]);
$existingTicket = $stmt->fetch(PDO::FETCH_ASSOC);
$messages       = [];

if ($existingTicket) {
    $stmt    = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
    $stmt->execute([$existingTicket['id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ========================================
// ОБРАБОТКА POST-ЗАПРОСА
// ========================================

// Обработка POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ========================================
    // ПРОВЕРКА CSRF-ТОКЕНА
    // ========================================
    
    // Проверка CSRF-токена (как в /admin/registration.php)
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = "Недопустимый запрос. Повторите попытку.";
        logEvent(
            "Попытка CSRF-атаки при отправке сообщения в поддержку — User ID: " . $user['id'] . " — IP: {$_SERVER['REMOTE_ADDR']}",
            LOG_ERROR_ENABLED,
            'error'
        );
    } else {
        
        // ========================================
        // ВАЛИДАЦИЯ ДАННЫХ
        // ========================================
        
        if (!$existingTicket) {
            // Валидация многострочного текстового поля
            $resultsubject = validateTextareaField(trim($_POST['subject'] ?? ''), 2, 255, 'Тема обращения');
            
            if ($resultsubject['valid']) {
                $subject = ($resultsubject['value']);
            } else {
                $errors[] = ($resultsubject['error']);
                $subject  = false;
            }
        }
        
        // Валидация многострочного текстового поля
        $resultmessage = validateTextareaField(trim($_POST['message'] ?? ''), 2, 10000, 'Сообщение');
        
        if ($resultmessage['valid']) {
            $messageText = ($resultmessage['value']);
        } else {
            $errors[]    = ($resultmessage['error']);
            $messageText = false;
        }
        
        if (empty($errors)) {
            
            // ========================================
            // ОБРАБОТКА ВЛОЖЕНИЯ
            // ========================================
            
            // Обработка вложения
            $attachmentPath = null;
            
            if (!empty($_FILES['attachment']['name'])) {
                $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'txt', 'zip'];
                $maxSize = 10 * 1024 * 1024;
                $file    = $_FILES['attachment'];
                
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Ошибка загрузки файла.';
                } elseif ($file['size'] > $maxSize) {
                    $errors[] = 'Файл слишком большой (макс. 10 МБ).';
                } else {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    
                    if (!in_array($ext, $allowed)) {
                        $errors[] = 'Недопустимый тип файла.';
                    } else {
                        // Создаём директорию, если не существует
                        if (!is_dir(SUPPORT_ATTACHMENTS_DIR)) {
                            if (!mkdir(SUPPORT_ATTACHMENTS_DIR, 0755, true)) {
                                $errors[] = 'Не удалось создать папку для файлов.';
                                logEvent("Ошибка создания папки: " . SUPPORT_ATTACHMENTS_DIR, LOG_ERROR_ENABLED, 'error');
                            }
                        }
                        
                        $filename = 'ticket_' . ($existingTicket ? $existingTicket['id'] : uniqid()) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $fullPath = SUPPORT_ATTACHMENTS_DIR . $filename;
                        
                        if (move_uploaded_file($file['tmp_name'], $fullPath)) {
                            $attachmentPath = $filename;
                        } else {
                            $errors[] = 'Не удалось сохранить файл.';
                            logEvent("Ошибка сохранения файла: $fullPath", LOG_ERROR_ENABLED, 'error');
                        }
                    }
                }
            }
        }
        
        if (empty($errors)) {
            
            // ========================================
            // СОЗДАНИЕ ИЛИ ОБНОВЛЕНИЕ ОБРАЩЕНИЯ
            // ========================================
            
            if (!$existingTicket) {
                $stmt = $pdo->prepare("
                    INSERT INTO support_tickets (user_id, user_email, subject, status, created_at, updated_at)
                    VALUES (?, ?, ?, 'new', NOW(), NOW())
                ");
                $stmt->execute([$user['id'], $userDataAdmin['email'], $subject]);
                $ticketId = $pdo->lastInsertId();
                
                logEvent("Создано новое обращение #{$ticketId} от пользователя {$user['id']}", LOG_INFO_ENABLED, 'info');
                
                // Отправляет уведомление администратору о новом обращении или новом сообщении
                sendAdminSupportNotification($adminData['email'], $userDataAdmin['email'], $subject, $ticketId, $adminPanel, true);
            } else {
                $ticketId = $existingTicket['id'];
                // Удалена проверка на статус 'closed'
                $pdo->prepare("UPDATE support_tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticketId]);
            }
            
            // ========================================
            // СОХРАНЕНИЕ СООБЩЕНИЯ
            // ========================================
            
            // Сохраняем сообщение
            $pdo->prepare("
                INSERT INTO support_messages (ticket_id, author_type, author_id, author_email, message, attachment_path, created_at)
                VALUES (?, 'user', ?, ?, ?, ?, NOW())
            ")->execute([$ticketId, $user['id'], $userDataAdmin['email'], $messageText, $attachmentPath]);
            
            if ($existingTicket) {
                // Отправляет уведомление администратору о новом обращении или новом сообщении
                sendAdminSupportNotification($adminData['email'], $userDataAdmin['email'], '', $ticketId, $adminPanel, false);
            }
            
            $successMessages[] = 'Сообщение отправлено.';
        }
    }
    
    // ========================================
    // СОХРАНЕНИЕ СООБЩЕНИЙ И ПЕРЕНАПРАВЛЕНИЕ
    // ========================================
    
    // Сохраняем сообщения в сессию для отображения после редиректа
    if (!empty($errors) || !empty($successMessages)) {
        $_SESSION['flash_messages'] = [
            'success' => $successMessages,
            'error'   => $errors,
        ];
    }
    
    header("Location: support.php");
    exit;
}

// ========================================
// ОБНОВЛЕНИЕ СООБЩЕНИЙ
// ========================================

// Обновляем сообщения
if ($existingTicket) {
    $stmt    = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
    $stmt->execute([$existingTicket['id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ========================================
// ПОЛУЧЕНИЕ ЛОГОТИПА
// ========================================

// Получает логотип
$adminUserId = getAdminUserId($pdo);
$logoProfile = getFileVersionFromList($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg', $adminUserId);

// Название раздела
$titlemeta = 'Обратная связь с техподдержкой';

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($titlemeta) ?></title>
    <script>
        (function() {
            const t = localStorage.getItem('theme');
            if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        })();
    </script>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Локальные стили -->
    <link rel="stylesheet" href="../css/main.css">
    <link rel="icon" href="<?php echo escape($logoProfile); ?>" type="image/x-icon">
</head>
<body>
<div class="container-fluid">
<?php require_once __DIR__ . '/../template/sidebar.php'; ?>
<main class="main-content">
<?php require_once __DIR__ . '/../template/header.php'; ?>
<div class="content-card">
    
    <!-- Отображение сообщений -->
    <?php displayAlerts(
        $successMessages,  // Массив сообщений об успехе
        $errors,           // Массив сообщений об ошибках
        true               // Показывать сообщения как toast-уведомления true/false
    ); 
    ?>

    <?php if ($existingTicket): ?>
        <h5>История переписки</h5>
        <div class="mb-3">
        <?php foreach ($messages as $msg): ?>
            <div class="d-flex mb-3 <?= $msg['author_type'] === 'admin' ? 'justify-content-start' : 'justify-content-end' ?>">
                <div class="<?= $msg['author_type'] === 'admin' ? 'bg-light text-dark' : 'bg-primary text-white' ?>" style="max-width: 80%; padding: 0.75rem; border-radius: 12px; word-wrap: break-word;">
                    <small class="d-block opacity-75 mb-1">
                        <?php if ($msg['author_type'] === 'admin'): ?>
                            <i class="bi bi-shield-check"></i> Администратор
                        <?php else: ?>
                            <i class="bi bi-person"></i> Вы
                        <?php endif; ?>
                        — <?= escape(date('d.m.Y H:i', strtotime($msg['created_at']))) ?>
                    </small>
                    <div><?= nl2br(escape($msg['message'])) ?></div>
                    <?php if ($msg['attachment_path']): ?>
                        <div class="mt-2">
                            <a href="download.php?file=<?= escape(urlencode($msg['attachment_path'])) ?>&amp;ticket_id=<?= escape((string)$msg['ticket_id']) ?>"
                               class="btn btn-sm <?= $msg['author_type'] === 'admin' ? 'btn-outline-dark' : 'btn-outline-light' ?>">
                                <i class="bi bi-paperclip"></i> <?= escape(basename($msg['attachment_path'])) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <!-- Удалено поле action - больше не нужно -->
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
        <?php if ($existingTicket): ?>
            <input type="hidden" name="ticket_id" value="<?= escape((string)$existingTicket['id']) ?>">
        <?php endif; ?>
        <?php if (!$existingTicket): ?>
            <div class="mb-3">
                <label class="form-label">Тема обращения</label>
                <input type="text" class="form-control" name="subject" required minlength="2" maxlength="255" value="<?= escape($_POST['subject'] ?? '') ?>">
            </div>
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label">Сообщение</label>
            <textarea class="form-control" name="message" rows="4" minlength="2" maxlength="10000" required></textarea>
            <div class="form-text">Не более 10 000 знаков</div>
        </div>
        <div class="mb-3">
            <label class="form-label"><i class="bi bi-paperclip"></i> Прикрепить файл (pdf, jpg, png, txt, zip, до 10 МБ)</label>
            <input type="file" class="form-control" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.txt,.zip">
            <div class="form-text">Поддерживаются: PDF, изображения, текст, архивы.</div>
        </div>
        <!-- Удалена кнопка "Закрыть обращение" полностью -->
        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-send"></i> <?= $existingTicket ? 'Отправить ответ' : 'Отправить обращение' ?>
            </button>
        </div>
    </form>
</div>
</main>
</div>
<!-- Popper.js -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<!-- Модульный JS admin -->
<script type="module" src="../js/main.js"></script>
<!-- Удален весь JavaScript связанный с закрытием -->
</body>
</html>
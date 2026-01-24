<?php
/**
 * Файл: admin/support/support.php
 *
 * Модуль обратной связи для авторизованных ПОЛЬЗОВАТЕЛЕЙ.
 * Несмотря на расположение в /admin/, доступ имеют ТОЛЬКО обычные пользователи (не админы).
 * Администраторы автоматически перенаправляются в index.php.
 *
 * ИСПРАВЛЕНИЯ:
 * 1. ПОЛНОСТЬЮ УДАЛЕНА кнопка "Закрыть обращение"
 * 2. УДАЛЕНА вся PHP логика обработки action='close'
 * 3. УДАЛЕНА HTML кнопка закрытия и связанный с ней код
 * 4. УДАЛЕНА проверка $existing_ticket['status'] !== 'closed'
 * 5. ОСТАЛАСЬ только отправка новых обращений и ответов
 *
 * Возможности:
 * - отправка нового обращения
 * - переписка с поддержкой
 * - прикрепление файлов
 *
 * Файлы сохраняются в защищённой директории вне public_html.
 * Использует функции из /admin/functions/.
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

// Подключаем системные компоненты
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования
require_once __DIR__ . '/../functions/mailer.php';                   // Отправка email уведомлений 
require_once __DIR__ . '/../functions/display_alerts.php';           // Отображения сообщений
require_once __DIR__ . '/../functions/sanitization.php';             // Валидация экранирование

// === НАСТРОЙКА ПАПКИ ДЛЯ ФАЙЛОВ ===
// Путь вне public_html — например: /home/user/support_files/
// Можно изменить имя папки — главное, чтобы она была НЕ в DOCUMENT_ROOT
define('SUPPORT_ATTACHMENTS_DIR', $_SERVER['DOCUMENT_ROOT'] . '/../support/');

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
    header("Location: ../logout.php");
    exit;
}

// Название Админ-панели
$AdminPanel = $adminData['AdminPanel'] ?? 'AdminPanel';

// Включаем/отключаем логирование
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

try {
    $user = requireAuth($pdo);
    if (!$user) {
        $redirectTo = '../logout.php';
        logEvent("Неавторизованный доступ — перенаправление на: $redirectTo — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}", LOG_INFO_ENABLED, 'info');
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: $redirectTo");
        exit;
    }

    $userDataAdmin = getUserData($pdo, $user['id']);
    if ($userDataAdmin['author'] === 'admin') {
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: index.php");
        exit;
    }

    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg = $userDataAdmin['message'];
        $level = $userDataAdmin['level'];
        $logEnabled = match($level) {'info' => LOG_INFO_ENABLED, 'error' => LOG_ERROR_ENABLED, default => LOG_ERROR_ENABLED};
        logEvent($msg, $logEnabled, $level);
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");
        exit;
    }

    // Успех
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

    // Разрешить чат онлайн с администратором Нет/Да
    if (!$adminData['allow_online_chat']) {
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");
        exit;
    }

} catch (Exception $e) {
    logEvent("Ошибка при инициализации: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

$errors = [];
$successMessages = [];

// Загружаем последнее обращение
$stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$user['id']]);
$existing_ticket = $stmt->fetch(PDO::FETCH_ASSOC);
$messages = [];
if ($existing_ticket) {
    $stmt = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
    $stmt->execute([$existing_ticket['id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ИСПРАВЛЕНИЕ 1: УДАЛЕНА вся обработка action='close'

// Обработка POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Обновите страницу.';
        logEvent("CSRF проверка не пройдена — ID: " . $user['id'], LOG_ERROR_ENABLED, 'error');
    } else {
        // ИСПРАВЛЕНИЕ 2: УДАЛЕНА проверка $action === 'close'

        if (!$existing_ticket) {
            // Валидация многострочного текстового поля
            $resultsubject = validateTextareaField(trim($_POST['subject'] ?? ''), 2, 255, 'Тема обращения');
            if ($resultsubject['valid']) {
                $subject = ($resultsubject['value']);
            } else {
                $errors[] = ($resultsubject['error']);
                $subject = false;
            }
        }

        // Валидация многострочного текстового поля
        $resultmessage = validateTextareaField(trim($_POST['message'] ?? ''), 2, 10000, 'Сообщение');
        if ($resultmessage['valid']) {
            $message_text = ($resultmessage['value']);
        } else {
            $errors[] = ($resultmessage['error']);
            $message_text = false;
        }

        if (empty($errors)) {
            // Обработка вложения
            $attachment_path = null;
            if (!empty($_FILES['attachment']['name'])) {
                $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'txt', 'zip'];
                $max_size = 10 * 1024 * 1024;
                $file = $_FILES['attachment'];

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Ошибка загрузки файла.';
                } elseif ($file['size'] > $max_size) {
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

                        $filename = 'ticket_' . ($existing_ticket ? $existing_ticket['id'] : uniqid()) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $full_path = SUPPORT_ATTACHMENTS_DIR . $filename;

                        if (move_uploaded_file($file['tmp_name'], $full_path)) {
                            $attachment_path = $filename;
                        } else {
                            $errors[] = 'Не удалось сохранить файл.';
                            logEvent("Ошибка сохранения файла: $full_path", LOG_ERROR_ENABLED, 'error');
                        }
                    }
                }
            }
        }

        if (empty($errors)) {
            if (!$existing_ticket) {
                $stmt = $pdo->prepare("
                    INSERT INTO support_tickets (user_id, user_email, subject, status, created_at, updated_at)
                    VALUES (?, ?, ?, 'new', NOW(), NOW())
                ");
                $stmt->execute([$user['id'], $userDataAdmin['email'], $subject]);
                $ticket_id = $pdo->lastInsertId();
                logEvent("Создано новое обращение #{$ticket_id} от пользователя {$user['id']}", LOG_INFO_ENABLED, 'info');

                // Отправляет уведомление администратору о новом обращении или новом сообщении
                sendAdminSupportNotification($adminData['email'], $userDataAdmin['email'], $subject, $ticket_id, $AdminPanel, true);
            } else {
                $ticket_id = $existing_ticket['id'];
                // ИСПРАВЛЕНИЕ 3: УДАЛЕНА проверка на статус 'closed'
                $pdo->prepare("UPDATE support_tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticket_id]);
            }

            // Сохраняем сообщение
            $pdo->prepare("
                INSERT INTO support_messages (ticket_id, author_type, author_id, author_email, message, attachment_path, created_at)
                VALUES (?, 'user', ?, ?, ?, ?, NOW())
            ")->execute([$ticket_id, $user['id'], $userDataAdmin['email'], $message_text, $attachment_path]);

            if ($existing_ticket) {
                // Отправляет уведомление администратору о новом обращении или новом сообщении
                sendAdminSupportNotification($adminData['email'], $userDataAdmin['email'], '', $ticket_id, $AdminPanel, false);
            }

            $successMessages[] = 'Сообщение отправлено.';
        }
    }
}

// Обновляем сообщения
if ($existing_ticket) {
    $stmt = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
    $stmt->execute([$existing_ticket['id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получает логотип
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
// Название раздела
$titlemeta = 'Обратная связь с техподдержкой';

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
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
    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
</head>
<body>
<div class="container-fluid">
<?php require_once __DIR__ . '/../template/sidebar.php'; ?>
<main class="main-content">
<?php require_once __DIR__ . '/../template/header.php'; ?>
<div class="content-card">
    <?php displayAlerts($successMessages, $errors); ?>
    <?php if ($existing_ticket): ?>
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
                        — <?= date('d.m.Y H:i', strtotime($msg['created_at'])) ?>
                    </small>
                    <div><?= nl2br(escape($msg['message'])) ?></div>
                    <?php if ($msg['attachment_path']): ?>
                        <div class="mt-2">
                            <a href="download.php?file=<?= urlencode($msg['attachment_path']) ?>&ticket_id=<?= $msg['ticket_id'] ?>"
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
    <!-- ИСПРАВЛЕНИЕ 4: УДАЛЕНО поле action - больше не нужно -->
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
        <?php if ($existing_ticket): ?>
            <input type="hidden" name="ticket_id" value="<?= $existing_ticket['id'] ?>">
        <?php endif; ?>
        <?php if (!$existing_ticket): ?>
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
        <!-- ИСПРАВЛЕНИЕ 5: УДАЛЕНА кнопка "Закрыть обращение" полностью -->
        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-send"></i> <?= $existing_ticket ? 'Отправить ответ' : 'Отправить обращение' ?>
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
<!-- ИСПРАВЛЕНИЕ 6: УДАЛЕН весь JavaScript связанный с закрытием -->
</body>
</html>

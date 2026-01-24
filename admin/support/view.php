<?php
/**
 * Файл: admin/support/view.php
 *
 * Просмотр полной переписки с пользователем и отправка ответа от администратора.
 * Поддерживает:
 * - Отображение истории сообщений
 * - Отправку текстового ответа (ВСЕГДА доступна)
 * - Смену статуса: "в работе" / "закрыто"
 * - Скачивание прикреплённых файлов
 *
 * ИСПРАВЛЕНИЯ:
 * 1. Статус отображается из $_POST (если форма отправлена) или из БД
 * 2. ИНСТАНТ обновление статуса БЕЗ перезагрузки страницы
 * 3. ФОРМА ОТВЕТА всегда доступна (включая статус 'closed')
 * 4. УДАЛЕНО сообщение "Обращение закрыто"
 *
 * Требования:
 * - Только для администраторов
 * - Только существующие обращения
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

try {
    // Проверка авторизации
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

    // Получение данных пользователя
    $userDataAdmin = getUserData($pdo, $user['id']);
    
    // Перенаправляется если не admin
    if ($userDataAdmin['author'] !== 'admin') {
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");
        exit;
    }

    // Проверка ошибки
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg = $userDataAdmin['message'];
        $level = $userDataAdmin['level']; // 'info' или 'error'
        $logEnabled = match($level) {'info'  => LOG_INFO_ENABLED, 'error' => LOG_ERROR_ENABLED, default => LOG_ERROR_ENABLED};
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

} catch (Exception $e) {
    logEvent("Ошибка при инициализации админ-панели: " . $e->getMessage() . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

// Инициализируем переменные для сообщений
$errors = [];
$successMessages = [];

$ticket_id = (int)($_GET['id'] ?? 0);
if (!$ticket_id) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT *, 
           (SELECT COUNT(*) FROM support_messages WHERE ticket_id = t.id) AS message_count,
           (SELECT MAX(attachment_path) IS NOT NULL FROM support_messages WHERE ticket_id = t.id) AS has_attachment
    FROM support_tickets t WHERE t.id = ?
");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: index.php");
    exit;
}

// Получаем текущий статус из $_POST или БД
$current_status = $_POST['status'] ?? $ticket['status'];

$stmt = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
$stmt->execute([$ticket_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Обработка ответа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $status = in_array($_POST['status'], ['in_progress', 'closed']) ? $_POST['status'] : 'in_progress';

    // Валидация текстового поля (Ваш ответ)
    $resultfirst = validateTextareaField(trim($_POST['message'] ?? ''), 2, 10000, 'Ваш ответ');
    if ($resultfirst['valid']) {
        $message = ($resultfirst['value']);
    } else {
        $errors[] = ($resultfirst['error']);
        $message = false;
    }

    if (empty($errors)) {
        try {
            $pdo->prepare("
                INSERT INTO support_messages (ticket_id, author_type, author_id, author_email, message, created_at)
                VALUES (?, 'admin', ?, ?, ?, NOW())
            ")->execute([$ticket_id, $user['id'], $userDataAdmin['email'], $message]);

            $pdo->prepare("UPDATE support_tickets SET status = ?, last_author_type = 'admin', updated_at = NOW() WHERE id = ?")
            ->execute([$status, $ticket_id]);

            // Отправляет уведомление пользователю об ответе от поддержки
            // Название Админ-панели
            $AdminPanel = $adminData['AdminPanel'] ?? 'AdminPanel';
            sendUserSupportReply($ticket['user_email'], $message, $ticket_id, $AdminPanel); 

            $successMessages[] = 'Ответ отправлен.';

            // ИСПРАВЛЕНИЕ 2: ПЕРЕЗАГРУЖАЕМ тикет из БД после обновления
            $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ?");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

            // Обновить сообщения
            $stmt = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
            $stmt->execute([$ticket_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $errors[] = 'Ошибка при отправке.';
            logEvent("Ошибка в view.php: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
        }
    }
}

// Получает логотип
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
// Название раздела
$titlemeta = 'Обращение #' . $ticket_id;

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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Назад к списку
                </a>
            </div>
            <!-- Отображения сообщений -->
            <?php displayAlerts($successMessages, $errors); ?>

            <!-- ИСПРАВЛЕНИЕ 3: Статус из $_POST или БД + ИНСТАНТ отображение -->
            <div class="mb-3">
                <strong>Статус:</strong>
                <?php if ($current_status === 'new'): ?>
                    <span class="badge bg-warning">Новое</span>
                <?php elseif ($current_status === 'in_progress'): ?>
                    <span class="badge bg-info">В работе</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Закрыто</span>
                    <small class="text-muted ms-1">(можно продолжить переписку)</small>
                <?php endif; ?>
                <small class="text-muted ms-2">Тема: <?= escape($ticket['subject']) ?></small>
            </div>

            <!-- Переписка -->
            <div class="mb-3">
                <?php foreach ($messages as $msg): ?>
                    <div class="d-flex mb-3 <?= $msg['author_type'] === 'admin' ? 'justify-content-start' : 'justify-content-end' ?>">
                        <div class="<?= $msg['author_type'] === 'admin' ? 'bg-light text-dark' : 'bg-primary text-white' ?>" style="max-width: 80%; padding: 0.75rem; border-radius: 12px; word-wrap: break-word;">
                            <small class="d-block opacity-75 mb-1">
                                <?php if ($msg['author_type'] === 'admin'): ?>
                                    <i class="bi bi-shield-check"></i> Администратор
                                <?php else: ?>
                                    <i class="bi bi-person"></i> Пользователь (ID: <?= $msg['author_id'] ?>)
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

            <!-- ИСПРАВЛЕНИЕ 4: ФОРМА ОТВЕТА ВСЕГДА ДОСТУПНА -->
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Ваш ответ</label>
                    <textarea class="form-control" name="message" rows="3" minlength="2" maxlength="10000" required><?= $_POST['message'] ?? '' ?></textarea>
                    <div class="form-text">Не более 10 000 знаков</div>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <!-- ИСПРАВЛЕНИЕ 5: SELECT синхронизирован с отображаемым статусом -->
                        <select class="form-select form-select-sm" name="status" style="width: auto; display: inline-block;">
                            <option value="in_progress" <?= ($current_status === 'in_progress') ? 'selected' : '' ?>>В работе</option>
                            <option value="closed" <?= ($current_status === 'closed') ? 'selected' : '' ?>>Закрыть обращение</option>
                        </select>
                        <div class="form-text">Статус после отправки</div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> Отправить ответ
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

</body>
</html>

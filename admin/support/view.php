<?php

/**
 * Название файла:      view.php
 * Назначение:          Просмотр полной переписки с пользователем и отправка ответа от администратора.
 *                      Поддерживает:
 *                      - Отображение истории сообщений
 *                      - Отправку текстового ответа (ВСЕГДА доступна)
 *                      - Смену статуса: "в работе" / "закрыто"
 *                      - Скачивание прикреплённых файлов
 *                      
 *                      ИСПРАВЛЕНИЯ:
 *                      1. Статус отображается из $_POST (если форма отправлена) или из БД
 *                      2. ИНСТАНТ обновление статуса БЕЗ перезагрузки страницы
 *                      3. ФОРМА ОТВЕТА всегда доступна (включая статус 'closed')
 *                      4. УДАЛЕНО сообщение "Обращение закрыто"
 *                      5. ДОБАВЛЕНА ПРОВЕРКА CSRF-ТОКЕНА
 *                      
 *                      Требования:
 *                      - Только для администраторов
 *                      - Только существующие обращения
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors'   => false,   // включение отображения ошибок true/false
    'set_encoding'     => true,    // включение кодировки UTF-8
    'db_connect'       => true,    // подключение к базе
    'auth_check'       => true,    // подключение функций авторизации
    'file_log'         => true,    // подключение системы логирования
    'display_alerts'   => true,    // подключение отображения сообщений
    'sanitization'     => true,    // подключение валидации/экранирования
    'mailer'           => true,    // подключение отправка email уведомлений
    'csrf_token'       => true,    // генерация CSRF-токена
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
// Логировать успешные события true/false
define(
    'LOG_INFO_ENABLED',
    ($adminData['log_info_enabled'] ?? false) === true
);

// Логировать ошибки true/false
define(
    'LOG_ERROR_ENABLED',
    ($adminData['log_error_enabled'] ?? false) === true
);

// ========================================
// АВТОРИЗАЦИЯ И ПРОВЕРКА ПРАВ
// ========================================

try {
    
    // Проверка авторизации
    $user = requireAuth($pdo);
    
    if (!$user) {
        $redirectTo = '../logout.php';
        $logMessage = "Неавторизованный доступ — перенаправление на: $redirectTo — IP: "
            . "{$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}";
        logEvent($logMessage, LOG_INFO_ENABLED, 'info');
        header("Location: $redirectTo");
        exit;
    }

    // Получение данных пользователя
    $userDataAdmin = getUserData($pdo, $user['id']);
    
    // Перенаправляется если не admin
    if ($userDataAdmin['author'] !== 'admin') {
        header("Location: ../logout.php");
        exit;
    }

    // Проверка ошибки
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg        = $userDataAdmin['message'];
        $level      = $userDataAdmin['level'];  // 'info' или 'error'
        $logEnabled = match ($level) {
            'info'  => LOG_INFO_ENABLED,
            'error' => LOG_ERROR_ENABLED,
            default => LOG_ERROR_ENABLED,
        };
        
        logEvent($msg, $logEnabled, $level);
        header("Location: ../logout.php");
        exit;
    }

    // Декодируем JSON-данные администратора
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

} catch (Exception $e) {
    $logMessage = "Ошибка при инициализации админ-панели: " . $e->getMessage()
        . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    header("Location: ../logout.php");
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ И ПРОВЕРКА ТИКЕТА
// ========================================

$ticketId = (int)($_GET['id'] ?? 0);

if (!$ticketId) {
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT *, 
           (SELECT COUNT(*) FROM support_messages WHERE ticket_id = t.id) AS message_count,
           (SELECT MAX(attachment_path) IS NOT NULL FROM support_messages WHERE ticket_id = t.id) AS has_attachment
    FROM support_tickets t WHERE t.id = ?
");
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    header("Location: index.php");
    exit;
}

// ========================================
// ОБРАБОТКА ФОРМЫ
// ========================================

// Получаем текущий статус из $_POST или БД
$currentStatus = $_POST['status'] ?? $ticket['status'];

$stmt = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
$stmt->execute([$ticketId]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Обработка ответа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ========================================
    // ПРОВЕРКА CSRF-ТОКЕНА
    // ========================================
    
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[]   = "Недопустимый запрос. Повторите попытку.";
        $logMessage = "Попытка CSRF-атаки при ответе в поддержку — Тикет ID: $ticketId — IP: "
            . "{$_SERVER['REMOTE_ADDR']}";
        logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    } else {
        
        // ========================================
        // ВАЛИДАЦИЯ И ОТПРАВКА СООБЩЕНИЯ
        // ========================================
        
        $status = in_array($_POST['status'], ['in_progress', 'closed']) ? $_POST['status'] : 'in_progress';

        // Валидация текстового поля (Ваш ответ)
        $resultfirst = validateTextareaField(trim($_POST['message'] ?? ''), 2, 10000, 'Ваш ответ');
        
        if ($resultfirst['valid']) {
            $message = ($resultfirst['value']);
        } else {
            $errors[] = ($resultfirst['error']);
            $message  = false;
        }

        if (empty($errors)) {
            try {
                $insertStmt = $pdo->prepare(
                    "INSERT INTO support_messages (ticket_id, author_type, author_id, author_email, message, created_at)
                     VALUES (?, 'admin', ?, ?, ?, NOW())"
                );
                $insertStmt->execute([$ticketId, $user['id'], $userDataAdmin['email'], $message]);

                $updateStmt = $pdo->prepare(
                    "UPDATE support_tickets SET status = ?, last_author_type = 'admin', updated_at = NOW()
                     WHERE id = ?"
                );
                $updateStmt->execute([$status, $ticketId]);

                // Отправляет уведомление пользователю об ответе от поддержки
                // Название Админ-панели
                $adminPanel = $adminData['AdminPanel'] ?? 'AdminPanel';
                sendUserSupportReply($ticket['user_email'], $message, $ticketId, $adminPanel);

                $successMessages[] = 'Ответ отправлен.';

                // ИСПРАВЛЕНИЕ 2: ПЕРЕЗАГРУЖАЕМ тикет из БД после обновления
                $stmt = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ?");
                $stmt->execute([$ticketId]);
                $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

                // Обновить сообщения
                $stmt = $pdo->prepare(
                    "SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC"
                );
                $stmt->execute([$ticketId]);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            } catch (Exception $e) {
                $errors[] = 'Ошибка при отправке.';
                logEvent("Ошибка в view.php: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
            }
        }
    }
}

// ========================================
// ПОЛУЧЕНИЕ ЛОГОТИПА И НАСТРОЕК СТРАНИЦЫ
// ========================================

// Получает логотип
$adminUserId = getAdminUserId($pdo);
$logoProfile = getFileVersionFromList(
    $pdo,
    $adminData['profile_logo'] ?? '',
    'thumbnail',
    '../img/avatar.svg',
    $adminUserId,
);

// Название раздела
$titlemeta = 'Обращение #' . $ticketId;

// Закрываем соединение при завершении скрипта

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($titlemeta) ?></title>
    
    <!-- ========================================
         МОДУЛЬ УПРАВЛЕНИЯ СВЕТЛОЙ/ТЁМНОЙ ТЕМОЙ
         ======================================== -->
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Назад к списку
                </a>
            </div>
            
            <!-- ========================================
                 ОТОБРАЖЕНИЕ СООБЩЕНИЙ
                 ======================================== -->
            <?php displayAlerts(
                $successMessages,  // Массив сообщений об успехе
                $errors,           // Массив сообщений об ошибках
                true,              // Показывать сообщения как toast-уведомления true/false
            ); ?>

            <!-- ========================================
                 СТАТУС ОБРАЩЕНИЯ
                 ======================================== -->
            
            <!-- ИСПРАВЛЕНИЕ 3: Статус из $_POST или БД + ИНСТАНТ отображение -->
            <div class="mb-3">
                <strong>Статус:</strong>
                <?php if ($currentStatus === 'new'): ?>
                    <span class="badge bg-warning">Новое</span>
                <?php elseif ($currentStatus === 'in_progress'): ?>
                    <span class="badge bg-info">В работе</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Закрыто</span>
                    <small class="text-muted ms-1">(можно продолжить переписку)</small>
                <?php endif; ?>
                <small class="text-muted ms-2">Тема: <?= escape($ticket['subject']) ?></small>
            </div>

            <!-- ========================================
                 ПЕРЕПИСКА
                 ======================================== -->
            <div class="mb-3">
                <?php foreach ($messages as $msg): ?>
                    <div class="d-flex mb-3 <?= $msg['author_type'] === 'admin' ? 'justify-content-start' : 'justify-content-end' ?>">
                        <div class="<?= $msg['author_type'] === 'admin' ? 'bg-light text-dark' : 'bg-primary text-white' ?>" style="max-width: 80%; padding: 0.75rem; border-radius: 12px; word-wrap: break-word;">
                            <small class="d-block opacity-75 mb-1">
                                <?php if ($msg['author_type'] === 'admin'): ?>
                                    <i class="bi bi-shield-check"></i> Администратор
                                <?php else: ?>
                                    <i class="bi bi-person"></i> Пользователь (ID: <?= escape((string)$msg['author_id']) ?>)
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

            <!-- ========================================
                 ФОРМА ОТВЕТА
                 ======================================== -->
            
            <!-- ИСПРАВЛЕНИЕ 4: ФОРМА ОТВЕТА ВСЕГДА ДОСТУПНА -->
            <form method="POST">
                <!-- CSRF-токен -->
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                
                <div class="mb-3">
                    <label class="form-label">Ваш ответ</label>
                    <textarea class="form-control" name="message" rows="3" minlength="2" maxlength="10000" required><?= escape($_POST['message'] ?? '') ?></textarea>
                    <div class="form-text">Не более 10 000 знаков</div>
                </div>
                
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <!-- ИСПРАВЛЕНИЕ 5: SELECT синхронизирован с отображаемым статусом -->
                        <select class="form-select form-select-sm" name="status" style="width: auto; display: inline-block;">
                            <option value="in_progress" <?= ($currentStatus === 'in_progress') ? 'selected' : '' ?>>В работе</option>
                            <option value="closed" <?= ($currentStatus === 'closed') ? 'selected' : '' ?>>Закрыть обращение</option>
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

<!-- ========================================
     ПОДКЛЮЧЕНИЕ СКРИПТОВ
     ======================================== -->
    
<!-- Popper.js -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

<!-- Модульный JS admin -->
<script type="module" src="../js/main.js"></script>

</body>
</html>
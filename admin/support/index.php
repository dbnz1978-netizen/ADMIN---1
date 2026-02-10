<?php

/**
 * Название файла:      index.php
 * Назначение:          Админка: список всех обращений пользователей в систему поддержки
 *                      Поддерживает:
 *                      - Пагинацию (10 обращений на страницу)
 *                      - Поиск по email
 *                      - Фильтрацию по статусу: все / новые / в работе / закрытые
 *                      - Удаление обращений (с удалением файлов)
 *                      Требования:
 *                      - Только для авторизованных администраторов (проверка $userDataAdmin['author'] === 'admin')
 *                      - Использует функции из /admin/functions/
 * Автор:               User
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ СКРИПТА
// ========================================

$config = [
    'display_errors' => false,  // Включение отображения ошибок (true/false)
    'set_encoding'   => true,   // Включение кодировки UTF-8
    'db_connect'     => true,   // Подключение к базе данных
    'auth_check'     => true,   // Подключение функций авторизации
    'file_log'       => true,   // Подключение системы логирования
    'display_alerts' => true,   // Подключение отображения сообщений
    'sanitization'   => true,   // Подключение валидации/экранирования
    'pagination'     => true,   // Генерация пагинации
    'csrf_token'     => true,   // Генерация CSRF-токена
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
// НАСТРОЙКИ ЛОГИРОВАНИЯ
// ========================================

// Логировать успешные события
define(
    'LOG_INFO_ENABLED',
    ($adminData['log_info_enabled'] ?? false) === true
);
// Логировать ошибки
define(
    'LOG_ERROR_ENABLED',
    ($adminData['log_error_enabled'] ?? false) === true
);

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ И ПОЛУЧЕНИЕ ДАННЫХ ПОЛЬЗОВАТЕЛЯ
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
// ЗАГРУЗКА FLASH-СООБЩЕНИЙ ИЗ СЕССИИ
// ========================================

$successMessages = [];
$errors          = [];

if (!empty($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors          = $_SESSION['flash_messages']['error'] ?? [];
    // Удаляем их, чтобы не показывались при повторной загрузке
    unset($_SESSION['flash_messages']);
}

// ========================================
// ПАРАМЕТРЫ ПАГИНАЦИИ И ФИЛЬТРАЦИИ
// ========================================

$page         = max(1, (int) ($_GET['page'] ?? 1));
$limit        = 10;
$offset       = ($page - 1) * $limit;
$searchEmail  = trim($_GET['search_email'] ?? '');
$statusFilter = $_GET['status'] ?? 'all';

if (!in_array($statusFilter, ['all', 'new', 'in_progress', 'closed'])) {
    $statusFilter = 'all';
}

// ========================================
// СБОРКА УСЛОВИЯ WHERE ДЛЯ ЗАПРОСА
// ========================================

$params = [];
$where  = [];

if ($searchEmail !== '') {
    $where[]  = "t.user_email LIKE ?";
    $params[] = "%{$searchEmail}%";
}

if ($statusFilter !== 'all') {
    $where[]  = "t.status = ?";
    $params[] = $statusFilter;
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// ========================================
// ПОЛУЧЕНИЕ СПИСКА ОБРАЩЕНИЙ С НЕПРОЧИТАННЫМИ СООБЩЕНИЯМИ
// ========================================

// Дополнительно: получаем список ticket_id, где последнее сообщение — от пользователя (для статуса online)
$unreadStmt = $pdo->prepare("
    SELECT DISTINCT t.id
    FROM support_tickets t
    INNER JOIN support_messages m ON m.ticket_id = t.id
    WHERE t.status != 'closed'
    AND m.author_type = 'user'
    AND m.created_at = (
        SELECT MAX(created_at)
        FROM support_messages m2
        WHERE m2.ticket_id = t.id
    )
");
$unreadStmt->execute();
$unreadTicketIds = array_column($unreadStmt->fetchAll(PDO::FETCH_ASSOC), 'id');

// ========================================
// ЗАГРУЗКА ОБРАЩЕНИЙ С ПАГИНАЦИЕЙ
// ========================================

// Загрузка обращений (LIMIT/OFFSET как числа!)
$sql = "
    SELECT t.*,
           COUNT(m.id) AS message_count,
           MAX(m.attachment_path) IS NOT NULL AS has_attachment
    FROM support_tickets t
    LEFT JOIN support_messages m ON m.ticket_id = t.id
    {$whereClause}
    GROUP BY t.id
    ORDER BY t.updated_at DESC
    LIMIT {$limit} OFFSET {$offset}
";

$stmt   = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ========================================
// ПОДСЧЁТ ОБЩЕГО КОЛИЧЕСТВА ОБРАЩЕНИЙ
// ========================================

// Общее количество
$countSql   = "SELECT COUNT(DISTINCT t.id) FROM support_tickets t {$whereClause}";
$countStmt  = $pdo->prepare($countSql);
$countStmt->execute($params);
$total      = (int) $countStmt->fetchColumn();
$pages      = ceil($total / $limit);

// ========================================
// ПОДГОТОВКА ДАННЫХ ДЛЯ ШАБЛОНА
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
$titlemeta = 'Обращения пользователей';

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= escape($titlemeta) ?></title>
    
    <!-- Автоматическое применение сохраненной темы -->
    <script>
        (function() {
            const t = localStorage.getItem('theme');
            if (t === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        })();
    </script>
    
    <!-- Подключение стилей -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
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
                    true               // Показывать сообщения как toast-уведомления
                );
                ?>
                
                <!-- ========================================
                     ФИЛЬТРЫ
                     ======================================== -->
                <div class="row mb-4">
                    <div class="col-md-5">
                        <form method="GET" class="d-flex">
                            <input type="text" name="search_email" class="form-control" placeholder="Поиск по email..." value="<?= escape($searchEmail) ?>">
                            <button class="btn btn-outline-secondary ms-2" type="submit">
                                <i class="bi bi-search" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                    <div class="col-md-7 text-md-end mt-2 mt-md-0">
                        <div class="btn-group" style="gap: 4px;">
                            <a href="?status=all&page=1" class="btn btn-sm <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>">Все</a>
                            <a href="?status=new&page=1" class="btn btn-sm <?= $statusFilter === 'new' ? 'btn-warning' : 'btn-outline-warning' ?>">Новые</a>
                            <a href="?status=in_progress&page=1" class="btn btn-sm <?= $statusFilter === 'in_progress' ? 'btn-info' : 'btn-outline-info' ?>">В работе</a>
                            <a href="?status=closed&page=1" class="btn btn-sm <?= $statusFilter === 'closed' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Закрытые</a>
                        </div>
                    </div>
                </div>

                <!-- ========================================
                     ТАБЛИЦА ОБРАЩЕНИЙ
                     ======================================== -->
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Email</th>
                                <th>Тема</th>
                                <th>Статус</th>
                                <th>Обновлено</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tickets)): ?>
                                <tr><td colspan="7" class="text-center text-muted">Обращений не найдено</td></tr>
                            <?php else: ?>
                                <?php foreach ($tickets as $t): ?>
                                    <tr>
                                        <td>
                                            <a href="/admin/user/add_account.php?id=<?= escape((string) $t['user_id']) ?>" class="user-link">
                                                <span class="badge bg-success">ID: <?= escape((string) $t['id']) ?></span>
                                            </a>
                                            <?php if (in_array($t['id'], $unreadTicketIds)): ?>
                                                <span class="user-status status-online"></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= escape($t['user_email']) ?></td>
                                        <td>
                                            <?= escape($t['subject']) ?>
                                            <?php if ($t['has_attachment']): ?><i class="bi bi-paperclip"></i><?php endif; ?>
                                            <div class="form-text">Сообщений: <?= escape((string) $t['message_count']) ?></div>
                                        </td>
                                        <td>
                                            <?php if ($t['status'] === 'new'): ?>
                                                <span class="badge bg-warning">Новое</span>
                                            <?php elseif ($t['status'] === 'in_progress'): ?>
                                                <span class="badge bg-info">В работе</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Закрыто</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= escape(date('d.m H:i', strtotime($t['updated_at']))) ?></td>
                                        <td>
                                            <a href="view.php?id=<?= escape((string) $t['id']) ?>" class="btn btn-sm btn-outline-primary" title="<?= escape('Ответить') ?>">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Удалить обращение и все прикреплённые файлы? Действие необратимо.');">
                                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="id" value="<?= escape((string) $t['id']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="<?= escape('Удалить') ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ========================================
                     ПАГИНАЦИЯ
                     ======================================== -->
                <?= renderPagination(
                    $page,
                    $pages,
                    array_filter([
                        'search_email' => $searchEmail,
                        'status'       => $statusFilter
                    ])
                ) ?>
            </div>
        </main>
    </div>

    <!-- ========================================
         ПОДКЛЮЧЕНИЕ JAVASCRIPT БИБЛИОТЕК
         ======================================== -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script type="module" src="../js/main.js"></script>
</body>
</html>
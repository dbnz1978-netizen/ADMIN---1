<?php
/**
* Файл: admin/support/index.php
*
* Админка: список всех обращений пользователей в систему поддержки.
* Поддерживает:
* - Пагинацию (10 обращений на страницу)
* - Поиск по email
* - Фильтрацию по статусу: все / новые / в работе / закрытые
* - Удаление обращений (с удалением файлов)
*
* Требования:
* - Только для авторизованных администраторов (проверка $userDataAdmin['author'] === 'admin')
* - Использует функции из /admin/functions/
*/

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/** 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

// Подключаем системные компоненты
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования
require_once __DIR__ . '/../functions/display_alerts.php';           // Отображения сообщений
require_once __DIR__ . '/../functions/sanitization.php';             // Валидация экранирование

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
    // перенаправляется если не admin
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

// Параметры
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;
$search_email = trim($_GET['search_email'] ?? '');
$status_filter = $_GET['status'] ?? 'all';

if (!in_array($status_filter, ['all', 'new', 'in_progress', 'closed'])) {
    $status_filter = 'all';
}

// Сборка WHERE
$params = [];
$where = [];
if ($search_email !== '') {
    $where[] = "t.user_email LIKE ?";
    $params[] = "%{$search_email}%";
}
if ($status_filter !== 'all') {
    $where[] = "t.status = ?";
    $params[] = $status_filter;
}
$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

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

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Общее количество
$countSql = "SELECT COUNT(DISTINCT t.id) FROM support_tickets t {$whereClause}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = ceil($total / $limit);

// Получает логотип
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
// Название раздела
$titlemeta = 'Обращения пользователей';

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
            <!-- Фильтры -->
            <div class="row mb-4">
                <div class="col-md-5">
                    <form method="GET" class="d-flex">
                        <input type="text" name="search_email" class="form-control" placeholder="Поиск по email..." value="<?= escape($search_email) ?>">
                        <button class="btn btn-outline-secondary ms-2" type="submit">Найти</button>
                    </form>
                </div>
                <div class="col-md-7 text-md-end mt-2 mt-md-0">
                    <div class="btn-group" style="gap: 4px;">
                        <a href="?status=all&page=1" class="btn btn-sm <?= $status_filter === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>">Все</a>
                        <a href="?status=new&page=1" class="btn btn-sm <?= $status_filter === 'new' ? 'btn-warning' : 'btn-outline-warning' ?>">Новые</a>
                        <a href="?status=in_progress&page=1" class="btn btn-sm <?= $status_filter === 'in_progress' ? 'btn-info' : 'btn-outline-info' ?>">В работе</a>
                        <a href="?status=closed&page=1" class="btn btn-sm <?= $status_filter === 'closed' ? 'btn-secondary' : 'btn-outline-secondary' ?>">Закрытые</a>
                    </div>
                </div>
            </div>

            <!-- Таблица -->
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
                                        <a href="/admin/user/add_account.php?id=<?= $t['user_id'] ?>" class="user-link"><span class="badge bg-success">ID: <?= $t['id'] ?></span></a>
                                        <?php if (in_array($t['id'], $unreadTicketIds)): ?>
                                            <span class="user-status status-online"></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= escape($t['user_email']) ?></td>
                                    <td>
                                        <?= escape($t['subject']) ?>
                                        <?php if ($t['has_attachment']): ?><i class="bi bi-paperclip"></i><?php endif; ?>
                                        <div class="form-text">Сообщений: <?= $t['message_count'] ?></div>
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
                                    <td><?= date('d.m H:i', strtotime($t['updated_at'])) ?></td>
                                    <td>
                                        <a href="view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary" title="<?= escape('Ответить') ?>">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="delete.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Удалить обращение и все прикреплённые файлы? Действие необратимо.')" title="<?= escape('Удалить') ?>">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Пагинация -->
            <?php if ($pages > 1): ?>
                <nav aria-label="Пагинация">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_filter(array_merge($_GET, ['page' => $page - 1]))) ?>">Назад</a>
                            </li>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_filter(array_merge($_GET, ['page' => $i]))) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($page < $pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_filter(array_merge($_GET, ['page' => $page + 1]))) ?>">Вперед</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
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
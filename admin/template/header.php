<?php
/**
 * Файл: /admin/template/header.php
 *
 * Верхняя панель сайта.
 * Динамически определяет:
 * - ссылку на модуль обратной связи (в зависимости от роли)
 * - количество непрочитанных сообщений
 *
 * Требует:
 * - $pdo — активное соединение с БД
 * - $user — данные авторизованного пользователя
 * - $userDataAdmin — данные пользователя из функции getUserData()
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/** 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

if (isset($user) && isset($userDataAdmin) && !isset($userDataAdmin['error'])) {
    // === Определяем ссылку и количество уведомлений ===
    if ($userDataAdmin['author'] === 'admin') {
        $support_link = '/admin/support/index.php';

        // Админ: тикеты, где последнее сообщение от пользователя и статус ≠ closed
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT t.id)
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
        $stmt->execute();
        $unread_count = (int)$stmt->fetchColumn();

    } else {
        $support_link = '/admin/support/support.php';

        // Пользователь: тикеты, где последнее сообщение от админа и статус ≠ closed
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT t.id)
            FROM support_tickets t
            INNER JOIN support_messages m ON m.ticket_id = t.id
            WHERE t.user_id = ?
              AND t.status != 'closed'
              AND m.author_type = 'admin'
              AND m.created_at = (
                  SELECT MAX(created_at)
                  FROM support_messages m2
                  WHERE m2.ticket_id = t.id
              )
        ");
        $stmt->execute([$user['id']]);
        $unread_count = (int)$stmt->fetchColumn();
    }
} else {
    $support_link = '#';
    $unread_count = 0;
}
?>
<!-- Верхняя панель -->
<header class="topbar">
    <button class="menu-toggle" aria-label="Переключить меню" aria-expanded="false">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <h1 class="page-title">
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <?php echo isset($titlemeta) ? escape($titlemeta) : 'Без названия'; ?>
    </h1>
    <div class="topbar-actions">
        <?php if ($adminData['allow_online_chat'] === true || $userDataAdmin['author'] == 'admin') { ?>      
        <a href="<?= $support_link ?>" class="notification-icon" role="button">
            <div class="d-flex flex-column flex-sm-row align-items-center gap-1">
                <i class="bi bi-bell" aria-hidden="true" title="Техподдержка"></i>
                <?php if ($unread_count > 0): ?>
                    <span class="notification-badge"><?= $unread_count ?></span>
                <?php endif; ?>
            </div>
        </a>
        <?php } ?>        
        <div class="user-avatar">
            <a href="/admin/user/personal_data.php" role="button">
                <img src="<?= $user_avatar; ?>" title="Аватар пользователя">
            </a>
        </div>
    </div>
</header>
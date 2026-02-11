<?php
// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}
?>

<!-- Меню настройки новости -->
<div class="mb-3">
    <?php if (isset($_GET['news_id']) && !empty($_GET['news_id']) && is_numeric($_GET['news_id'])) { ?>
        <a href="add_article.php?id=<?= isset($_GET['news_id']) ? (int)$_GET['news_id'] : 0; ?>" 
            class="btn btn-primary btn-sm tooltip-example">
            <i class="bi bi-arrow-left"></i> Вернуться к новости 
        </a>
    <?php }  ?>

    <?php
        // Определяем, находится ли пользователь на указанных страницах
        $currentPage = basename($_SERVER['SCRIPT_NAME']);
        $isInMenu = in_array($currentPage, ['extra_list.php', 'add_extra.php']);
    ?>
    <a href="extra_list.php?news_id=<?= isset($_GET['news_id']) ? (int)$_GET['news_id'] : (int)$_GET['id']; ?>" 
        class="btn btn-sm tooltip-example <?= $isInMenu ? 'btn-secondary' : 'btn-primary' ?>">
        <?php if (basename($_SERVER['SCRIPT_NAME']) === 'add_extra.php') { echo '<i class="bi bi-arrow-left"></i>'; } ?> <i class="bi bi-filetype-txt"></i> Дополнительное содержимое
    </a>
</div>

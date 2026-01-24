<!-- Меню настройки страницы -->
<div class="mb-3">
    <?php if (isset($_GET['author']) && !empty($_GET['author']) && is_numeric($_GET['author'])) { ?>
        <a href="add_pages.php?id=<?= isset($_GET['author']) ? (int)$_GET['author'] : 0; ?>" 
            class="btn btn-primary btn-sm tooltip-example">
            <i class="bi bi-arrow-left"></i> Вернуться к страницам 
        </a>
    <?php }  ?>

    <?php
        // Определяем, находится ли пользователь на указанных страницах
        $pagesMenu = basename($_SERVER['SCRIPT_NAME']);
        $isInMenu = in_array($pagesMenu, ['pages_extra_list.php', 'add_pages_extra.php']);
    ?>
    <a href="pages_extra_list.php?author=<?= isset($_GET['author']) ? (int)$_GET['author'] : (int)$_GET['id']; ?>" 
        class="btn btn-sm tooltip-example <?= $isInMenu ? 'btn-secondary' : 'btn-primary' ?>">
        <?php if (basename($_SERVER['SCRIPT_NAME']) === 'add_pages_extra.php') { echo '<i class="bi bi-arrow-left"></i>'; } ?> <i class="bi bi-filetype-txt"></i> Описание страницы
    </a>

    <?php if (!empty($defaultUrl) && !empty($defaultParentUrl)) { ?>
        <a href="<?= $defaultParentUrl ? '/' . escape($defaultParentUrl) . '/' . escape($defaultUrl) : '/' . escape($defaultUrl) ?>"
            target="_blank"
            class="btn btn-success btn-sm tooltip-example"
            title="<?= escape('Открыть в новом окне') ?>">
            <i class="bi bi-box-arrow-up-right"></i>
        </a>
    <?php } ?>
    
</div>




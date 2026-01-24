<!-- Меню настройки товара -->
<div class="mb-3">
    <?php if (isset($_GET['author']) && !empty($_GET['author']) && is_numeric($_GET['author'])) { ?>
        <a href="add_product.php?id=<?= isset($_GET['author']) ? (int)$_GET['author'] : 0; ?>" 
            class="btn btn-primary btn-sm tooltip-example">
            <i class="bi bi-arrow-left"></i> Вернуться к товару 
        </a>
    <?php }  ?>

    <?php
        // Определяем, находится ли пользователь на указанных страницах
        $shopMenu = basename($_SERVER['SCRIPT_NAME']);
        $isInMenu = in_array($shopMenu, ['shop_extra_list.php', 'add_shop_extra.php']);
    ?>
    <a href="shop_extra_list.php?author=<?= isset($_GET['author']) ? (int)$_GET['author'] : (int)$_GET['id']; ?>" 
        class="btn btn-sm tooltip-example <?= $isInMenu ? 'btn-secondary' : 'btn-primary' ?>">
        <?php if (basename($_SERVER['SCRIPT_NAME']) === 'add_shop_extra.php') { echo '<i class="bi bi-arrow-left"></i>'; } ?> <i class="bi bi-filetype-txt"></i> Описание товара
    </a>
</div>
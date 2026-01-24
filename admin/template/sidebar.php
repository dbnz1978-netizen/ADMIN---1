<?php
/**
 * Файл: /admin/template/sidebar.php
 *
 * Боковое меню
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

// === Подключение зависимостей ===
require_once __DIR__ . '/../functions/sanitization.php'; // Валидация экранирование 

// Извлекаем основные данные пользователя
$defaultFirstName = $currentData['first_name'] ?? '';
$selectedImages = $currentData['profile_images'] ?? '';

// Извлекаем настройки для пользователей
$allow_photo_upload = $adminData['allow_photo_upload'] ?? false; // Разрешить загрузку фотографий для главного admin

// Всегда разрешить загрузку фотографий для главного admin
if ($userDataAdmin['author'] == 'admin') {
    $allow_photo_upload = true;
}

if ($allow_photo_upload === true) {
    // Получает первое изображения из строки ID (например, "123,456")
    $user_avatar = getFileVersionFromList($pdo, $selectedImages, 'thumbnail', '../img/avatar.svg');
} else { 
    // значение по умолчанию
    $user_avatar = '../img/avatar.svg';
}

// Проверка прав доступа к магазину
$showShopMenu = false;
if ($userDataAdmin['author'] === 'admin' && ($adminData['allow_shop_admin'] ?? false) === true) {
    $showShopMenu = true;
} elseif ($userDataAdmin['author'] === 'user' && ($adminData['allow_shop_users'] ?? false) === true) {
    $showShopMenu = true;
}

// Проверка прав доступа к записям
$recordMenu = false;
if ($userDataAdmin['author'] === 'admin' && ($adminData['allow_catalog_admin'] ?? false) === true) {
    $recordMenu = true;
} elseif ($userDataAdmin['author'] === 'user' && ($adminData['allow_catalog_users'] ?? false) === true) {
    $recordMenu = true;
}

// Проверка прав доступа к страницам
$pagesMenu = false;
if ($userDataAdmin['author'] === 'admin' && ($adminData['allow_pages_admin'] ?? false) === true) {
    $pagesMenu = true;
} elseif ($userDataAdmin['author'] === 'user' && ($adminData['allow_pages_users'] ?? false) === true) {
    $pagesMenu = true;
}

// Проверка прав доступа к новостям
$newsMenu = false;
if ($userDataAdmin['author'] === 'admin' && ($adminData['allow_news_admin'] ?? false) === true) {
    $newsMenu = true;
} elseif ($userDataAdmin['author'] === 'user' && ($adminData['allow_news_users'] ?? false) === true) {
    $newsMenu = true;
}
?>

<!-- Боковое меню -->
<nav class="sidebar" aria-label="Основная навигация">
    <!-- Заголовок с логотипом -->
    <div class="sidebar-header">
        <a href="/admin/user/index.php" class="logo" style="gap: 4px;">
            <img src="<?php echo escape($logo_profile); ?>" alt="<?php echo escape($defaultFirstName ?: 'Администратор'); ?>" loading="lazy" width="50" height="50">
            <?= escape($adminData['AdminPanel'] ?? 'AdminPanel') ?>
        </a>
    </div>

    <!-- Навигация -->
    <div class="sidebar-nav">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a href="/admin/user/personal_data.php" class="nav-link <?php if (basename($_SERVER['SCRIPT_NAME']) === 'personal_data.php') { echo 'active'; } ?>">
                    <i class="bi bi-person-vcard"></i>
                    <span>Редактирование профиля</span>
                </a>
            </li>

            <?php if ($adminData['allow_photo_upload'] === true || $userDataAdmin['author'] == 'admin') { ?>
            <li class="nav-item">
                <a href="/admin/user/main_images.php" class="nav-link <?php if (basename($_SERVER['SCRIPT_NAME']) === 'main_images.php') { echo 'active'; } ?>">
                    <i class="bi bi-card-image"></i>
                    <span>Библиотека файлов</span>
                </a>
            </li>
            <?php } ?>

            <?php if ($userDataAdmin['author'] == 'admin') { ?>
                <?php
                // Определяем, находится ли пользователь внутри подменю "Пользователи"
                $currentScript = basename($_SERVER['SCRIPT_NAME']);
                $currentPath = $_SERVER['SCRIPT_NAME'];
                $isInAccountSubmenu = strpos($currentPath, '/admin/user/') !== false && in_array($currentScript, ['accounts_list.php', 'add_account.php']);
                ?>
                <li class="nav-item">
                    <a href="#settingsSubmenu_account" 
                       class="nav-link <?= $isInAccountSubmenu ? '' : 'collapsed' ?>"
                       data-bs-toggle="submenu" 
                       aria-expanded="<?= $isInAccountSubmenu ? 'true' : 'false' ?>">
                        <i class="bi bi-people"></i>
                        <span>Пользователи</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="submenu collapse <?= $isInAccountSubmenu ? 'show' : '' ?>" id="settingsSubmenu_account">
                        <a href="/admin/user/accounts_list.php" class="nav-link <?= strpos($currentPath, '/admin/user/') !== false && $currentScript === 'accounts_list.php' ? 'active' : '' ?>">
                            <i class="bi bi-card-list"></i>
                            <span>Список</span>
                        </a>
                        <a href="/admin/user/add_account.php" class="nav-link <?= strpos($currentPath, '/admin/user/') !== false && $currentScript === 'add_account.php' ? 'active' : '' ?>">
                            <i class="bi bi-person-plus"></i>
                            <span>Добавить</span>
                        </a>
                    </div>
                </li>

                <?php
                // Определяем, находится ли пользователь внутри подменю "Настройки"
                $currentScript = basename($_SERVER['SCRIPT_NAME']);
                $currentPath = $_SERVER['SCRIPT_NAME'];
                $isInUserSettingsSubmenu = (strpos($currentPath, '/admin/user/') !== false && $currentScript === 'user_settings.php') || 
                                          (strpos($currentPath, '/admin/app/') !== false && $currentScript === 'gpt.php');
                ?>
                <li class="nav-item">
                    <a href="#settingsSubmenu_user" 
                       class="nav-link <?= $isInUserSettingsSubmenu ? '' : 'collapsed' ?>"
                       data-bs-toggle="submenu" 
                       aria-expanded="<?= $isInUserSettingsSubmenu ? 'true' : 'false' ?>">
                        <i class="bi bi-gear"></i>
                        <span>Настройки</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="submenu collapse <?= $isInUserSettingsSubmenu ? 'show' : '' ?>" id="settingsSubmenu_user">
                        <a href="/admin/user/user_settings.php" class="nav-link <?= strpos($currentPath, '/admin/user/') !== false && $currentScript === 'user_settings.php' ? 'active' : '' ?>">
                            <i class="bi bi-people"></i>
                            <span>Пользователи</span>
                        </a>
                        <a href="/admin/app/gpt.php" class="nav-link <?= strpos($currentPath, '/admin/app/') !== false && $currentScript === 'gpt.php' ? 'active' : '' ?>">
                            <i class="bi bi-lightbulb"></i>
                            <span>GPT</span>
                        </a>
                    </div>
                </li>
            <?php } ?>

            <?php if ($showShopMenu): ?>
                <?php
                // Определяем, находится ли пользователь внутри подменю "Магазин"
                $currentScript = basename($_SERVER['SCRIPT_NAME']);
                $currentPath = $_SERVER['SCRIPT_NAME'];
                $isInShopSubmenu = strpos($currentPath, '/admin/shop/') !== false && in_array($currentScript, ['catalog_list.php', 'add_catalog.php', 'product_list.php', 'add_product.php', 'shop_extra_list.php', 'add_shop_extra.php']);
                ?>
                <li class="nav-item">
                    <a href="#settingsSubmenu_shop" 
                       class="nav-link <?= $isInShopSubmenu ? '' : 'collapsed' ?>"
                       data-bs-toggle="submenu" 
                       aria-expanded="<?= $isInShopSubmenu ? 'true' : 'false' ?>">
                        <i class="bi bi-cart"></i>
                        <span>Магазин</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="submenu collapse <?= $isInShopSubmenu ? 'show' : '' ?>" id="settingsSubmenu_shop">
                        <a href="/admin/shop/catalog_list.php" class="nav-link <?= strpos($currentPath, '/admin/shop/') !== false && $currentScript === 'catalog_list.php' ? 'active' : '' ?>">
                            <i class="bi bi-grid"></i>
                            <span>Управление каталогом</span>
                        </a>
                        <a href="/admin/shop/add_catalog.php" class="nav-link <?= strpos($currentPath, '/admin/shop/') !== false && $currentScript === 'add_catalog.php' ? 'active' : '' ?>">
                            <i class="bi bi-plus-circle"></i>
                            <span>Добавить каталог</span>
                        </a>
                        <a href="/admin/shop/product_list.php" class="nav-link <?= strpos($currentPath, '/admin/shop/') !== false && $currentScript === 'product_list.php' ? 'active' : '' ?>">
                            <i class="bi bi-hdd-stack"></i>
                            <span>Управление товаром</span>
                        </a>
                        <a href="/admin/shop/add_product.php" class="nav-link <?= strpos($currentPath, '/admin/shop/') !== false && $currentScript === 'add_product.php' ? 'active' : '' ?>">
                            <i class="bi bi-plus-circle"></i>
                            <span>Добавить товар</span>
                        </a>
                    </div>
                </li>
            <?php endif; ?>

            <?php if ($recordMenu): ?>
                <?php
                // Определяем, находится ли пользователь внутри подменю "Записи"
                $currentScript = basename($_SERVER['SCRIPT_NAME']);
                $currentPath = $_SERVER['SCRIPT_NAME'];
                $isInRecordSubmenu = strpos($currentPath, '/admin/record/') !== false && in_array($currentScript, ['category_list.php', 'add_category.php', 'record_list.php', 'add_record.php', 'record_extra_list.php', 'add_record_extra.php']);
                ?>
                <li class="nav-item">
                    <a href="#settingsSubmenu_record" 
                       class="nav-link <?= $isInRecordSubmenu ? '' : 'collapsed' ?>"
                       data-bs-toggle="submenu" 
                       aria-expanded="<?= $isInRecordSubmenu ? 'true' : 'false' ?>">
                        <i class="bi bi-grid"></i>
                        <span>Записи</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="submenu collapse <?= $isInRecordSubmenu ? 'show' : '' ?>" id="settingsSubmenu_record">
                        <a href="/admin/record/category_list.php" class="nav-link <?= strpos($currentPath, '/admin/record/') !== false && $currentScript === 'category_list.php' ? 'active' : '' ?>">
                            <i class="bi bi-grid"></i>
                            <span>Управление каталогом</span>
                        </a>
                        <a href="/admin/record/add_category.php" class="nav-link <?= strpos($currentPath, '/admin/record/') !== false && $currentScript === 'add_category.php' ? 'active' : '' ?>">
                            <i class="bi bi-plus-circle"></i>
                            <span>Добавить каталог</span>
                        </a>
                        <a href="/admin/record/record_list.php" class="nav-link <?= strpos($currentPath, '/admin/record/') !== false && $currentScript === 'record_list.php' ? 'active' : '' ?>">
                            <i class="bi bi-hdd-stack"></i>
                            <span>Управление записями</span>
                        </a>
                        <a href="/admin/record/add_record.php" class="nav-link <?= strpos($currentPath, '/admin/record/') !== false && $currentScript === 'add_record.php' ? 'active' : '' ?>">
                            <i class="bi bi-plus-circle"></i>
                            <span>Добавить запись</span>
                        </a>
                    </div>
                </li>
            <?php endif; ?>

            <?php if ($pagesMenu): ?>
                <?php
                // Определяем, находится ли пользователь внутри подменю "Страницы"
                $currentScript = basename($_SERVER['SCRIPT_NAME']);
                $currentPath = $_SERVER['SCRIPT_NAME'];
                $isInPagesSubmenu = strpos($currentPath, '/admin/pages/') !== false && in_array($currentScript, ['parent_list.php', 'add_parent.php', 'pages_list.php', 'add_pages.php', 'pages_extra_list.php', 'add_pages_extra.php']);
                ?>
                <li class="nav-item">
                    <a href="#settingsSubmenu_pages" 
                       class="nav-link <?= $isInPagesSubmenu ? '' : 'collapsed' ?>"
                       data-bs-toggle="submenu" 
                       aria-expanded="<?= $isInPagesSubmenu ? 'true' : 'false' ?>">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>Страницы</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="submenu collapse <?= $isInPagesSubmenu ? 'show' : '' ?>" id="settingsSubmenu_pages">
                        <a href="/admin/pages/parent_list.php" class="nav-link <?= strpos($currentPath, '/admin/pages/') !== false && $currentScript === 'parent_list.php' ? 'active' : '' ?>">
                            <i class="bi bi-grid"></i>
                            <span>Управление каталогом</span>
                        </a>
                        <a href="/admin/pages/add_parent.php" class="nav-link <?= strpos($currentPath, '/admin/pages/') !== false && $currentScript === 'add_parent.php' ? 'active' : '' ?>">
                            <i class="bi bi-plus-circle"></i>
                            <span>Добавить каталог</span>
                        </a>
                        <a href="/admin/pages/pages_list.php" class="nav-link <?= strpos($currentPath, '/admin/pages/') !== false && $currentScript === 'pages_list.php' ? 'active' : '' ?>">
                            <i class="bi bi-hdd-stack"></i>
                            <span>Управление страницами</span>
                        </a>
                        <a href="/admin/pages/add_pages.php" class="nav-link <?= strpos($currentPath, '/admin/pages/') !== false && $currentScript === 'add_pages.php' ? 'active' : '' ?>">
                            <i class="bi bi-plus-circle"></i>
                            <span>Добавить страницу</span>
                        </a>
                    </div>
                </li>
            <?php endif; ?>

            <?php if ($newsMenu): ?>
                <?php
                // Определяем, находится ли пользователь внутри подменю "Новости"
                $currentScript = basename($_SERVER['SCRIPT_NAME']);
                $currentPath = $_SERVER['SCRIPT_NAME'];
                $isInNewsSubmenu = strpos($currentPath, '/admin/news/') !== false && in_array($currentScript, ['category_list.php', 'add_category.php', 'record_list.php', 'add_record.php', 'record_extra_list.php', 'add_record_extra.php']);
                ?>
                <li class="nav-item">
                    <a href="#settingsSubmenu_news" 
                       class="nav-link <?= $isInNewsSubmenu ? '' : 'collapsed' ?>"
                       data-bs-toggle="submenu" 
                       aria-expanded="<?= $isInNewsSubmenu ? 'true' : 'false' ?>">
                        <i class="bi bi-newspaper"></i>
                        <span>Новости</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="submenu collapse <?= $isInNewsSubmenu ? 'show' : '' ?>" id="settingsSubmenu_news">
                        <a href="/admin/news/category_list.php" class="nav-link <?= strpos($currentPath, '/admin/news/') !== false && $currentScript === 'category_list.php' ? 'active' : '' ?>">
                            <i class="bi bi-grid"></i>
                            <span>Управление каталогом</span>
                        </a>
                        <a href="/admin/news/add_category.php" class="nav-link <?= strpos($currentPath, '/admin/news/') !== false && $currentScript === 'add_category.php' ? 'active' : '' ?>">
                            <i class="bi bi-plus-circle"></i>
                            <span>Добавить каталог</span>
                        </a>
                        <a href="/admin/news/record_list.php" class="nav-link <?= strpos($currentPath, '/admin/news/') !== false && $currentScript === 'record_list.php' ? 'active' : '' ?>">
                            <i class="bi bi-hdd-stack"></i>
                            <span>Управление новостями</span>
                        </a>
                        <a href="/admin/news/add_record.php" class="nav-link <?= strpos($currentPath, '/admin/news/') !== false && $currentScript === 'add_record.php' ? 'active' : '' ?>">
                            <i class="bi bi-plus-circle"></i>
                            <span>Добавить новость</span>
                        </a>
                    </div>
                </li>
            <?php endif; ?>

        </ul>
    </div>

    <!-- Нижняя часть сайдбара -->
    <div class="sidebar-footer">
        <!-- Информация о пользователе -->
        <div class="user-info">
            <div class="user-avatar">
                <img src="<?php echo escape($user_avatar); ?>" 
                     alt="<?php echo escape($defaultFirstName ?: 'Администратор'); ?>"
                     loading="lazy">
            </div>
            <div>
                <div class="user-name"><?php echo escape($defaultFirstName ?: 'Администратор'); ?></div>
                <div class="user-role">
                    <?php echo ($userDataAdmin['author'] == 'admin') ? "Super Admin" : "Admin"; ?>
                </div>
            </div>
        </div>

        <!-- Переключатель темы -->
        <div class="theme-toggle">
            <span class="theme-label">Тема</span>
            <label class="theme-switch-auth">
                <input type="checkbox" id="themeToggleAuth">
                <span class="theme-slider-auth">
                    <i class="bi bi-sun"></i>
                    <i class="bi bi-moon"></i>
                </span>
            </label>
        </div>

        <!-- Кнопка выхода -->
        <button class="logout-btn" onclick="window.location.href='../logout.php'" aria-label="Выйти из системы">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            Выйти
        </button>
    </div>
</nav>

<!-- Оверлей для мобильных устройств -->
<div class="overlay" role="button" aria-label="Закрыть меню" tabindex="0"></div>

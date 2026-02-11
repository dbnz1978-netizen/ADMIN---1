<?php

/**
 * Название файла:      sidebar.php
 * Назначение:          Боковое меню навигации админ-панели.
 *                      Отображает основные разделы, подменю и информацию о пользователе.
 *                      Поддерживает адаптивную навигацию и темную/светлую тему.
 *                      
 *                      Особенности:
 *                      - Динамическое формирование меню в зависимости от роли пользователя
 *                      - Поддержка подменю для разделов "Пользователи" и "Настройки"
 *                      - Отображение аватара пользователя для светлой/тёмной темы
 *                      - Защита от прямого доступа через проверку константы APP_ACCESS
 *                      - Интеграция с системой логирования и валидации
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// ПРОВЕРКА ДОСТУПА
// ========================================

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}


// ========================================
// КОНФИГУРАЦИЯ СКРИПТА
// ========================================

$config = [
    'display_errors'   => false,   // Включение отображения ошибок (true/false)
    'set_encoding'     => true,    // Включение кодировки UTF-8
    'sanitization'     => true,    // Подключение валидации/экранирования
    'plugin_manager'   => true,    // Подключение менеджера плагинов
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';


// Извлекаем основные данные пользователя
$defaultFirstName = $currentData['first_name'] ?? '';
$selectedImages   = $currentData['profile_images'] ?? '';

// ========================================
// НАСТРОЙКА ЛОГОТИПА САЙДБАРА
// ========================================

// Логотип для светлой/темной темы
$adminUserId      = getAdminUserId($pdo);
$logoPaths        = getThemeLogoPaths($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', $adminUserId);
$sidebarLogoLight = $logoPaths['light'] ?? '';
$sidebarLogoDark  = $logoPaths['dark'] ?? '';

if (empty($sidebarLogoLight) && empty($sidebarLogoDark)) {
    $sidebarLogoLight = '/admin/img/avatar.svg';
    $sidebarLogoDark  = $sidebarLogoLight;
} elseif (empty($sidebarLogoDark)) {
    $sidebarLogoDark = $sidebarLogoLight;
}

// ========================================
// НАСТРОЙКА АВАТАРА ПОЛЬЗОВАТЕЛЯ
// ========================================

// Извлекаем настройки для пользователей
$allowPhotoUpload = $adminData['allow_photo_upload'] ?? false;  // Разрешить загрузку фотографий для главного admin

// Всегда разрешить загрузку фотографий для главного admin
if ($userDataAdmin['author'] == 'admin') {
    $allowPhotoUpload = true;
}

$defaultAvatar = '/admin/img/avatar.svg';

if ($allowPhotoUpload === true) {
    // Получает первое и второе изображения из строки ID для светлой/тёмной темы
    $avatarPaths      = getThemeLogoPaths($pdo, $selectedImages, 'thumbnail');
    $userAvatarLight  = $avatarPaths['light'] ?? '';
    $userAvatarDark   = $avatarPaths['dark'] ?? '';
} else {
    $userAvatarLight = '';
    $userAvatarDark  = '';
}

if (empty($userAvatarLight) && empty($userAvatarDark)) {
    $userAvatarLight = $defaultAvatar;
    $userAvatarDark  = $defaultAvatar;
} elseif (empty($userAvatarLight)) {
    $userAvatarLight = $userAvatarDark;
} elseif (empty($userAvatarDark)) {
    $userAvatarDark = $userAvatarLight;
}

// ========================================
// ГЕНЕРАЦИЯ CSRF-ТОКЕНА
// ========================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$logoutCsrfToken = $_SESSION['csrf_token'];

?>

<!-- ========================================
     БОКОВОЕ МЕНЮ
     ======================================== -->
<nav class="sidebar" aria-label="Основная навигация">
    
    <!-- ========================================
         ЗАГОЛОВОК С ЛОГОТИПОМ
         ======================================== -->
    <div class="sidebar-header">
        <a href="/admin/user/index.php" class="logo" style="gap: 4px;">
            <img class="sidebar-logo-image sidebar-logo-light" src="<?php echo escape($sidebarLogoLight); ?>" alt="<?php echo escape($defaultFirstName ?: 'Администратор'); ?>" loading="lazy">
            <img class="sidebar-logo-image sidebar-logo-dark" src="<?php echo escape($sidebarLogoDark); ?>" alt="<?php echo escape($defaultFirstName ?: 'Администратор'); ?>" loading="lazy">
            <?= escape($adminData['AdminPanel'] ?? 'AdminPanel') ?>
        </a>
    </div>

    <!-- ========================================
         НАВИГАЦИЯ
         ======================================== -->
    <div class="sidebar-nav">
        <ul class="nav flex-column">
            
            <!-- Редактирование профиля -->
            <li class="nav-item">
                <a href="/admin/user/personal_data.php" class="nav-link <?php if (basename($_SERVER['SCRIPT_NAME']) === 'personal_data.php') { echo 'active'; } ?>">
                    <i class="bi bi-person-vcard"></i>
                    <span>Редактирование профиля</span>
                </a>
            </li>

            <!-- Библиотека файлов -->
            <?php if ($adminData['allow_photo_upload'] === true || $userDataAdmin['author'] == 'admin'): ?>
            <li class="nav-item">
                <a href="/admin/user/main_images.php" class="nav-link <?php if (basename($_SERVER['SCRIPT_NAME']) === 'main_images.php') { echo 'active'; } ?>">
                    <i class="bi bi-card-image"></i>
                    <span>Библиотека файлов</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Подменю для администратора -->
            <?php if ($userDataAdmin['author'] == 'admin'): ?>
                
                <?php
                // Определяем, находится ли пользователь внутри подменю "Пользователи"
                $currentScript        = basename($_SERVER['SCRIPT_NAME']);
                $currentPath          = $_SERVER['SCRIPT_NAME'];
                $isInAccountSubmenu   = strpos($currentPath, '/admin/user/') !== false && in_array($currentScript, ['accounts_list.php', 'add_account.php']);
                ?>
                
                <!-- Пользователи -->
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
                $currentScript            = basename($_SERVER['SCRIPT_NAME']);
                $currentPath              = $_SERVER['SCRIPT_NAME'];
                $isInUserSettingsSubmenu  = (strpos($currentPath, '/admin/user/') !== false && $currentScript === 'user_settings.php') || 
                                            (strpos($currentPath, '/admin/app/') !== false && $currentScript === 'gpt.php');
                ?>
                
                <!-- Настройки -->
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
                    </div>
                </li>

                <?php
                // Определяем, находится ли пользователь внутри подменю "Плагины"
                $currentScript           = basename($_SERVER['SCRIPT_NAME']);
                $currentPath             = $_SERVER['SCRIPT_NAME'];
                $isInPluginsSubmenu      = strpos($currentPath, '/admin/plugins/') !== false;
                ?>
                
                <!-- Плагины -->
                <li class="nav-item">
                    <a href="#settingsSubmenu_plugins" 
                       class="nav-link <?= $isInPluginsSubmenu ? '' : 'collapsed' ?>"
                       data-bs-toggle="submenu" 
                       aria-expanded="<?= $isInPluginsSubmenu ? 'true' : 'false' ?>">
                        <i class="bi bi-plugin"></i>
                        <span>Плагины</span>
                        <i class="bi bi-chevron-down ms-auto"></i>
                    </a>
                    <div class="submenu collapse <?= $isInPluginsSubmenu ? 'show' : '' ?>" id="settingsSubmenu_plugins">
                        <a href="/admin/plugins/list.php" class="nav-link <?= strpos($currentPath, '/admin/plugins/') !== false && $currentScript === 'list.php' ? 'active' : '' ?>">
                            <i class="bi bi-list-ul"></i>
                            <span>Список плагинов</span>
                        </a>
                    </div>
                </li>

            <?php endif; ?>
            
            <?php
            // ========================================
            // ДИНАМИЧЕСКОЕ МЕНЮ ПЛАГИНОВ
            // ========================================
            
            // Подключаем менеджер плагинов если ещё не подключен
            if (!function_exists('getPluginMenus')) {
                $pluginManagerPath = __DIR__ . '/../functions/plugin_manager.php';
                if (file_exists($pluginManagerPath)) {
                    require_once $pluginManagerPath;
                }
            }
            
            // Подключаем систему управления доступом к плагинам
            if (!function_exists('filterPluginMenusByAccess')) {
                $pluginAccessPath = __DIR__ . '/../functions/plugin_access.php';
                if (file_exists($pluginAccessPath)) {
                    require_once $pluginAccessPath;
                }
            }
            
            // Получаем меню включенных плагинов, если функция доступна
            $pluginMenus = function_exists('getPluginMenus') ? getPluginMenus($pdo) : [];
            
            // Фильтруем меню плагинов на основе прав доступа пользователя
            if (function_exists('filterPluginMenusByAccess')) {
                $userRole = $userDataAdmin['author'] ?? 'user';
                $pluginMenus = filterPluginMenusByAccess($pdo, $pluginMenus, $userRole);
            }
            
            // Отображаем меню каждого плагина
            foreach ($pluginMenus as $index => $menu):
                $menuId              = 'pluginSubmenu_' . $index;
                $menuTitle           = $menu['menu_title'] ?? 'Plugin Menu';
                $menuIcon            = $menu['menu_icon'] ?? 'bi-puzzle';
                $submenuItems        = $menu['submenu'] ?? [];
                
                // Фильтруем элементы подменю: страница настроек доступна только для admin
                $filteredSubmenuItems = [];
                foreach ($submenuItems as $item) {
                    $itemUrl = $item['url'] ?? '';
                    // Парсим URL для получения только пути без query string и fragment
                    $parsedUrl = parse_url($itemUrl);
                    $itemPath = $parsedUrl['path'] ?? '';
                    
                    // Если путь содержит /settings/ как сегмент, показываем только админам
                    if (preg_match('#/settings/#', $itemPath)) {
                        if ($userRole === 'admin') {
                            $filteredSubmenuItems[] = $item;
                        }
                    } else {
                        // Остальные пункты меню доступны всем
                        $filteredSubmenuItems[] = $item;
                    }
                }
                
                // Пропускаем меню, если нет доступных пунктов
                if (empty($filteredSubmenuItems)) {
                    continue;
                }
                
                // Определяем, находится ли пользователь в этом подменю
                $isInThisPluginMenu = false;
                foreach ($filteredSubmenuItems as $item) {
                    if (isset($item['url'])) {
                        $itemParsedUrl = parse_url($item['url']);
                        $itemPath = $itemParsedUrl['path'] ?? '';
                        // Сравниваем пути точно
                        if ($itemPath && strpos($currentPath, $itemPath) !== false) {
                            $isInThisPluginMenu = true;
                            break;
                        }
                    }
                }
            ?>
            
            <!-- Меню плагина: <?= escape($menuTitle) ?> -->
            <li class="nav-item">
                <a href="#<?= escape($menuId) ?>" 
                   class="nav-link <?= $isInThisPluginMenu ? '' : 'collapsed' ?>"
                   data-bs-toggle="submenu" 
                   aria-expanded="<?= $isInThisPluginMenu ? 'true' : 'false' ?>">
                    <i class="<?= escape($menuIcon) ?>"></i>
                    <span><?= escape($menuTitle) ?></span>
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>
                <div class="submenu collapse <?= $isInThisPluginMenu ? 'show' : '' ?>" id="<?= escape($menuId) ?>">
                    <?php foreach ($filteredSubmenuItems as $item): ?>
                        <?php
                        $itemTitle  = $item['title'] ?? 'Item';
                        $itemIcon   = $item['icon'] ?? 'bi-circle';
                        $itemUrl    = $item['url'] ?? '#';
                        $itemParsedUrl = parse_url($itemUrl);
                        $itemPath = $itemParsedUrl['path'] ?? '';
                        $isActive   = $itemPath && strpos($currentPath, $itemPath) !== false;
                        ?>
                        <a href="<?= escape($itemUrl) ?>" class="nav-link <?= $isActive ? 'active' : '' ?>">
                            <i class="<?= escape($itemIcon) ?>"></i>
                            <span><?= escape($itemTitle) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </li>
            
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- ========================================
         НИЖНЯЯ ЧАСТЬ САЙДБАРА
         ======================================== -->
    <div class="sidebar-footer">
        
        <!-- Информация о пользователе -->
        <div class="user-info">
            <div class="user-avatar">
                <img class="user-avatar-light" src="<?php echo escape($userAvatarLight); ?>" 
                     alt=""
                     aria-hidden="true"
                     loading="lazy">
                <img class="user-avatar-dark" src="<?php echo escape($userAvatarDark); ?>" 
                     alt=""
                     aria-hidden="true"
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
        <form method="post" action="/admin/logout.php">
            <input type="hidden" name="csrf_token" value="<?php echo escape($logoutCsrfToken); ?>">
            <button class="logout-btn" type="submit" aria-label="Выйти из системы">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                Выйти
            </button>
        </form>
    </div>
</nav>

<!-- ========================================
     ОВЕРЛЕЙ ДЛЯ МОБИЛЬНЫХ УСТРОЙСТВ
     ======================================== -->
<div class="overlay" role="button" aria-label="Закрыть меню" tabindex="0"></div>
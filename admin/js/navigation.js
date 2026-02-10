/**
 * Файл: /admin/js/navigation.js
 * 
 * Управление боковым меню: мобильное переключение, подменю, активные пункты.
 */

/**
 * Инициализация бокового меню
 */
export function initSidebarMenu() {
    // === ЭЛЕМЕНТЫ МЕНЮ ===
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.overlay');
    const submenuToggles = document.querySelectorAll('[data-bs-toggle="submenu"]');
    const navLinks = document.querySelectorAll('.nav-link:not([data-bs-toggle])');

    // Ничего не инициализируем, если меню отсутствует
    if (!sidebar) return;

    // === 1. ПЕРЕКЛЮЧЕНИЕ МЕНЮ (мобильная версия) ===
    function toggleMenu() {
        const isActive = sidebar.classList.toggle('active');
        if (overlay) overlay.classList.toggle('active');
        if (menuToggle) menuToggle.setAttribute('aria-expanded', isActive);
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            toggleMenu();
        });
    }

    if (overlay) {
        overlay.addEventListener('click', toggleMenu);
    }

    // Закрытие по клику вне бокового меню
    document.addEventListener('click', function (event) {
        if (!sidebar || !sidebar.classList.contains('active')) return;
        const isClickInsideSidebar = sidebar.contains(event.target);
        const isClickOnToggle = menuToggle && menuToggle.contains(event.target);

        if (!isClickInsideSidebar && !isClickOnToggle) {
            toggleMenu();
        }
    });

    // Закрытие по Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) {
            toggleMenu();
        }
    });

    // === 2. УПРАВЛЕНИЕ ПОДМЕНЮ ===
    submenuToggles.forEach(toggle => {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();

            const targetId = this.getAttribute('href');
            if (!targetId || !targetId.startsWith('#')) return;

            const targetSubmenu = document.querySelector(targetId);
            if (!targetSubmenu) return;

            // Закрываем все остальные подменю
            document.querySelectorAll('.submenu').forEach(menu => {
                if (menu !== targetSubmenu && menu.classList.contains('show')) {
                    menu.classList.remove('show');
                    const otherToggle = document.querySelector(`[href="${targetId}"]`);
                    if (otherToggle) {
                        otherToggle.classList.add('collapsed');
                        otherToggle.setAttribute('aria-expanded', 'false');
                    }
                }
            });

            // Переключаем текущее подменю
            const isExpanded = targetSubmenu.classList.toggle('show');
            this.classList.toggle('collapsed', !isExpanded);
            this.setAttribute('aria-expanded', isExpanded);
        });
    });

    // === 3. АКТИВНЫЙ ПУНКТ МЕНЮ ===
    navLinks.forEach(link => {
        link.addEventListener('click', function () {
            // Убираем active у всех простых ссылок (не подменю)
            navLinks.forEach(l => {
                l.classList.remove('active');
                l.removeAttribute('aria-current');
            });

            // Добавляем active к текущей
            this.classList.add('active');
            this.setAttribute('aria-current', 'page');

            // На мобильных — закрываем меню после выбора
            if (window.innerWidth < 992) {
                toggleMenu();
            }
        });
    });

    console.log('Sidebar menu initialized');
}

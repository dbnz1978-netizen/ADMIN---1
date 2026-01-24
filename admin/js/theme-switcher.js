/**
 * Файл: /admin/js/theme-switcher.js
 * Theme Switcher — модуль управления светлой/тёмной темой
 * 
 * Назначение:
 *   Переключает тему интерфейса (light ↔ dark) через атрибут `data-theme`
 *   на `<html>` и сохраняет выбор в `localStorage`.
 * 
 * Экспортирует:
 *   - initThemeSwitcher(root = document) — инициализирует переключатель темы
 *     в указанном контексте (по умолчанию — вся страница).
 *   - toggleTheme(isDark: boolean) — программное переключение темы.
 * 
 * Ожидаемая разметка:
 *   <input type="checkbox" id="themeToggleAuth" class="theme-switch">
 *   + CSS должен реагировать на: html[data-theme="dark"]
 * 
 * @version 1.1 (модульная версия)
 */

/**
 * Переключает тему глобально
 * @param {boolean} isDark — true = тёмная тема, false = светлая
 */
export function toggleTheme(isDark) {
  const html = document.documentElement;
  if (isDark) {
    html.setAttribute('data-theme', 'dark');
    localStorage.setItem('theme', 'dark');
  } else {
    html.removeAttribute('data-theme');
    localStorage.setItem('theme', 'light');
  }
}

/**
 * Инициализирует переключатель темы в указанном контейнере
 * @param {HTMLElement|Document} [root=document]
 */
export function initThemeSwitcher(root = document) {
  const themeToggle = root.getElementById 
    ? root.getElementById('themeToggleAuth') 
    : root.querySelector('#themeToggleAuth');

  // Защита от повторной инициализации
  if (themeToggle?.hasAttribute('data-theme-switcher-initialized')) {
    return;
  }

  // Восстановление сохранённой темы
  const savedTheme = localStorage.getItem('theme');
  if (savedTheme === 'dark') {
    toggleTheme(true);
  } else {
    toggleTheme(false); // гарантируем, что data-theme удалён
  }

  if (themeToggle) {
    // Синхронизируем состояние чекбокса
    themeToggle.checked = savedTheme === 'dark';

    themeToggle.addEventListener('change', function () {
      toggleTheme(this.checked);
    });

    themeToggle.setAttribute('data-theme-switcher-initialized', 'true');
  }
}

// Автоинициализация при прямом подключении (не при импорте)
if (typeof window !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initThemeSwitcher());
  } else {
    initThemeSwitcher();
  }
}
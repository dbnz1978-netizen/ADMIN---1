/**
 * Файл: /admin/js/main.js
 * 
 * =============================================================
 * Главная точка входа JavaScript-приложения (main.js)
 * -------------------------------------------------------------
 */

// === ИМПОРТ МОДУЛЕЙ ==================================================
// Модуль переключения видимости пароля (password visibility toggle)
import { initPasswordToggles } from './password-manager.js';

// Theme Switcher — модуль управления светлой/тёмной темой
import { initThemeSwitcher } from './theme-switcher.js';

// Универсальный валидатор форм с поддержкой live-валидации, кастомных правил
import { initFormValidation } from './required-fields-validator.js';

// Универсальная реализация капчи с перетаскиванием
import { initCaptchaSlider } from './captcha-slider.js';

// Форматирование телефонов — универсальное форматирование с поддержкой +7 и других кодов стран
import { initPhoneFormatting } from './phone-formatter.js';

// Боковое меню — мобильное переключение, подменю, активные пункты
import { initSidebarMenu } from './navigation.js';

// Инициализация списка пользователей (работает только если элементы присутствуют)
import { initListManager } from './list-manager.js';

// Импорт кастомного модального окна
import { initCustomModal } from './modal.js';

// Модуль выбора изображений в галерее
import { storeSectionId, handleSelectButtonClick } from './gallery-selector.js';

// Модуль выбора цвета
import '/admin/js/color-picker.js';

// === ГЛОБАЛЬНЫЙ ДОСТУП (опционально) ==================================
// Если нужно — можно сделать функции доступными глобально, например:
window.initCaptchaSlider = initCaptchaSlider;
window.storeSectionId = storeSectionId;
window.handleSelectButtonClick = handleSelectButtonClick;

// === ИНИЦИАЛИЗАЦИЯ ПРИ ЗАГРУЗКЕ СТРАНИЦЫ ===========================
document.addEventListener('DOMContentLoaded', () => {
  console.log('main.js: DOMContentLoaded — starting initialization...');

  // 1. Переключатели видимости пароля
  initPasswordToggles();

  // 2. Переключатель темы
  initThemeSwitcher();

  // 3. Валидация форм
  const validator = initFormValidation({
    // Можно кастомизировать, например:
    // formSelector: '.needs-validation',
    // errorClass: 'border-danger',
    // validClass: 'border-success'
  });

  // 4. Капча — инициализируем, если есть элементы на странице
  const captchaInstance = initCaptchaSlider({
    // Можно переопределить ID при необходимости:
    // sliderId: 'myCustomCaptchaSlider',
    // verifiedInputId: 'captcha_status'
  });

  // 5. Форматирование телефонов
  initPhoneFormatting({
    // Опционально: можно указать кастомный селектор
    // inputSelector: '.phone-input'
  });

  // 6. Боковое меню
  initSidebarMenu();

  // 7. Инициализация списка пользователей
  initListManager();

  // КАСТОМНОЕ МОДАЛЬНОЕ ОКНО — инициализация
  initCustomModal();
});
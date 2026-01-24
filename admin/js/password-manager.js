/**
 * Файл: /admin/js/password-manager.js
 * 
 * Модуль переключения видимости пароля (password visibility toggle)
 * 
 * Назначение:
 *   Позволяет пользователю временно показать/скрыть введённый пароль
 *   с помощью кнопки-переключателя рядом с полем ввода.
 * 
 * Экспортирует:
 *   - initPasswordToggles(root = document) — инициализирует переключатели
 *     в указанном контексте (по умолчанию — вся страница).
 * 
 * Особенности:
 *   - Поддерживает несколько полей на странице.
 *   - Работает с Bootstrap Icons (bi-eye / bi-eye-slash).
 *   - Безопасен для повторного вызова (не дублирует обработчики).
 *   - Поддерживает динамически добавленные элементы (если вызывать заново).
 */

/**
 * Инициализирует кнопки переключения видимости пароля в заданном контейнере.
 * @param {HTMLElement|Document} [root=document] — контейнер для поиска кнопок
 */
export function initPasswordToggles(root = document) {
  const toggleButtons = root.querySelectorAll('.password-toggle');

  toggleButtons.forEach(button => {
    // Защита от двойной инициализации: проверяем, был ли уже обработан
    if (button.hasAttribute('data-password-toggle-initialized')) {
      return;
    }

    button.addEventListener('click', function () {
      // Ищем связанное поле ввода:
      // 1. Предыдущий элемент (если разметка: <input><button>)
      // 2. Или внутри общего контейнера (например, .input-group)
      const input = this.previousElementSibling?.matches?.('input[type="password"], input[type="text"]')
        ? this.previousElementSibling
        : this.closest('.input-group')?.querySelector('input[type="password"], input[type="text"]');

      if (!input) return;

      const icon = this.querySelector('i');
      if (!icon) return;

      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
      }
    });

    // Помечаем кнопку как инициализированную
    button.setAttribute('data-password-toggle-initialized', 'true');
  });
}

// Опционально: автоматическая инициализация при загрузке документа
// (только если модуль используется как standalone-скрипт, но НЕ при импорте)
if (typeof window !== 'undefined' && document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    initPasswordToggles();
  });
} else if (document.readyState !== 'loading') {
  // Если DOM уже загружен — инициализируем сразу
  initPasswordToggles();
}
/**
 * Файл: /admin/js/modal.js
 * 
 * =============================================================
 * Кастомное модальное окно поверх всех (modal.js)
 * -------------------------------------------------------------
 * Независимое от Bootstrap модальное окно с эффектом мутного стекла.
 * Работает поверх любых других модальных окон, не влияет на body/modal-backdrop.
 * Поддерживает светлую/тёмную тему через CSS-переменные.
 */

let currentModalInstance = null;

// WeakMap для сохранения оригинальных настроек Bootstrap модалов
// Использование WeakMap позволяет сохранить типы данных и избежать утечек памяти
const bootstrapModalStates = new WeakMap();

// Константы для задержек фокусировки
// Задержка после открытия модального окна перед фокусировкой (для завершения анимации и деактивации Bootstrap FocusTrap)
const FOCUS_DELAY_AFTER_OPEN = 100;
// Задержка после загрузки контента перед фокусировкой (для завершения рендеринга DOM)
const FOCUS_DELAY_AFTER_CONTENT_LOAD = 150;

// Селектор для поиска первого интерактивного поля ввода
// Исключает hidden, кнопки, файлы и другие неинтерактивные элементы
// Также исключает readonly и disabled поля
const INPUT_SELECTOR = 'input:is([type="text"], [type="email"], [type="password"], [type="search"], [type="tel"], [type="url"], [type="number"]):not([readonly]):not([disabled]), textarea:not([readonly]):not([disabled]), select:not([disabled])';

/**
 * Устанавливает фокус на первый интерактивный элемент ввода
 * @param {HTMLElement} container - Контейнер для поиска input элемента
 * @param {number} delay - Задержка в миллисекундах перед установкой фокуса
 */
function focusFirstInput(container, delay = 0) {
  setTimeout(() => {
    const firstInput = container.querySelector(INPUT_SELECTOR);
    if (firstInput && typeof firstInput.focus === 'function') {
      try {
        firstInput.focus();
      } catch (e) {
        console.warn('[modal.js] Не удалось установить фокус на первый input:', e);
      }
    }
  }, delay);
}

// Экспортируем константы и функции для использования в других модулях
export { FOCUS_DELAY_AFTER_CONTENT_LOAD, INPUT_SELECTOR, focusFirstInput };

export function initCustomModal() {
  const modal = document.getElementById('customOverlayModal');
  if (!modal) {
    console.warn('[modal.js] #customOverlayModal не найден. Модалка недоступна.');
    return null;
  }

  function open(title = '') {
    // Если нужно установить заголовок
    if (title && document.getElementById('customModalTitle')) {
      document.getElementById('customModalTitle').textContent = title;
    }
    
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    
    // Запрещаем прокрутку основного контента
    document.body.style.overflow = 'hidden';
    
    // Добавляем класс к body для CSS-селектора (фоллбэк для браузеров без :has())
    document.body.classList.add('custom-modal-active');
    
    // Отключаем focus trap в открытых Bootstrap модалах
    // Это позволяет взаимодействовать с элементами в кастомном модальном окне
    const bootstrapModals = document.querySelectorAll('.modal.show');
    const modifiedModals = [];
    
    bootstrapModals.forEach(modalEl => {
      try {
        // Сохраняем состояние модала для последующего восстановления
        const state = { hadInert: false, originalFocus: null, focusTrap: null };
        
        if (window.bootstrap && window.bootstrap.Modal) {
          const bsModal = window.bootstrap.Modal.getInstance(modalEl);
          // Используем приватное свойство _config с осторожностью
          // В будущих версиях Bootstrap это может измениться
          if (bsModal) {
            if (bsModal._config && typeof bsModal._config.focus !== 'undefined') {
              state.originalFocus = bsModal._config.focus;
              bsModal._config.focus = false;
            }
            
            // Деактивируем FocusTrap если он существует
            if (bsModal._focustrap) {
              state.focusTrap = bsModal._focustrap;
              try {
                if (typeof bsModal._focustrap.deactivate === 'function') {
                  bsModal._focustrap.deactivate();
                }
              } catch (e) {
                console.warn('[modal.js] Ошибка при деактивации FocusTrap:', e);
              }
            }
          }
        }
        
        // Удаляем inert attribute если он установлен
        if (modalEl.hasAttribute('inert')) {
          state.hadInert = true;
          modalEl.removeAttribute('inert');
        }
        
        // Сохраняем состояние в WeakMap для последующего восстановления
        bootstrapModalStates.set(modalEl, state);
        modifiedModals.push(modalEl);
      } catch (error) {
        // Обрабатываем возможные ошибки при доступе к приватным свойствам
        console.warn('[modal.js] Не удалось изменить настройки Bootstrap модала:', error);
      }
    });
    
    currentModalInstance = { open, close, modifiedModals };
    
    // Устанавливаем фокус на первый input в кастомном модальном окне
    focusFirstInput(modal, FOCUS_DELAY_AFTER_OPEN);
    
    return currentModalInstance;
  }

  function close() {
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    
    // Разрешаем прокрутку основного контента
    document.body.style.overflow = '';
    
    // Удаляем класс у body
    document.body.classList.remove('custom-modal-active');
    
    // Восстанавливаем focus trap только для модалов, которые были изменены
    if (currentModalInstance && currentModalInstance.modifiedModals) {
      currentModalInstance.modifiedModals.forEach(modalEl => {
        try {
          const state = bootstrapModalStates.get(modalEl);
          if (!state) return;
          
          if (window.bootstrap && window.bootstrap.Modal) {
            const bsModal = window.bootstrap.Modal.getInstance(modalEl);
            if (bsModal) {
              if (bsModal._config && typeof state.originalFocus !== 'undefined') {
                // Восстанавливаем оригинальное значение focus
                bsModal._config.focus = state.originalFocus;
              }
              
              // Реактивируем FocusTrap если он был деактивирован
              if (state.focusTrap) {
                try {
                  if (typeof state.focusTrap.activate === 'function') {
                    state.focusTrap.activate();
                  }
                } catch (e) {
                  console.warn('[modal.js] Ошибка при реактивации FocusTrap:', e);
                }
              }
            }
          }
          
          // Восстанавливаем inert attribute если он был
          if (state.hadInert) {
            modalEl.setAttribute('inert', '');
          }
          
          // Удаляем состояние из WeakMap
          bootstrapModalStates.delete(modalEl);
        } catch (error) {
          // Обрабатываем возможные ошибки при восстановлении
          console.warn('[modal.js] Не удалось восстановить настройки Bootstrap модала:', error);
        }
      });
    }
    
    currentModalInstance = null;
  }

  // === Закрытие по клику на оверлей / × / Escape ===
  function handleClick(e) {
    const target = e.target;
    if (
      target === modal ||
      target.closest('.custom-modal-close') ||
      target.closest('[data-dismiss="custom-modal"]')
    ) {
      close();
    }
  }

  function handleEscape(e) {
    if (e.key === 'Escape' && modal.classList.contains('active')) {
      close();
    }
  }

  // Навешиваем обработчики (с заменой — защита от дублей)
  modal.removeEventListener('click', handleClick);
  modal.addEventListener('click', handleClick);

  document.removeEventListener('keydown', handleEscape);
  document.addEventListener('keydown', handleEscape);

  // === Делегирование для кнопок ОТКРЫТИЯ ===
  document.removeEventListener('click', delegateOpen);
  document.addEventListener('click', delegateOpen);

  function delegateOpen(e) {
    // Ищем кнопку с нужным data-атрибутом (без привязки к классу)
    const btn = e.target.closest('[data-open-custom-modal]');
    if (btn) {
      e.preventDefault();
      
      // Если есть data-title, используем его для заголовка
      const title = btn.dataset.title || 'Подтверждение действия';
      open(title);
    }
  }

  // Экспортируем API для ручного управления
  return { open, close, getCurrentInstance: () => currentModalInstance };
}
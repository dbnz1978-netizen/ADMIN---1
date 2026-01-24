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
    
    currentModalInstance = { open, close };
    return currentModalInstance;
  }

  function close() {
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    
    // Разрешаем прокрутку основного контента
    document.body.style.overflow = '';
    
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
/**
 * Файл: /admin/user_images/js/gallery.js
 * =============================================================
 * Модуль управления медиа-галереей (gallery.js)
 * -------------------------------------------------------------
 * Этот файл содержит логику для:
 * - массового выделения/снятия выделения элементов галереи
 * - обработки кликов и нажатий клавиш на отдельных элементах
 *
 * Поддерживает несколько независимых галерей на одной странице
 * за счёт уникального идентификатора sectionId.
 *
 * Используемые data-атрибуты:
 * - data-gallery="gallery_{sectionId}" — на каждом .gallery-item
 * - data-all="gallery-all_{sectionId}"   — на кнопке "Выделить всё"
 *
 * Зависимости:
 * - Bootstrap Icons (для отображения иконок в кнопках)
 * - Современный браузер с поддержкой ES6-модулей
 *
 * Экспортируемые функции:
 * - selectAll(sectionId)      → вызывается из HTML onclick
 * - initGalleryItems()        → инициализирует интерактивность
 * =============================================================
 */

/**
 * Универсальная функция для переключения состояния "выделено всё / ничего не выделено"
 * в конкретной галерее, идентифицируемой по sectionId.
 *
 * @param {string} sectionId — уникальный идентификатор секции галереи
 *                           (например: 'profile_images', 'documents')
 * @example
 *   // В HTML: <button onclick="selectAll('profile_images')">
 *   // Вызовет переключение выделения для всех элементов с
 *   // data-gallery="gallery_profile_images"
 */
export function selectAll(sectionId) {
  // Формируем селекторы для поиска элементов галереи и управляющей кнопки
  const gallerySelector = `[data-gallery="gallery_${sectionId}"]`;
  const buttonSelector = `[data-all="gallery-all_${sectionId}"]`;

  // Находим все элементы галереи и кнопку "Выделить всё / Снять выделение"
  const items = document.querySelectorAll(gallerySelector);
  const button = document.querySelector(buttonSelector);

  // Защита от ошибок: если галерея или кнопка не существуют — выходим
  if (!items.length || !button) {
    console.warn(
      `Галерея или кнопка "Выделить всё" с sectionId="${sectionId}" не найдены на странице.`
    );
    return;
  }

  // Проверяем: выделены ли ВСЕ элементы в текущей галерее?
  const allCurrentlySelected = Array.from(items).every(
    item => item.classList.contains('focused')
  );

  // Определяем новое состояние: инвертируем текущее
  const newSelectedState = !allCurrentlySelected;

  // Применяем новое состояние ко всем элементам галереи
  items.forEach(item => {
    item.classList.toggle('focused', newSelectedState);
  });

  // Обновляем текст и иконку кнопки в зависимости от состояния
  if (newSelectedState) {
    // Все элементы выделены → предлагаем снять выделение
    button.innerHTML = '<i class="bi bi-x-circle me-1"></i> Снять выделение';

    // Показывает кнопку для удаления
    document.getElementById('deleteBtn_' + sectionId).style.display = 'inline-block';
  } else {
    // Выделение снято (или частичное) → предлагаем выделить всё
    button.innerHTML = '<i class="bi bi-check-all me-1"></i> Выделить всё';

    // Скрывает кнопку для удаления
    document.getElementById('deleteBtn_' + sectionId).style.display = 'none';
  }
}


/**
 * Переключает фокус (класс "focused") у конкретного элемента галереи,
 * и обновляет видимость кнопки удаления для всей секции.
 *
 * @param {string} sectionId — идентификатор секции (например, 'section_123')
 * @param {number|string} itemId — идентификатор элемента (например, 456)
 */
export function updateDeleteButtonVisibility(sectionId, itemId, evt) {

    // Проверка: если клик был по кнопке с классом 'btn-icon' (например, info-кнопка), прерываем выполнение
    const clickEvent = evt || window.event;
    if (clickEvent && clickEvent.target?.closest('.photoInfo')) {
        return;
    }

    // Найти галерею по sectionId
    const gallery = document.getElementById('gallery_' + sectionId);
    if (!gallery) {
        console.warn('Gallery not found for sectionId:', sectionId);
        return;
    }

    // Найти конкретный элемент внутри этой галереи
    const itemElement = gallery.querySelector('#galleryid_' + itemId);
    if (!itemElement) {
        console.warn('Item not found for itemId:', itemId, 'in section:', sectionId);
        return;
    }

    // Переключить класс 'focused'
    itemElement.classList.toggle('focused');

    // Считаем количество focused-элементов в этой галерее
    const focusedCount = gallery.querySelectorAll('.gallery-item.focused').length;

    // Найдём кнопку удаления, привязанную к этой секции
    // Допустим, кнопка имеет id="delete-btn-{sectionId}"
    const deleteButton = document.getElementById('deleteBtn_' + sectionId);

    if (deleteButton) {
        // Показываем кнопку, если есть хотя бы один focused элемент
        deleteButton.style.display = focusedCount > 0 ? 'inline-block' : 'none';
    }
}

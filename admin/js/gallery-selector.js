/**
 * Файл: /admin/js/gallery-selector.js
 * 
 * Модуль управления выбором изображений в галерее
 * — сохранение sectionId
 * — сбор выделенных элементов
 * — обновление скрытых input-полей
 */

/**
 * Сохраняет sectionId в data-атрибут кнопки "Сохранить"
 * @param {string} sectionId — идентификатор секции (например, 'profile_images')
 */
export function storeSectionId(sectionId) {
    const saveBtn = document.getElementById('saveButton');
    if (saveBtn) {
        saveBtn.dataset.sectionId = sectionId;
    } else {
        console.warn('Кнопка #saveButton не найдена для сохранения sectionId');
    }
}

/**
 * Обрабатывает клик по кнопке "Выбрать" — собирает выделенные изображения
 * и обновляет соответствующий <input type="hidden">
 */
export function handleSelectButtonClick() {
    const button = document.getElementById('saveButton');
    if (!button) {
        console.error('Кнопка #saveButton не найдена');
        return;
    }

    const sectionId = button.dataset.sectionId;
    if (!sectionId) {
        console.error('Не указан data-section-id на кнопке');
        return;
    }

    const gallery = document.getElementById(`gallery_${sectionId}`);
    if (!gallery) {
        console.error(`Галерея #gallery_${sectionId} не найдена`);
        return;
    }

    const focusedItems = gallery.querySelectorAll('.gallery-item.focused');

    const ids = Array.from(focusedItems)
        .map(item => {
            const fullId = item.id;
            const match = fullId.match(/^galleryid_(\d+)$/);
            return match ? match[1] : null;
        })
        .filter(id => id !== null);

    const newIdString = ids.join(',');

    const input = document.getElementById(`selectedImages_${sectionId}`);
    if (!input) {
        console.error(`Input #selectedImages_${sectionId} не найден`);
        return;
    }

    const current = input.value || '';
    let finalIdString = current;

    if (newIdString) {
        if (finalIdString && !finalIdString.endsWith(',')) {
            finalIdString += ',';
        }
        finalIdString += newIdString;
    }

    input.value = finalIdString;

    // Вызываем обновление, если функции доступны
    if (typeof loadGallery === 'function') {
        loadGallery(sectionId);
    }
    if (typeof loadImageSection === 'function') {
        loadImageSection(sectionId);
    }

    console.log(`[gallery-selector] Обновлён input для "${sectionId}":`, finalIdString);
}
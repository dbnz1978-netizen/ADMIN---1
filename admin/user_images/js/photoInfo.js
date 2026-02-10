/**
 * Файл: /admin/user_images/js/photoInfo.js
 * 
 * Флаг для предотвращения параллельных запросов к серверу.
 * @type {boolean}
 */

// Импортируем общие константы и функции из modal.js
import { FOCUS_DELAY_AFTER_CONTENT_LOAD, INPUT_SELECTOR, focusFirstInput } from '../../js/modal.js';

let isFetching = false;

function getCsrfToken() {
    const metaToken = document.querySelector('meta[name="csrf-token"]');
    return metaToken && metaToken.content ? metaToken.content : '';
}

/**
 * Загружает информацию о фотографии (метаданные) по её ID и вставляет результат
 * в контейнер с id="loading-contents".
 *
 * @param {string|number} photoId - Уникальный идентификатор фотографии.
 * @returns {void}
 */
export function photoInfo(photoId) {
    // Получаем целевые элементы DOM
    const loadingContents = document.getElementById('loading-contents');
    const saveMetaBtn = document.getElementById('saveMetaBtn');

    // Защита от параллельных запросов
    if (isFetching) {
        console.warn('Запрос уже выполняется. Повторный вызов проигнорирован.');
        return;
    }

    // Показываем индикатор загрузки
    if (loadingContents) {
        loadingContents.innerHTML = `
            <div class="d-flex justify-content-center my-4">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                    <span class="visually-hidden">Загрузка...</span>
                </div>
                <span>Загрузка данных...</span>
            </div>
        `;
    }

    // Блокируем кнопку "Сохранить"
    if (saveMetaBtn) {
        saveMetaBtn.disabled = true;
    }

    isFetching = true;

    // Подготавливаем данные для запроса
    const data = new FormData();
    data.append('photoId', photoId);
    const csrfToken = getCsrfToken();
    data.append('csrf_token', csrfToken);

    fetch('/admin/user_images/get_photo_info.php', {
        method: 'POST',
        headers: {
            'X-CSRF-Token': csrfToken
        },
        body: data
    })
        .then(async (res) => {
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }
            return await res.text();
        })
        .then((html) => {
            // Вставляем полученный HTML в контейнер
            if (loadingContents) {
                loadingContents.innerHTML = html || '<p class="text-muted">Нет данных для отображения.</p>';
                
                // После вставки HTML, устанавливаем фокус на первый input
                // Используем общую функцию из modal.js для консистентности
                focusFirstInput(loadingContents, FOCUS_DELAY_AFTER_CONTENT_LOAD);
            }
        })
        .catch((err) => {
            console.error('Ошибка при загрузке информации о фото:', err);
            if (loadingContents) {
                loadingContents.innerHTML = `
                    <div class="alert alert-danger p-3 mb-0" role="alert">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Не удалось загрузить данные. Попробуйте позже.
                    </div>
                `;
            }
        })
        .finally(() => {
            // Разблокируем кнопку и сбрасываем флаг
            if (saveMetaBtn) {
                saveMetaBtn.disabled = false;
            }
            isFetching = false;
        });
}

/**
 * Отправляет обновлённые метаданные фотографии на сервер.
 * Работает с существующей формой (предполагается, что она уже вставлена в #loading-contents).
 *
 * @returns {void}
 */
export function updatePhotoInfo() {
    // Находим контейнер формы (предполагается, что он внутри #loading-contents или на странице)
    const container = document.getElementById('photo-form');
    if (!container) {
        console.warn('Контейнер photo-form не найден');
        return;
    }

    // Получаем ID из data-атрибута
    const photoId = container.getAttribute('data-photo-id');
    if (!photoId) {
        console.error('ID фотографии не найден в атрибуте data-photo-id');
        return;
    }

    // Считываем поля формы
    const title = container.querySelector('[name="title"]')?.value || '';
    const description = container.querySelector('[name="description"]')?.value || '';
    const alt_text = container.querySelector('[name="alt_text"]')?.value || '';

    // Подготавливаем данные
    const data = new FormData();
    data.append('id', photoId);
    data.append('title', title);
    data.append('description', description);
    data.append('alt_text', alt_text);
    const csrfToken = getCsrfToken();
    data.append('csrf_token', csrfToken);

    // Получаем кнопку для визуальной обратной связи
    const saveBtn = document.getElementById('saveMetaBtn');
    let originalBtnHtml = '';
    if (saveBtn) {
        originalBtnHtml = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Сохранение...';
    }

    // Отправляем запрос
    fetch('/admin/user_images/update_photo_info.php', {
        method: 'POST',
        headers: {
            'X-CSRF-Token': csrfToken
        },
        body: data
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.text();
        })
        .then(responseText => {
            console.log('Данные успешно обновлены:', responseText);
        })
        .catch(err => {
            console.error('Ошибка при обновлении фото:', err);
            alert('Ошибка при обновлении данных: ' + (err.message || 'неизвестная ошибка'));
        })
        .finally(() => {
            // Восстанавливаем кнопку
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalBtnHtml;
            }
        });
}

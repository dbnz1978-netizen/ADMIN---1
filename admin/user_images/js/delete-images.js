/**
 * Файл: /admin/user_images/js/delete-images.js
 * 
 * Обработчик массового удаления изображений из галереи конкретного раздела.
 * 
 * Функция:
 * - находит все выделенные изображения в галерее указанного раздела,
 * - запрашивает подтверждение у пользователя,
 * - отправляет AJAX-запрос на сервер для удаления выбранных изображений и их файлов С CSRF,
 * - отображает статус выполнения (прогресс/ошибку),
 * - после успешного удаления обновляет содержимое галереи.
 * 
 * @param {string|number} sectionId - идентификатор раздела галереи (например, 'portfolio', 123)
 */

function getCsrfToken() {
    const metaToken = document.querySelector('meta[name="csrf-token"]');
    return metaToken && metaToken.content ? metaToken.content : '';
}

export function deleteSelectedPhotos(sectionId) {
    // Находим контейнер галереи по уникальному ID, сформированному как 'gallery_{sectionId}'
    const gallery = document.getElementById('gallery_' + sectionId);
    if (!gallery) {
        console.error('Gallery not found for section:', sectionId);
        return;
    }

    // Ищем все элементы галереи с классом 'focused' — это выделенные пользователем изображения
    const selectedItems = gallery.querySelectorAll('.gallery-item.focused');
    const count = selectedItems.length;

    // Если ничего не выбрано — выводим предупреждение и выходим
    if (count === 0) {
        alert('Нет выбранных изображений для удаления.');
        return;
    }

    // Запрашиваем подтверждение удаления у пользователя
    if (!confirm(`Вы уверены, что хотите удалить ${count} изображений?`)) {
        return;
    }

    // Извлекаем ID изображений из атрибутов `id` элементов вида 'galleryid_{id}'
    const ids = [];
    selectedItems.forEach(item => {
        const idStr = item.id; // например: 'galleryid_42'
        if (idStr.startsWith('galleryid_')) {
            // Извлекаем числовой ID после префикса 'galleryid_'
            const id = parseInt(idStr.substring('galleryid_'.length), 10);
            if (!isNaN(id)) {
                ids.push(id);
            }
        }
    });

    // Формируем строку ID через запятую для передачи на сервер
    const idString = ids.join(',');

    // Показываем индикатор загрузки
    if (typeof showSpinner === 'function') {
        showSpinner(sectionId);
    }

    // Находим кнопку удаления и блокируем её, показывая индикатор загрузки
    const button = document.getElementById('deleteBtn_' + sectionId);
    if (button) {
        button.innerHTML = 'Удаление...';
        button.disabled = true;
    }

    const csrfToken = getCsrfToken();

    // Отправляем AJAX-запрос на сервер для удаления
    $.ajax({
        url: '/admin/user_images/delete-images.php',
        type: 'POST',
        data: {
            sectionId: sectionId, // ID раздела (для контекста)
            image_ids: idString,  // строка ID изображений, например: "1,2,3"
            csrf_token: csrfToken // ✅ CSRF
        },
        headers: {
            'X-CSRF-Token': csrfToken // ✅ CSRF (дублируем, чтобы сервер мог читать и из header тоже)
        },
        dataType: 'json', // ожидаем JSON-ответ
        timeout: 30000, // таймаут 30 секунд

        // Успешный ответ от сервера
        success: function(response) {
            if (response && response.success) {
                // Обновляем содержимое галереи, перезагружая её через внешнюю функцию
                if (typeof loadImageSection === 'function') {
                    loadImageSection(sectionId);
                } else {
                    console.warn('loadImageSection is not defined, галерея не обновлена автоматически');
                }
            } else {
                // Сервер вернул ошибку (например, недостаточно прав или БД не отвечает)
                alert('Ошибка: ' + (response && response.error ? response.error : 'Неизвестная ошибка при удалении'));
            }
        },

        // Ошибка соединения или таймаут
        error: function(xhr, status, error) {
            alert('Ошибка соединения с сервером. Попробуйте позже.');
            console.error('AJAX error:', status, error);
        },

        // В любом случае — сбрасываем состояние кнопки после завершения запроса
        complete: function() {
            if (button) {
                button.innerHTML = 'Удалить';
                button.disabled = false;
            }
            
            // Скрываем индикатор загрузки
            if (typeof hideSpinner === 'function') {
                hideSpinner(sectionId);
            }
        }
    });
}

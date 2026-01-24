/**
 * Файл: /admin/user_images/js/gallery-ajax.js
 * 
 * Обновляет скрытое поле selectedImages_{sectionId} на основе выбранных изображений
 * в контейнере selectedImagesList_{sectionId}
 * 
 * @param {string} sectionId - Уникальный идентификатор галереи (например, 'profile_logo')
 */
export function updateSelectedImagesInput(sectionId) {
    // 1. Находим контейнер с выбранными изображениями
    const imageListContainer = document.getElementById('selectedImagesList_' + sectionId);
    
    // 2. Находим скрытое поле для сохранения ID
    const hiddenInput = document.getElementById('selectedImages_' + sectionId);

    // Если скрытое поле не найдено — выходим
    if (!hiddenInput) {
        console.warn('Скрытое поле selectedImages_' + sectionId + ' не найдено');
        return;
    }

    let imageIds = [];

    // Если контейнер найден — собираем ID
    if (imageListContainer) {
        const imageItems = imageListContainer.querySelectorAll('.selected-image-item[data-image-id]');
        imageIds = Array.from(imageItems).map(item => item.dataset.imageId.trim()).filter(id => id !== '');
    }

    // Формируем строку через запятую или оставляем пустой
    const newValue = imageIds.length > 0 ? imageIds.join(',') : '';

    // Обновляем значение скрытого поля
    hiddenInput.value = newValue;
}


/**
 * УПРАВЛЕНИЕ ГАЛЕРЕЕЙ ИЗОБРАЖЕНИЙ С AJAX ЗАГРУЗКОЙ
 */

// Глобальная функция для загрузки галереи export 
export function loadGallery(sectionId) {
    // Получаем image_ids из скрытого поля
    const hiddenInput = document.getElementById('selectedImages_' + sectionId);
    if (!hiddenInput) {
        console.error('Скрытое поле не найдено для sectionId:', sectionId);
        return;
    }
    
    const imageIds = hiddenInput.value;
    console.log('Загрузка галереи:', { sectionId, imageIds });

    // AJAX запрос
    $.ajax({
        url: '../user_images/image-management-section.php',
        type: 'POST',
        data: {
            sectionId: sectionId,
            image_ids: imageIds
        },
        beforeSend: function() {
            console.log('Начало загрузки данных для секции:', sectionId);
        },
        success: function(response) {
            // Вставляем полученное содержимое в конкретный контейнер
            $('#loading-content-' + sectionId).html(response);
            
            // Плавное появление (опционально)
            $('#loading-content-' + sectionId).hide().fadeIn(300);
            
            // Обновляет скрытое поле selectedImages_{sectionId} на основе выбранных изображений
            updateSelectedImagesInput(sectionId);
            
            console.log('Галерея успешно загружена для секции:', sectionId);
        },
        error: function(xhr, status, error) {
            console.error('Ошибка AJAX запроса для секции ' + sectionId + ':', error);
            const errorHtml = `
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div>
                        <strong>Ошибка!</strong> Не удалось загрузить данные для секции ${sectionId}. Пожалуйста, попробуйте позже.
                    </div>
                </div>
            `;
            $('#loading-content-' + sectionId).html(errorHtml);
        },
        complete: function() {
            console.log('Запрос завершен для секции:', sectionId);
        }
    });
}

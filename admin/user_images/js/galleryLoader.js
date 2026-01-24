/**
 * Файл: /admin/user_images/js/galleryLoader.js
 * =============================================================
 * Модуль управления динамической загрузкой медиа-галереи
 * -------------------------------------------------------------
 * Предназначен для:
 * - Загрузки HTML-содержимого галереи через AJAX из `fetch_media.php`
 * - Поддержки пагинации ("Загрузить ещё")
 * - Обновления UI без перезагрузки страницы
 *
 * Основные возможности:
 * ✅ Полная перезагрузка галереи при offset = 0
 * ✅ Дозагрузка новых изображений при прокрутке/кликe
 * ✅ Автоматическое обновление счётчика элементов
 * ✅ Управление состоянием кнопки "Загрузить ещё"
 * ✅ Обработка ошибок и восстановление UI
 *
 * Зависимости:
 * - jQuery (для работы с DOM и AJAX)
 * - Bootstrap (для spinner и стилей кнопок)
 * - PHP-скрипт `fetch_media.php` (возвращает HTML-фрагмент)
 *
 * Используемые атрибуты в HTML:
 * - data-section — уникальный идентификатор секции галереи
 * - .load-more-btn — класс кнопки пагинации
 * - .gallery-grid — контейнер для изображений
 * - .items-count — счётчик отображаемых элементов
 * - .load-more-section — секция, содержащая кнопку и счётчик
 * =============================================================
 */

/**
 * Загружает HTML-содержимое секции управления изображениями с сервера.
 * 
 * @param {string} sectionId - Уникальный идентификатор секции галереи (должен совпадать с data-section в HTML)
 * @param {number} offset - Смещение для пагинации (по умолчанию 0 — загрузить первую порцию)
 * 
 * @example
 * loadImageSection('profile_images', 0); // полная перезагрузка
 * loadImageSection('profile_images', 12); // дозагрузка следующих 12 элементов
 */
export function loadImageSection(sectionId, offset = 0) {

    // Выполняем AJAX-запрос к серверу
    $.ajax({
        url: '../user_images/fetch_media.php',           // Эндпоинт, возвращающий HTML-фрагмент галереи
        type: 'POST',                                    // Метод запроса
        data: {                                          // Передаваемые данные
            sectionId: sectionId,
            offset: offset
        },
        dataType: 'html',                 // Ожидаем HTML в ответе
        success: function (response) {
            // Находим контейнер галереи по уникальному ID
            const $container = $(`#image-management-section_${sectionId}`);
            
            if (offset === 0) {
                // Полная перезагрузка всей секции (например, при первой загрузке)
                $container.html(response);
            } else {
                // ➕ Дозагрузка новых элементов (бесконечная прокрутка / "Загрузить ещё")
                const $newHtml = $(response); // Преобразуем ответ в jQuery-объект
                const $gallery = $container.find('.gallery-grid');        // Контейнер изображений
                const $countDiv = $container.find('.items-count');        // Счётчик элементов
                const $loadMoreSection = $container.find('.load-more-section'); // Секция пагинации

                // Добавляем новые изображения в конец галереи
                $gallery.append($newHtml.find('.gallery-grid').html());

                // Обновляем текст счётчика (например: "Отображение 24 из 100")
                const newCountText = $newHtml.find('.items-count').text();
                $countDiv.text(newCountText);

                // Обновляем секцию "Загрузить ещё":
                // Ищем кнопку в новом HTML-ответе
                const $newLoadMoreBtn = $newHtml.find('.load-more-btn');
                if ($newLoadMoreBtn.length) {
                    // Есть ещё данные — заменяем секцию на новую (с кнопкой)
                    $loadMoreSection.html($newLoadMoreBtn.parent().html());
                } else {
                    // Больше данных нет — очищаем секцию (кнопка исчезает)
                    $loadMoreSection.empty();
                }
            }
        },
        error: function (xhr, status, error) {
            // Обработка ошибок сети или сервера
            console.error('Ошибка при загрузке медиа-галереи:', error);
            
            if (offset === 0) {
                // Ошибка при первой загрузке — показываем сообщение об ошибке
                $(`#image-management-section_${sectionId}`).html(
                    '<p class="text-danger">Ошибка загрузки раздела управления изображениями</p>'
                );
            } else {
                // Ошибка при дозагрузке — уведомляем пользователя и восстанавливаем кнопку
                alert('Не удалось загрузить больше медиафайлов.');
                
                // Находим кнопку по data-section и возвращаем её в исходное состояние
                const $btn = $(`.load-more-btn[data-section="${sectionId}"]`);
                $btn.prop('disabled', false).html('<i class="bi bi-arrow-clockwise me-1"></i> Загрузить ещё');
            }
        }
    });
}

/**
 * Обработчик клика по кнопке "Загрузить ещё".
 * Меняет состояние кнопки на "загрузка" и инициирует подгрузку следующей порции.
 * 
 * @param {string} sectionId - Идентификатор секции (должен совпадать с data-section)
 * @param {number} offset - Смещение для следующей порции (обычно текущее + лимит)
 * 
 * @example
 * В HTML: onclick="clickAll('profile_images', 9, 12)"
 */
export function clickAll(sectionId, offset = 0) {
    // Находим кнопку по уникальному data-section атрибуту
    const $button = $(`.load-more-btn[data-section="${sectionId}"]`);

    if ($button.length) {
        // Заменяем содержимое кнопки на индикатор загрузки Bootstrap
        $button.html('<span class="spinner-border spinner-border-sm" role="status"></span> Загрузка...');

        // Отключаем кнопку, чтобы предотвратить повторные нажатия во время запроса
        $button.prop('disabled', true);
    }

    // Запускаем загрузку следующей порции медиа
    loadImageSection(sectionId, offset);
}
/**
 * Файл: /admin/user_images/js/fileUploader.js
 * @fileoverview Модуль загрузки изображений с предварительной обработкой настроек размеров.
 * 
 * Функционал:
 * - Извлекает настройки генерации превью (ширина, высота, стиль масштабирования) из data-атрибута input
 * - Позволяет выбрать несколько изображений
 * - Отправляет файлы по одному на сервер (/upload-handler.php)
 * - Отображает прогресс загрузки на кнопке
 * - Показывает уведомления об успехе/ошибке
 * - Обновляет галерею после завершения
 * 
 * Зависимости:
 * - jQuery ($) — для работы с кнопкой (можно заменить на чистый JS при необходимости)
 * - Глобальная функция `loadImageSection(sectionId)`
 * - Элементы DOM:
 *     - <input id="fileInput_{sectionId}" data-image-sizes="...">
 *     - <button class="load-more-files" data-section-files="{sectionId}">
 *     - <div id="notify_{sectionId}"> — контейнер для уведомлений
 * 
 * @module upload
 */

/**
 * Запускает процесс выбора и загрузки изображений для указанной секции.
 * 
 * @param {string} sectionId - Уникальный идентификатор секции (используется в ID элементов и data-атрибутах)
 * @returns {void}
 * 
 * @example
 * uploadFiles('gallery_main');
 */

function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta && meta.content ? meta.content : '';
}

export function uploadFiles(sectionId) {
    // === 1. ПОИСК И ВАЛИДАЦИЯ ЭЛЕМЕНТОВ DOM ===
    const input = document.getElementById(`fileInput_${sectionId}`);
    if (!input) {
        console.error(`[uploadFiles] Input #fileInput_${sectionId} не найден`);
        return;
    }

    // === 2. ИНИЦИАЛИЗАЦИЯ ВЫБОРА ФАЙЛОВ ===
    // Открываем системный диалог выбора файлов
    input.click();

    // === 3. НАЗНАЧЕНИЕ ОБРАБОТЧИКА ИЗМЕНЕНИЯ ФАЙЛОВ ===
    // Удаляем предыдущий обработчик (чтобы избежать дублирования при повторных вызовах)
    input.removeEventListener('change', handleFiles);
    input.addEventListener('change', handleFiles);

    /**
     * Внутренняя асинхронная функция обработки выбранных файлов.
     * Выполняется после выбора файлов пользователем.
     * 
     * @param {Event} e - Событие изменения input[type="file"]
     * @returns {Promise<void>}
     */
    async function handleFiles(e) {
        // === 4.1. ФИЛЬТРАЦИЯ И ПРОВЕРКА ФАЙЛОВ ===
        const files = Array.from(e.target.files).filter(file =>
            file.type.startsWith('image/')
        );
        if (files.length === 0) {
            console.warn('[handleFiles] Выбраны не изображения или отмена выбора');
            return;
        }

        // === 4.2. ПОИСК И СОХРАНЕНИЕ СОСТОЯНИЯ КНОПКИ ===
        const $button = $(`.load-more-files[data-section-files="${CSS.escape(sectionId)}"]`);
        const originalText = $button.length ? $button.html() : 'Добавить медиа файл';
        $button.prop('disabled', true);

        // === 4.3. ИНИЦИАЛИЗАЦИЯ СЧЁТЧИКОВ ===
        let successCount = 0;
        const totalFiles = files.length;

        /**
         * Вспомогательная функция: обновляет текст кнопки с прогрессом и спиннером.
         * @private
         */
        const updateButtonText = () => {
            if ($button.length) {
                $button.html(`
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    Выбрано: ${totalFiles} / Успешно: ${successCount}
                `);
            }
        };
        updateButtonText(); // Первое обновление (0/total)

        // === 4.4. ПОСЛЕДОВАТЕЛЬНАЯ ОБРАБОТКА КАЖДОГО ФАЙЛА === 
        let limitExceeded = false;

        for (const file of files) {
            // Прерываем загрузку, если лимит уже превышен ранее
            if (limitExceeded) {
                break;
            }

            const formData = new FormData();

            formData.append('section_id', sectionId);
            formData.append('file', file);

            // === CSRF ===
            const csrfToken = getCsrfToken();
            if (!csrfToken) {
                console.error('[uploadFiles] CSRF токен не найден в meta[name="csrf-token"]');
            }

            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch('../user_images/upload-handler.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-Token': csrfToken
                }
            });


                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const result = await response.json();

                if (result.success) {
                    successCount++;
                    showNotification(sectionId, `"${file.name}" — ${result.message || 'успешно загружен'}`, 'success');
                } else {
                    // Проверяем флаг превышения лимита
                    if (result.error === "Вы достигли лимита загрузок. Удалите старые файлы.") {

                        limitExceeded = true;
                        // Показываем ОДИН раз важное уведомление 
                        showNotification(
                            sectionId,
                            `Вы достигли лимита загрузок: не более ${result.max_files_per_user} файлов`,
                            'error',
                            true  // ←←← persistent = true
                        );
                        // Не продолжаем обработку остальных файлов
                        break;
                    } else {
                        // Обычная ошибка (не лимит) — показываем как раньше
                        showNotification(sectionId, `"${file.name}" — ${result.error || 'ошибка обработки'}`, 'error');
                    }
                }
            } catch (error) {
                showNotification(sectionId, `"${file.name}" — ошибка сети или сервера`, 'error');
                console.error(`[handleFiles] Ошибка при загрузке "${file.name}":`, error);
            }

            updateButtonText(); // Обновляем прогресс даже при ошибке (кроме лимита)
        }

        // === 4.5. ВОССТАНОВЛЕНИЕ ИНТЕРФЕЙСА ПОСЛЕ ЗАВЕРШЕНИЯ ===
        if ($button.length) {
            setTimeout(() => {
                $button.html(originalText).prop('disabled', false);
            }, 1500); // плавное восстановление через 1.5 сек
        }

        // === 4.6. ОБНОВЛЕНИЕ ГАЛЕРЕИ И ОЧИСТКА ===
        try {
            if (typeof loadImageSection === 'function') {
                await loadImageSection(sectionId);
            } else {
                console.warn('[handleFiles] Функция loadImageSection не определена');
            }
        } catch (e) {
            console.error('[handleFiles] Ошибка обновления галереи:', e);
        }

        // Сбрасываем input для возможности повторного выбора тех же файлов
        input.value = '';
        // Удаляем обработчик, чтобы не накапливались при повторных вызовах uploadFiles()
        input.removeEventListener('change', handleFiles);
    }
}

/**
 * Отображает уведомление в указанной секции.
 * Перед добавлением нового уведомления — удаляет все существующие уведомления в контейнере.
 * 
 * @param {string} sectionId - ID секции
 * @param {string} message - Текст уведомления
 * @param {('success'|'error')} [type='success'] - Тип уведомления
 * @param {boolean} [persistent=false] - Если true — уведомление НЕ исчезает автоматически
 * @returns {HTMLElement|null} — созданный элемент уведомления (или null)
 */
export function showNotification(sectionId, message, type = 'success', persistent = false) {
    const container = document.getElementById(`notify_${sectionId}`);
    if (!container) {
        console.warn(`[showNotification] Контейнер #notify_${sectionId} не найден`);
        return null;
    }

    // УДАЛЯЕМ ВСЕ ПРЕДЫДУЩИЕ УВЕДОМЛЕНИЯ В ЭТОМ КОНТЕЙНЕРЕ
    // Это предотвращает дублирование (например, две ошибки лимита подряд)
    while (container.firstChild) {
        container.removeChild(container.firstChild);
    }

    const alertDiv = document.createElement('div');
    
    let alertClass, iconClass;
    if (type === 'success') {
        alertClass = 'alert alert-success d-flex align-items-center';
        iconClass = 'bi bi-check-circle-fill me-2';
    } else {
        alertClass = 'alert alert-danger d-flex align-items-center';
        iconClass = 'bi bi-exclamation-triangle-fill me-2';
    }

    alertDiv.className = alertClass;
    alertDiv.setAttribute('role', 'alert');
    alertDiv.innerHTML = `<i class="${iconClass}"></i> ${message}`;

    // Добавляем кнопку закрытия ТОЛЬКО для persistent-уведомлений
    if (persistent) {
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close ms-auto';
        closeBtn.setAttribute('aria-label', 'Закрыть');
        closeBtn.addEventListener('click', () => {
            if (alertDiv.parentNode === container) {
                container.removeChild(alertDiv);
            }
        });
        alertDiv.appendChild(closeBtn);
    }

    container.appendChild(alertDiv);

    // Автоудаление через 3 сек, если НЕ persistent
    if (!persistent) {
        setTimeout(() => {
            if (alertDiv.parentNode === container) {
                container.removeChild(alertDiv);
            }
        }, 3000);
    }

    return alertDiv;
}
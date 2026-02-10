<?php
/**
 * Файл: /admin/functions/htmleditor.php
 * Рендерит HTML-редактор WYSIWYG на странице.
 *
 * Генерирует полную разметку редактора с панелью инструментов, 
 * редактируемой областью и скрытым textarea для отправки формы.
 * Поддерживает несколько экземпляров на одной странице за счёт уникального ID.
 *
 * Требования:
 * - На странице должен быть подключен Bootstrap 5 и Bootstrap Icons
 * - Должен быть доступен JavaScript-модуль editor.js (через main.js)
 * - Для работы таблицы требуется модальное окно #tableModal (рендерится один раз)
 *
 * @param string $editorId Уникальный идентификатор редактора (используется в id элементов)
 * @param string $initialContent Начальное содержимое редактора (HTML-строка)
 * @return void Выводит HTML-разметку напрямую
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

function renderHtmlEditor($editorId, $initialContent = '') {
    $safeId = preg_replace('/[^a-z0-9_-]/i', '_', $editorId);
    $content = $initialContent;
    ?>
    
      <!-- Панель настроек внешнего вида -->
      <div class="editor-appearance-settings" id="appearance_<?php echo $safeId; ?>">
        <div class="editor-appearance-setting">
          <label for="fontFamily_<?php echo $safeId; ?>">Шрифт:</label>
          <select id="fontFamily_<?php echo $safeId; ?>" onchange="changeFontFamily('<?php echo $safeId; ?>', this.value)">
            <option value="inherit">По умолчанию</option>
            <option value="Arial, sans-serif">Arial</option>
            <option value="'Times New Roman', serif">Times New Roman</option>
            <option value="'Courier New', monospace">Courier New</option>
            <option value="Georgia, serif">Georgia</option>
            <option value="Verdana, sans-serif">Verdana</option>
          </select>
        </div>
        <div class="editor-appearance-setting">
          <label for="fontSize_<?php echo $safeId; ?>">Размер:</label>
          <select id="fontSize_<?php echo $safeId; ?>" onchange="changeFontSize('<?php echo $safeId; ?>', this.value)">
            <option value="inherit">По умолчанию</option>
            <option value="10px">10px</option>
            <option value="12px">12px</option>
            <option value="14px">14px</option>
            <option value="16px">16px</option>
            <option value="18px">18px</option>
            <option value="20px">20px</option>
            <option value="22px">22px</option>
            <option value="24px">24px</option>
            <option value="26px">26px</option>
            <option value="28px">28px</option>
            <option value="32px">32px</option>
            <option value="36px">36px</option>
            <option value="48px">48px</option>
          </select>
        </div>
      </div>

      <!-- Панель инструментов -->
      <div class="editor-toolbar mb-1 position-relative" id="toolbar_<?php echo $safeId; ?>">
        <div class="editor-toolbar-controls">
          <!-- История -->
          <div class="btn-group me-2 p-1" role="group" style="gap: 2px;">
            <button type="button" class="btn btn-outline-secondary" title="Отменить" 
                    onclick="window.editorInstances['<?php echo $safeId; ?>'].history.undo()">
              <i class="bi bi-arrow-counterclockwise"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" title="Повторить" 
                    onclick="window.editorInstances['<?php echo $safeId; ?>'].history.redo()">
              <i class="bi bi-arrow-clockwise"></i>
            </button>
          </div>

        <!-- Текстовые стили -->
        <div class="btn-group me-2 p-1" role="group" style="gap: 2px;">
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="bold" title="Жирный">
            <i class="bi bi-type-bold"></i>
          </button>
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="italic" title="Курсив">
            <i class="bi bi-type-italic"></i>
          </button>
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="underline" title="Подчёркивание">
            <i class="bi bi-type-underline"></i>
          </button>
        </div>

        <!-- Ссылки -->
        <div class="btn-group me-2 p-1" role="group" style="gap: 2px;">
          <button type="button"
            class="btn btn-outline-secondary link-btn"
            title="Ссылка">
            <i class="bi bi-link-45deg"></i>
          </button>

          <button type="button"
            class="btn btn-outline-secondary unlink-btn"
            title="Убрать ссылку">
            <i class="bi bi-x-lg"></i>
          </button>
          
          <button type="button"
            class="btn btn-outline-secondary image-link-btn"
            title="Ссылка на большое изображение">
            <i class="bi bi-image"></i><i class="bi bi-link-45deg"></i>
          </button>
          
          <button type="button"
            class="btn btn-outline-secondary unlink-image-btn"
            title="Убрать ссылку с изображения">
            <i class="bi bi-image"></i><i class="bi bi-x-lg"></i>
          </button>
        </div>
        
        <!-- Заголовки H1–H6 -->
        <div class="btn-group me-2 p-1" role="group" style="gap: 2px;">
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="formatBlock" data-value="H1" title="Заголовок H1">H1</button>
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="formatBlock" data-value="H2" title="Заголовок H2">H2</button>
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="formatBlock" data-value="H3" title="Заголовок H3">H3</button>
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="formatBlock" data-value="H4" title="Заголовок H4">H4</button>
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="formatBlock" data-value="H5" title="Заголовок H5">H5</button>
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="formatBlock" data-value="H6" title="Заголовок H6">H6</button>
        </div>

        <!-- Списки -->
        <div class="btn-group me-2 p-1" role="group" style="gap: 2px;">
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="insertUnorderedList" title="Маркированный список">
            <i class="bi bi-list-ul"></i>
          </button>
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="insertOrderedList" title="Нумерованный список">
            <i class="bi bi-list-ol"></i>
          </button>
        </div>

        <!-- Выравнивание -->
        <div class="btn-group me-2 p-1" role="group" style="gap: 2px;">
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="justifyLeft" title="По левому краю">
            <i class="bi bi-text-left"></i>
          </button>
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="justifyCenter" title="По центру">
            <i class="bi bi-text-center"></i>
          </button>
          <button type="button" class="btn btn-outline-secondary format-btn" data-command="justifyRight" title="По правому краю">
            <i class="bi bi-text-right"></i>
          </button>
        </div>

        <!-- Цвета -->
        <div class="btn-group me-2 p-1" role="group" style="gap: 2px;">
          <button type="button" class="btn btn-outline-secondary color-btn" data-type="foreColor" title="Цвет текста"
                  onclick="showColorPanel(this, '<?php echo $safeId; ?>', 'foreColor')">
            <i class="bi bi-palette"></i>
          </button>
          <button type="button" class="btn btn-outline-secondary color-btn" data-type="hiliteColor" title="Цвет фона"
                  onclick="showColorPanel(this, '<?php echo $safeId; ?>', 'hiliteColor')">
            <i class="bi bi-eraser-fill"></i>
          </button>
        </div>

        <!-- Таблица -->
        <div class="btn-group p-1" role="group">
          <button type="button" class="btn btn-outline-secondary" title="Вставить таблицу"
                  onclick="showTableModal('<?php echo $safeId; ?>')">
            <i class="bi bi-table"></i>
          </button>
        </div>

        <!-- Адаптивная сетка -->
        <div class="btn-group p-1" role="group">
          <button type="button" class="btn btn-outline-secondary" title="Адаптивная сетка"
                  onclick="showGridModal('<?php echo $safeId; ?>')">
            <i class="bi bi-grid-3x3-gap"></i>
          </button>
        </div>

        <!-- Изображение -->
        <div class="btn-group p-1" role="group">
          <button type="button" class="btn btn-outline-secondary" title="Вставить изображение"
                  data-bs-toggle="modal" data-bs-target="#editorImageModal_<?php echo $safeId; ?>"
                  onclick="storeEditorId('<?php echo $safeId; ?>')">
            <i class="bi bi-image"></i>
          </button>
        </div>

        <!-- Обтекание изображения -->
        <div class="btn-group p-1" role="group" style="gap: 2px;">
          <button type="button" class="btn btn-outline-secondary image-float-btn" data-float="left" title="Обтекание слева"
                  onclick="applyImageFloat('<?php echo $safeId; ?>', 'left')">
            <i class="bi bi-align-start"></i>
          </button>
          <button type="button" class="btn btn-outline-secondary image-float-btn" data-float="right" title="Обтекание справа"
                  onclick="applyImageFloat('<?php echo $safeId; ?>', 'right')">
            <i class="bi bi-align-end"></i>
          </button>
          <button type="button" class="btn btn-outline-secondary image-float-btn" data-float="none" title="Без обтекания"
                  onclick="applyImageFloat('<?php echo $safeId; ?>', 'none')">
            <i class="bi bi-align-center"></i>
          </button>
        </div>

          <!-- Удаление -->
          <div class="btn-group p-1" role="group">
            <button type="button" class="btn btn-outline-secondary delete-element-btn" title="Удалить выбранное">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        </div>
 
        <!-- Режим -->
        <div class="btn-group p-1 editor-toolbar-toggle" role="group">
          <button type="button" class="btn btn-outline-primary toggle-mode-btn" 
                  onclick="toggleEditMode('<?php echo $safeId; ?>')">
            <i class="bi bi-code-slash"></i> Режим HTML
          </button>
        </div>
      </div>

      <?php
        $colorPresets = [
          '#000000', '#ffffff', '#e53935', '#fb8c00', '#fdd835',
          '#43a047', '#00acc1', '#1e88e5', '#5e35b1', '#8e8e8e'
        ];
      ?>
      <!-- Панель выбора цвета -->
      <div id="colorPanel_<?php echo $safeId; ?>" class="color-panel position-absolute shadow" 
        style="display: none; z-index: 2000; padding: 0.75rem; border-radius: 0.375rem; min-width: 230px;">
        <div class="color-panel-title"><i class="bi bi-palette"></i> Популярные цвета</div>
        <div class="color-panel-grid" role="group" aria-label="Популярные цвета">
          <?php foreach ($colorPresets as $preset): ?>
            <button type="button" class="color-swatch"
                    style="background-color: <?php echo $preset; ?>;"
                    title="<?php echo $preset; ?>"
                    aria-label="Цвет <?php echo $preset; ?>"
                    onclick="applyPresetColor('<?php echo $safeId; ?>', '<?php echo $preset; ?>')">
            </button>
          <?php endforeach; ?>
        </div>
        <div class="color-panel-actions">
          <button type="button" class="btn btn-sm btn-outline-secondary" 
                  onclick="resetColor('<?php echo $safeId; ?>')">
            <i class="bi bi-x-circle"></i> Сбросить
          </button>
          <button type="button" class="btn btn-sm btn-outline-primary" 
                  onclick="openCustomColorModal('<?php echo $safeId; ?>')">
            <i class="bi bi-eyedropper"></i> Произвольный цвет
          </button>
        </div>
      </div>

      <!-- Панель добавления ссылки -->
      <div id="linkPanel_<?php echo $safeId; ?>" class="color-panel link-panel position-absolute shadow"
        style="display: none; z-index: 2000; padding: 0.75rem; border-radius: 0.375rem; min-width: 260px;">
        <div class="link-panel-title"><i class="bi bi-link-45deg"></i> Добавить ссылку</div>
        <div class="mb-2">
          <label class="form-label small mb-1" for="linkInput_<?php echo $safeId; ?>">Ссылка</label>
          <input type="url" class="form-control form-control-sm" id="linkInput_<?php echo $safeId; ?>"
                 placeholder="https://example.com">
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="linkTargetBlank_<?php echo $safeId; ?>"
                 aria-label="Открывать в новой вкладке">
          <label class="form-check-label small" for="linkTargetBlank_<?php echo $safeId; ?>">
            Открывать в новой вкладке
          </label>
        </div>
        <div class="link-panel-actions">
          <button type="button" class="btn btn-sm btn-outline-secondary link-panel-close">Закрыть</button>
          <button type="button" class="btn btn-sm btn-primary link-panel-apply">Ок</button>
        </div>
      </div>

      <!-- Редактируемая область -->
      <div class="editor-content" contenteditable="true" id="editor_<?php echo $safeId; ?>" style="font-family: Arial, sans-serif; font-size: 16px;">
        <?php echo $content; ?>
      </div>
      <textarea class="form-control" id="htmlTextarea_<?php echo $safeId; ?>" 
                name="<?php echo $safeId; ?>" style="display: none;">
        <?php echo htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
      </textarea>

    <!-- Модальное окно для таблицы (только один раз) -->
    <?php if (!isset($GLOBALS['table_modal_rendered'])): ?>
    <div class="modal fade" id="tableModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Создать таблицу</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Строки (1–20):</label>
              <input type="number" id="tableRows" class="form-control" min="1" max="20" value="3">
            </div>
            <div class="mb-3">
              <label class="form-label">Колонки (1–10):</label>
              <input type="number" id="tableCols" class="form-control" min="1" max="10" value="3">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
            <button type="button" class="btn btn-primary" onclick="insertTableFromModal()">Вставить</button>
          </div>
        </div>
      </div>
    </div>
    <?php $GLOBALS['table_modal_rendered'] = true; endif; ?>

    <!-- Модальное окно для адаптивной сетки (только один раз) -->
    <?php if (!isset($GLOBALS['grid_modal_rendered'])): ?>
    <div class="modal fade" id="gridModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Адаптивная сетка</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Количество блоков (1–8):</label>
              <input type="number" id="gridColumns" class="form-control" min="1" max="8" value="2">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
            <button type="button" class="btn btn-primary" onclick="insertGridFromModal()">Вставить</button>
          </div>
        </div>
      </div>
    </div>
    <?php $GLOBALS['grid_modal_rendered'] = true; endif; ?>

    <!-- Модальное окно для произвольного выбора цвета (только один раз) -->
    <?php if (!isset($GLOBALS['color_modal_rendered'])): ?>
    <div class="modal fade" id="customColorModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Произвольный цвет</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label"><i class="bi bi-eyedropper"></i> Выберите цвет</label>
              <input type="color" id="customColorPicker" class="form-control form-control-color w-100"
                     style="height: 60px; padding: 0; border: none; background: none; cursor: pointer;"
                     title="Выберите произвольный цвет">
            </div>
            <div class="mb-3">
              <label for="customColorCode" class="form-label">Код цвета</label>
              <input type="text" id="customColorCode" class="form-control" placeholder="#000000">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
            <button type="button" class="btn btn-primary" onclick="applyCustomColor()">ОК</button>
          </div>
        </div>
      </div>
    </div>
    <?php $GLOBALS['color_modal_rendered'] = true; endif; ?>

    <!-- Модальное окно для выбора изображения (уникальное для каждого редактора) -->
    <div class="modal fade" id="editorImageModal_<?php echo $safeId; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen-custom">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Библиотека файлов</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                </div>
                <div class="modal-body">
                    <div id="notify_editorImage_<?php echo $safeId; ?>"></div>
                    <div id="image-management-section_editorImage_<?php echo $safeId; ?>"></div>
                    <input type="file" id="fileInput_editorImage_<?php echo $safeId; ?>" multiple accept="image/*" style="display: none;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                    <button type="button" id="insertImageButton_<?php echo $safeId; ?>" class="btn btn-primary"
                            data-editor-id="<?php echo htmlspecialchars($safeId, ENT_QUOTES); ?>"
                            onclick="handleInsertImageToEditor('<?php echo $safeId; ?>')"
                            data-bs-dismiss="modal">
                        Вставить в редактор
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для просмотра увеличенного изображения (только один раз) -->
    <?php if (!isset($GLOBALS['image_preview_modal_rendered'])): ?>
    <div id="imagePreviewModal" class="image-preview-modal" style="display: none;">
        <div class="image-preview-overlay"></div>
        <div class="image-preview-content">
            <button type="button" class="image-preview-close" onclick="closeImagePreview()">
                <i class="bi bi-x-lg"></i>
            </button>
            <img id="imagePreviewImg" src="" alt="Предварительный просмотр">
        </div>
    </div>
    <?php $GLOBALS['image_preview_modal_rendered'] = true; endif; ?>

    <!-- Глобальное модальное окно с информацией о фотографии (вне Bootstrap модала для избежания конфликтов) -->
    <?php if (!isset($GLOBALS['photo_info_included'])): ?>
        <?php defined('APP_ACCESS') || define('APP_ACCESS', true); ?>
        <?php require_once __DIR__ . '/../user_images/photo_info.php'; ?>
        <?php $GLOBALS['photo_info_included'] = true; ?>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        initEditor('<?php echo $safeId; ?>');
        
        // Инициализация загрузки галереи изображений для редактора при открытии модального окна
        var editorImageModal = document.getElementById('editorImageModal_<?php echo $safeId; ?>');
        if (editorImageModal) {
            editorImageModal.addEventListener('show.bs.modal', function () {
                // Загружаем галерею изображений при открытии модального окна
                if (typeof loadImageSection === 'function') {
                    loadImageSection('editorImage_<?php echo $safeId; ?>');
                }
            });
        }
    });
    </script>
    <?php
}

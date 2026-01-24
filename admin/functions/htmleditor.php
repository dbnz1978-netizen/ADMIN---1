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
    
      <!-- Панель инструментов -->
      <div class="editor-toolbar mb-1 position-relative" id="toolbar_<?php echo $safeId; ?>">
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
            <i class="bi bi-palette"></i> Текст
          </button>
          <button type="button" class="btn btn-outline-secondary color-btn" data-type="hiliteColor" title="Цвет фона"
                  onclick="showColorPanel(this, '<?php echo $safeId; ?>', 'hiliteColor')">
            <i class="bi bi-eraser-fill"></i> Фон
          </button>
        </div>

        <!-- Таблица -->
        <div class="btn-group p-1" role="group">
          <button type="button" class="btn btn-outline-secondary" title="Вставить таблицу"
                  onclick="showTableModal('<?php echo $safeId; ?>')">
            <i class="bi bi-table"></i> Таблица
          </button>
        </div>

        <!-- Режим -->
        <div class="btn-group ms-auto p-1" role="group">
          <button type="button" class="btn btn-outline-primary toggle-mode-btn" 
                  onclick="toggleEditMode('<?php echo $safeId; ?>')">
            <i class="bi bi-code-slash"></i> Режим HTML
          </button>
        </div>
      </div>

      <!-- Панель выбора цвета -->
      <div id="colorPanel_<?php echo $safeId; ?>" class="color-panel position-absolute shadow p-1" 
        style="display: none; z-index: 1000; padding: 1rem; border-radius: 0.375rem; min-width: 220px; gap: 2px;">
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" 
                  onclick="resetColor('<?php echo $safeId; ?>')">
            <i class="bi bi-x-circle"></i> Сбросить
          </button>
          <button type="button" class="btn btn-sm btn-primary" 
                  onclick="applyColor('<?php echo $safeId; ?>')">
            <i class="bi bi-check2-circle"></i> Применить
          </button>
        </div>
        <div class="mb-3">
          <label class="form-label mb-2"><i class="bi bi-eyedropper"></i> Выберите цвет</label>
          <input type="color" id="colorInput_<?php echo $safeId; ?>" class="form-control form-control-color w-100" 
                 style="height: 50px; padding: 0; border: none; background: none; cursor: pointer;" 
                 title="Нажмите для выбора цвета">
        </div>
      </div>

      <!-- Редактируемая область -->
      <div class="editor-content" contenteditable="true" id="editor_<?php echo $safeId; ?>">
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

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        initEditor('<?php echo $safeId; ?>');
    });
    </script>
    <?php
}
?>
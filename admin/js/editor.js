/**
 * Файл: /admin/js/list-manager.js
 */

// Глобальный объект для хранения всех редакторов
window.editorInstances = window.editorInstances || {};

// Инициализация одного редактора
function initEditor(editorId) {
    const editor = document.getElementById('editor_' + editorId);
    const htmlTextarea = document.getElementById('htmlTextarea_' + editorId);
    const toggleBtn = document.querySelector('#toolbar_' + editorId + ' .toggle-mode-btn');
    
    if (!editor || !htmlTextarea) {
        console.warn('Editor not found for ID:', editorId);
        return;
    }

    // История для этого редактора
    const history = {
        stack: [editor.innerHTML],
        index: 0,
        maxSize: 50,
        isHtmlMode: false,
        save() {
            if (this.isHtmlMode) return;
            const content = editor.innerHTML;
            if (this.index === this.stack.length - 1) {
                if (this.stack[this.index] !== content) {
                    this.stack.push(content);
                    if (this.stack.length > this.maxSize) this.stack.shift();
                    else this.index++;
                }
            } else {
                this.stack = this.stack.slice(0, this.index + 1);
                this.stack.push(content);
                this.index++;
            }
        },
        undo() {
            if (this.index > 0) {
                this.index--;
                editor.innerHTML = this.stack[this.index];
                syncContent();
                updateButtonStates();
            }
        },
        redo() {
            if (this.index < this.stack.length - 1) {
                this.index++;
                editor.innerHTML = this.stack[this.index];
                syncContent();
                updateButtonStates();
            }
        }
    };

    // Сохраняем экземпляр
    window.editorInstances[editorId] = { editor, htmlTextarea, toggleBtn, history };

    // Внутренние функции
    const syncContent = () => {
        if (!history.isHtmlMode) {
            htmlTextarea.value = editor.innerHTML;
        }
    };

    const updateButtonStates = () => {
        if (history.isHtmlMode) return;
        ['bold', 'italic', 'underline'].forEach(cmd => {
            const btn = document.querySelector(`#toolbar_${editorId} .format-btn[data-command="${cmd}"]`);
            if (btn) btn.classList.toggle('active', document.queryCommandState(cmd));
        });
        ['H1','H2','H3','H4','H5','H6'].forEach(tag => {
            const btn = document.querySelector(`#toolbar_${editorId} .format-btn[data-value="${tag}"]`);
            if (btn) {
                const sel = window.getSelection();
                let isActive = false;
                if (sel.rangeCount > 0) {
                    let el = sel.getRangeAt(0).commonAncestorContainer;
                    el = el.nodeType === 1 ? el : el.parentElement;
                    isActive = el.tagName === tag;
                }
                btn.classList.toggle('active', isActive);
            }
        });
        ['insertUnorderedList', 'insertOrderedList'].forEach(cmd => {
            const btn = document.querySelector(`#toolbar_${editorId} .format-btn[data-command="${cmd}"]`);
            if (btn) btn.classList.toggle('active', document.queryCommandState(cmd));
        });
        const align = getAlignment();
        ['justifyLeft', 'justifyCenter', 'justifyRight'].forEach(cmd => {
            const btn = document.querySelector(`#toolbar_${editorId} .format-btn[data-command="${cmd}"]`);
            if (btn) btn.classList.toggle('active', align === cmd);
        });
    };

    const getAlignment = () => {
        const sel = window.getSelection();
        if (sel.rangeCount === 0) return 'justifyLeft';
        let el = sel.getRangeAt(0).commonAncestorContainer;
        el = el.nodeType === 1 ? el : el.parentElement;
        const style = getComputedStyle(el);
        switch (style.textAlign) {
            case 'center': return 'justifyCenter';
            case 'right': return 'justifyRight';
            default: return 'justifyLeft';
        }
    };

    const formatText = (command, value = null) => {
        if (history.isHtmlMode) return;
        if (command === 'formatBlock' && value && isFormatActive('formatBlock', value)) {
            document.execCommand('formatBlock', false, '<P>');
        } else {
            document.execCommand(command, false, value);
        }
        editor.focus();
        syncContent();
        updateButtonStates();
        history.save();
    };

    const isFormatActive = (command, value = null) => {
        if (command === 'formatBlock') {
            const sel = window.getSelection();
            if (sel.rangeCount === 0) return false;
            let el = sel.getRangeAt(0).commonAncestorContainer;
            el = el.nodeType === 1 ? el : el.parentElement;
            return el.tagName === value;
        }
        return document.queryCommandState(command);
    };

    // === НОВОЕ: Обработчик кнопок форматирования ===
    document.querySelectorAll(`#toolbar_${editorId} .format-btn`).forEach(btn => {
        btn.addEventListener('click', () => {
            const command = btn.dataset.command;
            const value = btn.dataset.value || null;
            formatText(command, value);
        });
    });



    // === ССЫЛКИ ===
    const linkBtn = document.querySelector(`#toolbar_${editorId} .link-btn`);
    if (linkBtn) {
        linkBtn.addEventListener('click', () => {
        if (history.isHtmlMode) return;

            const sel = window.getSelection();
            if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return;

            let url = prompt('Введите ссылку (https://...)');
        if (!url) return;

        url = url.trim();

        // Небольшая помощь: если пользователь ввёл "site.com", добавим протокол
        if (!/^https?:\/\//i.test(url) && !/^mailto:/i.test(url) && !/^tel:/i.test(url)) {
            url = 'https://' + url;
        }

        document.execCommand('createLink', false, url);

        // Безопаснее: всем созданным ссылкам добавим target/rel
        const range = sel.getRangeAt(0);
        let el = range.commonAncestorContainer;
        el = el.nodeType === 1 ? el : el.parentElement;
        const a = el?.closest?.('a');
        if (a) {
          a.setAttribute('target', '_blank');
          a.setAttribute('rel', 'noopener noreferrer');
        }

        editor.focus();
        syncContent();
        updateButtonStates();
        history.save();
        });
    }

    const unlinkBtn = document.querySelector(`#toolbar_${editorId} .unlink-btn`);
    if (unlinkBtn) {
      unlinkBtn.addEventListener('click', () => {
        if (history.isHtmlMode) return;
        document.execCommand('unlink', false, null);
        editor.focus();
        syncContent();
        updateButtonStates();
        history.save();
      });
    }
    
    // Цвет
    window.showColorPanel = function(button, id, type) {
        if (window.editorInstances[id]?.history.isHtmlMode) return;
        window.activeColorEditor = id;
        window.activeColorType = type;
        const panel = document.getElementById('colorPanel_' + id);
        if (!panel) return;
        const rect = button.getBoundingClientRect();
        panel.style.left = (rect.left + window.scrollX) + 'px';
        panel.style.top = (rect.bottom + window.scrollY + 5) + 'px';
        const colorInput = document.getElementById('colorInput_' + id);
        if (colorInput) colorInput.value = '#000000';
        panel.style.display = 'block';
    };

    window.applyColor = function(id) {
        const color = document.getElementById('colorInput_' + id)?.value;
        if (!color) return;
        document.execCommand(window.activeColorType, false, color);
        window.editorInstances[id]?.editor.focus();
        syncContent();
        history.save();
        document.getElementById('colorPanel_' + id).style.display = 'none';
    };

    window.resetColor = function(id) {
        const color = window.activeColorType === 'foreColor' ? '#000000' : 'transparent';
        document.execCommand(window.activeColorType, false, color);
        window.editorInstances[id]?.editor.focus();
        syncContent();
        history.save();
        document.getElementById('colorPanel_' + id).style.display = 'none';
    };

    // Таблица
    window.showTableModal = function(id) {
        if (window.editorInstances[id]?.history.isHtmlMode) return;
        window.activeTableEditor = id;
        const modal = new bootstrap.Modal(document.getElementById('tableModal'));
        modal.show();
    };

    window.insertTableFromModal = function() {
        if (!window.activeTableEditor) return;
        const rows = Math.min(20, Math.max(1, parseInt(document.getElementById('tableRows')?.value || '3')));
        const cols = Math.min(10, Math.max(1, parseInt(document.getElementById('tableCols')?.value || '3')));
        let html = '<table>';
        for (let r = 0; r < rows; r++) {
            html += '<tr>';
            for (let c = 0; c < cols; c++) {
                html += '<td><br></td>';
            }
            html += '</tr>';
        }
        html += '</table>';
        document.execCommand('insertHTML', false, html);
        window.editorInstances[window.activeTableEditor]?.editor.focus();
        syncContent();
        history.save();
        bootstrap.Modal.getInstance(document.getElementById('tableModal'))?.hide();
        window.activeTableEditor = null;
    };

    // Переключение режима
    window.toggleEditMode = function(id) {
        const inst = window.editorInstances[id];
        if (!inst) return;
        inst.history.isHtmlMode = !inst.history.isHtmlMode;
        if (inst.history.isHtmlMode) {
            inst.htmlTextarea.value = inst.editor.innerHTML;
            inst.editor.style.display = 'none';
            inst.htmlTextarea.style.display = 'block';
            inst.toggleBtn.innerHTML = '<i class="bi bi-eye"></i> Визуальный режим';
            inst.toggleBtn.classList.replace('btn-outline-primary', 'btn-outline-secondary');
        } else {
            inst.editor.innerHTML = inst.htmlTextarea.value;
            inst.htmlTextarea.style.display = 'none';
            inst.editor.style.display = 'block';
            inst.toggleBtn.innerHTML = '<i class="bi bi-code-slash"></i> Режим HTML';
            inst.toggleBtn.classList.replace('btn-outline-secondary', 'btn-outline-primary');
        }
    };

    // Отправка формы
    document.getElementById('postForm_' + editorId)?.addEventListener('submit', (e) => {
        if (!history.isHtmlMode) {
            htmlTextarea.value = editor.innerHTML;
        }
    });

    // События
    editor.addEventListener('input', () => { syncContent(); history.save(); });
    editor.addEventListener('mouseup', updateButtonStates);
    editor.addEventListener('keyup', updateButtonStates);

    // Инициализация
    htmlTextarea.value = editor.innerHTML;
    history.stack = [editor.innerHTML];
    history.index = 0;
}

// Закрытие панелей цвета при клике вне
document.addEventListener('click', (e) => {
    Object.keys(window.editorInstances).forEach(id => {
        const panel = document.getElementById('colorPanel_' + id);
        if (!panel) return;
        const buttons = document.querySelectorAll(`#toolbar_${id} .color-btn`);
        const isClickOnColorBtn = Array.from(buttons).some(btn => btn.contains(e.target));
        if (!panel.contains(e.target) && !isClickOnColorBtn) {
            panel.style.display = 'none';
        }
    });
});
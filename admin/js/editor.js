/**
 * Файл: /admin/js/editor.js
 * Улучшенный WYSIWYG-редактор с поддержкой настроек внешнего вида
 */

// Глобальный объект для хранения всех редакторов
window.editorInstances = window.editorInstances || {};
window.tableModalSelections = window.tableModalSelections || {};
window.tableModalCleanupHandler = window.tableModalCleanupHandler || null;
window.gridModalSelections = window.gridModalSelections || {};
window.gridModalCleanupHandler = window.gridModalCleanupHandler || null;
window.activeLinkEditor = null;
window.activeLinkSelection = null;
window.activeLinkImage = null;
window.activeLinkElement = null;
window.resetLinkState = window.resetLinkState || function() {
    window.activeLinkEditor = null;
    window.activeLinkSelection = null;
    window.activeLinkImage = null;
    window.activeLinkElement = null;
};

function getLinkTargetBlankCheckbox(panel, editorId) {
    return panel?.querySelector(`#linkTargetBlank_${editorId}`);
}

// Константы для классов обтекания изображений
const IMAGE_FLOAT_CLASSES = {
    LEFT: 'float-left',
    RIGHT: 'float-right',
    NONE: 'float-none'
};
const LINK_EMPTY_MESSAGE = 'Введите ссылку';
const ALLOWED_FONT_FAMILIES = new Set([
    'Arial, sans-serif',
    "'Times New Roman', serif",
    "'Courier New', monospace",
    'Georgia, serif',
    'Verdana, sans-serif'
]);
const FONT_SIZE_PATTERN = /^\d+(?:\.\d+)?px$/;
const DEFAULT_FONT_FAMILY = 'Arial, sans-serif';
const DEFAULT_FONT_SIZE = '16px';
const DEFAULT_FONT_SELECT_VALUE = 'inherit';
const SAFE_URL_PROTOCOLS = new Set(['http:', 'https:', 'mailto:', 'tel:']);
const DATA_IMAGE_PATTERN = /^data:image\/[a-z0-9.+-]+;base64,/i;

function sanitizeUrl(value, { allowDataImage = false } = {}) {
    if (!value) return '';
    const trimmed = value.trim();
    if (!trimmed) return '';
    const isDataImage = allowDataImage && DATA_IMAGE_PATTERN.test(trimmed);
    if (isDataImage) {
        return trimmed;
    }
    if (/^data:/i.test(trimmed) && !isDataImage) {
        return '';
    }
    if (/^(javascript|vbscript):/i.test(trimmed)) {
        return '';
    }
    try {
        const parsed = new URL(trimmed, window.location.href);
        if (SAFE_URL_PROTOCOLS.has(parsed.protocol)) {
            return trimmed;
        }
    } catch (e) {
        // Некорректный URL может быть относительным или неполным — это безопасно игнорировать.
    }
    if (/^(\/|\.\/|\.\.\/|#)/.test(trimmed)) {
        return trimmed;
    }
    return '';
}

function sanitizeEditorContainer(container) {
    container.querySelectorAll('*').forEach((element) => {
        const tagName = element.tagName?.toLowerCase();
        if (tagName && ['script', 'iframe', 'object', 'embed'].includes(tagName)) {
            element.remove();
            return;
        }
        Array.from(element.attributes).forEach((attr) => {
            const name = attr.name.toLowerCase();
            if (name.startsWith('on')) {
                element.removeAttribute(attr.name);
                return;
            }
            if (name === 'href') {
                const safeUrl = sanitizeUrl(attr.value);
                if (safeUrl) {
                    element.setAttribute('href', safeUrl);
                } else {
                    element.removeAttribute('href');
                }
            }
            if (name === 'src') {
                const safeUrl = sanitizeUrl(attr.value, { allowDataImage: true });
                if (safeUrl) {
                    element.setAttribute('src', safeUrl);
                } else {
                    element.removeAttribute('src');
                }
            }
        });
    });
}

function createSanitizedContainer(html) {
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = html;
    sanitizeEditorContainer(tempDiv);
    return tempDiv;
}

function setEditorHtml(editor, html) {
    if (!editor) return;
    const tempDiv = createSanitizedContainer(html);
    if (typeof editor.replaceChildren === 'function') {
        const fragment = document.createDocumentFragment();
        while (tempDiv.firstChild) {
            fragment.appendChild(tempDiv.firstChild);
        }
        editor.replaceChildren(fragment);
        return;
    }
    // Фолбэк для старых браузеров без replaceChildren.
    while (editor.firstChild) {
        editor.removeChild(editor.firstChild);
    }
    while (tempDiv.firstChild) {
        editor.appendChild(tempDiv.firstChild);
    }
}

function normalizeFontFamily(value) {
    return value && value !== 'inherit' && ALLOWED_FONT_FAMILIES.has(value)
        ? value
        : DEFAULT_FONT_FAMILY;
}

function normalizeFontSize(value) {
    return value && value !== 'inherit' && FONT_SIZE_PATTERN.test(value)
        ? value
        : DEFAULT_FONT_SIZE;
}

function getElementFromNode(node) {
    return node?.nodeType === 1 ? node : node?.parentElement;
}

// Полифиллы для старых браузеров
if (!Element.prototype.closest) {
    Element.prototype.closest = function(s) {
        var el = this;
        do {
            if (el.matches(s)) return el;
            el = el.parentElement || el.parentNode;
        } while (el !== null && el.nodeType === 1);
        return null;
    };
}

if (!Element.prototype.matches) {
    Element.prototype.matches = Element.prototype.msMatchesSelector || 
                                Element.prototype.webkitMatchesSelector;
}

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
                setEditorHtml(editor, this.stack[this.index]);
                syncContent();
                updateButtonStates();
            }
        },
        redo() {
            if (this.index < this.stack.length - 1) {
                this.index++;
                setEditorHtml(editor, this.stack[this.index]);
                syncContent();
                updateButtonStates();
            }
        }
    };

    // Сохраняем экземпляр с функцией очистки
    window.editorInstances[editorId] = { 
        editor, 
        htmlTextarea, 
        toggleBtn, 
        history,
        cleanEditorHtml: (html) => {
            const tempDiv = createSanitizedContainer(html);
            // Удаляем класс selected-image со всех изображений
            tempDiv.querySelectorAll('img.selected-image').forEach(img => {
                img.classList.remove('selected-image');
            });
            
            // Удаляем все wrapper'ы с ручками изменения размера
            tempDiv.querySelectorAll('.resize-handles-wrapper').forEach(wrapper => {
                wrapper.remove();
            });
            
            return tempDiv.innerHTML;
        }
    };

    // Внутренние функции
    const cleanEditorHtml = window.editorInstances[editorId].cleanEditorHtml;
    
    const syncContent = () => {
        if (!history.isHtmlMode) {
            htmlTextarea.value = cleanEditorHtml(editor.innerHTML);
        }
    };

    const updateAppearanceSettings = (forceDefault = false) => {
        if (history.isHtmlMode) return;
        const fontFamilySelect = document.getElementById(`fontFamily_${editorId}`);
        const fontSizeSelect = document.getElementById(`fontSize_${editorId}`);
        if (!fontFamilySelect && !fontSizeSelect) return;
        const selection = window.getSelection();
        let element = null;
        if (selection) {
            const focusElement = getElementFromNode(selection.focusNode);
            element = focusElement || getElementFromNode(selection.anchorNode);
        }
        const isInsideEditor = element && editor.contains(element);
        const isImageTarget = element
            && (element.tagName?.toLowerCase() === 'img' || element.closest?.('img'));
        const isTextTarget = isInsideEditor && !isImageTarget;
        const setSelectValue = (select, value, fallback) => {
            if (!select) return;
            const hasOption = Array.from(select.options).some(option => option.value === value);
            select.value = hasOption ? value : fallback;
        };
        const defaultSelectValue = DEFAULT_FONT_SELECT_VALUE;
        if (forceDefault || !isTextTarget) {
            if (fontFamilySelect) fontFamilySelect.value = defaultSelectValue;
            if (fontSizeSelect) fontSizeSelect.value = defaultSelectValue;
            return;
        }
        const findStyleSource = (startElement, styleKey) => {
            let current = startElement;
            while (current && current !== editor) {
                if (current.style && current.style[styleKey]) {
                    return current;
                }
                current = current.parentElement;
            }
            return null;
        };
        const fontFamilyElement = findStyleSource(element, 'fontFamily');
        const fontSizeElement = findStyleSource(element, 'fontSize');
        const editorStyle = window.getComputedStyle(editor);
        const editorFontFamily = normalizeFontFamily(editorStyle.fontFamily);
        const editorFontSize = normalizeFontSize(editorStyle.fontSize);
        const elementStyle = window.getComputedStyle(element);
        const currentFontFamily = normalizeFontFamily(elementStyle.fontFamily);
        const currentFontSize = normalizeFontSize(elementStyle.fontSize);
        const useFontFamily = !!fontFamilyElement || currentFontFamily !== editorFontFamily;
        const useFontSize = !!fontSizeElement || currentFontSize !== editorFontSize;
        setSelectValue(fontFamilySelect, useFontFamily ? currentFontFamily : defaultSelectValue, defaultSelectValue);
        setSelectValue(fontSizeSelect, useFontSize ? currentFontSize : defaultSelectValue, defaultSelectValue);
    };

    const updateButtonStates = () => {
        if (history.isHtmlMode) return;
        const selection = window.getSelection();
        const range = selection && selection.rangeCount > 0 ? selection.getRangeAt(0) : null;
        const selectionContainer = range?.commonAncestorContainer
            ? getElementFromNode(range.commonAncestorContainer)
            : null;
        ['bold', 'italic', 'underline'].forEach(cmd => {
            const btn = document.querySelector(`#toolbar_${editorId} .format-btn[data-command="${cmd}"]`);
            if (btn) btn.classList.toggle('active', document.queryCommandState(cmd));
        });
        ['H1','H2','H3','H4','H5','H6'].forEach(tag => {
            const btn = document.querySelector(`#toolbar_${editorId} .format-btn[data-value="${tag}"]`);
            if (btn) {
                const headingTag = tag.toLowerCase();
                const isActive = selectionContainer?.tagName?.toLowerCase() === headingTag;
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
        const selectedImage = editor.querySelector('img.selected-image') ||
            (selectionContainer?.matches?.('img') ? selectionContainer : selectionContainer?.closest?.('img'));
        let anchorFromSelection = null;
        if (selection) {
            const selectionNodes = [selectionContainer, selection.anchorNode, selection.focusNode];
            for (const node of selectionNodes) {
                const element = getElementFromNode(node);
                anchorFromSelection = element?.closest?.('a');
                if (anchorFromSelection) break;
            }
        }
        const hasImageLink = !!selectedImage?.closest('a');
        const hasTextLink = anchorFromSelection && !hasImageLink;
        const hasLink = !!anchorFromSelection || hasImageLink;
        const linkBtn = document.querySelector(`#toolbar_${editorId} .link-btn`);
        if (linkBtn) linkBtn.classList.toggle('active', hasLink);
        const unlinkBtn = document.querySelector(`#toolbar_${editorId} .unlink-btn`);
        if (unlinkBtn) unlinkBtn.classList.toggle('active', hasTextLink);
        const imageLinkBtn = document.querySelector(`#toolbar_${editorId} .image-link-btn`);
        if (imageLinkBtn) imageLinkBtn.classList.toggle('active', hasImageLink);
        const unlinkImageBtn = document.querySelector(`#toolbar_${editorId} .unlink-image-btn`);
        if (unlinkImageBtn) unlinkImageBtn.classList.toggle('active', hasImageLink);
        const imageFloatButtons = document.querySelectorAll(`#toolbar_${editorId} .image-float-btn`);
        imageFloatButtons.forEach(btn => {
            const floatType = btn.dataset.float;
            let isActive = false;
            if (selectedImage && floatType) {
                const hasFloatLeft = selectedImage.classList.contains(IMAGE_FLOAT_CLASSES.LEFT);
                const hasFloatRight = selectedImage.classList.contains(IMAGE_FLOAT_CLASSES.RIGHT);
                const isFloatNone = !hasFloatLeft && !hasFloatRight;
                isActive = (floatType === 'none' && isFloatNone) ||
                    (floatType === 'left' && hasFloatLeft) ||
                    (floatType === 'right' && hasFloatRight);
            }
            btn.classList.toggle('active', isActive);
        });
        updateAppearanceSettings();
    };

    window.editorInstances[editorId].updateButtonStates = updateButtonStates;
    window.editorInstances[editorId].updateAppearanceSettings = updateAppearanceSettings;

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
        try {
            if (command === 'formatBlock' && value && isFormatActive('formatBlock', value)) {
                document.execCommand('formatBlock', false, '<P>');
            } else {
                document.execCommand(command, false, value);
            }
        } catch (e) {
            console.warn('Formatting command failed:', command, value, e);
        }
        editor.focus();
        syncContent();
        updateButtonStates();
        history.save();
        
        // Если применена команда выравнивания к изображению, обновляем позицию ручек
        if (['justifyCenter', 'justifyRight', 'justifyLeft'].includes(command)) {
            const selectedImg = editor.querySelector('img.selected-image');
            if (selectedImg) {
                let wrapper = selectedImg.nextElementSibling;
                if (!wrapper || !wrapper.classList.contains('resize-handles-wrapper')) {
                    const existingWrapper = editor.querySelector('.resize-handles-wrapper');
                    if (existingWrapper && selectedImg.parentNode) {
                        if (typeof selectedImg.after === 'function') {
                            selectedImg.after(existingWrapper);
                        } else {
                            selectedImg.parentNode.insertBefore(existingWrapper, selectedImg.nextSibling);
                        }
                        wrapper = existingWrapper;
                    }
                }
                if (wrapper && wrapper.classList.contains('resize-handles-wrapper')) {
                    // Force browser reflow
                    void selectedImg.offsetHeight;
                    
                    // Используем двойной requestAnimationFrame для обновления позиции
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            updateResizeHandlesPosition(selectedImg, wrapper, editor);
                        });
                    });
                }
            }
        }
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

            const panel = document.getElementById('linkPanel_' + editorId);
            if (!panel) return;

            // Сначала ищем выбранное изображение с классом selected-image
            let img = editor.querySelector('img.selected-image');
            const sel = window.getSelection();
            let el = null;
            let range = null;

            // Если не нашли выбранное изображение, пробуем получить из текущего выделения
            if (!img && sel && sel.rangeCount > 0) {
                range = sel.getRangeAt(0);
                el = range.commonAncestorContainer;
                el = el.nodeType === 1 ? el : el.parentElement;
                img = el?.tagName === 'IMG' ? el : el.querySelector?.('img');
            }

            const existingLink = img?.closest('a') || el?.closest?.('a');

            // Если изображение не найдено и выделение пустое или нет, показываем ошибку
            if (!img && (!sel || sel.rangeCount === 0 || sel.isCollapsed) && !existingLink) {
                alert('Пожалуйста, выберите текст или изображение для добавления ссылки');
                return;
            }

            window.activeLinkEditor = editorId;
            const selectionRange = range instanceof Range
                ? range.cloneRange()
                : (typeof getEditorSelectionRange === 'function' ? getEditorSelectionRange(editorId) : null);
            window.activeLinkSelection = selectionRange;
            window.activeLinkImage = img;
            window.activeLinkElement = existingLink || el;

            const input = panel.querySelector(`#linkInput_${editorId}`);
            const checkbox = getLinkTargetBlankCheckbox(panel, editorId);
            if (input) {
                input.value = existingLink?.getAttribute('href') || '';
            }
            if (checkbox) {
                const shouldOpenInNewTab = existingLink?.getAttribute('target') === '_blank';
                checkbox.checked = shouldOpenInNewTab;
            }
            const rect = linkBtn.getBoundingClientRect();
            panel.style.left = (rect.left + window.scrollX) + 'px';
            panel.style.top = (rect.bottom + window.scrollY + 5) + 'px';
            panel.style.display = 'block';

            if (input) {
                input.focus();
                input.select();
            }
        });
    }

    const unlinkBtn = document.querySelector(`#toolbar_${editorId} .unlink-btn`);
    if (unlinkBtn) {
      unlinkBtn.addEventListener('click', () => {
        if (history.isHtmlMode) return;
        
        // Сначала проверяем, есть ли выбранное изображение
        let img = editor.querySelector('img.selected-image');
        
        // Если нет, пробуем получить из текущего выделения
        if (!img) {
            const sel = window.getSelection();
            if (sel && sel.rangeCount > 0) {
                const range = sel.getRangeAt(0);
                let el = range.commonAncestorContainer;
                el = el.nodeType === 1 ? el : el.parentElement;
                
                // Ищем изображение
                img = el?.tagName === 'IMG' ? el : el.querySelector?.('img');
                
                // Если не нашли, проверяем родителя
                if (!img && el?.parentElement?.tagName === 'A') {
                    const parent = el.parentElement;
                    img = parent.querySelector('img');
                }
            }
        }
        
        // Если нашли изображение, удаляем ссылку с него
        if (img) {
            try {
                const parent = img.parentElement;
                // Если изображение в ссылке, удаляем ссылку
                if (parent.tagName === 'A') {
                    const grandParent = parent.parentElement;
                    grandParent.insertBefore(img, parent);
                    grandParent.removeChild(parent);
                    
                }
            } catch (e) {
                console.warn('Image unlink failed:', e);
            }
        } else {
            // Если изображения нет, пробуем удалить текстовую ссылку
            try {
                document.execCommand('unlink', false, null);
            } catch (e) {
                console.warn('Unlink command failed:', e);
            }
        }
        
        editor.focus();
        syncContent();
        updateButtonStates();
        history.save();
      });
    }
    
    // === ССЫЛКИ НА ИЗОБРАЖЕНИЯ ===
    const imageLinkBtn = document.querySelector(`#toolbar_${editorId} .image-link-btn`);
    if (imageLinkBtn) {
        imageLinkBtn.addEventListener('click', () => {
            if (history.isHtmlMode) return;

            // Сначала ищем выбранное изображение с классом selected-image
            let img = editor.querySelector('img.selected-image');
            
            // Если не нашли, пробуем получить из текущего выделения
            if (!img) {
                const sel = window.getSelection();
                if (sel && sel.rangeCount > 0) {
                    const range = sel.getRangeAt(0);
                    let el = range.commonAncestorContainer;
                    el = el.nodeType === 1 ? el : el.parentElement;
                    img = el?.tagName === 'IMG' ? el : el.querySelector?.('img');
                }
            }

            if (!img) {
                alert('Пожалуйста, выберите изображение для добавления ссылки');
                return;
            }

            // Получаем текущий src изображения
            const currentSrc = img.src;
            
            // Преобразуем medium в large для ссылки на большое изображение
            let largeImageUrl = currentSrc.replace(/_medium\./, '_large.');
            
            // Проверяем, произошла ли замена (если нет, используем текущий URL)
            if (largeImageUrl === currentSrc) {
                // Пытаемся заменить _thumbnail на _large
                largeImageUrl = currentSrc.replace(/_thumbnail\./, '_large.');
                
                // Если и это не сработало, используем текущий URL как есть
                if (largeImageUrl === currentSrc) {
                    console.warn('Could not convert image URL to large size, using current URL');
                }
            }
            
            try {
                const parent = img.parentElement;
                // Если изображение уже в ссылке, обновляем URL
                if (parent.tagName === 'A') {
                    parent.href = largeImageUrl;
                    parent.setAttribute('data-image-preview', 'true');
                    parent.removeAttribute('target');
                    parent.removeAttribute('rel');
                } else {
                    // Создаем новую ссылку для просмотра большого изображения
                    const link = document.createElement('a');
                    link.href = largeImageUrl;
                    link.setAttribute('data-image-preview', 'true');
                    parent.insertBefore(link, img);
                    link.appendChild(img);
                }
                
                
            } catch (e) {
                console.warn('Image link creation failed:', e);
                alert('Не удалось создать ссылку на изображение');
            }

            editor.focus();
            syncContent();
            updateButtonStates();
            history.save();
        });
    }
    
    const unlinkImageBtn = document.querySelector(`#toolbar_${editorId} .unlink-image-btn`);
    if (unlinkImageBtn) {
        unlinkImageBtn.addEventListener('click', () => {
            if (history.isHtmlMode) return;

            // Сначала ищем выбранное изображение с классом selected-image
            let img = editor.querySelector('img.selected-image');
            
            // Если не нашли, пробуем получить из текущего выделения
            if (!img) {
                const sel = window.getSelection();
                if (sel && sel.rangeCount > 0) {
                    const range = sel.getRangeAt(0);
                    let el = range.commonAncestorContainer;
                    el = el.nodeType === 1 ? el : el.parentElement;
                    
                    // Ищем изображение
                    img = el?.tagName === 'IMG' ? el : el.querySelector?.('img');
                    
                    // Если не нашли, проверяем родителя
                    if (!img && el?.parentElement?.tagName === 'A') {
                        const parent = el.parentElement;
                        img = parent.querySelector('img');
                    }
                }
            }

            if (!img) {
                alert('Пожалуйста, выберите изображение для удаления ссылки');
                return;
            }

            try {
                const parent = img.parentElement;
                // Если изображение в ссылке, удаляем ссылку
                if (parent.tagName === 'A') {
                    const grandParent = parent.parentElement;
                    grandParent.insertBefore(img, parent);
                    grandParent.removeChild(parent);
                    
                }
            } catch (e) {
                console.warn('Image unlink failed:', e);
                alert('Не удалось удалить ссылку с изображения');
            }

            editor.focus();
            syncContent();
            updateButtonStates();
            history.save();
        });
    }

    const deleteElementBtn = document.querySelector(`#toolbar_${editorId} .delete-element-btn`);
    if (deleteElementBtn) {
        deleteElementBtn.addEventListener('click', () => {
            if (history.isHtmlMode) return;

            const selection = window.getSelection();
            const selectedImage = editor.querySelector('img.selected-image');

            if (selectedImage) {
                hideResizeHandles();
                selectedImage.remove();
            } else if (selection && !selection.isCollapsed) {
                try {
                    // Используем deleteFromDocument, если доступно, иначе удаляем через Range.
                    if (typeof selection.deleteFromDocument === 'function') {
                        selection.deleteFromDocument();
                    } else if (selection.rangeCount > 0) {
                        selection.getRangeAt(0).deleteContents();
                    }
                } catch (e) {
                    console.warn('Delete command failed:', e);
                }
            } else {
                alert('Пожалуйста, выберите текст или изображение для удаления');
                return;
            }

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
        window.activeColorSelection = getEditorSelectionRange(id);
        const colorPicker = document.getElementById('customColorPicker');
        const colorCode = document.getElementById('customColorCode');
        if (colorPicker) colorPicker.value = '#000000';
        if (colorCode) colorCode.value = '#000000';
        const panel = document.getElementById('colorPanel_' + id);
        if (!panel) return;
        const rect = button.getBoundingClientRect();
        panel.style.left = (rect.left + window.scrollX) + 'px';
        panel.style.top = (rect.bottom + window.scrollY + 5) + 'px';
        panel.style.display = 'block';
    };

    window.applyPresetColor = function(id, color) {
        if (!color) return;
        applyColorValue(id, color);
    };

    window.applyColor = function(id) {
        const color = document.getElementById('customColorPicker')?.value;
        if (!color) return;
        applyColorValue(id, color);
    };

    window.resetColor = function(id) {
        const color = window.activeColorType === 'foreColor' ? '#000000' : 'transparent';
        applyColorValue(id, color);
    };

    window.openCustomColorModal = function(id) {
        if (!id) return;
        const panel = document.getElementById('colorPanel_' + id);
        if (panel) panel.style.display = 'none';
        const colorPicker = document.getElementById('customColorPicker');
        const colorCode = document.getElementById('customColorCode');
        if (colorPicker) colorPicker.value = '#000000';
        if (colorCode) colorCode.value = '#000000';
        const modalElement = document.getElementById('customColorModal');
        if (!modalElement) return;
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();
    };

    window.applyCustomColor = function() {
        const id = window.activeColorEditor;
        if (!id) return;
        const colorPicker = document.getElementById('customColorPicker');
        const colorCode = document.getElementById('customColorCode');
        let color = colorPicker?.value || '';
        if (colorCode && colorCode.value) {
            const trimmed = colorCode.value.trim();
            const normalized = trimmed.startsWith('#') ? trimmed : `#${trimmed}`;
            if (!/^#([0-9a-fA-F]{3}){1,2}$/.test(normalized)) {
                alert('Введите корректный HEX-код цвета, например #ff0000');
                return;
            }
            color = normalized;
        }
        if (!color) return;
        applyColorValue(id, color);
        bootstrap.Modal.getInstance(document.getElementById('customColorModal'))?.hide();
    };

    function getEditorSelectionRange(id) {
        const editor = window.editorInstances[id]?.editor;
        if (!editor) return null;
        const selection = window.getSelection();
        if (!selection || selection.rangeCount === 0) return null;
        const range = selection.getRangeAt(0);
        const container = range.commonAncestorContainer;
        if (container && editor.contains(container.nodeType === Node.ELEMENT_NODE ? container : container.parentNode)) {
            return range.cloneRange();
        }
        return null;
    }

    function restoreColorSelection(id) {
        const editor = window.editorInstances[id]?.editor;
        const range = window.activeColorSelection;
        if (!editor || !range) return;
        editor.focus();
        const selection = window.getSelection();
        if (!selection) return;
        try {
            selection.removeAllRanges();
            selection.addRange(range);
        } catch (e) {
            console.warn('Could not restore color selection:', e);
        }
    }

    function applyColorValue(id, color) {
        if (!color) return;
        restoreColorSelection(id);
        try {
            document.execCommand(window.activeColorType, false, color);
        } catch (e) {
            console.warn('Color application failed:', e);
        }
        window.editorInstances[id]?.editor.focus();
        syncContent();
        history.save();
        const panel = document.getElementById('colorPanel_' + id);
        if (panel) panel.style.display = 'none';
    }

    if (!window.customColorListenersAdded) {
        document.addEventListener('input', (e) => {
            if (e.target && e.target.id === 'customColorPicker') {
                const colorCode = document.getElementById('customColorCode');
                if (colorCode) colorCode.value = e.target.value;
            }
        });

        document.addEventListener('input', (e) => {
            if (e.target && e.target.id === 'customColorCode') {
                const trimmed = e.target.value.trim();
                const normalized = trimmed.startsWith('#') ? trimmed : `#${trimmed}`;
                if (/^#([0-9a-fA-F]{3}){1,2}$/.test(normalized)) {
                    const colorPicker = document.getElementById('customColorPicker');
                    if (colorPicker) colorPicker.value = normalized;
                }
            }
        });
        window.customColorListenersAdded = true;
    }

    // Таблица
    window.showTableModal = function(id) {
        if (window.editorInstances[id]?.history.isHtmlMode) return;
        window.activeTableEditor = id;
        const editor = window.editorInstances[id]?.editor;
        const selection = window.getSelection();
        let storedRange = null;
        if (editor && selection && selection.rangeCount > 0) {
            const range = selection.getRangeAt(0);
            let container = range.commonAncestorContainer;
            // Normalize text node selection to parent element
            if (container.nodeType === Node.TEXT_NODE) {
                container = container.parentNode;
            }
            if (container && editor.contains(container)) {
                // Clone range to prevent changes during modal interaction
                storedRange = range.cloneRange();
            }
        }
        if (storedRange) {
            window.tableModalSelections[id] = storedRange;
        }
        const modalElement = document.getElementById('tableModal');
        if (!modalElement) return;
        if (window.tableModalCleanupHandler) {
            modalElement.removeEventListener('hidden.bs.modal', window.tableModalCleanupHandler);
        }
        window.tableModalCleanupHandler = () => {
            if (window.activeTableEditor === id) {
                delete window.tableModalSelections[id];
                window.activeTableEditor = null;
            }
        };
        modalElement.addEventListener('hidden.bs.modal', window.tableModalCleanupHandler, { once: true });
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    };

    window.insertTableFromModal = function() {
        if (!window.activeTableEditor) return;
        const editorInstance = window.editorInstances[window.activeTableEditor];
        const selectionRange = window.tableModalSelections?.[window.activeTableEditor];
        if (editorInstance?.editor) {
            editorInstance.editor.focus();
            if (selectionRange) {
                const selection = window.getSelection();
                if (selection) {
                    try {
                        selection.removeAllRanges();
                        selection.addRange(selectionRange);
                    } catch (e) {
                        console.warn('Could not restore selection for table insertion. Table may be inserted at an unexpected location.', {
                            editorId: window.activeTableEditor,
                            error: e
                        });
                    }
                }
            }
        }
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
        try {
            document.execCommand('insertHTML', false, html);
        } catch (e) {
            console.warn('Insert HTML failed:', e);
            // Alternative approach for browsers that don't support insertHTML
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                const range = selection.getRangeAt(0);
                range.deleteContents();
                const fragment = document.createDocumentFragment();
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                while (tempDiv.firstChild) {
                    fragment.appendChild(tempDiv.firstChild);
                }
                range.insertNode(fragment);
            }
        }
        window.editorInstances[window.activeTableEditor]?.editor.focus();
        syncContent();
        history.save();
        bootstrap.Modal.getInstance(document.getElementById('tableModal'))?.hide();
        delete window.tableModalSelections[window.activeTableEditor];
        window.activeTableEditor = null;
    };

    // Адаптивная сетка
    window.showGridModal = function(id) {
        if (window.editorInstances[id]?.history.isHtmlMode) return;
        window.activeGridEditor = id;
        const editor = window.editorInstances[id]?.editor;
        const selection = window.getSelection();
        let storedRange = null;
        if (editor && selection && selection.rangeCount > 0) {
            const range = selection.getRangeAt(0);
            let container = range.commonAncestorContainer;
            if (container.nodeType === Node.TEXT_NODE) {
                container = container.parentNode;
            }
            if (container && editor.contains(container)) {
                storedRange = range.cloneRange();
            }
        }
        if (storedRange) {
            window.gridModalSelections[id] = storedRange;
        }
        const modalElement = document.getElementById('gridModal');
        if (!modalElement) return;
        if (window.gridModalCleanupHandler) {
            modalElement.removeEventListener('hidden.bs.modal', window.gridModalCleanupHandler);
        }
        window.gridModalCleanupHandler = () => {
            if (window.activeGridEditor === id) {
                delete window.gridModalSelections[id];
                window.activeGridEditor = null;
            }
        };
        modalElement.addEventListener('hidden.bs.modal', window.gridModalCleanupHandler, { once: true });
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    };

    window.insertGridFromModal = function() {
        if (!window.activeGridEditor) return;
        const editorInstance = window.editorInstances[window.activeGridEditor];
        const selectionRange = window.gridModalSelections?.[window.activeGridEditor];
        if (editorInstance?.editor) {
            editorInstance.editor.focus();
            if (selectionRange) {
                const selection = window.getSelection();
                if (selection) {
                    try {
                        selection.removeAllRanges();
                        selection.addRange(selectionRange);
                    } catch (e) {
                        console.warn('Could not restore selection for grid insertion. Grid may be inserted at an unexpected location.', {
                            editorId: window.activeGridEditor,
                            error: e
                        });
                    }
                }
            }
        }
        const columns = Math.min(8, Math.max(1, parseInt(document.getElementById('gridColumns')?.value || '2')));
        let html = `<section class="wysiwyg-grid wysiwyg-grid-${columns}">`;
        for (let i = 0; i < columns; i++) {
            html += '<div class="wysiwyg-grid__item"><p><br></p></div>';
        }
        html += '</section>';
        try {
            document.execCommand('insertHTML', false, html);
        } catch (e) {
            console.warn('Insert HTML failed:', e);
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                const range = selection.getRangeAt(0);
                range.deleteContents();
                const fragment = document.createDocumentFragment();
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                while(tempDiv.firstChild) {
                    fragment.appendChild(tempDiv.firstChild);
                }
                range.insertNode(fragment);
            }
        }
        if (editorInstance) {
            editorInstance.editor?.focus();
            if (!editorInstance.history.isHtmlMode) {
                editorInstance.htmlTextarea.value = editorInstance.cleanEditorHtml(editorInstance.editor.innerHTML);
            }
            editorInstance.history.save();
        }
        bootstrap.Modal.getInstance(document.getElementById('gridModal'))?.hide();
        delete window.gridModalSelections[window.activeGridEditor];
        window.activeGridEditor = null;
    };

    // Переключение режима
    window.toggleEditMode = function(id) {
        const inst = window.editorInstances[id];
        if (!inst) return;
        const toolbar = document.getElementById('toolbar_' + id);
        const appearancePanel = document.getElementById('appearance_' + id);
        inst.history.isHtmlMode = !inst.history.isHtmlMode;
        if (toolbar) {
            toolbar.classList.toggle('is-html-mode', inst.history.isHtmlMode);
        }
        if (appearancePanel) {
            appearancePanel.classList.toggle('is-hidden', inst.history.isHtmlMode);
        }
        if (inst.history.isHtmlMode) {
            // Очищаем HTML от служебных элементов перед переключением в HTML режим
            inst.htmlTextarea.value = inst.cleanEditorHtml(inst.editor.innerHTML);
            inst.editor.style.display = 'none';
            inst.htmlTextarea.style.display = 'block';
            inst.toggleBtn.innerHTML = '<i class="bi bi-eye"></i> Визуальный режим';
            inst.toggleBtn.classList.remove('btn-outline-primary');
            inst.toggleBtn.classList.add('btn-outline-secondary');
        } else {
            const sanitizedHtml = inst.cleanEditorHtml(inst.htmlTextarea.value);
            inst.htmlTextarea.value = sanitizedHtml;
            setEditorHtml(inst.editor, sanitizedHtml);
            inst.htmlTextarea.style.display = 'none';
            inst.editor.style.display = 'block';
            inst.toggleBtn.innerHTML = '<i class="bi bi-code-slash"></i> Режим HTML';
            inst.toggleBtn.classList.remove('btn-outline-secondary');
            inst.toggleBtn.classList.add('btn-outline-primary');
            inst.updateAppearanceSettings?.();
        }
    };

    // Отправка формы
    // fallback нужен, чтобы находить ближайшую форму у страниц без postForm_{id}
    const form = document.getElementById('postForm_' + editorId)
        || editor?.closest('form');
    form?.addEventListener('submit', () => {
        if (!history.isHtmlMode) {
            htmlTextarea.value = cleanEditorHtml(editor.innerHTML);
        }
    });

    // События
    editor.addEventListener('input', () => { syncContent(); history.save(); });
    editor.addEventListener('mouseup', updateButtonStates);
    editor.addEventListener('keyup', updateButtonStates);
    
    // Событие фокуса для лучшего UX
    editor.addEventListener('focus', () => {
        editor.classList.add('focused');
    });
    
    editor.addEventListener('blur', () => {
        editor.classList.remove('focused');
        updateAppearanceSettings(true);
    });

    // Инициализация
    htmlTextarea.value = editor.innerHTML;
    history.stack = [editor.innerHTML];
    history.index = 0;
    
    // Загружаем настройки внешнего вида
    loadAppearanceSettings(editorId);
    
    // Инициализируем обработчики изменения размера изображений
    initImageResizeHandlers(editorId);
}

function applyFontStyle(editorId, styleProperty, styleValue) {
    const instance = window.editorInstances[editorId];
    const editor = instance?.editor;
    if (!editor || instance?.history.isHtmlMode) return false;
    if (!styleProperty || !styleValue) return false;
    const allowedProperties = ['font-family', 'font-size'];
    if (!allowedProperties.includes(styleProperty)) return false;
    if (styleProperty === 'font-family') {
        if (normalizeFontFamily(styleValue) !== styleValue) return false;
    }
    if (styleProperty === 'font-size') {
        if (normalizeFontSize(styleValue) !== styleValue) return false;
    }
    const selection = window.getSelection();
    if (selection && selection.rangeCount > 0 && !selection.isCollapsed) {
        const range = selection.getRangeAt(0);
        if (!range.toString()) return false;
        const container = range.commonAncestorContainer;
        const element = container.nodeType === Node.ELEMENT_NODE ? container : container.parentNode;
        if (element && editor.contains(element)) {
            const existingSpan = element.closest('span[data-font-style="true"]');
            if (existingSpan && editor.contains(existingSpan)) {
                existingSpan.style.setProperty(styleProperty, styleValue);
                if (styleProperty === 'font-family') {
                    existingSpan.dataset.fontFamily = styleValue;
                } else if (styleProperty === 'font-size') {
                    existingSpan.dataset.fontSize = styleValue;
                }
                editor.focus();
                if (instance?.htmlTextarea && instance?.cleanEditorHtml) {
                    instance.htmlTextarea.value = instance.cleanEditorHtml(editor.innerHTML);
                }
                instance?.history?.save?.();
                return true;
            }
            const fragment = range.extractContents();
            if (fragment && fragment.childNodes.length > 0) {
                const span = document.createElement('span');
                span.style.setProperty(styleProperty, styleValue);
                span.dataset.fontStyle = 'true';
                if (styleProperty === 'font-family') {
                    span.dataset.fontFamily = styleValue;
                } else if (styleProperty === 'font-size') {
                    span.dataset.fontSize = styleValue;
                }
                span.appendChild(fragment);
                range.insertNode(span);
                editor.focus();
                try {
                    selection.removeAllRanges();
                    const newRange = document.createRange();
                    newRange.selectNodeContents(span);
                    newRange.collapse(false);
                    selection.addRange(newRange);
                } catch (e) {
                    const errorMessage = e?.message || 'Unknown error';
                    console.warn(`Failed to position caret after applying font style to selection: ${errorMessage}`);
                }
                if (instance?.htmlTextarea && instance?.cleanEditorHtml) {
                    instance.htmlTextarea.value = instance.cleanEditorHtml(editor.innerHTML);
                }
                instance?.history?.save?.();
                return true;
            }
        }
    }
    return false;
}

// Функция изменения шрифта
function changeFontFamily(editorId, fontFamily) {
    const instance = window.editorInstances[editorId];
    const editor = instance?.editor;
    if (editor && !instance?.history.isHtmlMode) {
        const normalizedFontFamily = normalizeFontFamily(fontFamily);
        if (!applyFontStyle(editorId, 'font-family', normalizedFontFamily)) {
            return;
        }
        instance.updateAppearanceSettings?.();
    }
}

// Функция изменения размера шрифта
function changeFontSize(editorId, fontSize) {
    const instance = window.editorInstances[editorId];
    const editor = instance?.editor;
    if (editor && !instance?.history.isHtmlMode) {
        const normalizedFontSize = normalizeFontSize(fontSize);
        if (!applyFontStyle(editorId, 'font-size', normalizedFontSize)) {
            return;
        }
        instance.updateAppearanceSettings?.();
    }
}

// Загрузка сохраненных настроек при инициализации
function loadAppearanceSettings(editorId) {
    const fontFamily = DEFAULT_FONT_FAMILY;
    const fontSize = DEFAULT_FONT_SIZE;
    const editor = document.getElementById('editor_' + editorId);
    const fontFamilySelect = document.getElementById(`fontFamily_${editorId}`);
    const fontSizeSelect = document.getElementById(`fontSize_${editorId}`);

    if (fontFamilySelect) {
        fontFamilySelect.value = fontFamily;
    }
    if (fontSizeSelect) {
        fontSizeSelect.value = fontSize;
    }
    if (editor) {
        editor.style.fontFamily = fontFamily;
        editor.style.fontSize = fontSize;
    }
    window.editorInstances[editorId]?.updateAppearanceSettings?.();
}

function restoreLinkSelection(editorId) {
    const editor = window.editorInstances[editorId]?.editor;
    const range = window.activeLinkSelection;
    if (!editor) return;
    editor.focus();
    const selection = window.getSelection();
    if (!selection) return;
    try {
        selection.removeAllRanges();
        if (range) {
            selection.addRange(range);
        }
    } catch (e) {
        console.warn('Could not restore link selection:', e);
    }
}

function applyLinkValue(editorId, url, shouldOpenInNewTab = false) {
    const trimmedUrl = url?.trim();
    if (!trimmedUrl) return;
    const safeUrl = sanitizeUrl(trimmedUrl);
    if (!safeUrl) {
        alert('Введите корректный URL.');
        return;
    }
    const editorInstance = window.editorInstances[editorId];
    const editor = editorInstance?.editor;
    if (!editorInstance || !editor) return;
    const img = window.activeLinkImage;
    const el = window.activeLinkElement;
    const existingAnchor = el?.closest?.('a');
    restoreLinkSelection(editorId);
    try {
        if (img) {
            const parent = img.parentElement;
            if (parent?.tagName === 'A') {
                parent.href = safeUrl;
                if (shouldOpenInNewTab) {
                    parent.setAttribute('target', '_blank');
                    parent.setAttribute('rel', 'noopener noreferrer');
                } else {
                    parent.removeAttribute('target');
                    parent.removeAttribute('rel');
                }
            } else if (parent) {
                const link = document.createElement('a');
                link.href = safeUrl;
                if (shouldOpenInNewTab) {
                    link.setAttribute('target', '_blank');
                    link.setAttribute('rel', 'noopener noreferrer');
                }
                parent.insertBefore(link, img);
                link.appendChild(img);
            }
        } else if (existingAnchor) {
            existingAnchor.href = safeUrl;
            if (shouldOpenInNewTab) {
                existingAnchor.setAttribute('target', '_blank');
                existingAnchor.setAttribute('rel', 'noopener noreferrer');
            } else {
                existingAnchor.removeAttribute('target');
                existingAnchor.removeAttribute('rel');
            }
        } else if (window.activeLinkSelection) {
            document.execCommand('createLink', false, safeUrl);
            const getAnchorFromNode = (node) => {
                const element = node?.nodeType === Node.ELEMENT_NODE ? node : node?.parentElement;
                return element?.closest?.('a');
            };
            const selection = window.getSelection();
            const anchor = getAnchorFromNode(selection?.anchorNode)
                || getAnchorFromNode(selection?.focusNode)
                || getAnchorFromNode(selection?.rangeCount ? selection.getRangeAt(0).commonAncestorContainer : null);
            if (anchor && editor.contains(anchor)) {
                if (shouldOpenInNewTab) {
                    anchor.setAttribute('target', '_blank');
                    anchor.setAttribute('rel', 'noopener noreferrer');
                } else {
                    anchor.removeAttribute('target');
                    anchor.removeAttribute('rel');
                }
            }
        }
    } catch (e) {
        console.warn('Link creation failed:', e);
        alert('Не удалось создать ссылку. Пожалуйста, проверьте URL.');
    }
    editor.focus();
    if (!editorInstance.history.isHtmlMode) {
        editorInstance.htmlTextarea.value = editorInstance.cleanEditorHtml(editor.innerHTML);
    }
    editorInstance.updateButtonStates?.();
    editorInstance.history.save();
    window.resetLinkState();
}

function applyLinkFromPanel(panel, editorId, url) {
    const shouldOpenInNewTab = getLinkTargetBlankCheckbox(panel, editorId)?.checked ?? false;
    applyLinkValue(editorId, url, shouldOpenInNewTab);
}

if (!window.linkPanelListenersAdded) {
    document.addEventListener('click', (e) => {
        if (!e.target) return;
        const applyButton = e.target.closest('.link-panel-apply');
        const closeButton = e.target.closest('.link-panel-close');
        if (!applyButton && !closeButton) return;

        const panel = e.target.closest('.link-panel');
        if (!panel) return;
        const editorId = panel.id?.replace('linkPanel_', '');
        if (!editorId) return;
        const input = panel.querySelector(`#linkInput_${editorId}`);

        if (applyButton) {
            const url = input?.value || '';
            if (!url.trim()) {
                alert(LINK_EMPTY_MESSAGE);
                input?.focus();
                return;
            }
            applyLinkFromPanel(panel, editorId, url);
        }

        panel.style.display = 'none';
        window.resetLinkState();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        const input = e.target?.closest('.link-panel input');
        if (!input) return;
        e.preventDefault();
        const panel = input.closest('.link-panel');
        const editorId = panel?.id?.replace('linkPanel_', '');
        if (!editorId) return;
        const url = input.value || '';
        if (!url.trim()) {
            alert(LINK_EMPTY_MESSAGE);
            input.focus();
            return;
        }
        applyLinkFromPanel(panel, editorId, url);
        panel.style.display = 'none';
        window.resetLinkState();
    });

    window.linkPanelListenersAdded = true;
}

// Закрытие панелей цвета и ссылок при клике вне
document.addEventListener('click', (e) => {
    Object.keys(window.editorInstances).forEach(id => {
        const colorPanel = document.getElementById('colorPanel_' + id);
        if (colorPanel) {
            const buttons = document.querySelectorAll(`#toolbar_${id} .color-btn`);
            const isClickOnColorBtn = Array.from(buttons).some(btn => btn.contains(e.target));
            if (!colorPanel.contains(e.target) && !isClickOnColorBtn) {
                colorPanel.style.display = 'none';
            }
        }

        const linkPanel = document.getElementById('linkPanel_' + id);
        if (!linkPanel) return;
        const buttons = document.querySelectorAll(`#toolbar_${id} .link-btn`);
        const isClickOnLinkBtn = Array.from(buttons).some(btn => btn.contains(e.target));
        if (!linkPanel.contains(e.target) && !isClickOnLinkBtn) {
            linkPanel.style.display = 'none';
            window.resetLinkState();
        }
        window.editorInstances[id]?.updateAppearanceSettings?.();
    });
});

// Обработка кликов вне панелей настроек внешнего вида
document.addEventListener('click', (e) => {
    Object.keys(window.editorInstances).forEach(id => {
        const appearancePanel = document.getElementById('appearance_' + id);
        if (!appearancePanel) return;
        
        // Не закрываем панель, если клик был внутри неё или по элементам управления
        if (!appearancePanel.contains(e.target)) {
            // Проверяем, не является ли элемент, по которому кликнули, частью панели настроек
            const isAppearanceControl = e.target.closest('.editor-appearance-setting') ||
                                      e.target.closest(`#fontFamily_${id}`) ||
                                      e.target.closest(`#fontSize_${id}`);
            
            if (!isAppearanceControl) {
                // При необходимости можно добавить логику скрытия панели настроек
                // appearancePanel.style.display = 'none'; // Пока не скрываем, т.к. панель всегда видна
            }
        }
    });
});

// === ФУНКЦИИ ДЛЯ ВСТАВКИ ИЗОБРАЖЕНИЙ В РЕДАКТОР ===

// Глобальная переменная для хранения ID текущего активного редактора
window.activeEditorForImage = null;

/**
 * Сохраняет ID редактора для последующей вставки изображения
 * @param {string} editorId - ID редактора
 */
window.storeEditorId = function(editorId) {
    window.activeEditorForImage = editorId;
};

/**
 * Применяет стиль обтекания текста к выбранному изображению
 * @param {string} editorId - ID редактора
 * @param {string} floatType - Тип обтекания: 'left', 'right', 'none'
 */
window.applyImageFloat = function(editorId, floatType) {
    if (!editorId) {
        console.error('Editor ID not provided');
        return;
    }
    
    const editor = document.getElementById('editor_' + editorId);
    if (!editor) {
        console.error('Editor not found:', editorId);
        return;
    }
    
    // Ищем выбранное изображение (с классом selected-image)
    let selectedImg = editor.querySelector('img.selected-image');
    
    // Если нет выбранного изображения, пробуем получить изображение из текущей выделенной области
    if (!selectedImg) {
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
            const range = selection.getRangeAt(0);
            const container = range.commonAncestorContainer;
            
            // Если выделен элемент или его родитель
            if (container.nodeType === Node.ELEMENT_NODE && container.tagName === 'IMG') {
                selectedImg = container;
            } else if (container.parentNode && container.parentNode.tagName === 'IMG') {
                selectedImg = container.parentNode;
            }
        }
    }
    
    if (!selectedImg) {
        alert('Пожалуйста, сначала выберите изображение, кликнув на него');
        return;
    }
    
    // Удаляем все классы обтекания
    selectedImg.classList.remove(IMAGE_FLOAT_CLASSES.LEFT, IMAGE_FLOAT_CLASSES.RIGHT, IMAGE_FLOAT_CLASSES.NONE);
    
    // Добавляем нужный класс обтекания
    if (floatType === 'left') {
        selectedImg.classList.add(IMAGE_FLOAT_CLASSES.LEFT);
    } else if (floatType === 'right') {
        selectedImg.classList.add(IMAGE_FLOAT_CLASSES.RIGHT);
    } else if (floatType === 'none') {
        selectedImg.classList.add(IMAGE_FLOAT_CLASSES.NONE);
    }
    
    // Обновляем позицию ручек изменения размера, если они существуют
    const wrapper = selectedImg.nextElementSibling;
    if (wrapper && wrapper.classList.contains('resize-handles-wrapper')) {
        // Принудительно вызываем reflow браузера для применения изменений float
        // getBoundingClientRect() заставляет браузер полностью пересчитать layout с учетом новых margin
        selectedImg.getBoundingClientRect();
        
        // Используем тройной requestAnimationFrame для гарантии полного завершения расчета layout
        // Первый кадр: браузер обрабатывает изменение float-класса
        // Второй кадр: браузер пересчитывает layout с новыми margin от float (например, 0→15px или 15px→0)
        // Третий кадр: браузер полностью завершает все расчеты и корректно позиционирует маркеры
        // Это исправляет проблему, когда позиция вычисляется неправильно сразу после изменения float
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    updateResizeHandlesPosition(selectedImg, wrapper, editor);
                });
            });
        });
    }
    
    // Синхронизируем с textarea
    const editorInstance = window.editorInstances[editorId];
    if (editorInstance && editorInstance.htmlTextarea) {
        editorInstance.htmlTextarea.value = editorInstance.cleanEditorHtml(editor.innerHTML);
    }

    editorInstance?.updateButtonStates?.();
    
    // Сохраняем в историю
    if (editorInstance && editorInstance.history && typeof editorInstance.history.save === 'function') {
        editorInstance.history.save();
    }
    
    
};

/**
 * Обрабатывает вставку выбранных изображений в редактор
 * @param {string} editorId - ID редактора
 */
window.handleInsertImageToEditor = function(editorId) {
    if (!editorId) {
        console.error('Editor ID not provided');
        return;
    }
    
    const sectionId = 'editorImage_' + editorId;
    const gallery = document.getElementById('gallery_' + sectionId);
    
    if (!gallery) {
        console.error('Gallery not found for section:', sectionId);
        return;
    }
    
    // Получаем все выбранные изображения (с классом focused)
    const selectedItems = gallery.querySelectorAll('.gallery-item.focused');
    
    if (selectedItems.length === 0) {
        alert('Пожалуйста, выберите хотя бы одно изображение');
        return;
    }
    
    // Получаем экземпляр редактора
    const editorInstance = window.editorInstances[editorId];
    if (!editorInstance || !editorInstance.editor) {
        console.error('Editor instance not found:', editorId);
        return;
    }
    
    const editor = editorInstance.editor;
    
    // Если редактор в HTML режиме, не вставляем
    if (editorInstance.history.isHtmlMode) {
        alert('Пожалуйста, переключитесь в визуальный режим для вставки изображений');
        return;
    }
    
    // Собираем URL изображений
    const imageUrls = [];
    selectedItems.forEach(item => {
        const img = item.querySelector('img');
        if (img && img.src) {
            // Получаем src изображения из галереи (это thumbnail)
            const thumbnailUrl = img.src;
            
            // Пытаемся получить data-атрибут с ID изображения для более точной загрузки
            const imageId = item.id.match(/galleryid_(\d+)/);
            
            // Заменяем _thumbnail на _medium в имени файла для вставки в редактор
            // Это обеспечит лучшее качество изображения в редакторе
            let fullUrl = thumbnailUrl.replace(/_thumbnail\./, '_medium.');
            
            imageUrls.push({
                url: fullUrl,
                alt: img.alt || 'Image',
                id: imageId ? imageId[1] : null
            });
        }
    });
    
    if (imageUrls.length === 0) {
        alert('Не удалось получить URL изображений');
        return;
    }
    
    // Фокусируемся на редакторе
    editor.focus();
    
    // Вставляем изображения
    imageUrls.forEach(imageData => {
        const imgHtml = `<img src="${imageData.url}" alt="${imageData.alt}" style="max-width: 100%; height: auto;">`;
        try {
            document.execCommand('insertHTML', false, imgHtml);
        } catch (e) {
            console.warn('Insert HTML failed, using alternative method:', e);
            // Альтернативный метод вставки
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                const range = selection.getRangeAt(0);
                range.deleteContents();
                const img = document.createElement('img');
                img.src = imageData.url;
                img.alt = imageData.alt;
                img.style.maxWidth = '100%';
                img.style.height = 'auto';
                range.insertNode(img);
            }
        }
    });
    
    // Синхронизируем содержимое с textarea
    if (editorInstance.htmlTextarea) {
        editorInstance.htmlTextarea.value = editorInstance.cleanEditorHtml(editor.innerHTML);
    }
    
    // Сохраняем в историю
    if (editorInstance.history && typeof editorInstance.history.save === 'function') {
        editorInstance.history.save();
    }
    
    // Снимаем выделение со всех изображений в галерее
    selectedItems.forEach(item => {
        item.classList.remove('focused');
    });
    
    
};

// === ФУНКЦИОНАЛ ИЗМЕНЕНИЯ РАЗМЕРА ИЗОБРАЖЕНИЙ ===

/**
 * Глобальная переменная для хранения состояния изменения размера
 */
window.imageResizeState = {
    isResizing: false,
    currentImage: null,
    currentHandle: null,
    startX: 0,
    startY: 0,
    startWidth: 0,
    startHeight: 0,
    aspectRatio: 1,
    editorId: null
};

/**
 * Вычисляет позицию left для wrapper'а ручек изменения размера
 * @param {HTMLImageElement} img - Элемент изображения
 * @param {DOMRect} imgRect - Прямоугольник изображения
 * @param {DOMRect} editorRect - Прямоугольник редактора
 * @param {number} borderLeft - Ширина левой границы редактора
 * @param {number} scrollLeft - Прокрутка редактора по горизонтали
 * @param {number} outlineOffset - Отступ outline изображения
 * @returns {number} - Вычисленная позиция left
 */
function calculateResizeHandlesLeftPosition(img, imgRect, editorRect, borderLeft, scrollLeft, outlineOffset) {
    // getBoundingClientRect() возвращает правильную позицию для всех типов float (left, right, none)
    // после того как браузер завершит расчет layout
    let leftPosition = imgRect.left - editorRect.left - borderLeft + scrollLeft - outlineOffset;
    
    return leftPosition;
}

/**
 * Обновляет позицию wrapper'а ручек изменения размера
 * @param {HTMLImageElement} img - Элемент изображения
 * @param {HTMLDivElement} wrapper - Wrapper с ручками
 * @param {HTMLElement} editor - Элемент редактора
 */
function updateResizeHandlesPosition(img, wrapper, editor) {
    if (!wrapper || !img || !editor) return;
    
    const imgRect = img.getBoundingClientRect();
    const editorRect = editor.getBoundingClientRect();
    
    const editorStyle = window.getComputedStyle(editor);
    const borderLeft = parseFloat(editorStyle.borderLeftWidth) || 0;
    const borderTop = parseFloat(editorStyle.borderTopWidth) || 0;
    
    const imgStyle = window.getComputedStyle(img);
    const outlineOffset = parseFloat(imgStyle.outlineOffset) || 2;
    
    wrapper.style.left = calculateResizeHandlesLeftPosition(
        img, imgRect, editorRect, borderLeft, editor.scrollLeft, outlineOffset
    ) + 'px';
    wrapper.style.top = (imgRect.top - editorRect.top - borderTop + editor.scrollTop - outlineOffset) + 'px';
    wrapper.style.width = (img.offsetWidth + outlineOffset * 2) + 'px';
    wrapper.style.height = (img.offsetHeight + outlineOffset * 2) + 'px';
}

/**
 * Создает ручки изменения размера для изображения
 * @param {HTMLImageElement} img - Элемент изображения
 * @returns {HTMLDivElement} - Контейнер с ручками
 */
function createResizeHandles(img) {
    const wrapper = document.createElement('div');
    wrapper.className = 'resize-handles-wrapper';
    
    // 8 ручек: 4 угла + 4 середины сторон
    const positions = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
    
    positions.forEach(pos => {
        const handle = document.createElement('div');
        handle.className = `resize-handle ${pos}`;
        handle.dataset.position = pos;
        
        // Обработчик начала изменения размера
        handle.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            startResize(img, pos, e);
        });
        handle.addEventListener('touchstart', function(e) {
            if (!e.touches?.length) return;
            startResize(img, pos, e);
        }, { passive: true });
        
        wrapper.appendChild(handle);
    });
    
    return wrapper;
}

/**
 * Показывает ручки изменения размера для выбранного изображения
 * @param {HTMLImageElement} img - Элемент изображения
 * @param {string} editorId - ID редактора
 */
function showResizeHandles(img, editorId) {
    // Удаляем существующие ручки
    hideResizeHandles();
    
    // Проверяем, что изображение находится в редакторе
    const editor = document.getElementById('editor_' + editorId);
    if (!editor || !editor.contains(img)) {
        return;
    }
    
    // Добавляем класс выделения
    img.classList.add('selected-image');
    
    // Создаем wrapper для ручек, если его еще нет
    let wrapper = img.nextElementSibling;
    if (!wrapper || !wrapper.classList.contains('resize-handles-wrapper')) {
        wrapper = createResizeHandles(img);
        img.parentNode.insertBefore(wrapper, img.nextSibling);
    }

    wrapper.style.visibility = 'hidden';

    // Принудительный reflow: убедиться, что браузер «увидел» outline/float
    img.getBoundingClientRect();

    // Тройной requestAnimationFrame:
    // 1) вставка wrapper, 2) завершение пересчёта float-раскладки, 3) точное позиционирование
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                wrapper.style.position = 'absolute';
                wrapper.style.pointerEvents = 'none';
                wrapper.style.willChange = 'left, top, width, height';
                
                // Обновляем позицию и размеры wrapper по точным метрикам
                updateResizeHandlesPosition(img, wrapper, editor);
                wrapper.style.visibility = 'visible';
                
                // Разрешаем события на самих ручках
                const handles = wrapper.querySelectorAll('.resize-handle');
                handles.forEach(handle => {
                    handle.style.pointerEvents = 'auto';
                });
            });
        });
    });
    
    window.imageResizeState.currentImage = img;
    window.imageResizeState.editorId = editorId;
}

/**
 * Скрывает ручки изменения размера
 */
function hideResizeHandles() {
    // Удаляем класс выделения со всех изображений
    document.querySelectorAll('.editor-content img.selected-image').forEach(img => {
        img.classList.remove('selected-image');
    });
    
    // Удаляем все wrapper'ы с ручками
    document.querySelectorAll('.resize-handles-wrapper').forEach(wrapper => {
        wrapper.remove();
    });
    
    window.imageResizeState.currentImage = null;
    window.imageResizeState.editorId = null;
}

/**
 * Начинает процесс изменения размера
 * @param {HTMLImageElement} img - Элемент изображения
 * @param {string} position - Позиция ручки (nw, n, ne, e, se, s, sw, w)
 * @param {MouseEvent} e - Событие мыши
 */
function startResize(img, position, e) {
    const isTouchEvent = !!e.touches;
    if (!isTouchEvent && e.cancelable) {
        e.preventDefault();
    }
    e.stopPropagation();
    
    // Если уже идет изменение размера, очищаем старые обработчики
    if (window.imageResizeState.isResizing) {
        document.removeEventListener('mousemove', handleResize);
        document.removeEventListener('mouseup', stopResize);
        document.removeEventListener('touchmove', handleResize);
        document.removeEventListener('touchend', stopResize);
        document.removeEventListener('touchcancel', stopResize);
    }
    
    window.imageResizeState.isResizing = true;
    window.imageResizeState.currentImage = img;
    window.imageResizeState.currentHandle = position;
    const point = e.touches?.[0] || e;
    window.imageResizeState.startX = point.clientX;
    window.imageResizeState.startY = point.clientY;
    window.imageResizeState.startWidth = img.offsetWidth;
    window.imageResizeState.startHeight = img.offsetHeight;
    window.imageResizeState.aspectRatio = img.offsetWidth / img.offsetHeight;
    
    // Добавляем глобальные обработчики
    document.addEventListener('mousemove', handleResize);
    document.addEventListener('mouseup', stopResize);
    document.addEventListener('touchmove', handleResize, { passive: false });
    document.addEventListener('touchend', stopResize);
    document.addEventListener('touchcancel', stopResize);
    
    // Предотвращаем выделение текста во время изменения размера
    document.body.style.userSelect = 'none';
}

/**
 * Обрабатывает изменение размера
 * @param {MouseEvent} e - Событие мыши
 */
function handleResize(e) {
    if (!window.imageResizeState.isResizing || !window.imageResizeState.currentImage) {
        return;
    }
    
    e.preventDefault();
    
    const state = window.imageResizeState;
    const img = state.currentImage;
    const handle = state.currentHandle;
    if (e.touches && e.touches.length === 0) {
        stopResize(e);
        return;
    }
    const point = e.touches?.[0] || e;
    if (!point) {
        stopResize(e);
        return;
    }
    const deltaX = point.clientX - state.startX;
    const deltaY = point.clientY - state.startY;
    
    let newWidth = state.startWidth;
    let newHeight = state.startHeight;
    
    // Вычисляем новые размеры в зависимости от позиции ручки
    switch (handle) {
        case 'se': // Правый нижний угол
            newWidth = state.startWidth + deltaX;
            newHeight = newWidth / state.aspectRatio;
            break;
        case 'sw': // Левый нижний угол
            newWidth = state.startWidth - deltaX;
            newHeight = newWidth / state.aspectRatio;
            break;
        case 'ne': // Правый верхний угол
            newWidth = state.startWidth + deltaX;
            newHeight = newWidth / state.aspectRatio;
            break;
        case 'nw': // Левый верхний угол
            newWidth = state.startWidth - deltaX;
            newHeight = newWidth / state.aspectRatio;
            break;
        case 'e': // Правая сторона
            newWidth = state.startWidth + deltaX;
            newHeight = newWidth / state.aspectRatio;
            break;
        case 'w': // Левая сторона
            newWidth = state.startWidth - deltaX;
            newHeight = newWidth / state.aspectRatio;
            break;
        case 's': // Нижняя сторона
            newHeight = state.startHeight + deltaY;
            newWidth = newHeight * state.aspectRatio;
            break;
        case 'n': // Верхняя сторона
            newHeight = state.startHeight - deltaY;
            newWidth = newHeight * state.aspectRatio;
            break;
    }
    
    // Ограничиваем минимальный размер
    const minSize = 30;
    if (newWidth < minSize) {
        newWidth = minSize;
        newHeight = newWidth / state.aspectRatio;
    }
    
    // Применяем новые размеры
    img.style.width = Math.round(newWidth) + 'px';
    img.style.height = Math.round(newHeight) + 'px';
    
    // Обновляем позицию ручек
    const wrapper = img.nextElementSibling;
    if (wrapper && wrapper.classList.contains('resize-handles-wrapper')) {
        // Обновляем позицию wrapper для float-элементов
        const editor = document.getElementById('editor_' + state.editorId);
        if (editor) {
            updateResizeHandlesPosition(img, wrapper, editor);
        }
    }
}

/**
 * Завершает процесс изменения размера
 * @param {MouseEvent} e - Событие мыши
 */
function stopResize(e) {
    if (!window.imageResizeState.isResizing) {
        return;
    }
    
    e.preventDefault();
    
    // Удаляем глобальные обработчики
    document.removeEventListener('mousemove', handleResize);
    document.removeEventListener('mouseup', stopResize);
    document.removeEventListener('touchmove', handleResize);
    document.removeEventListener('touchend', stopResize);
    document.removeEventListener('touchcancel', stopResize);
    
    // Восстанавливаем выделение текста
    document.body.style.userSelect = '';
    
    // Синхронизируем с textarea и сохраняем в историю
    const editorId = window.imageResizeState.editorId;
    if (editorId && window.editorInstances[editorId]) {
        const instance = window.editorInstances[editorId];
        if (instance.htmlTextarea) {
            instance.htmlTextarea.value = instance.cleanEditorHtml(instance.editor.innerHTML);
        }
        if (instance.history && typeof instance.history.save === 'function') {
            instance.history.save();
        }
    }
    
    window.imageResizeState.isResizing = false;
    window.imageResizeState.currentHandle = null;
}

/**
 * Инициализирует обработчики кликов на изображениях в редакторе
 * @param {string} editorId - ID редактора
 */
function initImageResizeHandlers(editorId) {
    const editor = document.getElementById('editor_' + editorId);
    if (!editor) {
        return;
    }
    
    // Единый обработчик клика для изображений и области вне изображений
    editor.addEventListener('click', function(e) {
        // Если клик на изображении - показываем ручки
        if (e.target.tagName === 'IMG') {
            e.preventDefault();
            e.stopPropagation();
            showResizeHandles(e.target, editorId);
            // Стабилизируем положение каретки, чтобы она не влияла на измерения
            moveCursorAfterImage(e.target);
            window.editorInstances[editorId]?.updateButtonStates?.();
        } 
        // Если клик вне изображения и не на ручке - скрываем ручки
        else if (!e.target.classList.contains('resize-handle') &&
                 !e.target.closest('.resize-handles-wrapper')) {
            hideResizeHandles();
            window.editorInstances[editorId]?.updateButtonStates?.();
        }
    });
}

/**
 * Перемещает курсор к правому краю изображения
 * @param {HTMLImageElement} img - Элемент изображения
 */
function moveCursorAfterImage(img) {
    try {
        const selection = window.getSelection();
        const range = document.createRange();
        
        // Ищем следующий узел после изображения
        let nextNode = img.nextSibling;
        
        // Если следующий узел - это wrapper с ручками, пропускаем его
        if (nextNode && nextNode.nodeType === Node.ELEMENT_NODE && 
            nextNode.classList.contains('resize-handles-wrapper')) {
            nextNode = nextNode.nextSibling;
        }
        
        if (nextNode) {
            // Если есть следующий узел, ставим курсор перед ним
            range.setStartBefore(nextNode);
            range.setEndBefore(nextNode);
        } else {
            // Если следующего узла нет, ставим курсор после изображения
            range.setStartAfter(img);
            range.setEndAfter(img);
        }
        
        selection.removeAllRanges();
        selection.addRange(range);

        const editorId = window.imageResizeState.editorId;
        const editor = editorId ? document.getElementById('editor_' + editorId) : null;
        const wrapper = img.nextElementSibling;
        if (editor && wrapper && wrapper.classList.contains('resize-handles-wrapper')) {
            requestAnimationFrame(() => {
                updateResizeHandlesPosition(img, wrapper, editor);
            });
        }
    } catch (e) {
        console.warn('Could not move cursor after image:', e);
    }
}

/**
 * Открывает модальное окно предварительного просмотра изображения
 * @param {string} imageUrl - URL изображения для просмотра
 */
window.openImagePreview = function(imageUrl) {
    const modal = document.getElementById('imagePreviewModal');
    const img = document.getElementById('imagePreviewImg');
    
    if (modal && img) {
        img.src = imageUrl;
        modal.style.display = 'flex';
        
        // Обработчик клавиши Escape (удаляется в closeImagePreview)
        document.addEventListener('keydown', handleEscapeKey);
    }
};

/**
 * Закрывает модальное окно предварительного просмотра изображения
 */
window.closeImagePreview = function() {
    const modal = document.getElementById('imagePreviewModal');
    if (modal) {
        modal.style.display = 'none';
        const img = document.getElementById('imagePreviewImg');
        if (img) {
            // Используем data URI вместо пустой строки для избежания ненужного запроса
            img.src = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        }
        
        // Удаляем обработчик клавиши Escape
        document.removeEventListener('keydown', handleEscapeKey);
    }
};

/**
 * Обработчик нажатия клавиши Escape для закрытия модального окна
 * @param {KeyboardEvent} e - Событие клавиатуры
 */
function handleEscapeKey(e) {
    if (e.key === 'Escape') {
        closeImagePreview();
    }
}

/**
 * Инициализирует обработчики кликов на изображениях с атрибутом data-image-preview
 * Добавляется глобально ко всем редакторам (выполняется один раз)
 */
document.addEventListener('click', function(e) {
    // Проверяем, является ли кликнутый элемент ссылкой с изображением для предварительного просмотра
    if (e.target.tagName === 'IMG') {
        const link = e.target.parentElement;
        if (link && link.tagName === 'A' && link.getAttribute('data-image-preview') === 'true') {
            e.preventDefault();
            e.stopPropagation();
            openImagePreview(link.href);
        }
    } else if (e.target.tagName === 'A' && e.target.getAttribute('data-image-preview') === 'true') {
        e.preventDefault();
        e.stopPropagation();
        const img = e.target.querySelector('img');
        if (img) {
            openImagePreview(e.target.href);
        }
    }
});

// Добавляем обработчик клика на overlay для закрытия (выполняется один раз при загрузке)
document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.querySelector('.image-preview-overlay');
    if (overlay) {
        overlay.addEventListener('click', closeImagePreview);
    }
});

/**
 * Файл: /admin/js/list-manager.js
 * Accounts List Manager (as ES Module)
 * 
 * Управление списком пользователей в административной панели
 * 
 * Экспортирует: initListManager()
 */

export function initListManager() {
    // Выполняем инициализацию только если на странице есть нужные элементы
    if (!document.querySelector('.table-card') && 
        !document.getElementById('massActionForm') &&
        !document.querySelector('.trash-user, .restore-user, .delete-user')) {
        return; // Нет признаков страницы списка — выходим
    }

    // =========================================================================
    // УПРАВЛЕНИЕ CHECKBOX'АМИ
    // =========================================================================
    
    const selectAll = document.getElementById('selectAll');
    const userCheckboxes = document.querySelectorAll('.user-checkbox');
    
    /**
     * Обновляет состояние чекбокса "Выбрать все"
     * Устанавливает checked если выбраны все, indeterminate если выбраны некоторые
     */
    function updateSelectAllState() {
        if (selectAll) {
            const allChecked = Array.from(userCheckboxes).every(cb => cb.checked);
            const someChecked = Array.from(userCheckboxes).some(cb => cb.checked);
            
            selectAll.checked = allChecked;
            selectAll.indeterminate = someChecked && !allChecked;
        }
    }

    /**
     * Обработчик для выбора всех пользователей
     */
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            userCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateSelectAllState();
        });
    }

    userCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectAllState);
    });

    // =========================================================================
    // ОБРАБОТКА МАССОВЫХ ДЕЙСТВИЙ
    // =========================================================================
    
    const massActionForm = document.getElementById('massActionForm');
    
    if (massActionForm) {
        massActionForm.addEventListener('submit', function(e) {
            const checkedBoxes = document.querySelectorAll('.user-checkbox:checked');
            const actionSelect = this.querySelector('select[name="action"]');
            
            if (checkedBoxes.length === 0) {
                e.preventDefault();
                alert('Выберите хотя бы одного пользователя');
                return;
            }
            
            if (!actionSelect.value) {
                e.preventDefault();
                alert('Выберите действие');
                return;
            }
            
            // Подтверждение для опасных действий
            let confirmMessage = '';
            if (actionSelect.value === 'delete') {
                confirmMessage = 'Вы уверены, что хотите удалить выбранных пользователей навсегда? Это действие нельзя отменить.';
            } else if (actionSelect.value === 'trash') {
                confirmMessage = 'Переместить выбраные записи в корзину?';
            }
            
            if (confirmMessage && !confirm(confirmMessage)) {
                e.preventDefault();
                return;
            }
            
            // Удаляем предыдущие скрытые инпуты (на случай повторной отправки)
            const existingInputs = this.querySelectorAll('input[name="user_ids[]"]');
            existingInputs.forEach(input => input.remove());
            
            // Добавляем актуальные ID
            checkedBoxes.forEach(checkbox => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'user_ids[]';
                hiddenInput.value = checkbox.value;
                this.appendChild(hiddenInput);
            });
        });
    }

    // =========================================================================
    // ОДИНОЧНЫЕ ДЕЙСТВИЯ ЧЕРЕЗ AJAX-ФОРМЫ
    // =========================================================================

    /**
     * Выполняет одиночное действие через динамическую форму
     */
    function submitSingleAction(action, userId) {
        const form = document.createElement('form');
        form.method = 'POST';
        // Сохраняем текущие GET-параметры (page, search, trash) для возврата на ту же страницу
        const url = new URL(window.location);
        const params = new URLSearchParams(url.search);
        // Можно добавить скрытые поля для возврата, но проще — POST на текущую страницу
        form.innerHTML = `
            <input type="hidden" name="action" value="${action}">
            <input type="hidden" name="user_ids[]" value="${userId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }

    // Назначаем обработчики
    document.querySelectorAll('.trash-user').forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.getAttribute('data-user-id');
            if (confirm('Переместить записи в корзину?')) {
                submitSingleAction('trash', userId);
            }
        });
    });

    document.querySelectorAll('.restore-user').forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.getAttribute('data-user-id');
            if (confirm('Восстановить запись?')) {
                submitSingleAction('restore', userId);
            }
        });
    });

    document.querySelectorAll('.delete-user').forEach(button => {
        button.addEventListener('click', function() {
            const userId = this.getAttribute('data-user-id');
            if (confirm('Удалить запись навсегда? Это действие нельзя отменить.')) {
                submitSingleAction('delete', userId);
            }
        });
    });

    // Первоначальное обновление состояния "Выбрать все"
    updateSelectAllState();
}
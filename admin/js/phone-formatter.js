/**
 * Файл: /admin/js/phone-formatter.js
 * 
 * Универсальный скрипт для форматирования международных номеров телефонов
 * Поддерживает номера разных стран с кодом страны
 * 
 * @version 2.1 (адаптировано для импорта в main.js)
 */

/**
 * Функция форматирования номера телефона с поддержкой международных кодов
 * @param {string} value - Исходное значение поля
 * @returns {string} Отформатированный номер телефона
 */
export function formatPhoneNumber(value) {
    if (!value) return '';
    
    const hasPlus = value.startsWith('+');
    let cleaned = value.replace(hasPlus ? /[^\d+]/g : /[^\d]/g, '');
    
    if (cleaned.startsWith('+7')) {
        return formatRussianPhone(cleaned);
    }
    
    return formatInternationalPhone(cleaned);
}

/**
 * Форматирование российского номера телефона
 * @param {string} cleaned - Очищенный номер (начинается с +7)
 * @returns {string} Отформатированный номер
 */
function formatRussianPhone(cleaned) {
    let digits = cleaned.substring(2);
    let formattedValue = '+7 (';
    
    if (digits.length > 0)    formattedValue += digits.substring(0, 3);
    if (digits.length > 3)    formattedValue += ') ' + digits.substring(3, 6);
    if (digits.length > 6)    formattedValue += '-' + digits.substring(6, 8);
    if (digits.length > 8)    formattedValue += '-' + digits.substring(8, 10);
    
    return formattedValue;
}

/**
 * Базовое форматирование международных номеров
 * @param {string} cleaned - Очищенный номер
 * @returns {string} Частично отформатированный номер
 */
function formatInternationalPhone(cleaned) {
    const hasPlus = cleaned.startsWith('+');
    const digits = hasPlus ? cleaned.slice(1) : cleaned;

    if (digits.length <= 4) {
        return hasPlus ? `+${digits}` : digits;
    }

    let formattedDigits;

    if (digits.length > 10) {
        formattedDigits = digits.replace(/(\d{3})(\d{3})(\d{4})(\d+)/, '$1 $2 $3 $4');
    } else if (digits.length > 7) {
        formattedDigits = digits.replace(/(\d{3})(\d{3})(\d{1,4})/, '$1 $2 $3');
    } else {
        formattedDigits = digits.replace(/(\d{3})(\d+)/, '$1 $2');
    }

    return hasPlus ? `+${formattedDigits}` : formattedDigits;
}

/**
 * Инициализация форматирования для всех полей телефона на странице
 * @param {Object} [options]
 * @param {string} [options.inputSelector='input[type="tel"]']
 */
export function initPhoneFormatting(options = {}) {
    const { inputSelector = 'input[type="tel"]' } = options;
    const phoneInputs = document.querySelectorAll(inputSelector);
    
    phoneInputs.forEach(phoneInput => {
        if (!phoneInput.placeholder) {
            phoneInput.placeholder = "+7 (XXX) XXX-XX-XX или +КодСтраны Номер";
        }

        phoneInput.addEventListener('input', function(e) {
            const cursorPosition = e.target.selectionStart;
            const formattedValue = formatPhoneNumber(e.target.value);
            const lengthDiff = formattedValue.length - e.target.value.length;
            
            e.target.value = formattedValue;
            
            if (cursorPosition > 0) {
                const newPosition = Math.max(0, cursorPosition + lengthDiff);
                e.target.setSelectionRange(newPosition, newPosition);
            }
        });

        phoneInput.addEventListener('blur', function(e) {
            const formattedValue = formatPhoneNumber(e.target.value);
            e.target.value = formattedValue;
        });

        phoneInput.addEventListener('focus', function(e) {
            // опционально: можно дать подсказку
        });

        phoneInput.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && 
                e.target.selectionStart === 1 && 
                e.target.value.startsWith('+')) {
                e.preventDefault();
            }
        });

        phoneInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const formattedValue = formatPhoneNumber(pastedText);

            const start = e.target.selectionStart;
            const end = e.target.selectionEnd;

            e.target.value = e.target.value.slice(0, start) + 
                             formattedValue + 
                             e.target.value.slice(end);

            const newPosition = start + formattedValue.length;
            e.target.setSelectionRange(newPosition, newPosition);
        });
    });

    console.log(`Phone formatting initialized for ${phoneInputs.length} input(s)`);
}

/**
 * Добавление форматирования для нового поля телефона (для динамически созданных полей)
 * @param {HTMLElement} phoneInput - Новое поле ввода телефона
 */
export function addPhoneFormatting(phoneInput) {
    if (!phoneInput || phoneInput.type !== 'tel') return;

    if (!phoneInput.placeholder) {
        phoneInput.placeholder = "+7 (XXX) XXX-XX-XX или +КодСтраны Номер";
    }

    phoneInput.addEventListener('input', function(e) {
        const cursorPosition = e.target.selectionStart;
        const formattedValue = formatPhoneNumber(e.target.value);
        const lengthDiff = formattedValue.length - e.target.value.length;
        
        e.target.value = formattedValue;
        
        if (cursorPosition > 0) {
            const newPosition = Math.max(0, cursorPosition + lengthDiff);
            e.target.setSelectionRange(newPosition, newPosition);
        }
    });

    phoneInput.addEventListener('blur', function(e) {
        const formattedValue = formatPhoneNumber(e.target.value);
        e.target.value = formattedValue;
    });

    console.log('Phone formatting added for new input');
}

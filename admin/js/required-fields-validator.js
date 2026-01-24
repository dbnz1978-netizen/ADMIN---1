/**
 * Файл: /admin/js/required-fields-validator.js
 * Required Fields Validator — модуль валидации обязательных полей форм
 * 
 * Назначение:
 *   Универсальный валидатор форм с поддержкой live-валидации, кастомных правил
 *   и интеграцией с Bootstrap-классами (is-valid / is-invalid).
 * 
 * Экспортирует:
 *   - RequiredFieldsValidator — класс для ручного создания экземпляров
 *   - initFormValidation(options?) — фабричная функция для быстрой инициализации
 * 
 * @version 2.0 (ES-модульная версия)
 */

/**
 * Класс валидатора форм
 */
export class RequiredFieldsValidator {
    constructor(options = {}) {
        this.options = {
            formSelector: 'form',
            requiredAttribute: 'required',
            errorClass: 'is-invalid',
            validClass: 'is-valid',
            liveValidation: true,
            validateOnSubmit: true,
            ...options
        };

        this.forms = [];
        this.firstInvalidField = null;
        this.customValidators = {};

        this.init();
    }

    init() {
        this.findForms();
        this.setupEventListeners();
    }

    findForms() {
        this.forms = Array.from(document.querySelectorAll(this.options.formSelector));
    }

    setupEventListeners() {
        this.forms.forEach(form => {
            if (this.options.liveValidation) {
                this.setupLiveValidation(form);
            }

            if (this.options.validateOnSubmit) {
                form.addEventListener('submit', (e) => {
                    if (!this.validateForm(form)) {
                        e.preventDefault();
                        this.showFormErrors(form);
                    }
                });
            }
        });
    }

    setupLiveValidation(form) {
        const requiredFields = this.getRequiredFields(form);
        requiredFields.forEach(field => {
            field.addEventListener('input', () => this.validateField(field));
            field.addEventListener('blur', () => this.validateField(field));
            field.addEventListener('focus', () => this.clearFieldError(field));
        });
    }

    getRequiredFields(form) {
        return Array.from(form.querySelectorAll(`[${this.options.requiredAttribute}]`));
    }

    validateField(field) {
        const isValid = this.isFieldValid(field);
        if (isValid) {
            this.markFieldAsValid(field);
        } else {
            this.markFieldAsInvalid(field);
        }
        this.dispatchValidationEvent(field, isValid);
        return isValid;
    }

    isFieldValid(field) {
        const value = field.value.trim();
        if (!value) return false;

        // Кастомный валидатор?
        if (this.customValidators[field.name] || this.customValidators[field.id]) {
            const validator = this.customValidators[field.name] || this.customValidators[field.id];
            return validator(value, field);
        }

        // Стандартные проверки
        switch (field.type) {
            case 'email': return this.isValidEmail(value);
            case 'tel': return this.isValidPhone(value);
            case 'url': return this.isValidUrl(value);
            default: return true;
        }
    }

    isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    isValidPhone(phone) {
        const digits = phone.replace(/\D/g, '');
        return digits.length >= 5 && digits.length <= 15;
    }

    isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    }

    markFieldAsValid(field) {
        field.classList.remove(this.options.errorClass);
        field.classList.add(this.options.validClass);
    }

    markFieldAsInvalid(field) {
        field.classList.remove(this.options.validClass);
        field.classList.add(this.options.errorClass);
    }

    clearFieldError(field) {
        field.classList.remove(this.options.errorClass, this.options.validClass);
    }

    validateForm(form) {
        return this.getRequiredFields(form).every(field => this.validateField(field));
    }

    showFormErrors(form) {
        this.firstInvalidField = null;
        const requiredFields = this.getRequiredFields(form);

        requiredFields.forEach(field => {
            if (!this.isFieldValid(field)) {
                this.markFieldAsInvalid(field);
                if (!this.firstInvalidField) {
                    this.firstInvalidField = field;
                    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });

        setTimeout(() => { this.firstInvalidField = null; }, 100);
    }

    dispatchValidationEvent(field, isValid) {
        field.dispatchEvent(new CustomEvent('fieldValidation', {
            detail: { field, isValid, value: field.value, name: field.name || field.id }
        }));
    }

    getFormStatus(form) {
        const requiredFields = this.getRequiredFields(form);
        const invalidFields = requiredFields.filter(field => !this.isFieldValid(field));
        return {
            isValid: invalidFields.length === 0,
            totalFields: requiredFields.length,
            invalidFields: invalidFields.length,
            invalidFieldsList: invalidFields
        };
    }

    forceValidation(form) {
        return this.validateForm(form);
    }

    resetFormValidation(form) {
        this.getRequiredFields(form).forEach(field => this.clearFieldError(field));
    }

    addCustomValidator(fieldName, validatorFn) {
        this.customValidators[fieldName] = validatorFn;
    }
}

/**
 * Фабричная функция для быстрой инициализации валидатора
 * @param {Object} [options] — опции валидатора
 * @returns {RequiredFieldsValidator} экземпляр валидатора
 */
export function initFormValidation(options = {}) {
    // Защита от повторной инициализации (на случай вызова дважды)
    if (window._formValidator) {
        return window._formValidator;
    }

    const validator = new RequiredFieldsValidator(options);
    window._formValidator = validator; // опционально — для отладки
    return validator;
}

/**
 * Файл: /admin/js/captcha-slider.js
 * 
 * Универсальная реализация капчи с перетаскиванием
 * — чистый ES-модуль, без побочных эффектов при импорте
 * — поддержка нескольких экземпляров на странице
 * — безопасность повторной инициализации
 * — интеграция с серверной верификацией (AJAX → /captcha/verify.php)
 * 
 * Экспортирует:
 *   - CaptchaSlider — класс для ручного создания экземпляров
 *   - initCaptchaSlider(options?) — фабричная функция для быстрой инициализации
 * 
 * @version 2.1 (с поддержкой серверной верификации)
 */

/**
 * Класс капчи с перетаскиванием
 */
export class CaptchaSlider {
    constructor(options = {}) {
        const {
            sliderId = 'captchaSlider',
            verifiedInputId = 'captchaVerified',
            submitButtonId,
            successThreshold = 0.95,
            handleOffset = 5,
            captchaTokenId = 'captchaToken'
        } = options;

        this.slider = document.getElementById(sliderId);
        this.handle = document.getElementById(sliderId.replace('Slider', 'Handle'));
        this.progress = document.getElementById(sliderId.replace('Slider', 'Progress'));
        this.progressExtended = document.getElementById(sliderId.replace('Slider', 'ProgressExtended'));
        this.verifiedInput = document.getElementById(verifiedInputId);
        this.form = this.slider ? this.slider.closest('form') : null;
        this.captchaTokenInput = this.form
            ? this.form.querySelector(`#${captchaTokenId}`)
            : document.getElementById(captchaTokenId);
        this.csrfTokenInput = this.form
            ? this.form.querySelector('input[name="csrf_token"]')
            : document.querySelector('input[name="csrf_token"]');
        this.submitButton = this.findSubmitButton(submitButtonId);

        if (!this.slider || !this.handle) {
            console.warn(`[CaptchaSlider] Элементы не найдены для ID: ${sliderId}`);
            return;
        }

        // Состояние
        this.isDragging = false;
        this.startX = 0;
        this.currentX = 0;
        this.maxX = 0;

        // Геометрия
        this.handleWidth = 0;
        this.handleRadius = 0;
        this.sliderWidth = 0;

        // Настройки
        this.successThreshold = successThreshold;
        this.handleOffset = handleOffset;

        this.init();
    }

    findSubmitButton(submitButtonId) {
        if (submitButtonId) return document.getElementById(submitButtonId);
        const form = this.form || this.slider?.closest('form');
        return form ? form.querySelector('button[type="submit"], [type="submit"]') : null;
    }

    init() {
        this.calculateDimensions();
        this.setupEventListeners();
        this.updateSubmitButton();
    }

    calculateDimensions() {
        const sliderRect = this.slider.getBoundingClientRect();
        const handleRect = this.handle.getBoundingClientRect();
        this.handleWidth = handleRect.width;
        this.handleRadius = this.handleWidth / 2;
        this.sliderWidth = sliderRect.width - this.handleWidth - 10;
    }

    setupEventListeners() {
        this.handle.addEventListener('mousedown', this.startDrag.bind(this));
        this.handle.addEventListener('touchstart', this.startDrag.bind(this), { passive: true });
        window.addEventListener('resize', this.calculateDimensions.bind(this));
    }

    startDrag(e) {
        this.isDragging = true;
        this.startX = e.type === 'mousedown' ? e.clientX : e.touches[0].clientX;
        this.currentX = parseInt(this.handle.style.left) || this.handleOffset;

        document.addEventListener('mousemove', this.drag.bind(this));
        document.addEventListener('touchmove', this.drag.bind(this));
        document.addEventListener('mouseup', this.stopDrag.bind(this));
        document.addEventListener('touchend', this.stopDrag.bind(this));

        // Отключаем анимации во время перетаскивания
        this.handle.style.transition = 'none';
        if (this.progress) this.progress.style.transition = 'none';
        if (this.progressExtended) this.progressExtended.style.transition = 'none';

        e.preventDefault();
    }

    drag(e) {
        if (!this.isDragging) return;

        const clientX = e.type === 'mousemove' ? e.clientX : e.touches[0].clientX;
        const deltaX = clientX - this.startX;
        let newX = this.currentX + deltaX;
        newX = Math.max(this.handleOffset, Math.min(this.sliderWidth, newX));

        this.handle.style.left = newX + 'px';

        const progressWidth = newX - this.handleOffset;
        const maxProgressWidth = this.sliderWidth - this.handleOffset;
        const progress = (progressWidth / maxProgressWidth) * 100;

        if (this.progress) this.progress.style.width = progressWidth + 'px';
        if (this.progressExtended) {
            const extendedWidth = newX + this.handleRadius - this.handleOffset;
            this.progressExtended.style.width = Math.max(0, extendedWidth) + 'px';
        }

        if (progress >= this.successThreshold * 100) {
            this.onSuccess();
        } else {
            this.onProgress();
        }
    }

    /**
     * Выполняет серверную верификацию капчи
     * @returns {Promise<boolean>} — true при успехе
     */
    async verifyOnServer() {
        // Индикация процесса (опционально — для UX)
        this.slider.setAttribute('data-verifying', 'true');

        try {
            const captchaToken = this.captchaTokenInput?.value;
            const csrfToken = this.csrfTokenInput?.value;

            if (!captchaToken) {
                throw new Error('Captcha token is missing');
            }

            const payload = {
                action: 'verify',
                captcha_token: captchaToken
            };

            if (csrfToken) {
                payload.csrf_token = csrfToken;
            }

            const response = await fetch('verify.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            if (!data.success) {
                throw new Error('Server verification failed');
            }

            // Успешная серверная проверка
            this.slider.classList.add('captcha-verified');
            return true;
        } catch (err) {
            console.warn('[CaptchaSlider] Server verification failed:', err);
            return false;
        } finally {
            this.slider.removeAttribute('data-verifying');
        }
    }

    /**
     * Обработка успешного завершения перетаскивания (визуально + серверно)
     */
    async onSuccess() {
        if (this.slider.hasAttribute('data-verifying') || this.slider.classList.contains('captcha-verified')) {
            return;
        }

        // 1. Визуальный успех (как раньше)
        this.slider.classList.add('captcha-success');
        if (this.progress) this.progress.style.animation = 'slideBackground 0.5s linear infinite';
        if (this.progressExtended) this.progressExtended.style.animation = 'slideBackground 0.5s linear infinite';

        // Плавное доехание до конца
        this.handle.style.left = this.sliderWidth + 'px';
        if (this.progress) this.progress.style.width = this.sliderWidth - this.handleOffset + 'px';
        if (this.progressExtended) this.progressExtended.style.width = this.sliderWidth + this.handleRadius - this.handleOffset + 'px';

        this.handle.style.transition = 'all 0.3s ease';
        if (this.progress) this.progress.style.transition = 'all 0.3s ease';
        if (this.progressExtended) this.progressExtended.style.transition = 'all 0.3s ease';

        // 2. Устанавливаем промежуточное состояние
        if (this.verifiedInput) {
            this.verifiedInput.value = 'pending';
            this.verifiedInput.dispatchEvent(new Event('captchaVerified', { bubbles: true }));
        }

        // 3. Выполняем серверную верификацию
        const verified = await this.verifyOnServer();

        if (verified && this.verifiedInput) {
            // Устанавливаем финальное состояние — можно отправлять
            this.verifiedInput.value = 'true';
            this.verifiedInput.dispatchEvent(new Event('captchaVerified', { bubbles: true }));
        } else {
            // При ошибке — сбрасываем
            this.resetToStart();
            if (this.verifiedInput) {
                this.verifiedInput.value = 'false';
                this.verifiedInput.dispatchEvent(new Event('captchaVerified', { bubbles: true }));
            }
        }

        this.updateSubmitButton();
    }

    onProgress() {
        this.slider.classList.remove('captcha-success');
        if (this.verifiedInput) {
            this.verifiedInput.value = 'false';
            this.verifiedInput.dispatchEvent(new Event('captchaVerified', { bubbles: true }));
        }
        if (this.progress) this.progress.style.animation = 'none';
        if (this.progressExtended) this.progressExtended.style.animation = 'none';
        this.updateSubmitButton();
    }

    stopDrag() {
        this.isDragging = false;

        document.removeEventListener('mousemove', this.drag.bind(this));
        document.removeEventListener('touchmove', this.drag.bind(this));
        document.removeEventListener('mouseup', this.stopDrag.bind(this));
        document.removeEventListener('touchend', this.stopDrag.bind(this));

        // Если не достигнуто состояние 'pending' или 'true' — сбрасываем
        if (this.verifiedInput?.value !== 'true' && this.verifiedInput?.value !== 'pending') {
            this.resetToStart();
        }
    }

    resetToStart() {
        this.handle.style.transition = 'left 0.3s ease';
        if (this.progress) this.progress.style.transition = 'width 0.3s ease';
        if (this.progressExtended) this.progressExtended.style.transition = 'width 0.3s ease';

        if (this.progress) this.progress.style.animation = 'none';
        if (this.progressExtended) this.progressExtended.style.animation = 'none';

        this.handle.style.left = this.handleOffset + 'px';
        if (this.progress) this.progress.style.width = '0px';
        if (this.progressExtended) this.progressExtended.style.width = '0px';

        this.slider.classList.remove('captcha-success', 'captcha-verified');
    }

    updateSubmitButton() {
        if (this.submitButton && this.verifiedInput) {
            // Кнопка активна только если сервер подтвердил
            this.submitButton.disabled = this.verifiedInput.value !== 'true';
        }
    }
}

/**
 * Фабричная функция: инициализирует капчу и возвращает экземпляр
 * Поддерживает защиту от дублирования через data-атрибут
 * @param {Object} [options] — параметры инициализации
 * @returns {CaptchaSlider|null}
 */
export function initCaptchaSlider(options = {}) {
    const sliderId = options.sliderId || 'captchaSlider';
    const sliderEl = document.getElementById(sliderId);

    if (!sliderEl) return null;

    // Защита от повторной инициализации
    if (sliderEl.hasAttribute('data-captcha-initialized')) {
        return null;
    }

    const captcha = new CaptchaSlider(options);
    sliderEl.setAttribute('data-captcha-initialized', 'true');
    
    return captcha;
}

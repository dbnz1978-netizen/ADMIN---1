/**
 * Файл: /admin/js/submit-button-protection.js
 *
 * Защита от повторных отправок формы: блокирует кнопку и подменяет иконку на спинер.
 */

const SPINNER_MARGIN_CLASSES = ['me-1', 'me-2', 'me-3', 'me-4', 'me-5'];

function createSpinner(spacingClass) {
    const spinner = document.createElement('span');
    spinner.className = 'spinner-border spinner-border-sm';
    spinner.setAttribute('role', 'status');
    spinner.setAttribute('aria-hidden', 'true');
    if (spacingClass) {
        spinner.classList.add(spacingClass);
    }
    return spinner;
}

function buttonHasText(button) {
    return Array.from(button.childNodes).some(node => {
        return node.nodeType === Node.TEXT_NODE && node.textContent.trim().length > 0;
    });
}

function replaceIconWithSpinner(button) {
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    if (button.dataset.loading === 'true') {
        return;
    }

    button.dataset.loading = 'true';

    const icon = button.querySelector('i');
    const hasText = buttonHasText(button);
    let spacingClass;
    if (hasText) {
        if (icon) {
            const matchingMarginClasses = SPINNER_MARGIN_CLASSES.filter(cls => icon.classList.contains(cls));
            // Expect a single margin utility; if multiple are present, keep the first.
            if (matchingMarginClasses.length) {
                spacingClass = matchingMarginClasses[0];
            }
        } else {
            // Add spacing when prepending a spinner to text-only buttons.
            spacingClass = 'me-1';
        }
    }
    const spinner = createSpinner(spacingClass);

    if (icon) {
        icon.replaceWith(spinner);
    } else {
        button.prepend(spinner);
    }
}

function handleFormSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (event.defaultPrevented) {
        return;
    }

    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }

    form.dataset.submitting = 'true';

    let submitter = event.submitter;
    if (!submitter) {
        const submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        if (submitButtons.length === 1) {
            submitter = submitButtons[0];
        }
    }

    if (!submitter) {
        return;
    }

    submitter.disabled = true;
    submitter.setAttribute('aria-disabled', 'true');
    replaceIconWithSpinner(submitter);
}

export function initSubmitButtonProtection() {
    document.addEventListener('submit', handleFormSubmit);
}

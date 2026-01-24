/**
 * Файл: /admin/js/universal-alerts.js
 * 
 * Universal Alerts Manager
 * 
 * Универсальная система управления всплывающими сообщениями
 * 
 * Основные функции:
 * - Автоматическое закрытие сообщений по таймауту
 * - Плавная анимация при закрытии
 * - Обработка закрытия через Bootstrap компоненты
 * - Ручное закрытие через кнопки
 * - Поддержка атрибута data-auto-close для настройки времени показа
 * 
 * Использование в HTML:
 * <div class="alert alert-success" data-auto-close="5000">
 *     Сообщение будет автоматически закрыто через 5 секунд
 *     <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
 * </div>
 * 
 * data-auto-close значения:
 * - 0: отключить авто-закрытие
 * - >0: время в миллисекундах до автоматического закрытия
 * - отсутствует: используется значение по умолчанию (5000 мс)
 */

document.addEventListener('DOMContentLoaded', function() {
    // Универсальная функция для закрытия alert
    const closeAlert = (alert) => {
        if (alert && alert.parentNode) {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s ease';
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 500);
        }
    };

    // Автоматическое закрытие alert сообщений
    const autoCloseAlerts = () => {
        const alerts = document.querySelectorAll('.alert[data-auto-close]');
        alerts.forEach(alert => {
            const timeout = parseInt(alert.getAttribute('data-auto-close')) || 5000;
            setTimeout(() => {
                closeAlert(alert);
            }, timeout);
        });
    };

    // Запускаем авто-закрытие при загрузке страницы
    autoCloseAlerts();

    // Обработка Bootstrap alert закрытия
    const bootstrapAlerts = document.querySelectorAll('.alert[data-bs-dismiss="alert"]');
    bootstrapAlerts.forEach(alert => {
        alert.addEventListener('closed.bs.alert', function() {
            closeAlert(this);
        });
    });

    // Дополнительная обработка для ручного закрытия
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('btn-close') || e.target.closest('.btn-close')) {
            const alert = e.target.closest('.alert');
            if (alert) {
                e.preventDefault();
                closeAlert(alert);
            }
        }
    });
});
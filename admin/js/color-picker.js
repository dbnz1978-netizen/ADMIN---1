/**
 * Универсальный элемент выбора цвета
 * Поддерживает светлую/тёмную тему и работу нескольких экземпляров на одной странице
 */

class ColorPicker {
  constructor(containerId, options = {}) {
    this.container = document.getElementById(containerId);
    if (!this.container) {
      console.error(`Color picker container with ID '${containerId}' not found`);
      return;
    }

    this.options = {
      initialColor: options.initialColor || '#000000',
      onChange: options.onChange || null,
      ...options
    };

    this.color = this.options.initialColor;
    this.inputId = `${containerId}_input`;
    this.displayId = `${containerId}_display`;
    this.codeId = `${containerId}_code`;

    this.init();
  }

  init() {
    // Создаём HTML-структуру
    this.render();

    // Получаем элементы
    this.input = document.getElementById(this.inputId);
    this.display = document.getElementById(this.displayId);
    this.codeElement = document.getElementById(this.codeId);

    // Устанавливаем начальный цвет
    this.setColor(this.color);

    // Добавляем обработчики событий
    this.addEventListeners();
  }

  render() {
    this.container.innerHTML = `
      <div class="color-picker-container">
        <div class="color-input-wrapper">
          <input 
            type="color" 
            id="${this.inputId}" 
            class="color-input-hidden"
            value="${this.options.initialColor}"
          />
          <div 
            id="${this.displayId}" 
            class="color-display"
            title="Выбрать цвет"
            style="color: ${this.options.initialColor};"
          ></div>
        </div>
        <input 
          type="text" 
          id="${this.codeId}" 
          class="color-code"
          value="${this.options.initialColor.toUpperCase()}"
          readonly
        />
      </div>
    `;
  }

  addEventListeners() {
    // При изменении цвета через input[type=color]
    this.input.addEventListener('input', (e) => {
      this.setColor(e.target.value);
    });

    // При клике на квадратик цвета открываем нативный выбор цвета
    this.display.addEventListener('click', () => {
      this.input.click();
    });

    // При клике на код цвета можно сделать что-то (например, скопировать)
    this.codeElement.addEventListener('click', () => {
      this.input.click();
    });
  }

  setColor(color) {
    this.color = color;

    // Обновляем отображение
    this.display.style.color = color;
    this.input.value = color;
    this.codeElement.value = color.toUpperCase();

    // Вызываем callback, если он задан
    if (typeof this.options.onChange === 'function') {
      this.options.onChange(color);
    }
  }

  getColor() {
    return this.color;
  }

  destroy() {
    if (this.container) {
      this.container.innerHTML = '';
    }
  }
}

// Инициализация всех элементов выбора цвета на странице при загрузке
document.addEventListener('DOMContentLoaded', function() {
  // Находим все контейнеры с классом color-picker-init
  const colorPickerContainers = document.querySelectorAll('.color-picker-init');
  
  colorPickerContainers.forEach(container => {
    const containerId = container.id;
    if (containerId) {
      // Получаем параметры из data-атрибутов
      const initialColor = container.getAttribute('data-initial-color') || '#000000';
      
      // Создаем экземпляр ColorPicker
      new ColorPicker(containerId, {
        initialColor: initialColor,
        onChange: function(color) {
          // Можно добавить дополнительную логику при изменении цвета
          console.log(`Цвет изменён в контейнере ${containerId}: ${color}`);
        }
      });
    }
  });
});

// Экспортируем класс для использования в других модулях
if (typeof module !== 'undefined' && module.exports) {
  module.exports = ColorPicker;
}
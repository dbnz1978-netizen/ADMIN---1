/**
 * Файл:  /admin/user_images/js/main.js
 * =============================================================
 * Главный точка входа JavaScript-приложения (main.js)
 * -------------------------------------------------------------
 * Этот файл:
 * - Импортирует модули функциональности (галерея, формы, утилиты и т.д.)
 * - Делает выбранные функции доступными в глобальной области видимости,
 *   чтобы их можно было вызывать напрямую из HTML (например, через onclick)
 * - Запускает инициализацию всех компонентов после полной загрузки DOM
 *
 *  Принцип работы:
 *   Все логические блоки вынесены в отдельные файлы (модули).
 *   Здесь они собираются воедино — как "главный дирижёр".
 *
 *  Подключение:
 *   Должен подключаться в HTML через <script type="module" src=".../main.js"></script>
 * =============================================================
 */

// === ИМПОРТ МОДУЛЕЙ ==================================================
// Импортируем только те функции, которые реально используются на странице

// Галерея: Функции для выделения элементов и обработки кликов по HTML-содержимого галереи.
import { selectAll, updateDeleteButtonVisibility } from './gallery.js';

// Галерея: Загрузки HTML-содержимого галереи с погинацией
import { loadImageSection, clickAll } from './galleryLoader.js';

// Галерея: Модуль загрузки изображений с предварительной обработкой настроек размеров.
import { uploadFiles, showNotification } from './fileUploader.js';

// Галерея: Обработчик массового удаления изображений из галереи конкретного раздела.
import { deleteSelectedPhotos } from './delete-images.js';

// Галерея: Загружает информацию о фотографии (метаданные) по её ID и отображает в модальном окне.
import { photoInfo, updatePhotoInfo } from './photoInfo.js';

// УПРАВЛЕНИЕ ГАЛЕРЕЕЙ ИЗОБРАЖЕНИЙ С AJAX ЗАГРУЗКОЙ
import { loadGallery, updateSelectedImagesInput } from './gallery-ajax.js';

// Менеджер галерей — управление загрузкой, перетаскиванием изображений
import { initGalleryManager } from './gallery-manager.js';

// === ГЛОБАЛЬНЫЙ ДОСТУП ===============================================
// Некоторые функции должны быть доступны из HTML-атрибутов вроде onclick="..."
// Для этого явно добавляем их в глобальный объект window

window.selectAll = selectAll;
window.updateDeleteButtonVisibility = updateDeleteButtonVisibility;
window.loadImageSection = loadImageSection;
window.clickAll = clickAll;
window.uploadFiles = uploadFiles;
window.showNotification = showNotification;
window.deleteSelectedPhotos = deleteSelectedPhotos;
window.photoInfo = photoInfo;
window.updatePhotoInfo = updatePhotoInfo;
window.loadGallery = loadGallery;
window.updateSelectedImagesInput = updateSelectedImagesInput;

document.addEventListener('DOMContentLoaded', () => {
  // Инициализируем обработчики кликов и клавиатуры для всех элементов галереи
  // initGallery();

  // Инициализация менеджера галерей
  initGalleryManager();

});

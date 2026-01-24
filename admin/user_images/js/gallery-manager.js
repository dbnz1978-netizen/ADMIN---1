/**
 * Файл: /admin/user_images/js/gallery-manager.js
 * 
 * УНИВЕРСАЛЬНЫЙ МЕНЕДЖЕР ГАЛЕРЕЙ С МОНИТОРИНГОМ DOM И ПОСЛЕДОВАТЕЛЬНОЙ ЗАГРУЗКОЙ
 * Автоматически активируется при появлении новых галерей
 * Загружает изображения по очереди с плавными анимациями
 */

export class GalleryManager {
    constructor() {
        this.initialized = false;
        this.observer = null;
        this.imageLoaders = new Map();
        
        // Глобальные переменные для управления перетаскиванием
        this.globalDragged = null;
        this.globalPlaceholder = null;
        this.activeGallery = null; // ID активной галереи, где происходит перетаскивание
        
        this.init();
    }
    
    init() {
        if (this.initialized) return;
        
        console.log('GalleryManager: Starting initialization...');
        
        // Сначала инициализируем существующие галереи
        this.initExistingGalleries();
        
        // Затем запускаем мониторинг для новых галерей
        this.startDOMObservation();
        
        // Инициализируем глобальные обработчики
        this.initGlobalDragHandlers();
        
        this.initialized = true;
    }
    
    // Инициализация глобальных обработчиков перетаскивания
    initGlobalDragHandlers() {
        // Глобальный обработчик dragend для очистки состояния
        document.addEventListener('dragend', () => {
            this.cleanupGlobalDragState();
        });
        
        // Глобальный обработчик dragover для предотвращения конфликтов
        document.addEventListener('dragover', (e) => {
            // Если есть активное перетаскивание, предотвращаем стандартное поведение
            if (this.globalDragged) {
                e.preventDefault();
            }
        });
    }
    
    // Очистка глобального состояния перетаскивания
    cleanupGlobalDragState() {
        if (this.globalPlaceholder && this.globalPlaceholder.parentNode) {
            this.globalPlaceholder.parentNode.removeChild(this.globalPlaceholder);
        }
        
        if (this.globalDragged) {
            this.globalDragged.classList.remove("dragging");
            this.globalDragged = null;
        }
        
        this.globalPlaceholder = null;
        this.activeGallery = null;
        
        // Убираем класс dragging-active со всех галерей
        document.querySelectorAll('.selected-images-container').forEach(container => {
            container.classList.remove('dragging-active');
        });
    }
    
    // Инициализация уже существующих галерей
    initExistingGalleries() {
        const imageLists = document.querySelectorAll('[id^="selectedImagesList_"]');
        
        if (imageLists.length > 0) {
            console.log(`GalleryManager: Found ${imageLists.length} image lists`);
            
            imageLists.forEach(list => {
                const sectionId = list.id.replace('selectedImagesList_', '');
                this.initSection(sectionId);
            });
            
            this.initGlobalHandlers();
        } else {
            console.log('GalleryManager: No existing galleries found, waiting for AJAX...');
        }
    }
    
    // Запуск мониторинга DOM для новых галерей
    startDOMObservation() {
        this.observer = new MutationObserver((mutations) => {
            let shouldReinit = false;
            
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1) {
                        if (node.id && node.id.startsWith('selectedImagesList_')) {
                            shouldReinit = true;
                        } else if (node.querySelector && node.querySelector('[id^="selectedImagesList_"]')) {
                            shouldReinit = true;
                        }
                    }
                });
            });
            
            if (shouldReinit) {
                console.log('GalleryManager: New galleries detected, reinitializing...');
                setTimeout(() => {
                    this.reinit();
                }, 100);
            }
        });
        
        this.observer.observe(document.body, {
            childList: true,
            subtree: true
        });
        
        console.log('GalleryManager: DOM observation started');
    }
    
    // Инициализация функционала перетаскивания для секции
    initSection(sectionId) {
        const list = document.getElementById(`selectedImagesList_${sectionId}`);
        const container = document.querySelector(`#image-management-section-${sectionId} .selected-images-container`) || list.parentElement;

        if (!list) {
            console.error(`GalleryManager: List not found for section: ${sectionId}`);
            return;
        }

        // Предотвращаем повторную инициализацию
        if (list.hasAttribute('data-drag-initialized')) {
            return;
        }
        list.setAttribute('data-drag-initialized', 'true');

        list.addEventListener("dragstart", (e) => {
            if (e.target.closest('.selected-image-remove')) {
                e.preventDefault();
                return false;
            }

            const imageItem = e.target.closest(".selected-image-item");
            if (!imageItem) return;

            // Устанавливаем глобальное состояние
            this.globalDragged = imageItem;
            this.activeGallery = sectionId;
            this.globalDragged.classList.add("dragging");
            
            // Добавляем класс только к активной галерее
            if (container) {
                container.classList.add('dragging-active');
            }

            e.dataTransfer.effectAllowed = "move";
            e.dataTransfer.setData("text/plain", this.globalDragged.dataset.imageId);
        });

        list.addEventListener("dragover", (e) => {
            e.preventDefault();
            
            // Если это не активная галерея, выходим
            if (this.activeGallery !== sectionId || !this.globalDragged) return;

            const target = e.target.closest(".selected-image-item");
            if (!target || target === this.globalDragged) return;

            const rect = target.getBoundingClientRect();
            const middle = rect.top + rect.height / 2;

            // Создаем placeholder если его нет
            if (!this.globalPlaceholder) {
                this.globalPlaceholder = document.createElement("div");
                this.globalPlaceholder.className = "drop-placeholder";
                this.globalPlaceholder.style.width = this.globalDragged.offsetWidth + "px";
                this.globalPlaceholder.style.height = this.globalDragged.offsetHeight + "px";
            }

            // Удаляем placeholder из DOM если он уже есть
            if (this.globalPlaceholder.parentNode) {
                this.globalPlaceholder.parentNode.removeChild(this.globalPlaceholder);
            }

            // Вставляем placeholder в правильную позицию
            if (e.clientY < middle) {
                list.insertBefore(this.globalPlaceholder, target);
            } else {
                list.insertBefore(this.globalPlaceholder, target.nextSibling);
            }
        });

        list.addEventListener("drop", (e) => {
            e.preventDefault();
            
            // Если это не активная галерея, выходим
            if (this.activeGallery !== sectionId || !this.globalDragged || !this.globalPlaceholder || !this.globalPlaceholder.parentNode) return;

            this.globalPlaceholder.parentNode.insertBefore(this.globalDragged, this.globalPlaceholder);
            this.globalPlaceholder.parentNode.removeChild(this.globalPlaceholder);
            
            this.globalDragged.classList.remove("dragging");
            if (container) {
                container.classList.remove('dragging-active');
            }
            
            this.saveOrder(sectionId);
            this.cleanupGlobalDragState();
        });

        list.addEventListener("dragend", () => {
            this.cleanupGlobalDragState();
        });

        // Обработчики для предотвращения конфликтов
        list.addEventListener('dragenter', (e) => {
            e.preventDefault();
        });

        list.addEventListener('dragleave', (e) => {
            // Если курсор покинул список и placeholder существует
            if (!list.contains(e.relatedTarget) && this.globalPlaceholder && this.globalPlaceholder.parentNode === list) {
                this.globalPlaceholder.parentNode.removeChild(this.globalPlaceholder);
            }
        });

        console.log(`GalleryManager: Drag & drop initialized for section: ${sectionId}`);
    }
    
    startSequentialImageLoad(sectionId) {
        const imagesData = document.getElementById(`imagesData_${sectionId}`);
        const imagesList = document.getElementById(`selectedImagesList_${sectionId}`);
        
        if (!imagesData || !imagesList) {
            console.log(`GalleryManager: No images to load for section: ${sectionId}`);
            this.initSection(sectionId);
            return;
        }
        
        if (this.imageLoaders.has(sectionId)) {
            clearTimeout(this.imageLoaders.get(sectionId));
        }
        
        console.log(`GalleryManager: Starting sequential load for section: ${sectionId}`);
        
        const imageElements = imagesData.querySelectorAll('div[data-image-src]');
        const totalImages = imageElements.length;
        
        imagesList.innerHTML = '';
        
        const imagesDataArray = Array.from(imageElements).map(img => ({
            id: img.getAttribute('data-image-id'),
            src: img.getAttribute('data-image-src'),
            alt: img.getAttribute('data-image-alt')
        }));
        
        imagesData.remove();
        
        this.loadImagesWithDelay(sectionId, imagesDataArray, 0, totalImages);
    }
    
    loadImagesWithDelay(sectionId, imagesData, currentIndex, totalImages) {
        if (currentIndex >= imagesData.length) {
            console.log(`GalleryManager: All ${totalImages} images loaded for section: ${sectionId}`);
            this.finishSectionInitialization(sectionId);
            return;
        }
        
        const imageData = imagesData[currentIndex];
        this.createImageElement(sectionId, imageData, currentIndex);
        this.updateImagesCounter(sectionId, currentIndex + 1, totalImages);
        
        const loaderId = setTimeout(() => {
            this.loadImagesWithDelay(sectionId, imagesData, currentIndex + 1, totalImages);
        }, 150);
        
        this.imageLoaders.set(sectionId, loaderId);
    }
    
    createImageElement(sectionId, imageData, index) {
        const imagesList = document.getElementById(`selectedImagesList_${sectionId}`);
        
        if (!imagesList) return;
        
        const imageItem = document.createElement('div');
        imageItem.className = 'selected-image-item lazy-loading';
        imageItem.setAttribute('data-image-id', imageData.id);
        imageItem.setAttribute('draggable', 'true');
        imageItem.style.opacity = '0';
        imageItem.style.transform = 'translateY(20px)';
        imageItem.style.transition = 'none';
        
        imageItem.innerHTML = `
            <img src="${imageData.src}" alt="${imageData.alt}" loading="lazy">
            <button type="button" class="selected-image-remove" data-section="${sectionId}" data-image-id="${imageData.id}">
                <i class="bi bi-x"></i>
            </button>
        `;
        
        imagesList.appendChild(imageItem);
        
        requestAnimationFrame(() => {
            imageItem.style.transition = 'all 0.5s ease-out';
            imageItem.style.opacity = '1';
            imageItem.style.transform = 'translateY(0)';
            
            setTimeout(() => {
                imageItem.classList.remove('lazy-loading');
            }, 500);
        });
        
        console.log(`GalleryManager: Loaded image ${index + 1} for section ${sectionId}: ${imageData.id}`);
    }
    
    updateImagesCounter(sectionId, loadedCount, totalCount) {
        const counter = document.getElementById(`selectedImagesCount_${sectionId}`);
        if (counter) {
            counter.textContent = loadedCount;
        }
    }
    
    finishSectionInitialization(sectionId) {
        this.updateHiddenInput(sectionId);
        this.initSection(sectionId);
        this.imageLoaders.delete(sectionId);
        console.log(`GalleryManager: Section ${sectionId} fully initialized with drag & drop`);
    }
    
    updateHiddenInput(sectionId) {
        const imagesList = document.getElementById(`selectedImagesList_${sectionId}`);
        const hiddenInput = document.getElementById(`selectedImages_${sectionId}`);
        
        if (!imagesList || !hiddenInput) return;
        
        const ids = [...imagesList.querySelectorAll(".selected-image-item")]
            .map(el => el.dataset.imageId);
        hiddenInput.value = ids.join(",");
    }
    
    saveOrder(sectionId) {
        const list = document.getElementById(`selectedImagesList_${sectionId}`);
        const input = document.getElementById(`selectedImages_${sectionId}`);
        
        if (!list || !input) return;
        
        const ids = [...list.querySelectorAll(".selected-image-item")]
            .map(el => el.dataset.imageId);
        input.value = ids.join(",");
        
        console.log(`GalleryManager: Order saved for ${sectionId}: ${input.value}`);
    }
    
    initGlobalHandlers() {
        // Важно: привязываем this, иначе теряется контекст
        this.handleRemoveClick = this.handleRemoveClick.bind(this);
        document.removeEventListener('click', this.handleRemoveClick);
        document.addEventListener('click', this.handleRemoveClick);
    }
    
    handleRemoveClick(e) {
        if (e.target.closest('.selected-image-remove')) {
            const button = e.target.closest('.selected-image-remove');
            const sectionId = button.getAttribute('data-section');
            const imageId = button.getAttribute('data-image-id');
            
            if (sectionId && imageId) {
                this.removeSelectedImage(sectionId, imageId);
            }
        }
    }
    
    removeSelectedImage(sectionId, imageId) {
        const imageElement = document.querySelector(`#selectedImagesList_${sectionId} .selected-image-item[data-image-id="${imageId}"]`);
        
        if (imageElement) {
            imageElement.classList.add('removing');
            
            setTimeout(() => {
                imageElement.remove();
                this.updateSectionData(sectionId);
            }, 400);
        }
    }
    
    updateSectionData(sectionId) {
        const list = document.getElementById(`selectedImagesList_${sectionId}`);
        const input = document.getElementById(`selectedImages_${sectionId}`);
        const countElement = document.getElementById(`selectedImagesCount_${sectionId}`);
        
        if (!list || !input || !countElement) return;
        
        const ids = [...list.querySelectorAll(".selected-image-item")].map(el => el.dataset.imageId);
        input.value = ids.join(",");
        countElement.textContent = ids.length;
    }
    
    reinit() {
        this.imageLoaders.forEach((loaderId, sectionId) => {
            clearTimeout(loaderId);
            console.log(`GalleryManager: Stopped image loader for section: ${sectionId}`);
        });
        this.imageLoaders.clear();
        
        document.querySelectorAll('[id^="selectedImagesList_"]').forEach(list => {
            list.removeAttribute('data-drag-initialized');
        });
        
        this.initExistingGalleries();
        console.log('GalleryManager: Reinitialized all galleries');
    }
    
    disconnect() {
        if (this.observer) {
            this.observer.disconnect();
            console.log('GalleryManager: DOM observation stopped');
        }
        
        this.imageLoaders.forEach((loaderId, sectionId) => {
            clearTimeout(loaderId);
        });
        this.imageLoaders.clear();
        
        this.cleanupGlobalDragState();
    }
}

// Экспортируем функцию-обёртку, чтобы вызывать init при подключении
export function initGalleryManager() {
    if (typeof window.galleryManager === 'undefined' || !window.galleryManager.initialized) {
        console.log('GalleryManager: Initializing via initGalleryManager()...');
        window.galleryManager = new GalleryManager();
    }
}
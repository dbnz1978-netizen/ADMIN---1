<?php
/**
 * Файл: /admin/user_images/photo_info.php
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}
?>
<!-- Кастомное модальное окно (независимое) -->
<div id="customOverlayModal" class="custom-overlay-modal" role="dialog" aria-modal="true" aria-labelledby="customModalTitle">
  <div class="custom-modal-dialog">
    <div class="custom-modal-content">
      <div class="custom-modal-header">
        <h5 id="customModalTitle" class="custom-modal-title">Подтверждение действия</h5>
        <button type="button" class="custom-modal-close" aria-label="Закрыть">&times;</button>
      </div>
            <!-- Контейнер с возможным спинером -->
            <div class="p-3" id="loading-contents">
                <!-- Спинер будет вставляться/скрываться здесь -->
                <div class="d-flex justify-content-center my-4">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                    <span>Загрузка данных...</span>
                </div>
            </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-dismiss="custom-modal">
                <i class="bi bi-x-lg"></i> Отмена
            </button>
            <button type="button" class="btn btn-primary btn-sm" onclick="updatePhotoInfo()" data-dismiss="custom-modal">
                <i class="bi bi-check-lg"></i> Обновить
            </button>
        </div>
    </div>
  </div>
</div>
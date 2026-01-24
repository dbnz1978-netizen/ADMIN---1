<?php
/**
 * Файл: /admin/functions/display_alerts.php
 * Отображение универсальных системных сообщений
 * 
 * Функция выводит блоки сообщений об успехе и ошибках в едином стиле
 * с поддержкой автоматического закрытия через JavaScript
 * 
 * @param array $successMessages Массив сообщений об успехе
 * @param array $errors Массив сообщений об ошибках
 * @return void
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

function displayAlerts($successMessages = [], $errors = []) {
    // Универсальный блок для сообщений об успехе
    if (!empty($successMessages)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert" data-auto-close="5000">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i><strong> Удачно выполнено:</strong>
            <div class="alert-content">
                <?php if (count($successMessages) === 1): ?>
                    <?= htmlspecialchars($successMessages[0] ?? '', ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    <ul class="mb-0">
                        <?php foreach ($successMessages as $message): ?>
                            <li><?= htmlspecialchars($message ?? '', ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
        </div>
    <?php endif;

    // Универсальный блок для сообщений об ошибках
    if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert" data-auto-close="10000">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> <strong> Обнаружены следующие ошибки:</strong>
            <div class="alert-content">
                <?php if (count($errors) === 1): ?>
                    <?= htmlspecialchars($errors[0] ?? '', ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error ?? '', ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
        </div>
    <?php endif;
}
?>
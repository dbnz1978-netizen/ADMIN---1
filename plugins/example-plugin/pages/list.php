<?php

/**
 * Название файла:      list.php
 * Назначение:          Пример страницы плагина - список страниц
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors' => false,  // включение отображения ошибок true/false
    'set_encoding'   => true,   // включение кодировки UTF-8
    'db_connect'     => true,   // подключение к базе
    'auth_check'     => true,   // подключение функций авторизации
    'file_log'       => true,   // подключение системы логирования
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../../../admin/functions/init.php';

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

// Получаем настройки администратора из БД
$adminData = getAdminData($pdo);

if ($adminData === false) {
    // Критическая ошибка: запись админа повреждена или отсутствует → безопасный выход
    header("Location: ../../../admin/logout.php");
    exit;
}

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);  // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки true/false

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ И ПОЛУЧЕНИЕ ДАННЫХ АДМИНИСТРАТОРА
// ========================================

try {
    // Проверка авторизации
    $user = requireAuth($pdo);
    
    if (!$user) {
        $redirectTo = '../../../admin/logout.php';
        logEvent(
            "Неавторизованный доступ — перенаправление на: $redirectTo — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}",
            LOG_ERROR_ENABLED,
            'error'
        );
        header("Location: $redirectTo");
        exit;
    }
    
    // Получаем данные администратора из БД
    $userDataAdmin = getUserAdmin($pdo, $user['user_id']);
    
    if (!$userDataAdmin) {
        // Не удалось получить данные администратора
        logEvent(
            "Не удалось получить данные администратора user_id={$user['user_id']} — IP: {$_SERVER['REMOTE_ADDR']}",
            LOG_ERROR_ENABLED,
            'error'
        );
        header("Location: ../../../admin/logout.php");
        exit;
    }
    
} catch (Exception $e) {
    logEvent(
        "Ошибка при проверке авторизации: " . $e->getMessage(),
        LOG_ERROR_ENABLED,
        'error'
    );
    header("Location: ../../../admin/logout.php");
    exit;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Список страниц - Example Plugin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>Список страниц</h1>
        <p>Это пример страницы плагина. Здесь может быть список страниц.</p>
        <a href="../../../admin/plugins/list.php" class="btn btn-secondary">Назад к плагинам</a>
    </div>
</body>
</html>

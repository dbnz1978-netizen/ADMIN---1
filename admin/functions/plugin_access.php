<?php
/**
 * Файл: /admin/functions/plugin_access.php
 * 
 * Централизованная система управления доступом к плагинам по ролям пользователей.
 * 
 * Основные функции:
 * - Проверка доступа пользователя к плагину на основе роли (users.author)
 * - Чтение/запись настроек доступа из JSON-поля users.data администратора
 * - Guard-функция для защиты страниц плагинов
 * - Фильтрация меню плагинов на основе прав доступа
 * 
 * Формат хранения настроек в users.data администратора:
 * {
 *   "plugins_access": {
 *     "news": {
 *       "user": true,
 *       "admin": true
 *     },
 *     "another-plugin": {
 *       "user": false,
 *       "admin": true
 *     }
 *   }
 * }
 * 
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-11
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

/**
 * Получает настройки доступа к плагинам из данных администратора
 * 
 * @param PDO $pdo Объект подключения к БД
 * @return array|false Массив настроек доступа или false при ошибке
 * 
 * @example
 * $settings = getPluginAccessSettings($pdo);
 * // Возвращает: ['news' => ['user' => true, 'admin' => true], ...]
 */
function getPluginAccessSettings($pdo) {
    $adminData = getAdminData($pdo);
    
    if ($adminData === false) {
        return false;
    }
    
    return $adminData['plugins_access'] ?? [];
}

/**
 * Сохраняет настройки доступа к плагинам в данные администратора
 * 
 * @param PDO $pdo Объект подключения к БД
 * @param array $accessSettings Массив настроек доступа
 * @return bool Успешность операции
 * 
 * @example
 * $settings = ['news' => ['user' => true, 'admin' => true]];
 * savePluginAccessSettings($pdo, $settings);
 */
function savePluginAccessSettings($pdo, $accessSettings) {
    try {
        // Получаем ID администратора
        $stmt = $pdo->prepare("SELECT id FROM `users` WHERE `author` = 'admin' LIMIT 1");
        $stmt->execute();
        $adminId = $stmt->fetchColumn();
        
        if (!$adminId) {
            error_log("[plugin_access.php] Администратор не найден в БД");
            return false;
        }
        
        // Используем функцию updateUserJsonData для безопасного обновления
        if (!function_exists('updateUserJsonData')) {
            require_once __DIR__ . '/jsondata.php';
        }
        
        $updateData = [
            'plugins_access' => $accessSettings
        ];
        
        $jsonData = updateUserJsonData($pdo, $adminId, $updateData);
        
        // Сохраняем в БД
        $stmt = $pdo->prepare("UPDATE `users` SET `data` = ? WHERE `id` = ?");
        $result = $stmt->execute([$jsonData, $adminId]);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("[plugin_access.php] Ошибка сохранения настроек доступа: " . $e->getMessage());
        return false;
    }
}

/**
 * Проверяет, имеет ли пользователь доступ к плагину
 * 
 * @param PDO $pdo Объект подключения к БД
 * @param string $pluginName Имя плагина (например: 'news')
 * @param string $userRole Роль пользователя (например: 'user', 'admin')
 * @return bool true если доступ разрешён, false если запрещён
 * 
 * @example
 * if (hasPluginAccess($pdo, 'news', $userDataAdmin['author'])) {
 *     // Пользователь имеет доступ
 * }
 */
function hasPluginAccess($pdo, $pluginName, $userRole) {
    // Получаем настройки доступа
    $accessSettings = getPluginAccessSettings($pdo);
    
    if ($accessSettings === false) {
        // При ошибке получения настроек - запрещаем доступ
        return false;
    }
    
    // Если настройки для плагина не заданы - разрешаем доступ по умолчанию
    if (!isset($accessSettings[$pluginName])) {
        return true;
    }
    
    // Проверяем настройку для конкретной роли
    // По умолчанию доступ разрешён, если настройка не задана
    return $accessSettings[$pluginName][$userRole] ?? true;
}

/**
 * Guard-функция для защиты страниц плагинов
 * Проверяет авторизацию и права доступа, при отказе возвращает 403
 * 
 * @param PDO $pdo Объект подключения к БД
 * @param string $pluginName Имя плагина (например: 'news')
 * @param string|null $requiredRole Требуемая роль (например: 'admin'). Если указана, доступ только для этой роли
 * @return array Данные пользователя при успешной проверке
 * 
 * @example
 * // В начале страницы плагина
 * $userDataAdmin = pluginAccessGuard($pdo, 'news');
 * 
 * // Для страниц только для администраторов
 * $userDataAdmin = pluginAccessGuard($pdo, 'news', 'admin');
 */
function pluginAccessGuard($pdo, $pluginName, $requiredRole = null) {
    // Проверяем авторизацию
    $user = requireAuth($pdo);
    
    if (!$user) {
        http_response_code(403);
        exit('Доступ запрещён: требуется авторизация');
    }
    
    // Получаем полные данные пользователя
    $userDataResult = getUserData($pdo, $user['id']);
    
    if (isset($userDataResult['error']) && $userDataResult['error'] === true) {
        http_response_code(403);
        exit('Доступ запрещён: ошибка получения данных пользователя');
    }
    
    $userRole = $userDataResult['author'] ?? 'user';
    
    // Если требуется определённая роль - проверяем её
    if ($requiredRole !== null) {
        if ($userRole !== $requiredRole) {
            $logEnabled = defined('LOG_INFO_ENABLED') ? LOG_INFO_ENABLED : false;
            if (function_exists('logEvent')) {
                logEvent(
                    "Отказ в доступе: пользователь ID={$user['id']} (роль=$userRole) пытался получить доступ к странице, требующей роль=$requiredRole — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}",
                    $logEnabled,
                    'info'
                );
            }
            
            http_response_code(403);
            exit('Доступ запрещён: недостаточно прав');
        }
    }
    
    // Проверяем доступ к плагину на основе роли
    if (!hasPluginAccess($pdo, $pluginName, $userRole)) {
        $logEnabled = defined('LOG_INFO_ENABLED') ? LOG_INFO_ENABLED : false;
        if (function_exists('logEvent')) {
            logEvent(
                "Отказ в доступе к плагину '$pluginName': пользователь ID={$user['id']} (роль=$userRole) — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}",
                $logEnabled,
                'info'
            );
        }
        
        http_response_code(403);
        exit('Доступ запрещён: у вас нет прав для доступа к этому разделу');
    }
    
    return $userDataResult;
}

/**
 * Фильтрует меню плагинов на основе прав доступа пользователя
 * 
 * @param PDO $pdo Объект подключения к БД
 * @param array $pluginMenus Массив меню плагинов
 * @param string $userRole Роль пользователя
 * @return array Отфильтрованный массив меню
 * 
 * @example
 * $pluginMenus = getPluginMenus($pdo);
 * $filteredMenus = filterPluginMenusByAccess($pdo, $pluginMenus, $userDataAdmin['author']);
 */
function filterPluginMenusByAccess($pdo, $pluginMenus, $userRole) {
    $filtered = [];
    
    foreach ($pluginMenus as $menu) {
        $pluginName = $menu['plugin_name'] ?? '';
        
        // Если имя плагина не указано, пропускаем (не фильтруем)
        if (empty($pluginName)) {
            $filtered[] = $menu;
            continue;
        }
        
        // Проверяем доступ к плагину
        if (hasPluginAccess($pdo, $pluginName, $userRole)) {
            $filtered[] = $menu;
        }
    }
    
    return $filtered;
}

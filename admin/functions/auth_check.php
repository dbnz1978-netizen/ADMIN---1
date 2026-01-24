<?php
/**
 * Файл: /admin/functions/auth_check.php
 * 
 * Универсальная система проверки авторизации пользователей.
 * 
 * Основные функции:
 * - Проверка статуса авторизации пользователя
 * - Перенаправление неавторизованных пользователей
 * - Перенаправление уже авторизованных пользователей
 * - Получение данных пользователя из базы данных
 * - Управление сессиями пользователя
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

/**
 * Безопасный запуск сессии
 */
function startSessionSafe() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
        ini_set('session.use_strict_mode', 1);
        
        session_start();
        
        if (empty($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }
}


/**
 * Проверяет авторизацию пользователя по данным сессии
 * 
 * @return array|false Массив с данными пользователя или false если не авторизован
 */
function checkAuth() {
    startSessionSafe(); // Безопасный запуск сессии
    
    if (isset($_SESSION['user_id'], $_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 28800) {
            logoutUser(); // Выход пользователя из системы
            return false;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'email' => $_SESSION['user_email'] ?? '',
            'name' => $_SESSION['user_name'] ?? '',
            'login_time' => $_SESSION['login_time'] ?? time(),
            'logged_in' => true
        ];
    }
    
    return false;
}


/**
 * Проверяет авторизацию и валидность пользователя в базе данных
 * 
 * @param PDO $pdo Объект подключения к базе данных
 * @return array|false Массив с данными пользователя или false если не авторизован
 */
function checkAuthWithValidation($pdo) {
    $user = checkAuth(); // Проверяет авторизацию пользователя по данным сессии
    
    if (!$user) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, email_verified FROM users WHERE id = ? AND email_verified = 1");
        $stmt->execute([$user['id']]);
        $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$dbUser) {
            logoutUser(); // Выход пользователя из системы
            return false;
        }
        
        return $user;
    } catch (Exception $e) {
        // ОШИБКА → 'error'
        logEvent("Ошибка БД при проверке подлинности пользователя ID: {$user['id']} — " . $e->getMessage(), 3, LOG_ERROR_ENABLED, 'error');
        return false;
    }
}


/**
 * Проверяет авторизацию и валидность пользователя (включая подтверждение email).
 * НЕ выполняет перенаправление и логирование внутри.
 * 
 * @param PDO $pdo Объект подключения к БД
 * @return array|false Массив с данными пользователя или false если не авторизован/недействителен
 */
function requireAuth($pdo) {
    return checkAuthWithValidation($pdo);
}

/**
 * Проверяет авторизацию и возвращает данные пользователя, если авторизован.
 * НЕ выполняет перенаправление внутри себя.
 * 
 * @param string $redirectTo URL для перенаправления (используется только для лога, если нужно)
 * @return array|false Массив с данными пользователя, или false если не авторизован
 */
function redirectIfAuth() {
    $user = checkAuth();
    
    if ($user) {
        // Можно вернуть данные — вызывающий код сам решит, что делать
        return $user;
    }
    
    return false;
}


/**
 * Получает данные пользователя. В случае ошибки возвращает массив с готовым сообщением для лога.
 * 
 * @param PDO $pdo Объект подключения к БД
 * @param int|null $userId ID пользователя (если null — берётся из сессии)
 * @return array
 *     Успех: [данные пользователя]
 *     Ошибка: ['error' => true, 'message' => 'полное сообщение для logEvent', 'level' => 'info'|'error']
 */
function getUserData($pdo, $userId = null) {
    // Определяем IP и URL один раз — для всех сообщений
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $url = $_SERVER['REQUEST_URI'] ?? 'unknown';

    // 1. Проверка аутентификации (если $userId не передан)
    if ($userId === null) {
        $user = checkAuth();
        if (!$user) {
            return [
                'error'  => true,
                'message' => "Попытка получить данные без аутентификации — IP: $ip — URL: $url",
                'level'  => 'info'
            ];
        }
        $userId = $user['id'];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            return [
                'error'  => true,
                'message' => "Пользователь не найден в БД — ID: $userId — IP: $ip",
                'level'  => 'error'
            ];
        }

        if ($userData['status'] === 0) {
            return [
                'error'  => true,
                'message' => "Доступ заблокированному пользователю — ID: $userId — IP: $ip — URL: $url",
                'level'  => 'info'
            ];
        }

        // Успех — возвращаем данные
        return $userData;

    } catch (Exception $e) {
        $errorMessage = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        return [
            'error'  => true,
            'message' => "Ошибка БД в getUserData для ID: $userId — $errorMessage — IP: $ip",
            'level'  => 'error'
        ];
    }
}


/**
 * Получает настройки администратора из таблицы users
 * 
 * @param PDO $pdo Объект подключения к БД
 * @return array|false Массив с данными администратора или false при ошибке/отсутствии
 */
function getAdminData($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT data FROM `users` WHERE `author` = 'admin' LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['data'])) {
            $currentData = json_decode($result['data'], true);
            // Проверим, что JSON корректно распарсился
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("[auth_check.php] Ошибка парсинга JSON в поле `data` у admin: " . json_last_error_msg());
                return false;
            }
            return $currentData;
        } else {
            error_log("[auth_check.php] Пользователь Admin не найден в БД");
            return false;
        }
    } catch (PDOException $e) {
        error_log("[auth_check.php] Ошибка БД в getAdminData: " . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log("[auth_check.php] Неизвестная ошибка в getAdminData: " . $e->getMessage());
        return false;
    }
}


/**
 * Получает путь к указанной версии первого изображения из строки ID (например, "123,456")
 * Возвращает путь к файлу или заглушку, если изображение не найдено/недоступно/не существует.
 * 
 * @param PDO    $pdo             Объект подключения к БД
 * @param string $imageIdsString  Строка с ID изображений через запятую (например: "123,456")
 * @param string $versionName     Название версии (по умолчанию 'thumbnail')
 * @param string $fallback        Путь к заглушке по умолчанию
 * @return string                 URL-путь к изображению или заглушке
 */
function getFileVersionFromList($pdo, $imageIdsString, $versionName = 'thumbnail', $fallback = '../img/avatar.svg') {
    if (empty($imageIdsString) || !is_string($imageIdsString)) {
        return $fallback;
    }

    // Разбиваем строку и берём первый ID
    $ids = array_map('trim', explode(',', $imageIdsString));
    $firstId = (int)($ids[0] ?? 0);

    if ($firstId <= 0) {
        return $fallback;
    }

    try {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            logEvent("Попытка получить аватар без авторизации", LOG_ERROR_ENABLED, 'error');
            return $fallback;
        }

        // Запрашиваем файл, принадлежащий пользователю
        $stmt = $pdo->prepare("SELECT file_versions FROM `media_files` WHERE `id` = ? AND `user_id` = ?");
        $stmt->execute([$firstId, (int)$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['file_versions'])) {
            return $fallback;
        }

        $versions = json_decode($row['file_versions'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($versions)) {
            return $fallback;
        }

        // Проверяем наличие запрошенной версии и файла на диске
        if (isset($versions[$versionName]['path']) && !empty($versions[$versionName]['path'])) {
            $relativePath = ltrim($versions[$versionName]['path'], '/');
            $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $relativePath;

            if (file_exists($fullPath)) {
                return '/uploads/' . $relativePath;
            }
        }

        return $fallback;

    } catch (Exception $e) {
        logEvent("Ошибка в getFileVersionFromList: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
        return $fallback;
    }
}
/**
 * 
 * Получает  первое изображения из строки ID (например, "123,456")
$user_avatar = getFileVersionFromList($pdo, $selectedImages, 'thumbnail', '../img/avatar.svg');
 */


/**
 * Выход пользователя из системы
 */
function logoutUser() {
    startSessionSafe(); // Безопасный запуск сессии
    
    $userId = $_SESSION['user_id'] ?? null;
    
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, "/");
    }
    
    session_destroy();
    
    if ($userId) {
        // Штатный выход → 'info'
        logEvent("Пользователь вышел — ID: $userId — IP: {$_SERVER['REMOTE_ADDR']}", LOG_INFO_ENABLED, 'info');
    }
}
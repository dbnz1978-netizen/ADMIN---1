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

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

// Определяем заглушку для logEvent(), если file_log.php не подключён
// Это предотвращает фатальные ошибки "Call to undefined function logEvent()"
if (!function_exists('logEvent')) {
    /**
     * Заглушка для logEvent() когда file_log.php не подключён.
     * Логирует через error_log() только если включено логирование.
     * 
     * ВАЖНО: Не передавайте в $message конфиденциальные данные (пароли, токены, session ID).
     * Сообщение записывается в error_log без дополнительной обработки.
     * 
     * @param string $message Сообщение для лога (должно быть безопасным для записи)
     * @param bool $enabled Включено ли логирование
     * @param string $level Уровень лога ('info' или 'error')
     * @return bool true если сообщение было залогировано, false если отключено
     */
    function logEvent(string $message, bool $enabled = true, string $level = 'info'): bool {
        if (!$enabled) {
            return false;
        }
        error_log("[auth_check.php][$level] $message");
        return true;
    }
}

if (!defined('HTTPS_PORT')) {
    define('HTTPS_PORT', 443);
}

/**
 * Determines whether the request is secure (HTTPS).
 * Uses proxy headers only when proxy trust is explicitly configured (TRUSTED_PROXY_IPS).
 * Note: TRUSTED_PROXY_IPS expects exact IP values (IPv4/IPv6, array or comma-separated string, no CIDR matching).
 * For CIDR support, pre-expand the list or implement custom matching before defining TRUSTED_PROXY_IPS.
 * Note: Port 443 check assumes HTTPS is served on the standard port (override via HTTPS_PORT if needed).
 * Note: X-Forwarded-Proto uses the first value in the chain; ensure trusted proxies sanitize headers.
 *
 * @return bool
 */
function isSecureRequest() {
    $trustProxyHeaders = false;
    if (defined('TRUSTED_PROXY_IPS')) {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $trustedProxyIps = TRUSTED_PROXY_IPS;
        if (is_string($trustedProxyIps)) {
            $trustedProxyIps = array_map('trim', explode(',', $trustedProxyIps));
        } else {
            $trustedProxyIps = (array)$trustedProxyIps;
        }
        $trustedProxyIps = array_filter(
            $trustedProxyIps,
            function ($ip) {
                return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP);
            }
        );
        $trustProxyHeaders = $remoteAddr !== ''
            && filter_var($remoteAddr, FILTER_VALIDATE_IP)
            && in_array($remoteAddr, $trustedProxyIps, true);
    }

    $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
    if ($https === 'on' || $https === '1' || $https === 'true') {
        return true;
    }

    if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === HTTPS_PORT) {
        return true;
    }

    if ($trustProxyHeaders) {
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (!empty($forwardedProto)) {
            $forwardedProtoParts = array_map('trim', explode(',', $forwardedProto));
            if (!empty($forwardedProtoParts) && strtolower($forwardedProtoParts[0]) === 'https') {
                return true;
            }
        }

        $forwardedSsl = $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '';
        if (!empty($forwardedSsl) && strtolower((string)$forwardedSsl) === 'on') {
            return true;
        }

        $frontEndHttps = $_SERVER['HTTP_FRONT_END_HTTPS'] ?? '';
        if (!empty($frontEndHttps) && strtolower((string)$frontEndHttps) === 'on') {
            return true;
        }
    }

    return false;
}

/**
 * Безопасный запуск сессии
 */
function startSessionSafe() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isSecureRequest() ? '1' : '0');
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
 * @param PDO|null $pdo Объект подключения к БД для проверки remember-token при истечении сессии.
 *                      Если не передан, remember-token не проверяется и авторизация будет сброшена при истечении login_time.
 * @return array|false Массив с данными пользователя или false если не авторизован
 */
function checkAuth($pdo = null) {
    startSessionSafe(); // Безопасный запуск сессии
    if (isset($_SESSION['user_id'], $_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 28800) {
            $rememberToken = $_COOKIE['remember_token'] ?? null;
            $tokenValid = false;
            if ($rememberToken) {
                $rememberTokenHash = hash('sha256', $rememberToken);
                if ($pdo instanceof PDO) {
                    try {
                        $stmt = $pdo->prepare("SELECT token FROM user_sessions WHERE user_id = ? AND token = ? AND expires_at > NOW() LIMIT 1");
                        $stmt->execute([$_SESSION['user_id'], $rememberTokenHash]);
                        $storedToken = $stmt->fetchColumn();
                        if ($storedToken !== false) {
                            $tokenValid = hash_equals((string)$storedToken, $rememberTokenHash);
                        }
                    } catch (PDOException $e) {
                        $errorCode = $e->getCode();
                        logEvent("Ошибка проверки токена 'Запомнить меня' для пользователя ID={$_SESSION['user_id']} — ошибка базы данных (код: $errorCode)", LOG_ERROR_ENABLED, 'error');
                    }
                } else {
                    logEvent("Ошибка проверки токена 'Запомнить меня' для пользователя ID={$_SESSION['user_id']} — нет соединения с базой данных", LOG_ERROR_ENABLED, 'error');
                }
            }

            if ($tokenValid) {
                $_SESSION['login_time'] = time();
            } else {
                logoutUser(); // Выход пользователя из системы
                return false;
            }
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
    $user = checkAuth($pdo); // Проверяет авторизацию пользователя по данным сессии
    
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
        logEvent("Ошибка БД при проверке подлинности пользователя ID: {$user['id']} — " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
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
 * Проверяет авторизацию и возвращает данные пользователя без перенаправления.
 * 
 * @param PDO|null $pdo Объект подключения к БД для проверки remember-token.
 *                      Если не передан, remember-token не проверяется и авторизация будет сброшена при истечении login_time.
 * @return array|false Массив с данными пользователя, или false если не авторизован
 */
function redirectIfAuth($pdo = null) {
    $user = checkAuth($pdo);
    
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
        $user = checkAuth($pdo);
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
 * Получает ID администратора.
 *
 * @param PDO $pdo Объект подключения к БД
 * @return int|null ID первого администратора или null при ошибке
 */
function getAdminUserId($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM `users` WHERE `author` = 'admin' LIMIT 1");
        $stmt->execute();
        $adminId = $stmt->fetchColumn();

        return $adminId ? (int)$adminId : null;
    } catch (Exception $e) {
        $logEnabled = defined('LOG_ERROR_ENABLED') ? LOG_ERROR_ENABLED : false;
        logEvent("Ошибка получения ID администратора: " . $e->getMessage(), $logEnabled, 'error');
        return null;
    }
}


/**
 * Получает путь к указанной версии первого изображения из строки ID (например, "123,456")
 * Возвращает путь к файлу или заглушку, если изображение не найдено/недоступно/не существует.
 * 
 * @param PDO    $pdo             Объект подключения к БД
 * @param string $imageIdsString  Строка с ID изображений через запятую (например: "123,456")
 * @param string $versionName     Название версии (по умолчанию 'thumbnail')
 * @param string|null $fallback   Путь к заглушке по умолчанию
 * @param int|null $userId        ID пользователя, чьи файлы проверяем (если null — берём из сессии)
 * @return string                 URL-путь к изображению или заглушке
 */
function getFileVersionFromList($pdo, $imageIdsString, $versionName = 'thumbnail', $fallback = '../img/avatar.svg', $userId = null) {
    if (empty($imageIdsString) || !is_string($imageIdsString)) {
        return $fallback;
    }

    // Разбиваем строку и берём первый ID
    $ids = array_map('trim', explode(',', $imageIdsString));
    $firstId = (int)($ids[0] ?? 0);

    if ($firstId <= 0) {
        return $fallback;
    }

    return getFileVersionById($pdo, $firstId, $versionName, $fallback, $userId);
}

/**
 * Получает путь к версии изображения по ID.
 *
 * @param PDO $pdo
 * @param int $imageId
 * @param string $versionName
 * @param string|null $fallback
 * @param int|null $userId
 * @return string|null
 */
function getFileVersionById($pdo, $imageId, $versionName = 'thumbnail', $fallback = '../img/avatar.svg', $userId = null) {
    $imageId = (int)$imageId;
    if ($imageId <= 0) {
        return $fallback;
    }

    try {
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);
        if ($userId === null) {
            $logEnabled = defined('LOG_ERROR_ENABLED') ? LOG_ERROR_ENABLED : false;
            logEvent("Попытка получить аватар без авторизации", $logEnabled, 'error');
            return $fallback;
        }

        // Запрашиваем файл, принадлежащий пользователю
        $stmt = $pdo->prepare("SELECT file_versions FROM `media_files` WHERE `id` = ? AND `user_id` = ?");
        $stmt->execute([$imageId, (int)$userId]);
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
        $logEnabled = defined('LOG_ERROR_ENABLED') ? LOG_ERROR_ENABLED : false;
        logEvent("Ошибка в getFileVersionById: " . $e->getMessage(), $logEnabled, 'error');
        return $fallback;
    }
}

/**
 * Возвращает пути логотипов для светлой и тёмной темы.
 * Первая доступная картинка используется для светлой темы, вторая — для тёмной.
 * Если доступна только одна, она используется в обеих темах.
 *
 * @param PDO $pdo
 * @param string $imageIdsString
 * @param string $versionName
 * @param int|null $userId
 * @return array{light: ?string, dark: ?string}
 */
function getThemeLogoPaths($pdo, $imageIdsString, $versionName = 'thumbnail', $userId = null) {
    if (empty($imageIdsString) || !is_string($imageIdsString)) {
        return ['light' => null, 'dark' => null];
    }

    $ids = array_values(array_filter(array_map('trim', explode(',', $imageIdsString)), static fn($id) => $id !== ''));
    $paths = [];

    foreach ($ids as $id) {
        $path = getFileVersionById($pdo, $id, $versionName, null, $userId);
        if (!empty($path)) {
            $paths[] = $path;
        }
        if (count($paths) >= 2) {
            break;
        }
    }

    if (empty($paths)) {
        return ['light' => null, 'dark' => null];
    }

    $light = $paths[0];
    $dark = $paths[1] ?? $paths[0];

    return ['light' => $light, 'dark' => $dark];
}
/**
 * 
 * Получает  первое изображения из строки ID (например, "123,456")
 $userAvatar = getFileVersionFromList($pdo, $selectedImages, 'thumbnail', '../img/avatar.svg');
 */


/**
 * Выход пользователя из системы
 */
function logoutUser() {
    startSessionSafe(); // Безопасный запуск сессии
    
    $userId = $_SESSION['user_id'] ?? null;
    $pdo = $GLOBALS['pdo'] ?? null;

    if ($userId && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
            $stmt->execute([$userId]);
            $deletedCount = $stmt->rowCount();
            if ($deletedCount > 0) {
                logEvent("Сессии пользователя удалены при выходе — ID: $userId", LOG_INFO_ENABLED, 'info');
            }
        } catch (PDOException $e) {
            logEvent("Ошибка удаления сессий пользователя при выходе — ID: $userId", LOG_ERROR_ENABLED, 'error');
        }
    } elseif ($userId) {
        logEvent("Ошибка удаления сессий пользователя при выходе — нет соединения с базой данных — ID: $userId", LOG_ERROR_ENABLED, 'error');
    }
    
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    if (isset($_COOKIE['remember_token'])) {
        // Удалить cookie с условным защищенным флагом
        $isSecure = isSecureRequest();
        setcookie('remember_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    
    session_destroy();
    
    if ($userId) {
        // Штатный выход → 'info'
        logEvent("Пользователь вышел — ID: $userId — IP: {$_SERVER['REMOTE_ADDR']}", LOG_INFO_ENABLED, 'info');
    }
}

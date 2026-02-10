<?php

/**
 * Название файла:      verify.php
 * Назначение:          Обработчик верификации капчи для административной панели.
 *                      Проверяет токен капчи, CSRF-токен и источник запроса,
 *                      устанавливает флаг успешной верификации в сессии.
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// ИНИЦИАЛИЗАЦИЯ
// ========================================

if (!defined('APP_ACCESS')) {
    define('APP_ACCESS', true);
}

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors'   => false,  // Включение отображения ошибок (true/false)
    'set_encoding'     => true,   // Включение кодировки UTF-8
    'start_session'    => true,   // запуск Session
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/functions/init.php';


// ========================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ========================================


/**
 * Отправка JSON-ответа с ошибкой капчи и завершение скрипта
 *
 * @param string $message     Текст ошибки
 * @param int    $statusCode  HTTP-код статуса (по умолчанию 400)
 * @return void
 */
function respondCaptchaError(string $message, int $statusCode = 400): void
{
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/**
 * Проверка, можно ли доверять заголовкам прокси-сервера
 *
 * @return bool  true, если REMOTE_ADDR находится в списке доверенных прокси
 */
function shouldTrustProxyHeaders(): bool
{
    if (!defined('TRUSTED_PROXY_IPS')) {
        return false;
    }

    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    
    if ($remoteAddr === '' || !filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return false;
    }

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

    return in_array($remoteAddr, $trustedProxyIps, true);
}

/**
 * Нормализация значения Origin для сравнения
 *
 * @param string|null $origin         Исходное значение Origin
 * @param string|null $defaultScheme  Схема по умолчанию (http/https)
 * @param int|null    $defaultPort    Порт по умолчанию
 * @return string|null                Нормализованный Origin или null
 */
function normalizeOriginValue(?string $origin, ?string $defaultScheme = null, ?int $defaultPort = null): ?string
{
    $origin = trim((string)$origin);
    
    if ($origin === '') {
        return null;
    }

    $hasScheme = stripos($origin, 'http://') === 0 || stripos($origin, 'https://') === 0;
    
    if (!$hasScheme) {
        if ($defaultScheme === null) {
            return null;
        }
        $origin = $defaultScheme . '://' . $origin;
    }

    $parts = parse_url($origin);
    
    if ($parts === false || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower($parts['scheme'] ?? ($defaultScheme ?? ''));
    
    if ($scheme === '') {
        return null;
    }

    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $host = strtolower($parts['host']);
    $port = $parts['port'] ?? null;
    
    if ($port === null && !$hasScheme && $defaultPort !== null) {
        $port = $defaultPort;
    }

    if ($port !== null && is_numeric($port)) {
        $port = (int)$port;
    } else {
        $port = null;
    }

    $defaultPortForScheme = $scheme === 'https' ? 443 : 80;
    
    if ($port === $defaultPortForScheme) {
        $port = null;
    }

    return $scheme . '://' . $host . ($port ? ':' . $port : '');
}

/**
 * Получение хоста и порта из заголовков запроса
 *
 * @return array  Массив [$host, $port], где $host - строка или null, $port - int или null
 */
function getRequestHostAndPort(): array
{
    $trustProxyHeaders = shouldTrustProxyHeaders();
    $hostHeader        = '';
    $port              = null;
    $usedForwardedHost = false;

    if ($trustProxyHeaders && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $forwardedHosts    = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_HOST']));
        $hostHeader        = $forwardedHosts[0] ?? '';
        $usedForwardedHost = $hostHeader !== '';
    }

    if ($hostHeader === '') {
        $hostHeader = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    }

    if ($hostHeader === '') {
        return [null, null];
    }

    $hostParts = parse_url('http://' . $hostHeader);
    
    if (
        $hostParts === false
        || empty($hostParts['host'])
        || isset($hostParts['user'])
        || isset($hostParts['pass'])
        || isset($hostParts['path'])
        || isset($hostParts['query'])
        || isset($hostParts['fragment'])
    ) {
        return [null, null];
    }

    $host       = strtolower($hostParts['host']);
    $isValidHost = filter_var($host, FILTER_VALIDATE_IP)
        || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    
    if (!$isValidHost) {
        return [null, null];
    }

    if (isset($hostParts['port'])) {
        $port = (int)$hostParts['port'];
    }

    $forwardedPort     = $_SERVER['HTTP_X_FORWARDED_PORT'] ?? '';
    $hasForwardedPort  = $trustProxyHeaders && $forwardedPort !== '' && is_numeric($forwardedPort);
    
    if ($port === null && $hasForwardedPort) {
        $forwardedPortValue = (int)$forwardedPort;
        
        if ($forwardedPortValue >= 1 && $forwardedPortValue <= 65535) {
            $port = $forwardedPortValue;
        }
    }

    $serverPort        = $_SERVER['SERVER_PORT'] ?? '';
    $hasServerPort     = $serverPort !== '' && is_numeric($serverPort);
    $shouldUseServerPort = $port === null && (!$trustProxyHeaders || !$usedForwardedHost);
    
    if ($shouldUseServerPort && $hasServerPort) {
        $serverPortValue = (int)$serverPort;
        
        if ($serverPortValue >= 1 && $serverPortValue <= 65535) {
            $port = $serverPortValue;
        }
    }

    return [$host, $port];
}

/**
 * Получение списка разрешённых источников для CORS
 *
 * @return array  Массив нормализованных Origin URL
 */
function getAllowedOrigins(): array
{
    $scheme          = isSecureRequest() ? 'https' : 'http';
    [$requestHost, $requestPort] = getRequestHostAndPort();
    $allowedOrigins  = [];

    if ($requestHost !== null) {
        $origin = normalizeOriginValue($requestHost . ($requestPort ? ':' . $requestPort : ''), $scheme, $requestPort);
        
        if ($origin !== null) {
            $allowedOrigins[] = $origin;
        }
    }

    if (defined('ALLOWED_ORIGINS')) {
        $configuredOrigins = ALLOWED_ORIGINS;
        
        if (is_string($configuredOrigins)) {
            $configuredOrigins = array_map('trim', explode(',', $configuredOrigins));
        }
        
        foreach ((array)$configuredOrigins as $configuredOrigin) {
            $normalized = normalizeOriginValue((string)$configuredOrigin, $scheme, $requestPort);
            
            if ($normalized !== null) {
                $allowedOrigins[] = $normalized;
            }
        }
    }

    return array_values(array_unique($allowedOrigins));
}

/**
 * Получение источника входящего запроса (Origin или Referer)
 *
 * @return string|null  Нормализованный Origin или null
 */
function getIncomingOrigin(): ?string
{
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        return normalizeOriginValue($_SERVER['HTTP_ORIGIN']);
    }

    if (!empty($_SERVER['HTTP_REFERER'])) {
        $refererParts = parse_url($_SERVER['HTTP_REFERER']);
        
        if ($refererParts !== false && !empty($refererParts['scheme']) && !empty($refererParts['host'])) {
            $refererOrigin = $refererParts['scheme'] . '://' . $refererParts['host'];
            
            if (!empty($refererParts['port'])) {
                $refererOrigin .= ':' . $refererParts['port'];
            }
            
            return normalizeOriginValue($refererOrigin);
        }
    }

    return null;
}

// ========================================
// ВАЛИДАЦИЯ ЗАПРОСА
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondCaptchaError('Method not allowed', 405);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') === false) {
    respondCaptchaError('Invalid content type');
}

$payload = json_decode(file_get_contents('php://input'), true);

if (!is_array($payload)) {
    respondCaptchaError('Invalid payload');
}

// ========================================
// ПРОВЕРКА ТОКЕНА КАПЧИ
// ========================================

$captchaToken  = $payload['captcha_token'] ?? '';
$csrfToken     = $payload['csrf_token'] ?? '';
$sessionToken  = $_SESSION['captcha_token'] ?? '';
$tokenCreated  = $_SESSION['captcha_token_created'] ?? 0;
$tokenUsed     = !empty($_SESSION['captcha_token_used']);
$tokenTtl      = 600;

if (!is_string($captchaToken) || $captchaToken === '') {
    respondCaptchaError('Captcha token missing');
}

if (!$sessionToken || !hash_equals($sessionToken, $captchaToken) || $tokenUsed) {
    respondCaptchaError('Captcha token invalid');
}

if ($tokenCreated && (time() - $tokenCreated) > $tokenTtl) {
    respondCaptchaError('Captcha token expired');
}

// ========================================
// ПРОВЕРКА CSRF-ТОКЕНА
// ========================================

if (!empty($_SESSION['csrf_token'])) {
    if (!is_string($csrfToken) || $csrfToken === '' || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        respondCaptchaError('Invalid CSRF token');
    }
}

// ========================================
// ПРОВЕРКА ИСТОЧНИКА ЗАПРОСА (CORS)
// ========================================

$incomingOrigin = getIncomingOrigin();
$allowedOrigins = getAllowedOrigins();

if ($incomingOrigin !== null) {
    if (empty($allowedOrigins) || !in_array($incomingOrigin, $allowedOrigins, true)) {
        respondCaptchaError('Invalid request origin');
    }
}

// ========================================
// УСПЕШНАЯ ВЕРИФИКАЦИЯ
// ========================================

$_SESSION['captcha_passed']     = time();
$_SESSION['captcha_token_used'] = true;

echo json_encode(['success' => true]);
exit;
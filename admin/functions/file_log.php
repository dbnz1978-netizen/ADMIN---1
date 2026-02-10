<?php
/**
 * Файл: /admin/functions/file_log.php
 * 
 * Универсальная система логирования с автоматическим именованием файлов,
 * ротацией по размеру и сжатием в архивы.
 * 
 * Особенности:
 *   - Автоматически определяет имя вызывающего файла → создаёт логи вида: `auth_check_info.log`
 *   - Поддерживает два уровня: 'info' (успех/действия) и 'error' (ошибки)
 *   - При превышении 10 МБ — архивирует текущий лог в .gz и создаёт новый
 *   - Хранит до 5 последних архивов (старые удаляются)
 *   - Безопасна для параллельных запросов (блокировка через flock)
 *   - Не требует exec() — работает даже на хостингах с отключёнными shell-функциями
 *   - Логи хранятся ВНЕ DOCUMENT_ROOT (в папке /logs/ на уровень выше корня проекта)
 * 
 * Использование:
 *   // В начале проекта (рекомендуется):
 *   define('LOG_INFO_ENABLED',  true);
 *   define('LOG_ERROR_ENABLED', true);
 * 
 *   // В коде:
 *   logEvent("Пользователь вошёл", LOG_INFO_ENABLED, 'info');
 *   logEvent("Ошибка БД: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
 * 
 * Параметры:
 *   - $message     — текст сообщения (обязательно)
 *   - $enabled     — включить/отключить запись (по умолчанию true)
 *   - $level       — 'info' или 'error' (по умолчанию 'info')
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

// Защита от повторного включения
if (!defined('FILE_LOG_INCLUDED')) {
    define('FILE_LOG_INCLUDED', true);

    // === Определяем корень проекта: на уровень выше DOCUMENT_ROOT ===
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (empty($docRoot)) {
        // fallback: если DOCUMENT_ROOT не установлен (например, CLI), используем __DIR__
        $projectRoot = dirname(__DIR__, 2);  // можно настроить по ситуации
        error_log('logEvent(): $_SERVER[\'DOCUMENT_ROOT\'] отсутствует — использован fallback для $projectRoot');
    } else {
        $projectRoot = dirname($docRoot);
    }

    /**
     * Логирует событие в файл:
     *     $projectRoot/logs/ИМЯ_ТЕКУЩЕГО_ФАЙЛА_{level}.log
     *
     * При превышении MAX_LOG_SIZE файл архивируется в .gz и очищается.
     *
     * @param string $message    Текст сообщения (должен быть безопасным для записи)
     * @param bool   $enabled    Разрешена ли запись (по умолчанию true)
     * @param string $level      'info' или 'error' (по умолчанию 'info')
     * @return bool true — запись выполнена, false — отключено или ошибка
     */
    if (!function_exists('logEvent')) {
        function logEvent(string $message, bool $enabled = true, string $level = 'info'): bool
        {
            global $projectRoot;

            if (!$enabled) {
                return false;
            }

            if (!in_array($level, ['info', 'error'], true)) {
                error_log("logEvent(): недопустимый уровень '$level'. Используйте 'info' или 'error'.");
                return false;
            }

            // === Настройки ===
            $maxLogSize = 10 * 1024 * 1024;  // 10 МБ
            $maxArchives = 5;  // Макс. количество .gz-архивов

            // === Определяем имя вызывающего файла ===
            $callerFile = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? __FILE__;
            $baseName = basename($callerFile, '.php');  // например: 'auth_check'

            // Путь к лог-файлу: $projectRoot/logs/baseName_level.log
            $logDir  = $projectRoot . '/logs';
            $logFile = $logDir . '/' . $baseName . '_' . $level . '.log';

            // Создаём директорию /logs/, если не существует
            if (!is_dir($logDir)) {
                if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
                    error_log("logEvent(): не удалось создать директорию: $logDir");
                    return false;
                }
            }

            // === Ротация логов (только один процесс может архивировать) ===
            $lockFile = $logDir . '/.log_rotate.lock';
            $lockHandle = @fopen($lockFile, 'c+');
            if ($lockHandle && flock($lockHandle, LOCK_EX | LOCK_NB)) {
                // Архивируем, если файл существует и > 10 МБ
                if (file_exists($logFile) && filesize($logFile) > $maxLogSize) {
                    try {
                        $timestamp   = date('Ymd_His');
                        $archiveName = "{$baseName}_{$level}_{$timestamp}.log.gz";
                        $archivePath = $logDir . '/' . $archiveName;

                        // Считываем и сжимаем
                        $logContent = file_get_contents($logFile);
                        if ($logContent !== false) {
                            $gzData = gzencode($logContent, 6);
                            if ($gzData !== false && file_put_contents($archivePath, $gzData) !== false) {
                                unlink($logFile);  // удаляем оригинал
                                cleanupOldArchives($logDir, $baseName, $level, $maxArchives);
                            }
                        }
                    } catch (Exception $e) {
                        error_log("logEvent(): ошибка архивации: " . $e->getMessage());
                    }
                }
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            } elseif ($lockHandle) {
                fclose($lockHandle);
            }

            // === Запись сообщения в лог ===
            $line = date('Y-m-d H:i:s') . " [$level] - $message" . PHP_EOL;
            $bytes = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
            return $bytes !== false;
        }
    }


    /**
     * Удаляет старые .gz-архивы, оставляя только последние $maxCount
     */
    if (!function_exists('cleanupOldArchives')) {
        function cleanupOldArchives(string $logDir, string $baseName, string $level, int $maxCount): void
        {
            $pattern = $logDir . '/' . $baseName . '_' . $level . '_*.log.gz';
            $archives = glob($pattern);
            if (!$archives) {
                return;
            }

            usort($archives, static function ($a, $b) {
                return filemtime($a) <=> filemtime($b);
            });

            $toDelete = array_slice($archives, 0, max(0, count($archives) - $maxCount));
            foreach ($toDelete as $file) {
                @unlink($file);
            }
        }
    }
}


/**
// Включаем/отключаем логирование. Глобальные константы.
// === Настройки логирования (ожидается boolean true/false) ===
// Напрямую разрешаем или запрещаем логирование
define('LOG_INFO_ENABLED',  true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', true);    // Логировать ошибки true/false

// Получаем настройки из переменных. Разрешаем или запрещаем логирование
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

// Устанавливаем в коде:
logEvent("Пользователь ID=42 обновил профиль", LOG_INFO_ENABLED, 'info');            // Для логирования собщений
logEvent("Ошибка подключения к SMTP", LOG_ERROR_ENABLED, 'error');                   // Для логирования ошибок
 */

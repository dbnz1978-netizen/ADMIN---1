<?php
/**
 * Файл: /admin/user/user_settings.php
 *
 * Назначение:
 * Страница настроек администратора для управления API-ключом DeepSeek.
 * Позволяет:
 *   - ввести или удалить API-ключ,
 *   - сохранить его в JSON-поле `data` таблицы `users`,
 *   - автоматически запросить и отобразить баланс аккаунта DeepSeek при наличии ключа,
 *   - корректно обрабатывать ошибки (неверный ключ, сетевые проблемы, JSON-ошибки).
 *
 * Доступ разрешён только авторизованным пользователям с ролью 'admin'.
 * Поддерживает CSRF-защиту, логирование и темную/светлую тему интерфейса.
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Запрет прямого доступа
define('APP_ACCESS', true);

// Установка кодировки
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

// Подключение системных компонентов
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получение данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования
require_once __DIR__ . '/../functions/jsondata.php';                 // Обновление JSON данных пользователя
require_once __DIR__ . '/../functions/display_alerts.php';           // Отображение сообщений
require_once __DIR__ . '/../functions/sanitization.php';             // Валидация и экранирование

// Безопасный запуск сессии
startSessionSafe();

// Получение настроек администратора
$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

// Глобальные константы логирования
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);

try {
    // Проверка авторизации
    $user = requireAuth($pdo);
    if (!$user) {
        $redirectTo = '../logout.php';
        logEvent("Неавторизованный доступ — перенаправление на: $redirectTo — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}", LOG_INFO_ENABLED, 'info');
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: $redirectTo");
        exit;
    }

    // Получение данных пользователя
    $userDataAdmin = getUserData($pdo, $user['id']);
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg = $userDataAdmin['message'];
        $level = $userDataAdmin['level'];
        $logEnabled = match($level) {
            'info'  => LOG_INFO_ENABLED,
            'error' => LOG_ERROR_ENABLED,
            default => LOG_ERROR_ENABLED
        };
        logEvent($msg, $logEnabled, $level);
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");
        exit;
    }

    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

    // Доступ только для администратора
    if ($userDataAdmin['author'] !== 'admin') {
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");
        exit;
    }
} catch (Exception $e) {
    logEvent("Ошибка при инициализации админ-панели: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

// Генерация CSRF токена
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Валидация CSRF токена
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

$errors = [];
$successMessages = [];

// Загрузка текущего API-ключа из данных админа
$apiKey = $currentData['deepseek_api_key'] ?? '';

// Устанавливаем значения по умолчанию (булево!)
$status_gpt = (bool)($currentData['status_gpt'] ?? true);

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Пожалуйста, обновите страницу.';
        logEvent("Проверка CSRF-токена не пройдена — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    } else {
        // Валидация названия deepseek_api_key
        $inputKey = trim($_POST['deepseek_api_key'] ?? '');
        $resultinputKey = validateTextareaField($inputKey, 1, 50, 'API-ключ DeepSeek');
        if ($resultinputKey['valid']) {
            $inputKey = $resultinputKey['value'];
        } else {
            $errors[] = $resultinputKey['error'];
            $inputKey = false;
        }

        // Получаем данные из формы (булевы флаги)
        $status_gpt = isset($_POST['status_gpt']);

        try {
            // Сохраняем даже пустое значение (для удаления ключа)
            $updateData = [
                'deepseek_api_key' => $inputKey ?: null,
                'status_gpt' => $status_gpt
            ];

            // Безопасно обновляем JSON данные (сохраняем существующие поля)
            $jsonData = updateUserJsonData($pdo, $user['id'], $updateData);
            $stmt = $pdo->prepare("UPDATE users SET data = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$jsonData, $user['id']]);
            $successMessages[] = 'API-ключ успешно сохранён.';
            $apiKey = $inputKey; // Обновляем для немедленного отображения баланса
            logEvent("Обновлён DeepSeek API-ключ", LOG_INFO_ENABLED, 'info');
        } catch (PDOException $e) {
            $errors[] = 'Ошибка при сохранении ключа. Пожалуйста, попробуйте позже.';
            logEvent("Ошибка базы данных при сохранении ключа: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
        } catch (JsonException $e) {
            $errors[] = 'Ошибка при обработке данных. Пожалуйста, попробуйте позже.';
            logEvent("Ошибка JSON при сохранении ключа: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
        }
    }
}

// === Функция получения баланса ===
function deepseekGetBalance(string $apiKey): array
{
    $url = "https://api.deepseek.com/user/balance"; // Исправлено: убраны лишние пробелы

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiKey",
            "Accept: application/json",
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'PHP/DeepSeekClient'
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'message' => "cURL Error: $error"];
    }
    if ($httpCode !== 200) {
        return ['success' => false, 'message' => "HTTP $httpCode: " . substr((string)$response, 0, 400)];
    }
    $decoded = json_decode((string)$response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'message' => "JSON error: " . json_last_error_msg()];
    }
    return ['success' => true, 'data' => $decoded];
}

// Получение баланса, если ключ задан
$balanceInfo = null;
$balanceError = null;
if (!empty($apiKey)) {
    $balResult = deepseekGetBalance($apiKey);
    if ($balResult['success']) {
        $balanceInfo = $balResult['data']['balance_infos'][0]['total_balance'] ?? null;
    } else {
        $balanceError = $balResult['message'];
    }
}

// Подготовка данных для шаблона
$csrf_token = generateCsrfToken();
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
$titlemeta = 'Настройки для ИИ';

// Закрываем соединение при завершении скрипта
register_shutdown_function(function() {
    if (isset($pdo)) {
        $pdo = null; 
    }
});
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= escape($csrf_token) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Админ-панель управления системой">
    <meta name="author" content="Админ-панель">
    <title><?= escape($titlemeta) ?></title>
    <!-- Переключение темы (сохраняется в localStorage) -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Кастомные стили (включая поддержку темной/светлой темы) -->
    <link rel="stylesheet" href="../css/main.css">
    <!-- Favicon -->
    <link rel="icon" href="<?= escape($logo_profile) ?>" type="image/x-icon">
</head>

<body>
    <div class="container-fluid">
        <!-- Боковое меню -->
        <?php require_once __DIR__ . '/../template/sidebar.php'; ?>

        <!-- Основной контент -->
        <main class="main-content">
            <!-- Верхняя панель -->
            <?php require_once __DIR__ . '/../template/header.php'; ?>

            <!-- Отображение сообщений -->
            <?php displayAlerts($successMessages, $errors); ?>

            <form action="" method="post">
                <!-- CSRF Protection -->
                <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">

                <div class="form-section mb-5">
                    <!-- Поле API-ключа -->
                    <div class="row mb-5">
                        <div class="col-12">
                            <h3 class="card-title">
                                <i class="bi bi-key"></i> API-ключ DeepSeek
                            </h3>
                            <input type="text"
                                   class="form-control"
                                   name="deepseek_api_key"
                                   minlength="1" maxlength="50"
                                   placeholder="sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                   value="<?= escape($apiKey) ?>">
                            <div class="form-text">
                                Введите действительный API-ключ от <a href="https://platform.deepseek.com" target="_blank">DeepSeek</a>.
                                Оставьте пустым, чтобы удалить.
                            </div>
                        </div>
                    </div>

                    <!-- Блок баланса -->
                    <div class="row">
                        <div class="col-12">
                            <h3 class="card-title">
                                <i class="bi bi-wallet2"></i> Баланс аккаунта
                            </h3>
                            <?php if (!empty($apiKey)): ?>
                                <?php if ($balanceError): ?>
                                    <div class="alert alert-danger">
                                        <strong>Ошибка получения баланса:</strong><br>
                                        <?= escape($balanceError) ?>
                                    </div>
                                <?php elseif ($balanceInfo !== null): ?>
                                    <p><strong>Текущий баланс:</strong> $<?= htmlspecialchars(number_format((float)$balanceInfo, 4), ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php else: ?>
                                    <p class="text-muted">Баланс не найден в ответе API.</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-muted">Укажите API-ключ, чтобы увидеть баланс.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input"
                                   type="checkbox"
                                   id="status_gpt"
                                   name="status_gpt"
                                   <?= $status_gpt ? 'checked' : '' ?>>
                            <label class="form-check-label" for="status_gpt"><?= escape('Нет/Да') ?></label>
                        </div>
                        <div class="form-text"><?= escape('Активировать работу ИИ') ?></div>
                    </div>
                </div>

                <!-- Кнопка отправки -->
                <div class="mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Сохранить
                    </button>
                </div>
            </form>
        </main>
    </div>

    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script type="module" src="../js/main.js"></script>
</body>
</html>
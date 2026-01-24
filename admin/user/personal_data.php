<?php
/**
 * Файл: /admin/user/personal_data.php
 *
 * Страница редактирования профиля пользователя
 *
 * Основные функции:
 * - Обновление личных данных пользователя (имя, фамилия, email, телефон)
 * - Смена пароля с валидацией
 * - Загрузка и управление аватаром через медиа-библиотеку
 * - Валидация данных на стороне сервера и клиента
 * - Логирование изменений и ошибок
 * - Защита от CSRF атак
 *
 * Особенности безопасности:
 * - Экранирование всех выводимых данных
 * - Валидация email и пароля
 * - Хеширование паролей
 * - Защита от XSS
 * - Логирование операций
 */

// === Безопасные настройки отображения ошибок (ТОЛЬКО в разработке!) ===
/** 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
*/

// Запретить прямой доступ ко всем .php файлам
define('APP_ACCESS', true);

// Устанавливаем кодировку
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
header('Content-Type: text/html; charset=utf-8');

// Подключаем системные компоненты
require_once $_SERVER['DOCUMENT_ROOT'] . '/connect/db.php';          // База данных
require_once __DIR__ . '/../functions/auth_check.php';               // Авторизация и получения данных пользователей
require_once __DIR__ . '/../functions/file_log.php';                 // Система логирования
require_once __DIR__ . '/../functions/jsondata.php';                 // Обновление JSON данных пользователя
require_once __DIR__ . '/../functions/display_alerts.php';           // Отображения сообщений
require_once __DIR__ . '/../functions/sanitization.php';             // Валидация экранирование 
require_once __DIR__ . '/../functions/mailer.php';                   // Отправка email уведомлений

// Безопасный запуск сессии
startSessionSafe();

// Получаем настройки администратора
$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    exit;
}

// Название Админ-панели
$AdminPanel = $adminData['AdminPanel'] ?? 'AdminPanel';

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);    // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);    // Логировать ошибки true/false

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
    // Проверка ошибки
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg = $userDataAdmin['message'];
        $level = $userDataAdmin['level']; // 'info' или 'error'
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
        // Закрываем соединение при завершении скрипта
        register_shutdown_function(function() {
            if (isset($pdo)) {
                $pdo = null; 
            }
        });
        header("Location: ../logout.php");        
        exit;
    }

    // Успех
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

} catch (Exception $e) {
    logEvent("Ошибка при инициализации страницы профиля: " . $e->getMessage() . " — ID пользователя: " . ($user['id'] ?? 'unknown') . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    header("Location: ../logout.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Валидация CSRF токена
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// === ЗАГРУЗКА ТЕКУЩИХ ДАННЫХ ПОЛЬЗОВАТЕЛЯ ДО ОБРАБОТКИ ФОРМЫ ===
$defaultFirstName = '';
$defaultLastName = '';
$defaultPhone = '';
$profile_images = '';
$images = '';
$defaultEmail = '';

try {
    $userData = getUserData($pdo, $user['id']);
    if (!$userData) {
        throw new Exception("User data not found");
    }
    $currentData = json_decode($userData['data'] ?? '{}', true) ?? [];
    $defaultFirstName = $currentData['first_name'] ?? '';
    $defaultLastName = $currentData['last_name'] ?? '';
    $defaultPhone = $currentData['phone'] ?? '';
    $profile_images = $currentData['profile_images'] ?? '';
    $images = $currentData['images'] ?? '';
    $defaultEmail = $userData['email'] ?? '';
} catch (Exception $e) {
    logEvent("Не удалось загрузить данные пользователя для формы профиля: " . $e->getMessage() . " — ID пользователя: " . $user['id'] . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
}

// Инициализируем переменные для сообщений
$errors = [];
$successMessages = [];

// Обработка сообщений после перенаправления (например, из confirm_email.php)
if (!empty($_SESSION['alerts'])) {
    foreach ($_SESSION['alerts'] as $alert) {
        if ($alert['type'] === 'success') {
            $successMessages[] = $alert['message'];
        } else {
            $errors[] = $alert['message'];
        }
    }
    unset($_SESSION['alerts']);
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Валидация CSRF токена
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Пожалуйста, обновите страницу.';
        logEvent("Проверка CSRF-токена не пройдена — ID пользователя: " . $user['id'] . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    } else {
        // Получаем и очищаем данные из формы
        $password = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirmPassword'] ?? '');

        /**
         * РАСШИРЕННАЯ ВАЛИДАЦИЯ
         */
        if (empty($errors)) {

            // Валидация строки вида "123,456,789"
            $maxDigits = 1; // Макс. количество элементов
            $result_images = validateIdList(trim($_POST['profile_images'] ?? ''), $maxDigits);
            if ($result_images['valid']) {
                $profile_images = $result_images['value']; // '123,456,789,0001'
            } else {
                $errors[] = $result_images['error'];
                $profile_images = false;
            }

            // Валидация текстового поля (имя)
            $resultfirst = validateNameField(trim($_POST['firstName'] ?? ''), 2, 50, 'Имя');
            if ($resultfirst['valid']) {
                $firstName = ($resultfirst['value']);
            } else {
                $errors[] = ($resultfirst['error']);
                $firstName = false;
            }

            // Валидация текстового поля (фамилия)
            $resultlast = validateNameField(trim($_POST['lastName'] ?? ''), 2, 50, 'Фамилия');
            if ($resultlast['valid']) {
                $lastName = ($resultlast['value']);
            } else {
                $errors[] = ($resultlast['error']);
                $lastName = false;
            }

            // Валидация email-адреса
            $result_email = validateEmail(trim($_POST['email'] ?? ''));
            if ($result_email['valid']) {
                $email =  $result_email['email'];
            } else {
                $errors[] = $result_email['error'];
                $email = false;
            }

            // Валидация телефонного номера
            $resultPhone = validatePhone(trim($_POST['phone'] ?? ''));
            if ($resultPhone['valid']) {
                $phone = $resultPhone['value'] ?? '';
            } else {
                $errors[] = $resultPhone['error'];
                $phone = false;
            }

            // Пароль и подтверждение (если задан хотя бы один)
            $passwordFilled = !empty($password) || !empty($confirmPassword);
            if ($passwordFilled && empty($errors)) {

                // Валидация пароля
                $result_pass = validatePassword(trim($_POST['password'] ?? ''));
                if ($result_pass['valid']) {
                    $password = $result_pass['value'];
                } else {
                    $errors[] = $result_pass['error'];
                    $password = false;
                } 

                if (empty($errors) && !empty($password) && !empty($confirmPassword) && $password !== $confirmPassword) {
                    $errors[] = "Пароли не совпадают!";
                }
            }
        }

        // Проверка уникальности email (кроме текущего пользователя)
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $stmt->execute([$email, $user['id']]);
                if ($stmt->fetch()) {
                    $errors[] = 'Этот email адрес уже используется другим пользователем.';
                }
            } catch (PDOException $e) {
                $errors[] = 'Ошибка при проверке email. Пожалуйста, попробуйте позже.';
                logEvent("Ошибка базы данных при проверке уникальности email: " . $e->getMessage() . " — ID пользователя: " . $user['id'] . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
            }
        }

        // === Обновление в БД только если ошибок нет ===
        if (empty($errors)) {
            try {
                // Проверяем, меняется ли email
                $emailChanged = ($email !== $defaultEmail);

                // Подготавливаем данные для JSON
                $updateData = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'profile_images' => $profile_images
                ];
                $jsonData = updateUserJsonData($pdo, $user['id'], $updateData);

                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($hash === false) {
                        throw new Exception("Не удалось захешировать пароль");
                    }
                }

                if ($emailChanged) {
                    // Генерируем уникальный токен (безопасный)
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                    // Сохраняем временные данные
                    if (!empty($password)) {
                        $update = $pdo->prepare("UPDATE users SET password = ?, data = ?, pending_email = ?, email_change_token = ?, email_change_expires = ?, updated_at = NOW() WHERE id = ?");
                        $update->execute([$hash, $jsonData, $email, $token, $expires, $user['id']]);
                    } else {
                        $update = $pdo->prepare("UPDATE users SET data = ?, pending_email = ?, email_change_token = ?, email_change_expires = ?, updated_at = NOW() WHERE id = ?");
                        $update->execute([$jsonData, $email, $token, $expires, $user['id']]);
                    }

                    // Формируем ссылку подтверждения
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'];
                    $confirmLink = $protocol . '://' . $host . '/admin/user/confirm_email.php?token=' . urlencode($token);

                    // Отправляем письмо через mailer.php
                    $adminPanelName = $adminData['AdminPanel'] ?? 'AdminPanel';
                    if (sendEmailChangeConfirmationLink($email, $confirmLink, $adminPanelName, $AdminPanel)) {
                        $successMessages[] = 'Данные успешно обновлены! На новый email отправлена ссылка для подтверждения. После подтверждения email будет изменён.';
                    } else {
                        $successMessages[] = 'Данные обновлены, но не удалось отправить письмо. Email будет изменён после подтверждения по ссылке.';
                    }

                    logEvent("Запрошена смена email для ID: " . $user['id'] . " (старый: $defaultEmail → новый: $email)", LOG_INFO_ENABLED, 'info');

                } else {
                    // Email НЕ меняется — обновляем как обычно
                    if (!empty($password)) {
                        $update = $pdo->prepare("UPDATE users SET email = ?, password = ?, data = ?, updated_at = NOW() WHERE id = ?");
                        $update->execute([$email, $hash, $jsonData, $user['id']]);
                    } else {
                        $update = $pdo->prepare("UPDATE users SET email = ?, data = ?, updated_at = NOW() WHERE id = ?");
                        $update->execute([$email, $jsonData, $user['id']]);
                    }

                    $successMessages[] = !empty($password) ? 'Данные и пароль успешно обновлены!' : 'Данные успешно обновлены!';
                    logEvent("Профиль обновлён — ID: " . $user['id'] . ", Email: " . $email, LOG_INFO_ENABLED, 'info');
                }

                // Обновляем сессию — email остаётся старым до подтверждения!
                if (isset($_SESSION['user_email'])) {
                    $_SESSION['user_email'] = $defaultEmail;
                }

            } catch (PDOException $e) {
                $errors[] = 'Ошибка при обновлении данных. Пожалуйста, попробуйте позже.';
                logEvent("Ошибка БД в personal_data.php: " . $e->getMessage() . " — ID: " . $user['id'], LOG_ERROR_ENABLED, 'error');
            } catch (JsonException $e) {
                $errors[] = 'Ошибка при обработке данных. Пожалуйста, попробуйте позже.';
                logEvent("Ошибка JSON в personal_data.php: " . $e->getMessage() . " — ID: " . $user['id'], LOG_ERROR_ENABLED, 'error');
            } catch (Exception $e) {
                $errors[] = 'Неизвестная ошибка. Пожалуйста, попробуйте позже.';
                logEvent("Исключение в personal_data.php: " . $e->getMessage() . " — ID: " . $user['id'], LOG_ERROR_ENABLED, 'error');
            }
        }
    }

    // Закрываем соединение при завершении скрипта
    register_shutdown_function(function() {
        if (isset($pdo)) {
            $pdo = null; 
        }
    });
    // Перезагрузка при бновлении
    header("Location: personal_data.php");
    exit;
}

// Проверка CSRF токена для AJAX запросов
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Получает логотип
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
// Название раздела
$titlemeta = 'Редактирование профиля';

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
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Админ-панель управления системой">
    <meta name="author" content="Админ-панель">
    <title><?php echo escape($titlemeta); ?></title>
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
    <!-- Подключение кастомных стилей -->
    <link rel="stylesheet" href="../css/main.css">
    <!-- Медиа-библиотека -->
    <link rel="stylesheet" href="../user_images/css/main.css">
    <link rel="icon" href="<?php echo escape($logo_profile); ?>" type="image/x-icon">
</head>
<body>
    <div class="container-fluid">
        <?php require_once __DIR__ . '/../template/sidebar.php'; ?>
        <main class="main-content">
            <?php require_once __DIR__ . '/../template/header.php'; ?>

            <form action="" method="post" enctype="multipart/form-data" id="profileForm" novalidate>

                <div class="form-section">
                    <!-- Отображения сообщений -->
                    <?php displayAlerts($successMessages, $errors); ?>

                    <!-- CSRF-токен -->
                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="firstName" class="form-label">Имя <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control"
                                   id="firstName"
                                   name="firstName"
                                   placeholder="Введите имя"
                                   required
                                   maxlength="50"
                                   pattern="[а-яА-ЯёЁa-zA-Z\s\-ʼ’']{2,50}"
                                   title="Только буквы, пробелы, дефисы и апострофы. Минимум 2 символа."
                                   value="<?= escape($firstName ?? $defaultFirstName) ?>">
                            <div class="form-text">Максимум 50 символов</div>
                        </div>
                        <div class="col-md-6">
                            <label for="lastName" class="form-label">Фамилия <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control"
                                   id="lastName"
                                   name="lastName"
                                   placeholder="Введите фамилию"
                                   required
                                   maxlength="50"
                                   pattern="[а-яА-ЯёЁa-zA-Z\s\-ʼ’']{2,50}"
                                   title="Только буквы, пробелы, дефисы и апострофы. Минимум 2 символа."
                                   value="<?= escape($lastName ?? $defaultLastName) ?>">
                            <div class="form-text">Максимум 50 символов</div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="phone" class="form-label">Телефон</label>
                            <input type="tel"
                                   class="form-control"
                                   id="phone"
                                   name="phone"
                                   placeholder="+7 (XXX) XXX-XX-XX или + Код Страны Номер"
                                   maxlength="30"
                                   value="<?= escape($phone ?? $defaultPhone) ?>">
                            <div class="form-text">Формат: +7 (XXX) XXX-XX-XX или + Код Страны Номер</div>
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email адрес <span class="text-danger">*</span></label>
                            <input type="email"
                                   class="form-control"
                                   id="email"
                                   name="email"
                                   placeholder="your@email.com"
                                   required
                                   maxlength="254"
                                   value="<?= escape($email ?? $defaultEmail) ?>">
                            <div class="form-text">Максимум 254 символов</div>
                        </div>
                    </div>

                    <div class="row mb-5">
                        <div class="col-md-6">
                            <label for="password" class="form-label">Новый пароль</label>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control"
                                       id="password"
                                       name="password"
                                       placeholder="Введите новый пароль"
                                       minlength="6"
                                       maxlength="128"
                                       autocomplete="new-password">
                                <button type="button" class="password-toggle">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">Минимум 6 символов</div>
                        </div>
                        <div class="col-md-6">
                            <label for="confirmPassword" class="form-label">Подтвердите новый пароль</label>
                            <div class="input-group">
                                <input type="password"
                                       class="form-control"
                                       id="confirmPassword"
                                       name="confirmPassword"
                                       placeholder="Повторите новый пароль"
                                       minlength="6"
                                       maxlength="128"
                                       autocomplete="new-password">
                                <button type="button" class="password-toggle">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">Подтвердите новый пароль</div>
                        </div>
                    </div>

                    <?php if ($adminData['allow_photo_upload'] === true || $userDataAdmin['author'] == 'admin') { ?>
               
                        <div class="mb-3">
                            <h3 class="card-title">
                                <i class="bi bi-card-image"></i>
                                Аватар профиля
                            </h3>
                            <!---------------------------------------------------- Галерея №1 ---------------------------------------------------->
                            <?php
                            // Настройка галереи
                            $sectionId = 'profile_images';         // Уникальное имя галереи
                            $image_ids = $profile_images;          // ID изображений галереи

                            // Лимит загрузки файлов на пользователя
                            $_SESSION['max_files_per_user'] = $adminData['image_limit'] ?? 0; 

                            // Настройки размеров изображений: [ширина, высота, режим]
                            $imageSizes = [
                                "thumbnail" => [100, 100, "cover"],
                                "small"     => [300, 'auto', "contain"], // Обязательное имя small
                                "medium"    => [600, 'auto', "contain"],
                                "large"     => [1200, 'auto', "contain"]
                            ];

                            // Сохраняем настройки в сессии
                            $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;
                            ?>

                            <!-- Скрытое поле для выбранных изображений  №1-->
                            <input type="hidden" id="selectedImages_<?php echo $sectionId; ?>" name="<?php echo $sectionId; ?>" value="<?php echo isset($image_ids) ? $image_ids : ''; ?>">
                            <div id="image-management-section-<?php echo $sectionId; ?>">

                                <!-- Контент будет загружен сюда  №1 -->
                                <div id="loading-content-<?php echo $sectionId; ?>"></div>

                                <button type="button" class="btn btn-outline-primary load-more-files" data-bs-toggle="modal" data-bs-target="#<?php echo $sectionId; ?>" onclick="storeSectionId('<?php echo $sectionId; ?>')">
                                    <i class="bi bi-plus-circle me-1"></i> Добавить медиа файл
                                </button>
                                <div class="form-text">Не более 1 шт</div>
                            </div>

                            <!-- Модальные окна с библиотекай файлов -->
                            <div class="modal fade" id="<?php echo $sectionId; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-fullscreen-custom">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Библиотека файлов</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                                        </div>
                                        <div class="modal-body">
                                            <!-- Контейнер для уведомлений -->
                                            <div id="notify_<?php echo $sectionId; ?>"></div>

                                            <!-- Контейнер галереи -->
                                            <div id="image-management-section_<?php echo $sectionId; ?>"></div>

                                            <!-- Скрытый input для загрузки файлов -->
                                            <input type="file" id="fileInput_<?php echo $sectionId; ?>" multiple accept="image/*" style="display: none;">

                                            <!-- Модальное окно редактирования метаданных фото -->
                                            <?php require_once __DIR__ . '/../user_images/photo_info.php'; ?>
                                            <!-- /Галерея №1 -->
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                                            <button type="button" id="saveButton" class="btn btn-primary" 
                                                    data-section-id="<?php echo htmlspecialchars($sectionId, ENT_QUOTES); ?>"
                                                    onclick="handleSelectButtonClick()"
                                                    data-bs-dismiss="modal">
                                                Выбрать
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!---------------------------------------------------- /Галерея №1 ---------------------------------------------------->
                        </div>

                    <?php } ?>

                </div>

                <div class="mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Сохранить изменения
                    </button>
                </div>
            </form>

        </main>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Модульный JS admin -->
    <script type="module" src="../js/main.js"></script>
    <!-- Модульный JS галереи -->
    <script type="module" src="../user_images/js/main.js"></script>

    <!-- Инициализация галереи -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {

        // Загружаем галерею при старте №1
        loadGallery('profile_images');

        // Загружаем библиотеку файлов
        loadImageSection('profile_images');

    });
    </script>
</body>
</html>
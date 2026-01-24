<?php
/**
 * Файл: /admin/user/add_account.php
 * 
 * Админ-панель - Добавление/редактирование аккаунта
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
require_once __DIR__ . '/../functions/mailer.php';                   // Отправка email уведомлений 
require_once __DIR__ . '/../functions/sanitization.php';             // Валидация экранирование 

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
        header("Location: ../logout.php");
        exit;
    }
    
    // Успех
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

    // Закрываем страницу от user
    // Перенаправляем на страницу входа если не Admin
    if ($userDataAdmin['author'] !== 'admin') {
        header("Location: ../logout.php");
        exit;
    }

} catch (Exception $e) {
    logEvent("Ошибка при инициализации админ-панели: " . $e->getMessage() . " — ID админа: " . ($user['id'] ?? 'unknown') . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    header("Location: ../logout.php");
    exit;
}

// Валидация CSRF токена для формы
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Генерация случайного пароля
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

$errors = [];
$successMessages = [];
$isEditMode = isset($_GET['id']);
$userId = $isEditMode ? (int)$_GET['id'] : null;
$redirectAfterCreate = false;
$newUserId = null;

// Загрузка данных пользователя для редактирования
$userData = null;
$defaultNameFirst = '';
$defaultLastName = '';
$defaultPhone = '';
$defaultEmail = '';
$defaultStatus = 1;
$defaultCustomField = '';

if ($isEditMode && $userId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userData) {
            $currentDatauser = json_decode($userData['data'] ?? '{}', true) ?? [];
            $defaultNameFirst = $currentDatauser['first_name'] ?? '';
            $defaultLastName = $currentDatauser['last_name'] ?? '';
            $defaultPhone = $currentDatauser['phone'] ?? '';
            $profile_images = $currentDatauser['profile_images'] ?? '';
            $defaultEmail = $userData['email'] ?? '';
            $defaultStatus = $userData['status'] ?? 1;
            $defaultCustomField = $currentDatauser['custom_field'] ?? '';
        } else {
            $errors[] = 'Пользователь не найден';
        }
    } catch (PDOException $e) {
        $errors[] = 'Ошибка при загрузке данных пользователя';
        logEvent("Ошибка базы данных при загрузке данных пользователя с ID $userId: " . $e->getMessage() . " — ID админа: " . $user['id'] . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    }
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Валидация CSRF токена
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Пожалуйста, обновите страницу.';
        logEvent("Проверка CSRF-токена не пройдена — ID админа: " . $user['id'] . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
    } else {

        // Получаем и очищаем данные из формы
        $phone = trim($_POST['phone'] ?? '');
        $status = isset($_POST['status']) ? 1 : 0;
        $customField = trim($_POST['custom_field'] ?? '');

        // Валидация строки вида "123,456,789"
        $maxDigits = 1; // Макс. количество элементов
        $result_images = validateIdList(trim($_POST['profile_images'] ?? ''), $maxDigits);
        if ($result_images['valid']) {
            $profile_images = $result_images['value']; // '123,456,789,0001'
        } else {
            $errors[] = $result_images['error'];
            $profile_images = false;
        }

        // === РАСШИРЕННАЯ ВАЛИДАЦИЯ (как в personal_data.php) ===
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

        // Телефон (если указан)
        if (empty($errors) && !empty($phone)) {
            // Валидация телефонного номера
            $resultPhone = validatePhone(trim($_POST['phone'] ?? ''));
            if ($resultPhone['valid']) {
                $phone = $resultPhone['value'] ?? '';
            } else {
                $errors[] = $resultPhone['error'];
                $phone = false;
            }
        }

        // Проверка уникальности email (кроме текущего пользователя при редактировании)
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?" . ($isEditMode && $userId ? " AND id != ?" : ""));
                $params = [$email];
                if ($isEditMode && $userId) {
                    $params[] = $userId;
                }
                $stmt->execute($params);
                if ($stmt->fetch()) {
                    $errors[] = 'Этот email адрес уже используется другим пользователем.';
                }
            } catch (PDOException $e) {
                $errors[] = 'Ошибка при проверке email. Пожалуйста, попробуйте позже.';
                logEvent("Ошибка базы данных при проверке уникальности email: " . $e->getMessage() . " — ID админа: " . $user['id'] . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
            }
        }

        // Дополнительная информация (если указана)
        if (empty($errors) && !empty($customField)) {
            // Валидация текстового поля (Дополнительная информация)
            $resultield = validateTextareaField($customField, 1, 300, 'Дополнительная информация');
            if ($resultield['valid']) {
                $customField = ($resultield['value']);
            } else {
                $errors[] = ($resultield['error']);
                $customField = false;
            }
        }

        // === Сохранение, если нет ошибок ===
        if (empty($errors)) {
            try {
                $userJsonData = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'profile_images' => $profile_images,
                    'custom_field' => $customField
                ];

                $jsonData = updateUserJsonData($pdo, $user['id'], $userJsonData);

                if ($isEditMode && $userId) {
                    // Режим обновления
                    $emailChanged = ($email !== $defaultEmail);
                    if ($emailChanged) {
                        $generatedPassword = generateRandomPassword();
                        $hash = password_hash($generatedPassword, PASSWORD_DEFAULT);
                        $update = $pdo->prepare("UPDATE users SET email = ?, password = ?, data = ?, status = ?, updated_at = NOW() WHERE id = ?");
                        $update->execute([$email, $hash, $jsonData, $status, $userId]);

                        if (sendAccountEmail($email, $generatedPassword, $firstName, $firstName)) {
                            $successMessages[] = 'Данные успешно обновлены! Новый пароль отправлен на email.';
                            logEvent("Аккаунт обновлён, email с паролем отправлен: ID админа {$user['id']}, ID пользователя $userId, Email: " . $email . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_INFO_ENABLED, 'info');
                        } else {
                            $successMessages[] = 'Данные успешно обновлены, но не удалось отправить email с паролем.';
                            logEvent("Аккаунт обновлён, но email не отправлен: ID админа {$user['id']}, ID пользователя $userId, Email: " . $email . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_INFO_ENABLED, 'info');
                        }
                    } else {
                        $update = $pdo->prepare("UPDATE users SET email = ?, data = ?, status = ?, updated_at = NOW() WHERE id = ?");
                        $update->execute([$email, $jsonData, $status, $userId]);
                        $successMessages[] = 'Данные успешно обновлены!';
                        logEvent("Аккаунт пользователя обновлён: ID админа {$user['id']}, ID пользователя $userId, Email: " . $email . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_INFO_ENABLED, 'info');
                    }
                } else {
                    // Режим добавления
                    $generatedPassword = generateRandomPassword();
                    $hash = password_hash($generatedPassword, PASSWORD_DEFAULT);
                    $insert = $pdo->prepare("INSERT INTO users (author, email, password, email_verified, data, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $insert->execute(['user', $email, $hash, 1, $jsonData, $status]);
                    $newUserId = $pdo->lastInsertId();

                    if (sendAccountEmail($email, $generatedPassword, $AdminPanel, $firstName)) {
                        $successMessages[] = 'Аккаунт успешно создан! Пароль отправлен на email.';
                        logEvent("Создан новый аккаунт, email с паролем отправлен: ID админа {$user['id']}, новый ID пользователя $newUserId, Email: " . $email . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_INFO_ENABLED, 'info');
                    } else {
                        $successMessages[] = 'Аккаунт успешно создан, но не удалось отправить email с паролем.';
                        logEvent("Создан новый аккаунт, но email не отправлен: ID админа {$user['id']}, новый ID пользователя $newUserId, Email: " . $email . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_INFO_ENABLED, 'info');
                    }
                    $redirectAfterCreate = true;
                }
            } catch (PDOException $e) {
                $errors[] = 'Ошибка при сохранении данных. Пожалуйста, попробуйте позже.';
                logEvent("Ошибка базы данных при " . ($isEditMode ? 'обновлении' : 'создании') . " аккаунта: " . $e->getMessage() . " — ID админа: " . $user['id'] . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
            } catch (JsonException $e) {
                $errors[] = 'Ошибка при обработке данных. Пожалуйста, попробуйте позже.';
                logEvent("Ошибка кодирования JSON: " . $e->getMessage() . " — ID админа: " . $user['id'] . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
            } catch (Exception $e) {
                $errors[] = 'Неизвестная ошибка. Пожалуйста, попробуйте позже.';
                logEvent("Неожиданная ошибка: " . $e->getMessage() . " — ID админа: " . $user['id'] . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), LOG_ERROR_ENABLED, 'error');
            }
        }
    }
}

// Проверка CSRF токена для AJAX запросов
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Получает логотип
$logo_profile = getFileVersionFromList($pdo, $currentData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg');
$titlemeta = 'Управления пользователями';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= escape($_SESSION['csrf_token']) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Админ-панель управления системой">
    <meta name="author" content="Админ-панель">
    <title><?= escape($titlemeta) ?></title>
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

            <form action="" method="post" enctype="multipart/form-data" id="accountForm" novalidate>

                <div class="form-section mb-5">
                    <h3 class="card-title">
                        <i class="bi <?= $isEditMode ? 'bi-pencil-square' : 'bi-person-plus' ?>" aria-hidden="true"></i>
                        <?= $isEditMode ? escape('Редактирование аккаунта') : escape('Добавление нового аккаунта') ?>
                    </h3>

                    <!-- Отображение сообщений -->
                    <?php displayAlerts($successMessages, $errors, 'escape'); ?>

                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="firstName" class="form-label">Имя<span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control"
                                   id="firstName"
                                   name="firstName"
                                   placeholder="Введите имя"
                                   required
                                   maxlength="50"
                                   pattern="[а-яА-ЯёЁa-zA-Z\s\-ʼ’']{2,50}"
                                   title="Только буквы, пробелы, дефисы и апострофы. Минимум 2 символа."
                                   value="<?= escape($firstName ?? $defaultNameFirst) ?>">
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
                            <div class="form-text">Максимум 254 символа</div>
                        </div>
                    </div>

                    <div class="row mb-5">
                        <div class="col-12">
                            <label for="custom_field" class="form-label">Дополнительная информация</label>
                            <textarea class="form-control"
                                      id="custom_field"
                                      name="custom_field"
                                      rows="3"
                                      placeholder="Введите дополнительную информацию"
                                      maxlength="300"><?= escape($customField ?? $defaultCustomField) ?></textarea>
                            <div class="form-text">Максимум 300 символов</div>
                        </div>
                    </div>

                    <?php if ($adminData['allow_photo_upload'] === true) { ?>
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

                <div class="row mb-3">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input"
                                   type="checkbox"
                                   id="status"
                                   name="status"
                                   value="1"
                                   <?= ($status ?? $defaultStatus) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="status"><?= escape('Нет/Да') ?></label>
                        </div>
                        <div class="form-text"><?= escape('Аккаунт будет доступен для входа в систему') ?></div>
                    </div>
                </div>

                <div class="mb-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        <?= escape($isEditMode ? 'Сохранить изменения' : 'Создать аккаунт') ?>
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
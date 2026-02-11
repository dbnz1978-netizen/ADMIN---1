<?php

/**
 * Название файла:      add_account.php
 * Назначение:          Админ-панель — Добавление и редактирование пользовательских аккаунтов
 *                      Основные функции:
 *                      - Создание нового аккаунта пользователя (роль "user")
 *                      - Редактирование существующего аккаунта
 *                      - Генерация случайного пароля при создании или смене email
 *                      - Отправка email с учётными данными
 *                      - Управление аватаром через медиа-библиотеку
 *                      - Валидация всех полей формы (имя, фамилия, email, телефон, доп. поле)
 *                      - Проверка уникальности email
 *                      - Защита от CSRF-атак
 *                      - Логирование всех операций
 * Автор:               User
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ СКРИПТА
// ========================================

$config = [
    'display_errors' => false,  // Включение отображения ошибок (true/false)
    'set_encoding'   => true,   // Включение кодировки UTF-8
    'db_connect'     => true,   // Подключение к базе данных
    'auth_check'     => true,   // Подключение функций авторизации
    'file_log'       => true,   // Подключение системы логирования
    'display_alerts' => true,   // Подключение отображения сообщений
    'sanitization'   => true,   // Подключение валидации/экранирования
    'mailer'         => true,   // Подключение отправки email уведомлений
    'jsondata'       => true,   // Подключение обновления JSON данных пользователя
    'csrf_token'     => true,   // Генерация CSRF-токена
    'start_session'  => true,   // Запуск Session
    'image_sizes'    => true,   // Подключение модуля управления размерами изображений
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';


// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

$adminData = getAdminData($pdo);
if ($adminData === false) {
    // Ошибка: администратор не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ СТРАНИЦЫ
// ========================================

$userData           = null;
$defaultNameFirst   = '';
$defaultLastName    = '';
$defaultPhone       = '';
$defaultEmail       = '';
$defaultStatus      = 1;
$defaultCustomField = '';
$profileImages      = '';
$maxDigits          = 2;  // Ограничение на количество изображений
$adminPanel         = $adminData['AdminPanel'] ?? 'AdminPanel';  // Название админ-панели для отправки email
$titlemeta          = 'Пользователи';  // Заголовок страницы

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled']  ?? false) === true);  // Логировать успешные события true/false
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true);  // Логировать ошибки true/false

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ И ПОЛУЧЕНИЕ ДАННЫХ ПОЛЬЗОВАТЕЛЯ
// ========================================

try {
    // Проверка авторизации
    $user = requireAuth($pdo);
    if (!$user) {
        $redirectTo = '../logout.php';
        $logMessage = "Неавторизованный доступ — перенаправление на: $redirectTo — IP: "
            . "{$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}";
        logEvent($logMessage, LOG_INFO_ENABLED, 'info');

        header("Location: $redirectTo");
        exit;
    }

    // Получение данных текущего администратора
    $userDataAdmin = getUserData($pdo, $user['id']);
    
    // Проверка ошибки
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg        = $userDataAdmin['message'];
        $level      = $userDataAdmin['level'];  // 'info' или 'error'
        $logEnabled = match ($level) {
            'info'  => LOG_INFO_ENABLED,
            'error' => LOG_ERROR_ENABLED,
            default => LOG_ERROR_ENABLED,
        };
        logEvent($msg, $logEnabled, $level);
        header("Location: ../logout.php");
        exit;
    }

    // Декодируем JSON-данные администратора
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];

    // Закрываем страницу от обычных пользователей
    if ($userDataAdmin['author'] !== 'admin') {
        header("Location: ../logout.php");
        exit;
    }

} catch (Exception $e) {
    $logMessage = "Ошибка при инициализации админ-панели: " . $e->getMessage()
        . " — ID админа: " . ($user['id'] ?? 'unknown')
        . " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    logEvent($logMessage, LOG_ERROR_ENABLED, 'error');
    header("Location: ../logout.php");
    exit;
}

// ========================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ========================================

/**
 * Генерирует случайный надёжный пароль заданной длины
 *
 * @param int $length Длина пароля (по умолчанию 12)
 * @return string Сгенерированный пароль
 */
function generateRandomPassword($length = 12) {
    $chars    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// ========================================
// ОПРЕДЕЛЕНИЕ РЕЖИМА РАБОТЫ
// ========================================

$isEditMode         = isset($_GET['id']);
$userId             = $isEditMode ? (int) $_GET['id'] : null;
$redirectAfterCreate = false;
$newUserId          = null;

// ========================================
// ЗАГРУЗКА FLASH-СООБЩЕНИЙ ИЗ СЕССИИ
// ========================================

$successMessages = [];
$errors          = [];

// Загружаем flash-сообщения из сессии (если есть)
if (!empty($_SESSION['flash_messages'])) {
    $successMessages = $_SESSION['flash_messages']['success'] ?? [];
    $errors          = $_SESSION['flash_messages']['error'] ?? [];
    // Удаляем их, чтобы не показывались при повторной загрузке
    unset($_SESSION['flash_messages']);
}

// ========================================
// ЗАГРУЗКА ДАННЫХ ПРИ РЕДАКТИРОВАНИИ
// ========================================

if ($isEditMode && $userId) {
    try {
        $stmt     = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userData) {
            $currentDatauser  = json_decode($userData['data'] ?? '{}', true) ?? [];
            $defaultNameFirst = $currentDatauser['first_name'] ?? '';
            $defaultLastName  = $currentDatauser['last_name'] ?? '';
            $defaultPhone     = $currentDatauser['phone'] ?? '';
            $profileImages    = $currentDatauser['profile_images'] ?? '';
            $defaultEmail     = $userData['email'] ?? '';
            $defaultStatus    = $userData['status'] ?? 1;
            $defaultCustomField = $currentDatauser['custom_field'] ?? '';
        } else {
            $errors[] = 'Пользователь не найден';
        }
    } catch (PDOException $e) {
        $errors[] = 'Ошибка при загрузке данных пользователя';
        logEvent(
            "Ошибка базы данных при загрузке данных пользователя с ID $userId: " . $e->getMessage() .
            " — ID админа: " . $user['id'] .
            " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            LOG_ERROR_ENABLED,
            'error'
        );
    }
}

// ========================================
// ОБРАБОТКА ФОРМЫ
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ========================================
    // ВАЛИДАЦИЯ CSRF ТОКЕНА
    // ========================================
    
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Пожалуйста, обновите страницу.';
        logEvent(
            "Проверка CSRF-токена не пройдена — ID админа: " . $user['id'] .
            " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            LOG_ERROR_ENABLED,
            'error'
        );
    } else {
        
        // ========================================
        // ПОЛУЧЕНИЕ И ОЧИСТКА ДАННЫХ ИЗ ФОРМЫ
        // ========================================
        
        $phone       = trim($_POST['phone'] ?? '');
        $status      = isset($_POST['status']) ? 1 : 0;
        $customField = trim($_POST['custom_field'] ?? '');

        // ========================================
        // ВАЛИДАЦИЯ СТРОКИ ID ИЗОБРАЖЕНИЙ
        // ========================================
        
        // Валидация строки вида "123,456,789" для аватара
        $resultImages = validateIdList(trim($_POST['profile_images'] ?? ''), $maxDigits);
        if ($resultImages['valid']) {
            $profileImages = $resultImages['value'];  // '123,456,789,0001'
        } else {
            $errors[]      = $resultImages['error'];
            $profileImages = false;
        }

        // ========================================
        // РАСШИРЕННАЯ ВАЛИДАЦИЯ ПОЛЕЙ ФОРМЫ
        // ========================================
        
        // Валидация текстового поля (имя)
        $resultfirst = validateNameField(trim($_POST['firstName'] ?? ''), 2, 50, 'Имя');
        if ($resultfirst['valid']) {
            $firstName = $resultfirst['value'];
        } else {
            $errors[]  = $resultfirst['error'];
            $firstName = false;
        }

        // Валидация текстового поля (фамилия)
        $resultlast = validateNameField(trim($_POST['lastName'] ?? ''), 2, 50, 'Фамилия');
        if ($resultlast['valid']) {
            $lastName = $resultlast['value'];
        } else {
            $errors[] = $resultlast['error'];
            $lastName = false;
        }

        // Валидация email-адреса
        $resultEmail = validateEmail(trim($_POST['email'] ?? ''));
        if ($resultEmail['valid']) {
            $email = $resultEmail['email'];
        } else {
            $errors[] = $resultEmail['error'];
            $email    = false;
        }

        // ========================================
        // ВАЛИДАЦИЯ ТЕЛЕФОНА
        // ========================================
        
        // Телефон (если указан)
        if (empty($errors) && !empty($phone)) {
            $resultPhone = validatePhone(trim($_POST['phone'] ?? ''));
            if ($resultPhone['valid']) {
                $phone = $resultPhone['value'] ?? '';
            } else {
                $errors[] = $resultPhone['error'];
                $phone    = false;
            }
        }

        // ========================================
        // ПРОВЕРКА УНИКАЛЬНОСТИ EMAIL
        // ========================================
        
        // Проверка уникальности email (кроме текущего пользователя при редактировании)
        if (empty($errors) && isset($email)) {
            try {
                $stmt   = $pdo->prepare("SELECT id FROM users WHERE email = ?" . ($isEditMode && $userId ? " AND id != ?" : ""));
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
                logEvent(
                    "Ошибка базы данных при проверке уникальности email: " . $e->getMessage() .
                    " — ID админа: " . $user['id'] .
                    " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    LOG_ERROR_ENABLED,
                    'error'
                );
            }
        }

        // ========================================
        // ВАЛИДАЦИЯ ДОПОЛНИТЕЛЬНОГО ПОЛЯ
        // ========================================
        
        // Дополнительная информация (если указана)
        if (empty($errors) && !empty($customField)) {
            $resultield = validateTextareaField($customField, 1, 300, 'Дополнительная информация');
            if ($resultield['valid']) {
                $customField = $resultield['value'];
            } else {
                $errors[]    = $resultield['error'];
                $customField = false;
            }
        }

        // ========================================
        // СОХРАНЕНИЕ ДАННЫХ, ЕСЛИ НЕТ ОШИБОК
        // ========================================
        
        if (empty($errors)) {
            try {
                $userJsonData = [
                    'first_name'     => $firstName,
                    'last_name'      => $lastName,
                    'phone'          => $phone,
                    'profile_images' => $profileImages,
                    'custom_field'   => $customField
                ];

                // Обновляем JSON-данные (используется ID администратора для совместимости с функцией)
                $jsonData = updateUserJsonData($pdo, $user['id'], $userJsonData);

                if ($isEditMode && $userId) {
                    // ========================================
                    // РЕЖИМ ОБНОВЛЕНИЯ СУЩЕСТВУЮЩЕГО АККАУНТА
                    // ========================================
                    
                    $emailChanged = ($email !== $defaultEmail);
                    if ($emailChanged) {
                        // При смене email — генерируем новый пароль и отправляем его
                        $generatedPassword = generateRandomPassword();
                        $hash              = password_hash($generatedPassword, PASSWORD_DEFAULT);
                        $update            = $pdo->prepare("UPDATE users SET email = ?, password = ?, data = ?, status = ?, updated_at = NOW() WHERE id = ?");
                        $update->execute([$email, $hash, $jsonData, $status, $userId]);

                        if (sendAccountEmail($email, $generatedPassword, $adminPanel, $firstName)) {
                            $successMessages[] = 'Данные успешно обновлены! Новый пароль отправлен на email.';
                            logEvent(
                                "Аккаунт обновлён, email с паролем отправлен: ID админа {$user['id']}, ID пользователя $userId, Email: " . $email .
                                " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                                LOG_INFO_ENABLED,
                                'info'
                            );
                        } else {
                            $successMessages[] = 'Данные успешно обновлены, но не удалось отправить email с паролем.';
                            logEvent(
                                "Аккаунт обновлён, но email не отправлен: ID админа {$user['id']}, ID пользователя $userId, Email: " . $email .
                                " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                                LOG_INFO_ENABLED,
                                'info'
                            );
                        }
                    } else {
                        // Email не менялся — обновляем без пароля
                        $update = $pdo->prepare("UPDATE users SET email = ?, data = ?, status = ?, updated_at = NOW() WHERE id = ?");
                        $update->execute([$email, $jsonData, $status, $userId]);
                        $successMessages[] = 'Данные успешно обновлены!';
                        logEvent(
                            "Аккаунт пользователя обновлён: ID админа {$user['id']}, ID пользователя $userId, Email: " . $email .
                            " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                            LOG_INFO_ENABLED,
                            'info'
                        );
                    }
                } else {
                    // ========================================
                    // РЕЖИМ СОЗДАНИЯ НОВОГО ПОЛЬЗОВАТЕЛЯ
                    // ========================================
                    
                    $generatedPassword = generateRandomPassword();
                    $hash              = password_hash($generatedPassword, PASSWORD_DEFAULT);
                    $insert            = $pdo->prepare("INSERT INTO users (author, email, password, email_verified, data, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $insert->execute(['user', $email, $hash, 1, $jsonData, $status]);
                    $newUserId = $pdo->lastInsertId();

                    if (sendAccountEmail($email, $generatedPassword, $adminPanel, $firstName)) {
                        $successMessages[] = 'Аккаунт успешно создан! Пароль отправлен на email.';
                        logEvent(
                            "Создан новый аккаунт, email с паролем отправлен: ID админа {$user['id']}, новый ID пользователя $newUserId, Email: " . $email .
                            " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                            LOG_INFO_ENABLED,
                            'info'
                        );
                    } else {
                        $successMessages[] = 'Аккаунт успешно создан, но не удалось отправить email с паролем.';
                        logEvent(
                            "Создан новый аккаунт, но email не отправлен: ID админа {$user['id']}, новый ID пользователя $newUserId, Email: " . $email .
                            " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                            LOG_INFO_ENABLED,
                            'info'
                        );
                    }
                    $redirectAfterCreate = true;
                }
            } catch (PDOException $e) {
                $errors[] = 'Ошибка при сохранении данных. Пожалуйста, попробуйте позже.';
                logEvent(
                    "Ошибка базы данных при " . ($isEditMode ? 'обновлении' : 'создании') . " аккаунта: " . $e->getMessage() .
                    " — ID админа: " . $user['id'] .
                    " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    LOG_ERROR_ENABLED,
                    'error'
                );
            } catch (JsonException $e) {
                $errors[] = 'Ошибка при обработке данных. Пожалуйста, попробуйте позже.';
                logEvent(
                    "Ошибка кодирования JSON: " . $e->getMessage() .
                    " — ID админа: " . $user['id'] .
                    " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    LOG_ERROR_ENABLED,
                    'error'
                );
            } catch (Exception $e) {
                $errors[] = 'Неизвестная ошибка. Пожалуйста, попробуйте позже.';
                logEvent(
                    "Неожиданная ошибка: " . $e->getMessage() .
                    " — ID админа: " . $user['id'] .
                    " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    LOG_ERROR_ENABLED,
                    'error'
                );
            }
        }
    }

    // ========================================
    // СОХРАНЕНИЕ СООБЩЕНИЙ В СЕССИЮ
    // ========================================
    
    // Сохраняем сообщения в сессию для отображения после редиректа
    if (!empty($errors) || !empty($successMessages)) {
        $_SESSION['flash_messages'] = [
            'success' => $successMessages,
            'error'   => $errors
        ];
    }

    // ========================================
    // ПЕРЕНАПРАВЛЕНИЕ ПОСЛЕ ОБРАБОТКИ ФОРМЫ
    // ========================================
    
    // Перенаправление после создания
    if ($redirectAfterCreate) {
        header("Location: add_account.php?id=" . urlencode($newUserId));
        exit;
    } else {
        // Перезагрузка текущей страницы для отображения сообщений
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// ========================================
// ПОДГОТОВКА ДАННЫХ ДЛЯ ШАБЛОНА
// ========================================

// Получает логотип
$adminUserId = getAdminUserId($pdo);
$logoProfile = getFileVersionFromList(
    $pdo,
    $adminData['profile_logo'] ?? '',
    'thumbnail',
    '../img/avatar.svg',
    $adminUserId
);

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
    
    <!-- Автоматическое применение сохраненной темы -->
    <script>
        (function () {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    
    <!-- Подключение стилей -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="../user_images/css/main.css">
    <link rel="icon" href="<?php echo escape($logoProfile); ?>" type="image/x-icon">
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
                    <?php displayAlerts(
                        $successMessages,  // Массив сообщений об успехе
                        $errors,           // Массив сообщений об ошибках
                        true               // Показывать сообщения как toast-уведомления
                    ); 
                    ?>
                    
                    <!-- CSRF Protection -->
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

                            <!-- ========================================
                                 ГАЛЕРЕЯ №1
                                 ======================================== -->

                            <?php
                            $sectionId = 'profile_images';
                            $imageIds  = $profileImages;

                            $_SESSION['max_files_per_user'] = $adminData['image_limit'] ?? 0;

                            // Получаем глобальные настройки размеров изображений
                            $imageSizes = getGlobalImageSizes($pdo);

                            $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;
                            ?>

                            <input type="hidden" id="selectedImages_<?php echo $sectionId; ?>" name="<?php echo $sectionId; ?>"
                                   value="<?php echo escape(isset($imageIds) ? $imageIds : ''); ?>">

                            <div id="image-management-section-<?php echo $sectionId; ?>">
                                <div id="loading-content-<?php echo $sectionId; ?>"></div>
                                <div class="selected-images-section d-flex flex-wrap gap-2">
                                    <div id="selectedImagesPreview_<?php echo $sectionId; ?>" class="selected-images-preview">
                                        <!-- Индикатор загрузки -->
                                        <div class="w-100 d-flex justify-content-center align-items-center" style="min-height: 170px;">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Загрузка...</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-text">Не более: <?= escape($maxDigits) ?> шт</div>
                                </div>
                            </div>

                            <div class="modal fade" id="<?php echo $sectionId; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-fullscreen-custom">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Библиотека файлов</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div id="notify_<?php echo $sectionId; ?>"></div>
                                            <div id="image-management-section_<?php echo $sectionId; ?>"></div>
                                            <input type="file" id="fileInput_<?php echo $sectionId; ?>" multiple accept="image/*" style="display: none;">
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                                            <button type="button" id="saveButton" class="btn btn-primary"
                                                    data-section-id="<?php echo escape($sectionId); ?>"
                                                    onclick="handleSelectButtonClick()"
                                                    data-bs-dismiss="modal">
                                                Выбрать
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ========================================
                                 /ГАЛЕРЕЯ №1
                                 ======================================== -->
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
                            <label class="form-check-label" for="status"><?= escape('Активен') ?></label>
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

    <!-- Глобальное модальное окно с информацией о фотографии (используется всеми галереями) -->
    <?php if (!isset($GLOBALS['photo_info_included'])): ?>
        <?php defined('APP_ACCESS') || define('APP_ACCESS', true); ?>
        <?php require_once __DIR__ . '/../user_images/photo_info.php'; ?>
        <?php $GLOBALS['photo_info_included'] = true; ?>
    <?php endif; ?>
    
    <!-- Подключение JavaScript библиотек -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"
            integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r"
            crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"></script>

    <script type="module" src="../js/main.js"></script>
    <script type="module" src="../user_images/js/main.js"></script>

    <!-- Инициализация галереи -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // Загружаем галерею при старте №1
            loadGallery('profile_images');

            // Загружаем библиотеку файлов
            loadImageSection('profile_images');

        });
    </script>
</body>
</html>
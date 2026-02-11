<?php

/**
 * Название файла:      personal_data.php
 * Назначение:          Страница редактирования профиля пользователя
 *                      - Обновление личных данных пользователя (имя, фамилия, email, телефон)
 *                      - Смена пароля с валидацией
 *                      - Загрузка и управление аватаром через медиа-библиотеку
 *                      - Валидация данных на стороне сервера и клиента
 *                      - Логирование изменений и ошибок
 *                      - Защита от CSRF-атак
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// КОНФИГУРАЦИЯ
// ========================================

$config = [
    'display_errors' => true,   // включение отображения ошибок true/false
    'set_encoding'   => true,    // включение кодировки UTF-8
    'db_connect'     => true,    // подключение к базе
    'auth_check'     => true,    // подключение функций авторизации
    'file_log'       => true,    // подключение системы логирования
    'display_alerts' => true,    // подключение отображения сообщений
    'sanitization'   => true,    // подключение валидации/экранирования
    'mailer'         => true,    // подключение отправки email уведомлений
    'jsondata'       => true,    // подключение обновления JSON данных пользователя
    'csrf_token'     => true,    // генерация CSRF-токена
    'image_sizes'    => true,    // подключение модуля управления размерами изображений
];

// Подключаем центральную инициализацию
require_once __DIR__ . '/../functions/init.php';

// ========================================
// ПОЛУЧЕНИЕ НАСТРОЕК АДМИНИСТРАТОРА
// ========================================

// Получаем настройки администратора
$adminData = getAdminData($pdo);

if ($adminData === false) {
    // Ошибка: admin не найден / ошибка БД / некорректный JSON
    header("Location: ../logout.php");
    exit;
}

// ========================================
// НАСТРОЙКИ ИНТЕРФЕЙСА
// ========================================

$defaultFirstName = '';
$defaultLastName  = '';
$defaultPhone     = '';
$profileImages    = '';
$images           = '';
$defaultEmail     = '';
$maxDigits        = 2;                                             // Ограничение на количество изображений для аватара
$maxDigitsImages  = 12;                                            // Макс. количество элементов для дополнительных изображений
$adminPanel       = $adminData['AdminPanel'] ?? 'AdminPanel';      // Название Админ-панели для отправки email
$titlemeta        = 'Редактирование профиля';                      // Название страницы

// ========================================
// НАСТРОЙКА ЛОГИРОВАНИЯ
// ========================================

// Включаем/отключаем логирование. Глобальные константы.
define('LOG_INFO_ENABLED',  ($adminData['log_info_enabled'] ?? false) === true);  // Логировать успешные события
define('LOG_ERROR_ENABLED', ($adminData['log_error_enabled'] ?? false) === true); // Логировать ошибки

// ========================================
// ПРОВЕРКА АВТОРИЗАЦИИ И ЗАГРУЗКА ДАННЫХ ПОЛЬЗОВАТЕЛЯ
// ========================================

try {
    // Проверка авторизации
    $user = requireAuth($pdo);
    
    if (!$user) {
        $redirectTo = '../logout.php';
        logEvent(
            "Неавторизованный доступ — перенаправление на: $redirectTo — IP: {$_SERVER['REMOTE_ADDR']} — URL: {$_SERVER['REQUEST_URI']}",
            LOG_INFO_ENABLED,
            'info'
        );
        
        header("Location: $redirectTo");
        exit;
    }
    
    // Получение данных пользователя
    $userDataAdmin = getUserData($pdo, $user['id']);
    
    // Проверка ошибки
    if (isset($userDataAdmin['error']) && $userDataAdmin['error'] === true) {
        $msg        = $userDataAdmin['message'];
        $level      = $userDataAdmin['level']; // 'info' или 'error'
        $logEnabled = match ($level) {
            'info'  => LOG_INFO_ENABLED,
            'error' => LOG_ERROR_ENABLED,
            default => LOG_ERROR_ENABLED
        };
        
        logEvent($msg, $logEnabled, $level);
        
        header("Location: ../logout.php");
        exit;
    }
    
    // Декодируем JSON-данные администратора
    $currentData = json_decode($userDataAdmin['data'] ?? '{}', true) ?? [];
    
} catch (Exception $e) {
    logEvent(
        "Ошибка при инициализации страницы профиля: " . $e->getMessage() .
        " — ID пользователя: " . ($user['id'] ?? 'unknown') .
        " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        LOG_ERROR_ENABLED,
        'error'
    );
    
    header("Location: ../logout.php");
    exit;
}

// ========================================
// РЕЗЕРВНАЯ ЗАГРУЗКА ДАННЫХ ПОЛЬЗОВАТЕЛЯ
// ========================================

// Примечание: этот блок дублирует предыдущую логику. Возможно, его можно удалить,
// если первая попытка всегда завершается корректно.
try {
    $userData         = getUserData($pdo, $user['id']);
    
    if (!$userData) {
        throw new Exception("User data not found");
    }
    
    $currentData      = json_decode($userData['data'] ?? '{}', true) ?? [];
    $defaultFirstName = $currentData['first_name'] ?? '';
    $defaultLastName  = $currentData['last_name'] ?? '';
    $defaultPhone     = $currentData['phone'] ?? '';
    $profileImages    = $currentData['profile_images'] ?? '';
    $images           = $currentData['images'] ?? '';
    $defaultEmail     = $userData['email'] ?? '';
} catch (Exception $e) {
    logEvent(
        "Не удалось загрузить данные пользователя для формы профиля: " . $e->getMessage() .
        " — ID пользователя: " . $user['id'] .
        " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        LOG_ERROR_ENABLED,
        'error'
    );
}

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
// ОБРАБОТКА POST-ЗАПРОСА ФОРМЫ
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ========================================
    // ПРОВЕРКА CSRF-ТОКЕНА
    // ========================================
    
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCsrfToken($csrfToken)) {
        $errors[] = 'Недействительная форма. Пожалуйста, обновите страницу.';
        logEvent(
            "Проверка CSRF-токена не пройдена — ID пользователя: " . $user['id'] .
            " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            LOG_ERROR_ENABLED,
            'error'
        );
    } else {
        
        // ========================================
        // ПОЛУЧЕНИЕ И ОЧИСТКА ДАННЫХ ИЗ ФОРМЫ
        // ========================================
        
        // Получаем и очищаем данные из формы
        $password        = trim($_POST['password'] ?? '');
        $confirmPassword = trim($_POST['confirmPassword'] ?? '');
        
        // ========================================
        // РАСШИРЕННАЯ ВАЛИДАЦИЯ ДАННЫХ
        // ========================================
        
        if (empty($errors)) {
            
            // Валидация строки вида "123,456,789" для аватара
            $resultImages = validateIdList(trim($_POST['profile_images'] ?? ''), $maxDigits);
            
            if ($resultImages['valid']) {
                $profileImages = $resultImages['value']; // '123,456,789,0001'
            } else {
                $errors[]      = $resultImages['error'];
                $profileImages = false;
            }
            
            // Валидация строки вида "123,456,789" для дополнительных изображений
            $resultImagesGallery = validateIdList(trim($_POST['images'] ?? ''), $maxDigitsImages);
            
            if ($resultImagesGallery['valid']) {
                $images = $resultImagesGallery['value']; // '123,456,789,0001'
            } else {
                $errors[] = $resultImagesGallery['error'];
                $images   = false;
            }
            
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
            
            // Валидация телефонного номера
            $resultPhone = validatePhone(trim($_POST['phone'] ?? ''));
            
            if ($resultPhone['valid']) {
                $phone = $resultPhone['value'] ?? '';
            } else {
                $errors[] = $resultPhone['error'];
                $phone    = false;
            }
            
            // Пароль и подтверждение (если задан хотя бы один)
            $passwordFilled = !empty($password) || !empty($confirmPassword);
            
            if ($passwordFilled && empty($errors)) {
                
                // Валидация пароля
                $resultPass = validatePassword(trim($_POST['password'] ?? ''));
                
                if ($resultPass['valid']) {
                    $password = $resultPass['value'];
                } else {
                    $errors[] = $resultPass['error'];
                    $password = false;
                }
                
                if (empty($errors) && !empty($password) && !empty($confirmPassword) && $password !== $confirmPassword) {
                    $errors[] = "Пароли не совпадают!";
                }
            }
        }
        
        // ========================================
        // ПРОВЕРКА УНИКАЛЬНОСТИ EMAIL
        // ========================================
        
        // Проверка уникальности email (кроме текущего пользователя)
        if (empty($errors) && isset($email)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $stmt->execute([$email, $user['id']]);
                
                if ($stmt->fetch()) {
                    $errors[] = 'Этот email адрес уже используется другим пользователем.';
                }
            } catch (PDOException $e) {
                $errors[] = 'Ошибка при проверке email. Пожалуйста, попробуйте позже.';
                logEvent(
                    "Ошибка базы данных при проверке уникальности email: " . $e->getMessage() .
                    " — ID пользователя: " . $user['id'] .
                    " — IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                    LOG_ERROR_ENABLED,
                    'error'
                );
            }
        }
        
        // ========================================
        // ОБНОВЛЕНИЕ ДАННЫХ В БАЗЕ
        // ========================================
        
        // Обновление в БД только если ошибок нет
        if (empty($errors)) {
            try {
                // Проверяем, меняется ли email
                $emailChanged = ($email !== $defaultEmail);
                
                // Подготавливаем данные для JSON
                $updateData = [
                    'first_name'      => $firstName,
                    'last_name'       => $lastName,
                    'phone'           => $phone,
                    'profile_images'  => $profileImages,
                    'images'          => $images,
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
                    $token   = bin2hex(random_bytes(32));
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
                    $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host        = $_SERVER['HTTP_HOST'];
                    $confirmLink = $protocol . '://' . $host . '/admin/user/confirm_email.php?token=' . urlencode($token);
                    
                    // Отправляем письмо через mailer.php
                    $adminPanelName = $adminData['AdminPanel'] ?? 'AdminPanel';
                    
                    if (sendEmailChangeConfirmationLink($email, $confirmLink, $adminPanelName, $adminPanel)) {
                        $successMessages[] = 'Данные успешно обновлены! На новый email отправлена ссылка для подтверждения. После подтверждения email будет изменён.';
                    } else {
                        $successMessages[] = 'Данные обновлены, но не удалось отправить письмо. Email будет изменён после подтверждения по ссылке.';
                    }
                    
                    logEvent(
                        "Запрошена смена email для ID: " . $user['id'] . " (старый: $defaultEmail → новый: $email)",
                        LOG_INFO_ENABLED,
                        'info'
                    );
                    
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
                    
                    logEvent(
                        "Профиль обновлён — ID: " . $user['id'] . ", Email: " . $email,
                        LOG_INFO_ENABLED,
                        'info'
                    );
                }
                
                // Обновляем сессию — email остаётся старым до подтверждения!
                if (isset($_SESSION['user_email'])) {
                    $_SESSION['user_email'] = $defaultEmail;
                }
                
            } catch (PDOException $e) {
                $errors[] = 'Ошибка при обновлении данных. Пожалуйста, попробуйте позже.';
                logEvent(
                    "Ошибка БД в personal_data.php: " . $e->getMessage() . " — ID: " . $user['id'],
                    LOG_ERROR_ENABLED,
                    'error'
                );
            } catch (JsonException $e) {
                $errors[] = 'Ошибка при обработке данных. Пожалуйста, попробуйте позже.';
                logEvent(
                    "Ошибка JSON в personal_data.php: " . $e->getMessage() . " — ID: " . $user['id'],
                    LOG_ERROR_ENABLED,
                    'error'
                );
            } catch (Exception $e) {
                $errors[] = 'Неизвестная ошибка. Пожалуйста, попробуйте позже.';
                logEvent(
                    "Исключение в personal_data.php: " . $e->getMessage() . " — ID: " . $user['id'],
                    LOG_ERROR_ENABLED,
                    'error'
                );
            }
        }
    }
    
    // ========================================
    // СОХРАНЕНИЕ СООБЩЕНИЙ И ПЕРЕНАПРАВЛЕНИЕ
    // ========================================
    
    // Сохраняем сообщения в сессию для отображения после редиректа
    if (!empty($errors) || !empty($successMessages)) {
        $_SESSION['flash_messages'] = [
            'success' => $successMessages,
            'error'   => $errors,
        ];
    }
    
    // Перезагрузка при обновлении
    header("Location: personal_data.php");
    exit;
}

// ========================================
// ПОЛУЧЕНИЕ ЛОГОТИПА
// ========================================

// Получает логотип
$adminUserId  = getAdminUserId($pdo);
$logoProfile  = getFileVersionFromList($pdo, $adminData['profile_logo'] ?? '', 'thumbnail', '../img/avatar.svg', $adminUserId);

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= escape($_SESSION['csrf_token']) ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Админ-панель управления системой">
    <meta name="author" content="Админ-панель">
    <title><?php echo escape($titlemeta); ?></title>
    <script>
        (function () {
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
    <link rel="icon" href="<?php echo escape($logoProfile); ?>" type="image/x-icon">
</head>
<body>
<div class="container-fluid">
    <?php require_once __DIR__ . '/../template/sidebar.php'; ?>
    <main class="main-content">
        <?php require_once __DIR__ . '/../template/header.php'; ?>

        <form action="" method="post" enctype="multipart/form-data" id="profileForm" novalidate>

            <div class="form-section mb-5">
                <!-- Отображение сообщений -->
                <?php displayAlerts(
                    $successMessages,  // Массив сообщений об успехе
                    $errors,           // Массив сообщений об ошибках
                    true               // Показывать сообщения как toast-уведомления true/false
                ); 
                ?>

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

                <?php if ($adminData['allow_photo_upload'] === true || ($userDataAdmin['author'] ?? '') == 'admin') { ?>

                    <!-- Аватар профиля -->
                    <div class="mb-3">
                        <h3 class="card-title">
                            <i class="bi bi-card-image"></i>
                            Аватар профиля
                        </h3>
                        <!---------------------------------------------------- Галерея №1 ---------------------------------------------------->

                        <?php
                        $sectionId = 'profile_images';
                        $imageIds  = $profileImages;

                        $_SESSION['max_files_per_user'] = $adminData['image_limit'] ?? 0;

                        // Получаем глобальные настройки размеров изображений
                        $imageSizes = getGlobalImageSizes($pdo);

                        $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;
                        ?>

                        <input type="hidden" id="selectedImages_<?php echo $sectionId; ?>"
                               name="<?php echo $sectionId; ?>"
                               value="<?php echo escape(isset($imageIds) ? $imageIds : ''); ?>">

                        <div class="mb-5" id="image-management-section-<?php echo $sectionId; ?>">
                            <div id="loading-content-<?php echo $sectionId; ?>"></div>
                            <div class="selected-images-section d-flex flex-wrap gap-2">
                                <div id="selectedImagesPreview_<?php echo $sectionId; ?>" class="selected-images-preview">
                                    <!-- Индикатор загрузки -->
                                    <div class="w-100 d-flex justify-content-center align-items-center"
                                         style="min-height: 170px;">
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
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Закрыть"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div id="notify_<?php echo $sectionId; ?>"></div>
                                        <div id="image-management-section_<?php echo $sectionId; ?>"></div>
                                        <input type="file" id="fileInput_<?php echo $sectionId; ?>" multiple
                                               accept="image/*" style="display: none;">
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary"
                                                data-bs-dismiss="modal">Закрыть
                                        </button>
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

                        <!---------------------------------------------------- /Галерея №1 ---------------------------------------------------->

                        <!---------------------------------------------------- Галерея №2 ---------------------------------------------------->
                        <!-- Дополнительные изображения -->
                        <h3 class="card-title">
                            <i class="bi bi-images"></i>
                            Дополнительные изображения
                        </h3>

                        <?php
                        $sectionId = 'images';
                        $imageIds  = $images;

                        $_SESSION['max_files_per_user'] = $adminData['image_limit'] ?? 0;

                        // Получаем глобальные настройки размеров изображений
                        $imageSizes = getGlobalImageSizes($pdo);

                        $_SESSION["imageSizes_{$sectionId}"] = $imageSizes;
                        ?>

                        <input type="hidden" id="selectedImages_<?php echo $sectionId; ?>"
                               name="<?php echo $sectionId; ?>"
                               value="<?php echo escape(isset($imageIds) ? $imageIds : ''); ?>">

                        <div id="image-management-section-<?php echo $sectionId; ?>">
                            <div id="loading-content-<?php echo $sectionId; ?>"></div>
                            <div class="selected-images-section d-flex flex-wrap gap-2">
                                <div id="selectedImagesPreview_<?php echo $sectionId; ?>" class="selected-images-preview">
                                    <!-- Индикатор загрузки -->
                                    <div class="w-100 d-flex justify-content-center align-items-center"
                                         style="min-height: 170px;">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Загрузка...</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-text">Не более: <?= escape($maxDigitsImages) ?> шт</div>
                            </div>
                        </div>

                        <div class="modal fade" id="<?php echo $sectionId; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-fullscreen-custom">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Библиотека файлов</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Закрыть"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div id="notify_<?php echo $sectionId; ?>"></div>
                                        <div id="image-management-section_<?php echo $sectionId; ?>"></div>
                                        <input type="file" id="fileInput_<?php echo $sectionId; ?>" multiple
                                               accept="image/*" style="display: none;">
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary"
                                                data-bs-dismiss="modal">Закрыть
                                        </button>
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

                        <!---------------------------------------------------- /Галерея №2 ---------------------------------------------------->
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

<!-- Глобальное модальное окно с информацией о фотографии (используется всеми галереями) -->
<?php if (!isset($GLOBALS['photo_info_included'])): ?>
    <?php defined('APP_ACCESS') || define('APP_ACCESS', true); ?>
    <?php require_once __DIR__ . '/../user_images/photo_info.php'; ?>
    <?php $GLOBALS['photo_info_included'] = true; ?>
<?php endif; ?>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Popper.js -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"
        integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r"
        crossorigin="anonymous"></script>
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<!-- Модульный JS admin -->
<script type="module" src="../js/main.js"></script>
<!-- Модульный JS галереи -->
<script type="module" src="../user_images/js/main.js"></script>

<!-- Инициализация галереи -->
<script>
    document.addEventListener('DOMContentLoaded', function () {

        // Загружаем галерею при старте №1
        loadGallery('profile_images');

        // Загружаем библиотеку файлов
        loadImageSection('profile_images');

        // Загружаем галерею дополнительных изображений
        loadGallery('images');

        // Загружаем библиотеку файлов для дополнительных изображений
        loadImageSection('images');

    });
</script>
</body>
</html>
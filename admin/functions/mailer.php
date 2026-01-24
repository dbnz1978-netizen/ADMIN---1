<?php
/**
 * Файл: /admin/functions/mailer.php
 * 
 * Универсальная система отправки email уведомлений для административной панели.
 * Объединяет конфигурацию и функциональность отправки писем в одном файле.
 * 
 * Основные функции:
 * - Конфигурация почтовых настроек
 * - Отправка email для подтверждения регистрации
 * - Отправка приветственных писем после активации аккаунта
 * - Отправка email с данными аккаунта (при создании/редактировании админом)
 * - Отправка писем восстановления пароля
 * - Формирование HTML-шаблонов писем
 * - Автоматическое определение базового URL для ссылок
 * 
 */

/**
 * КОНФИГУРАЦИЯ ПОЧТЫ
 * 
 * Настройки для отправки email. Для использования SMTP заполните
 * MAIL_SMTP_USER и MAIL_SMTP_PASS. Если оставить пустыми, будет
 * использована встроенная функция mail() PHP.
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

// Базовые настройки отправителя
define('MAIL_FROM_EMAIL', 'admin@' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'));

// SMTP настройки (опционально - для улучшенной доставки)
define('MAIL_SMTP_HOST', 'localhost');    // SMTP сервер
define('MAIL_SMTP_PORT', 587);            // Порт SMTP (587 для TLS, 465 для SSL)
define('MAIL_SMTP_USER', '');             // Логин SMTP (оставьте пустым для использования mail())
define('MAIL_SMTP_PASS', '');             // Пароль SMTP (оставьте пустым для использования mail())
define('MAIL_SMTP_SECURE', 'tls');        // Шифрование: tls или ssl

/**
 * Отправляет email для восстановления пароля
 * 
 * @param string $email Email получателя
 * @param string $firstName Имя пользователя
 * @param string $token Токен сброса пароля
 * @param string $AdminPanel Название админ-панели
 * @return bool Успешность отправки
 */
function sendPasswordResetEmail($email, $firstName, $token, $AdminPanel) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/') ?: '';
    $resetLink = $protocol . '://' . $host . $basePath . '/reset.php?token=' . urlencode($token);

    $subject = "Восстановление пароля - " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8');
    $messageBody = "
    <html>
    <head>
        <title>Восстановление пароля</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #007bff; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #f8f9fa; border-radius: 0 0 8px 8px; }
            .button { display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
            .warning { background: #fff3cd; padding: 10px; border-radius: 4px; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Восстановление пароля</h1>
            </div>
            <div class='content'>
                <h2>Здравствуйте, " . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . "!</h2>
                <p>Мы получили запрос на восстановление пароля для вашего аккаунта в " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . ".</p>
                
                <div style='text-align: center; margin: 25px 0;'>
                    <a href='" . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . "' class='button'>Восстановить пароль</a>
                </div>
                
                <p>Если кнопка не работает, скопируйте и вставьте следующую ссылку в ваш браузер:</p>
                <p style='word-break: break-all; background: #e9ecef; padding: 10px; border-radius: 4px;'>
                    <a href='" . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . "</a>
                </p>
                
                <div class='warning'>
                    <p><strong>Внимание:</strong> Ссылка действительна в течение 1 часа.</p>
                </div>
                
                <p>Если вы не запрашивали восстановление пароля, пожалуйста, проигнорируйте это письмо.</p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . ". Все права защищены.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    return sendEmail($email, $subject, $messageBody, $AdminPanel);
}

/**
 * Отправляет email для подтверждения регистрации пользователя
 * 
 * Создает и отправляет письмо с ссылкой для подтверждения email адреса.
 * Ссылка содержит уникальный токен для верификации и действительна 24 часа.
 * 
 * @param string $email Email адрес получателя
 * @param string $firstName Имя пользователя для персонализации письма
 * @param string $verificationToken Уникальный токен для подтверждения email
 * @param string $AdminPanel Название админ-панели
 * @return bool Возвращает true если письмо отправлено успешно, false в случае ошибки
 */
function sendVerificationEmail($email, $firstName, $verificationToken, $AdminPanel) {
    // Тема письма
    $subject = "Подтверждение регистрации - " . $AdminPanel;
    
    // Правильное формирование URL для верификации
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['PHP_SELF']);
    
    // Убираем лишние слеши и формируем базовый URL
    $baseUrl = rtrim($protocol . "://" . $host . $scriptPath, '/');
    $verificationUrl = $baseUrl . "/verify_email.php?token=" . urlencode($verificationToken);
    
    // HTML-шаблон письма подтверждения
    $message = "
    <html>
    <head>
        <title>Подтверждение регистрации</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #007bff; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .button { display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . "</h1>
            </div>
            <div class='content'>
                <h2>Здравствуйте, " . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . "!</h2>
                <p>Благодарим вас за регистрацию в " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . ". Для завершения регистрации и активации вашего аккаунта, пожалуйста, подтвердите ваш email адрес.</p>
                <p style='text-align: center;'>
                    <a href='" . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . "' class='button'>Подтвердить Email</a>
                </p>
                <p>Если кнопка не работает, скопируйте и вставьте следующую ссылку в ваш браузер:</p>
                <p><a href='" . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8') . "</a></p>
                <p><strong>Ссылка действительна в течение 24 часов.</strong></p>
            </div>
            <div class='footer'>
                <p>Если вы не регистрировались в " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . ", пожалуйста, проигнорируйте это письмо.</p>
                <p>&copy; " . date('Y') . " " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . ". Все права защищены.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Отправка email с выбором метода
    return sendEmail($email, $subject, $message, $AdminPanel);
}

/**
 * Отправляет приветственное письмо после успешной активации аккаунта
 * 
 * Создает и отправляет приветственное письмо пользователю после того,
 * как он подтвердил свой email адрес и аккаунт был активирован.
 * 
 * @param string $email Email адрес получателя
 * @param string $firstName Имя пользователя для персонализации письма
 * @return bool Возвращает true если письмо отправлено успешно, false в случае ошибки
 */
function sendWelcomeEmail($email, $firstName, $AdminPanel) {
    // Тема приветственного письма
    $subject = "Добро пожаловать в " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . "!";
    
    // HTML-шаблон приветственного письма
    $message = "
    <html>
    <head>
        <title>Добро пожаловать</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #28a745; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Добро пожаловать!</h1>
            </div>
            <div class='content'>
                <h2>Поздравляем, " . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . "!</h2>
                <p>Ваш аккаунт в " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . " успешно активирован.</p>
                <p>Теперь вы можете войти в систему и начать работу.</p>
                <p>Если у вас возникнут вопросы, не стесняйтесь обращаться в нашу службу поддержки.</p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . ". Все права защищены.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Отправка email с выбором метода
    return sendEmail($email, $subject, $message, $AdminPanel);
}

/**
 * Отправляет email с данными аккаунта (для админа при создании/редактировании)
 * 
 * Создает и отправляет письмо с логином и паролем для нового или обновленного аккаунта.
 * 
 * @param string $email Email адрес получателя
 * @param string $password Пароль для входа
 * @param string $firstName Имя пользователя для персонализации письма
 * @return bool Возвращает true если письмо отправлено успешно, false в случае ошибки
 */
function sendAccountEmail($email, $password, $AdminPanel, $firstName = '') {
    // Тема письма
    $subject = "Данные вашего аккаунта - " . $AdminPanel;
    
    // Правильное формирование URL
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['PHP_SELF']);
    $baseUrl = rtrim($protocol . "://" . $host . $scriptPath, '/');

    // HTML-шаблон письма с данными аккаунта
    $message = "
    <html>
    <head>
        <title>Данные вашего аккаунта</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #007bff; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .credentials { background: #fff; border: 2px solid #007bff; border-radius: 8px; padding: 20px; margin: 20px 0; }
            .credential-item { margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 4px; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
            .warning { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; padding: 15px; margin: 15px 0; }
            .button { display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . "</h1>
                <h2>Данные вашего аккаунта</h2>
            </div>
            <div class='content'>
                <h2>Здравствуйте" . ($firstName ? ", " . htmlspecialchars($firstName, ENT_QUOTES | ENT_HTML5, 'UTF-8') : "") . "!</h2>
                <p>Ваш аккаунт был создан/обновлен в системе " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . ".</p>
                <div class='credentials'>
                    <h3>Ваши данные для входа:</h3>
                    <div class='credential-item'>
                        <strong>Email:</strong> " . htmlspecialchars($email, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "
                    </div>
                    <div class='credential-item'>
                        <strong>Пароль:</strong> " . htmlspecialchars($password, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "
                    </div>
                </div>
                <div class='warning'>
                    <strong>Важно!</strong>
                    <p>Рекомендуем сменить пароль после первого входа в систему для обеспечения безопасности.</p>
                </div>
                <p style='text-align: center;'>
                    <a href='" . htmlspecialchars($baseUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "' class='button'>Войти в систему</a>
                </p>
                <p>Если кнопка не работает, перейдите по ссылке: <a href='" . htmlspecialchars($baseUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "'>" . htmlspecialchars($baseUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "</a></p>
            </div>
            <div class='footer'>
                <p>Если вы не ожидали это письмо, пожалуйста, проигнорируйте его.</p>
                <p>&copy; " . date('Y') . " " . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . ". Все права защищены.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Отправка email
    return sendEmail($email, $subject, $message, $AdminPanel);
}

/**
 * Отправляет уведомление администратору о новом обращении или новом сообщении
 *
 * @param string $adminEmail Email администратора
 * @param string $userEmail Email пользователя
 * @param string $subject Тема обращения (если новое)
 * @param string $ticketId ID тикета
 * @param bool $isNewTicket Создано ли новое обращение
 * @param string $AdminPanel Название админ-панели
 * @return bool Успешность отправки
 */
function sendAdminSupportNotification($adminEmail, $userEmail, $subject, $ticketId, $AdminPanel, $isNewTicket = false) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = rtrim($protocol . '://' . $host, '/');
    $ticketUrl = $baseUrl . '/admin/support/view.php?id=' . (int)$ticketId;

    if ($isNewTicket) {
        $subjectLine = "🆕 Новое обращение в поддержку (#{$ticketId})";
        $body = "
<h2>Новое обращение от пользователя</h2>
<p><strong>Пользователь:</strong> " . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . "</p>
<p><strong>Тема:</strong> " . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . "</p>
<p><a href='" . htmlspecialchars($ticketUrl, ENT_QUOTES, 'UTF-8') . "' style='display:inline-block;padding:10px 20px;background:#007bff;color:white;text-decoration:none;border-radius:4px;'>Перейти к обращению</a></p>
        ";
    } else {
        $subjectLine = "💬 Новое сообщение в обращении (#{$ticketId})";
        $body = "
<h2>Новое сообщение от пользователя</h2>
<p><strong>Пользователь:</strong> " . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . "</p>
<p><a href='" . htmlspecialchars($ticketUrl, ENT_QUOTES, 'UTF-8') . "' style='display:inline-block;padding:10px 20px;background:#007bff;color:white;text-decoration:none;border-radius:4px;'>Перейти к переписке</a></p>
        ";
    }

    $message = "
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 20px auto; padding: 20px; }
        .header { background: #007bff; color: white; padding: 15px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; border-radius: 8px; margin-top: 10px; }
        .button { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'><h2>" . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . " — Поддержка</h2></div>
        <div class='content'>{$body}</div>
    </div>
</body>
</html>
    ";

    return sendEmail($adminEmail, $subjectLine, $message, $AdminPanel);
}

/**
 * Отправляет уведомление пользователю об ответе от поддержки
 *
 * @param string $userEmail Email пользователя
 * @param string $adminMessage Текст ответа от админа
 * @param int $ticketId ID тикета
 * @param string $AdminPanel Название админ-панели
 * @return bool Успешность отправки
 */
function sendUserSupportReply($userEmail, $adminMessage, $ticketId, $AdminPanel) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = rtrim($protocol . '://' . $host, '/');
    $ticketUrl = $baseUrl . '/support.php'; // Пользователь всегда переходит в свой support.php

    $subjectLine = "📨 Ответ от техподдержки (обращение #{$ticketId})";
    $body = "
<h2>Получен ответ от техподдержки</h2>
<blockquote style='border-left: 4px solid #007bff; padding-left: 15px; margin: 15px 0; font-style: italic;'>
    " . nl2br(htmlspecialchars($adminMessage, ENT_QUOTES, 'UTF-8')) . "
</blockquote>
<p>Вы можете продолжить переписку в <a href='" . htmlspecialchars($ticketUrl, ENT_QUOTES, 'UTF-8') . "'>личном кабинете</a>.</p>
    ";

    $message = "
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 20px auto; padding: 20px; }
        .header { background: #28a745; color: white; padding: 15px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; border-radius: 8px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'><h2>" . htmlspecialchars($AdminPanel, ENT_QUOTES, 'UTF-8') . " — Поддержка</h2></div>
        <div class='content'>{$body}</div>
    </div>
</body>
</html>
    ";

    return sendEmail($userEmail, $subjectLine, $message, $AdminPanel);
}


/**
 * Отправляет письмо со ссылкой подтверждения смены email
 * 
 * @param string $to Новый email
 * @param string $confirmLink Ссылка подтверждения
 * @param string $adminPanelName Название системы
 * @return bool Успешность отправки
 */
function sendEmailChangeConfirmationLink($to, $confirmLink, $adminPanelName, $AdminPanel) {
    $subject = "Подтверждение смены email — " . $adminPanelName;
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #007bff; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .button { display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; font-weight: bold; margin: 20px 0; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars($adminPanelName, ENT_QUOTES, 'UTF-8') . "</h1>
                <h2>Смена email</h2>
            </div>
            <div class='content'>
                <p>Вы запросили смену email-адреса.</p>
                <p>Чтобы подтвердить новый email, перейдите по ссылке ниже:</p>
                <div style='text-align: center;'>
                    <a href='" . htmlspecialchars($confirmLink, ENT_QUOTES, 'UTF-8') . "' class='button'>Подтвердить email</a>
                </div>
                <p>Если кнопка не работает, скопируйте ссылку в браузер:</p>
                <p style='word-break: break-all; background: #e9ecef; padding: 10px; border-radius: 4px;'>
                    <a href='" . htmlspecialchars($confirmLink, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($confirmLink, ENT_QUOTES, 'UTF-8') . "</a>
                </p>
                <p>Ссылка действительна в течение 1 часа.</p>
                <p>Если вы не запрашивали смену email, просто проигнорируйте это письмо.</p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " " . htmlspecialchars($adminPanelName, ENT_QUOTES, 'UTF-8') . "</p>
            </div>
        </div>
    </body>
    </html>
    ";

    return sendEmail($to, $subject, $message, $AdminPanel);
}


/**
 * Универсальная функция отправки email
 * 
 * Выбирает метод отправки в зависимости от конфигурации:
 * - SMTP если настроены логин/пароль
 * - Стандартная функция mail() если SMTP не настроен
 * 
 * @param string $to Email получателя
 * @param string $subject Тема письма
 * @param string $message HTML-содержимое письма
 * @return bool Результат отправки
 */
function sendEmail($to, $subject, $message, $AdminPanel) {
    // Формируем базовые заголовки
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type: text/html; charset=utf-8" . "\r\n";
    $headers .= "From: " . $AdminPanel . " <" . MAIL_FROM_EMAIL . ">" . "\r\n";
    $headers .= "Reply-To: " . MAIL_FROM_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    // Проверяем, настроен ли SMTP
    if (!empty(MAIL_SMTP_USER) && !empty(MAIL_SMTP_PASS)) {
        // Используем SMTP отправку
        return sendSmtpEmail($to, $subject, $message, $headers);
    } else {
        // Используем стандартную функцию mail()
        return mail($to, $subject, $message, $headers);
    }
}

/**
 * Отправка email через SMTP (заглушка для будущей реализации)
 * 
 * @param string $to Email получателя
 * @param string $subject Тема письма
 * @param string $message HTML-содержимое письма
 * @param string $headers Заголовки письма
 * @return bool Результат отправки
 */
function sendSmtpEmail($to, $subject, $message, $headers) {
    // TODO: Реализовать отправку через PHPMailer или SwiftMailer
    // Пока используем стандартную функцию как fallback
    
    error_log("SMTP sending requested but not implemented. Using mail() fallback.");
    return mail($to, $subject, $message, $headers);
}

/**
 * Проверяет конфигурацию почты
 * 
 * @return array Массив с информацией о конфигурации

function getMailConfigInfo() {
    return [
        'from_email' => MAIL_FROM_EMAIL,
        'from_name' => MAIL_FROM_NAME,
        'smtp_configured' => !empty(MAIL_SMTP_USER) && !empty(MAIL_SMTP_PASS),
        'smtp_host' => MAIL_SMTP_HOST,
        'smtp_user' => MAIL_SMTP_USER ? '***' . substr(MAIL_SMTP_USER, -3) : 'Not set'
    ];
}

 */

?>
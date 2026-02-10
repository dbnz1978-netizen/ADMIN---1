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
$mailHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$mailHost = preg_replace('/:\d+$/', '', $mailHost);
define('MAIL_FROM_EMAIL', 'admin@' . $mailHost);

// SMTP настройки (опционально - для улучшенной доставки)
define('MAIL_SMTP_HOST', 'localhost');    // SMTP сервер
define('MAIL_SMTP_PORT', 587);            // Порт SMTP (587 для TLS, 465 для SSL)
define('MAIL_SMTP_USER', '');             // Логин SMTP (оставьте пустым для использования mail())
define('MAIL_SMTP_PASS', '');             // Пароль SMTP (оставьте пустым для использования mail())
define('MAIL_SMTP_SECURE', 'tls');        // Шифрование: tls или ssl
define('MAIL_LOGO_FALLBACK', '/admin/img/avatar.svg');

/**
 * Формирует абсолютный URL для email
 *
 * @param string $baseUrl
 * @param string|null $path Optional path to append to base URL.
 * @return string Absolute URL combining base URL and path.
 */
function formatEmailUrl($baseUrl, $path) {
    if (empty($path)) {
        return $baseUrl;
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

/**
 * Получает данные брендинга для email (логотип, base URL, email поддержки).
 *
 * @param string $adminPanel
 * @return array{base_url: string, logo_url: string, support_email: string, preheader: string}
 */
function getEmailBrandingData($adminPanel) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = rtrim($protocol . '://' . $host, '/');
    $logoPath = null;

    if (!empty($GLOBALS['authLogoLight'])) {
        $logoPath = $GLOBALS['authLogoLight'];
    }

    if (empty($logoPath) && isset($GLOBALS['adminData'], $GLOBALS['pdo']) && function_exists('getThemeLogoPaths')) {
        $adminUserId = null;
        if (function_exists('getAdminUserId')) {
            $adminUserId = getAdminUserId($GLOBALS['pdo']);
        }
        if ($adminUserId !== null) {
            $logoPaths = getThemeLogoPaths($GLOBALS['pdo'], $GLOBALS['adminData']['profile_logo'] ?? '', 'thumbnail', $adminUserId);
            $logoPath = $logoPaths['light'] ?? null;
        }
    }

    if (empty($logoPath)) {
        $logoPath = MAIL_LOGO_FALLBACK;
    }

    $supportEmail = MAIL_FROM_EMAIL;
    if (!empty($GLOBALS['adminData']['email']) && filter_var($GLOBALS['adminData']['email'], FILTER_VALIDATE_EMAIL)) {
        $supportEmail = $GLOBALS['adminData']['email'];
    }

    return [
        'base_url' => $baseUrl,
        'logo_url' => formatEmailUrl($baseUrl, $logoPath),
        'support_email' => $supportEmail,
        'preheader' => $adminPanel . ' уведомление'
    ];
}

/**
 * Собирает единый HTML-шаблон письма.
 *
 * @param string $title
 * @param string $contentHtml
 * @param string $adminPanel
 * @return string HTML письмо.
 */
function buildEmailTemplate($title, $contentHtml, $adminPanel) {
    $branding = getEmailBrandingData($adminPanel);
    $logoUrl = htmlspecialchars($branding['logo_url'], ENT_QUOTES, 'UTF-8');
    $supportEmail = htmlspecialchars($branding['support_email'], ENT_QUOTES, 'UTF-8');
    $panelName = htmlspecialchars($adminPanel, ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $preheader = htmlspecialchars($branding['preheader'] ?? $title, ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    return <<<HTML
<html>
<head>
    <meta charset="UTF-8">
    <title>{$safeTitle}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,sans-serif;color:#1f2937;">
    <div style="display:none;max-height:0;overflow:hidden;color:transparent;opacity:0;">{$preheader}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f9;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 12px 30px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#0d6efd,#2563eb);padding:28px 32px;text-align:center;color:#ffffff;">
                            <img src="{$logoUrl}" alt="{$panelName}" style="max-height:48px;display:block;margin:0 auto 12px;" />
                            <div style="font-size:20px;font-weight:600;line-height:1.2;">{$panelName}</div>
                            <div style="font-size:14px;opacity:0.9;margin-top:4px;">{$safeTitle}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            {$contentHtml}
                            <p style="margin:24px 0 0;font-size:14px;color:#6b7280;">С уважением,<br><strong>{$panelName}</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px;background-color:#f8fafc;color:#6b7280;font-size:12px;text-align:center;line-height:1.6;">
                            <p style="margin:0 0 8px;">Это автоматическое письмо. Пожалуйста, не отвечайте на него.</p>
                            <p style="margin:0 0 8px;">Если у вас есть вопросы, напишите нам: <a href="mailto:{$supportEmail}" style="color:#0d6efd;text-decoration:none;">{$supportEmail}</a></p>
                            <p style="margin:0;">&copy; {$year} {$panelName}. Все права защищены.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

/**
 * Конвертирует HTML в текстовую версию письма для multipart писем.
 *
 * @param string $html
 * @return string
 */
function buildPlainTextEmail($html) {
    $lineBreaks = ['<br>', '<br/>', '<br />', '</p>', '</div>', '</h1>', '</h2>', '</h3>', '</li>'];
    $text = str_ireplace($lineBreaks, "\n", $html);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/\n{3,}/", "\n\n", trim($text));
    return $text;
}

/**
 * Генерирует безопасный случайный токен для почтовых заголовков.
 *
 * @param int $byteLength
 * @return string
 */
function getRandomToken($byteLength) {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($byteLength));
    }

    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($byteLength));
    }

    $urandom = @fopen('/dev/urandom', 'rb');
    if ($urandom !== false) {
        $bytes = fread($urandom, $byteLength);
        fclose($urandom);
        if ($bytes !== false) {
            return bin2hex($bytes);
        }
    }

    // Небезопасный fallback: используется только для технических заголовков (не для токенов безопасности).
    return substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, $byteLength * 2);
}

/**
 * Отправляет email для восстановления пароля
 * 
 * @param string $email Email получателя
 * @param string $firstName Имя пользователя
 * @param string $token Токен сброса пароля
 * @param string $adminPanel Название админ-панели
 * @return bool Успешность отправки
 */
function sendPasswordResetEmail($email, $firstName, $token, $adminPanel) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/') ?: '';
    $resetLink = $protocol . '://' . $host . $basePath . '/reset.php?token=' . urlencode($token);

    $subject = "Восстановление пароля - " . htmlspecialchars($adminPanel, ENT_QUOTES, 'UTF-8');
    $safeResetLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');
    $body = "
        <h2 style='margin-top:0;'>Здравствуйте, " . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . "!</h2>
        <p>Мы получили запрос на восстановление пароля для вашего аккаунта в " . htmlspecialchars($adminPanel, ENT_QUOTES, 'UTF-8') . ".</p>
        <div style='text-align:center;margin:24px 0;'>
            <a href='{$safeResetLink}' style='display:inline-block;padding:12px 24px;background-color:#0d6efd;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;'>Восстановить пароль</a>
        </div>
        <p style='margin:0 0 12px;'>Если кнопка не работает, скопируйте и вставьте следующую ссылку в ваш браузер:</p>
        <p style='word-break:break-all;background-color:#f1f5f9;padding:12px;border-radius:8px;margin:0 0 16px;'>
            <a href='{$safeResetLink}' style='color:#0d6efd;text-decoration:none;'>{$safeResetLink}</a>
        </p>
        <div style='background-color:#fff7ed;border:1px solid #fed7aa;padding:12px;border-radius:8px;color:#92400e;margin-bottom:16px;'>
            <strong>Внимание:</strong> Ссылка действительна в течение 1 часа.
        </div>
        <p style='margin:0;'>Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо.</p>
    ";
    $messageBody = buildEmailTemplate('Восстановление пароля', $body, $adminPanel);

    return sendEmail($email, $subject, $messageBody, $adminPanel);
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
 * @param string $adminPanel Название админ-панели
 * @return bool Возвращает true если письмо отправлено успешно, false в случае ошибки
 */
function sendVerificationEmail($email, $firstName, $verificationToken, $adminPanel) {
    // Тема письма
    $subject = "Подтверждение регистрации - " . $adminPanel;
    
    // Правильное формирование URL для верификации
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['PHP_SELF']);
    
    // Убираем лишние слеши и формируем базовый URL
    $baseUrl = rtrim($protocol . "://" . $host . $scriptPath, '/');
    $verificationUrl = $baseUrl . "/verify_email.php?token=" . urlencode($verificationToken);
    
    // HTML-шаблон письма подтверждения
    $safeVerificationUrl = htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8');
    $body = "
        <h2 style='margin-top:0;'>Здравствуйте, " . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . "!</h2>
        <p>Благодарим вас за регистрацию в " . htmlspecialchars($adminPanel, ENT_QUOTES, 'UTF-8') . ". Для завершения регистрации и активации вашего аккаунта, пожалуйста, подтвердите ваш email адрес.</p>
        <div style='text-align:center;margin:24px 0;'>
            <a href='{$safeVerificationUrl}' style='display:inline-block;padding:12px 24px;background-color:#0d6efd;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;'>Подтвердить Email</a>
        </div>
        <p style='margin:0 0 12px;'>Если кнопка не работает, скопируйте и вставьте следующую ссылку в ваш браузер:</p>
        <p style='word-break:break-all;background-color:#f1f5f9;padding:12px;border-radius:8px;margin:0 0 16px;'>
            <a href='{$safeVerificationUrl}' style='color:#0d6efd;text-decoration:none;'>{$safeVerificationUrl}</a>
        </p>
        <p style='margin:0;'><strong>Ссылка действительна в течение 24 часов.</strong></p>
        <p style='margin:16px 0 0;'>Если вы не регистрировались в " . htmlspecialchars($adminPanel, ENT_QUOTES, 'UTF-8') . ", пожалуйста, проигнорируйте это письмо.</p>
    ";
    $message = buildEmailTemplate('Подтверждение регистрации', $body, $adminPanel);
    
    // Отправка email с выбором метода
    return sendEmail($email, $subject, $message, $adminPanel);
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
function sendWelcomeEmail($email, $firstName, $adminPanel) {
    // Тема приветственного письма
    $subject = "Добро пожаловать в " . htmlspecialchars($adminPanel, ENT_QUOTES, 'UTF-8') . "!";

    $branding = getEmailBrandingData($adminPanel);
    $baseUrl = htmlspecialchars($branding['base_url'], ENT_QUOTES, 'UTF-8');
    $body = "
        <h2 style='margin-top:0;'>Поздравляем, " . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . "!</h2>
        <p>Ваш аккаунт в " . htmlspecialchars($adminPanel, ENT_QUOTES, 'UTF-8') . " успешно активирован.</p>
        <p>Теперь вы можете войти в систему и начать работу.</p>
        <div style='text-align:center;margin:24px 0;'>
            <a href='{$baseUrl}' style='display:inline-block;padding:12px 24px;background-color:#22c55e;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;'>Перейти в панель</a>
        </div>
        <p style='margin:0;'>Если у вас возникнут вопросы, не стесняйтесь обращаться в нашу службу поддержки.</p>
    ";
    $message = buildEmailTemplate('Добро пожаловать', $body, $adminPanel);
    
    // Отправка email с выбором метода
    return sendEmail($email, $subject, $message, $adminPanel);
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
function sendAccountEmail($email, $password, $adminPanel, $firstName = '') {
    // Тема письма
    $subject = "Данные вашего аккаунта - " . $adminPanel;
    
    // Правильное формирование URL
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $scriptPath = dirname($_SERVER['PHP_SELF']);
    $baseUrl = rtrim($protocol . "://" . $host . $scriptPath, '/');

    $safeBaseUrl = htmlspecialchars($baseUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = "
        <h2 style='margin-top:0;'>Здравствуйте" . ($firstName ? ", " . htmlspecialchars($firstName, ENT_QUOTES | ENT_HTML5, 'UTF-8') : "") . "!</h2>
        <p>Ваш аккаунт был создан/обновлен в системе " . htmlspecialchars($adminPanel, ENT_QUOTES, 'UTF-8') . ".</p>
        <div style='background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px;margin:20px 0;'>
            <h3 style='margin:0 0 12px;'>Ваши данные для входа:</h3>
            <p style='margin:0 0 8px;'><strong>Email:</strong> " . htmlspecialchars($email, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "</p>
            <p style='margin:0;'><strong>Пароль:</strong> " . htmlspecialchars($password, ENT_QUOTES | ENT_HTML5, 'UTF-8') . "</p>
        </div>
        <div style='background-color:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:12px;margin-bottom:16px;color:#92400e;'>
            <strong>Важно:</strong> Рекомендуем сменить пароль после первого входа в систему.
        </div>
        <div style='text-align:center;margin:24px 0;'>
            <a href='{$safeBaseUrl}' style='display:inline-block;padding:12px 24px;background-color:#0d6efd;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;'>Войти в систему</a>
        </div>
        <p style='margin:0;'>Если кнопка не работает, перейдите по ссылке: <a href='{$safeBaseUrl}' style='color:#0d6efd;text-decoration:none;'>{$safeBaseUrl}</a></p>
        <p style='margin:16px 0 0;'>Если вы не ожидали это письмо, пожалуйста, проигнорируйте его.</p>
    ";
    $message = buildEmailTemplate('Данные вашего аккаунта', $body, $adminPanel);
    
    // Отправка email
    return sendEmail($email, $subject, $message, $adminPanel);
}

/**
 * Отправляет уведомление администратору о новом обращении или новом сообщении
 *
 * @param string $adminEmail Email администратора
 * @param string $userEmail Email пользователя
 * @param string $subject Тема обращения (если новое)
 * @param string $ticketId ID тикета
 * @param bool $isNewTicket Создано ли новое обращение
 * @param string $adminPanel Название админ-панели
 * @return bool Успешность отправки
 */
function sendAdminSupportNotification($adminEmail, $userEmail, $subject, $ticketId, $adminPanel, $isNewTicket = false) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = rtrim($protocol . '://' . $host, '/');
    $ticketUrl = $baseUrl . '/admin/support/view.php?id=' . (int)$ticketId;

    if ($isNewTicket) {
        $subjectLine = "🆕 Новое обращение в поддержку (#{$ticketId})";
        $title = 'Новое обращение в поддержку';
        $body = "
            <h2 style='margin-top:0;'>Новое обращение от пользователя</h2>
            <p><strong>Пользователь:</strong> " . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . "</p>
            <p><strong>Тема:</strong> " . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . "</p>
            <div style='text-align:center;margin:24px 0;'>
                <a href='" . htmlspecialchars($ticketUrl, ENT_QUOTES, 'UTF-8') . "' style='display:inline-block;padding:12px 24px;background-color:#0d6efd;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;'>Перейти к обращению</a>
            </div>
        ";
    } else {
        $subjectLine = "💬 Новое сообщение в обращении (#{$ticketId})";
        $title = 'Новое сообщение в поддержке';
        $body = "
            <h2 style='margin-top:0;'>Новое сообщение от пользователя</h2>
            <p><strong>Пользователь:</strong> " . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . "</p>
            <div style='text-align:center;margin:24px 0;'>
                <a href='" . htmlspecialchars($ticketUrl, ENT_QUOTES, 'UTF-8') . "' style='display:inline-block;padding:12px 24px;background-color:#0d6efd;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;'>Перейти к переписке</a>
            </div>
        ";
    }

    $message = buildEmailTemplate($title, $body, $adminPanel);

    return sendEmail($adminEmail, $subjectLine, $message, $adminPanel);
}

/**
 * Отправляет уведомление пользователю об ответе от поддержки
 *
 * @param string $userEmail Email пользователя
 * @param string $adminMessage Текст ответа от админа
 * @param int $ticketId ID тикета
 * @param string $adminPanel Название админ-панели
 * @return bool Успешность отправки
 */
function sendUserSupportReply($userEmail, $adminMessage, $ticketId, $adminPanel) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = rtrim($protocol . '://' . $host, '/');
    $ticketUrl = $baseUrl . '/support.php'; // Пользователь всегда переходит в свой support.php

    $subjectLine = "📨 Ответ от техподдержки (обращение #{$ticketId})";
    $body = "
        <h2 style='margin-top:0;'>Получен ответ от техподдержки</h2>
        <blockquote style='border-left:4px solid #0d6efd;padding-left:15px;margin:15px 0;font-style:italic;color:#334155;'>
            " . nl2br(htmlspecialchars($adminMessage, ENT_QUOTES, 'UTF-8')) . "
        </blockquote>
        <p>Вы можете продолжить переписку в <a href='" . htmlspecialchars($ticketUrl, ENT_QUOTES, 'UTF-8') . "' style='color:#0d6efd;text-decoration:none;'>личном кабинете</a>.</p>
    ";

    $message = buildEmailTemplate('Ответ техподдержки', $body, $adminPanel);

    return sendEmail($userEmail, $subjectLine, $message, $adminPanel);
}


/**
 * Отправляет письмо со ссылкой подтверждения смены email
 * 
 * @param string $to Новый email
 * @param string $confirmLink Ссылка подтверждения
 * @param string $adminPanelName Название системы
 * @return bool Успешность отправки
 */
function sendEmailChangeConfirmationLink($to, $confirmLink, $adminPanelName, $adminPanel) {
    $subject = "Подтверждение смены email — " . $adminPanelName;
    $safeConfirmLink = htmlspecialchars($confirmLink, ENT_QUOTES, 'UTF-8');
    $body = "
        <h2 style='margin-top:0;'>Смена email</h2>
        <p>Вы запросили смену email-адреса.</p>
        <p>Чтобы подтвердить новый email, перейдите по ссылке ниже:</p>
        <div style='text-align:center;margin:24px 0;'>
            <a href='{$safeConfirmLink}' style='display:inline-block;padding:12px 24px;background-color:#0d6efd;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;'>Подтвердить email</a>
        </div>
        <p style='margin:0 0 12px;'>Если кнопка не работает, скопируйте ссылку в браузер:</p>
        <p style='word-break:break-all;background-color:#f1f5f9;padding:12px;border-radius:8px;margin:0 0 16px;'>
            <a href='{$safeConfirmLink}' style='color:#0d6efd;text-decoration:none;'>{$safeConfirmLink}</a>
        </p>
        <p style='margin:0;'><strong>Ссылка действительна в течение 1 часа.</strong></p>
        <p style='margin:16px 0 0;'>Если вы не запрашивали смену email, просто проигнорируйте это письмо.</p>
    ";
    $message = buildEmailTemplate('Подтверждение смены email', $body, $adminPanel);

    return sendEmail($to, $subject, $message, $adminPanel);
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
function sendEmail($to, $subject, $message, $adminPanel) {
    if (preg_match("/[\r\n]/", $to) || preg_match("/[\r\n]/", $subject)) {
        return false;
    }

    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $domain = preg_replace('/:\d+$/', '', $domain);
    $domain = preg_replace('/[^a-zA-Z0-9\.\-]/', '', $domain);
    $domain = trim($domain, '.-');
    if ($domain === '') {
        $domain = 'localhost';
    }
    $boundary = '==Multipart_Boundary_x' . getRandomToken(12) . 'x';
    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
        : $subject;
    $fromName = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($adminPanel, 'UTF-8', 'B', "\r\n")
        : $adminPanel;
    $plainText = buildPlainTextEmail($message);

    // Формируем базовые заголовки
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: {$fromName} <" . MAIL_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . MAIL_FROM_EMAIL . "\r\n";
    $headers .= "Return-Path: " . MAIL_FROM_EMAIL . "\r\n";
    $headers .= "Date: " . date(DATE_RFC2822) . "\r\n";
    $messageTimestamp = str_replace('.', '_', sprintf('%.6f', microtime(true)));
    $headers .= "Message-ID: <{$messageTimestamp}." . getRandomToken(8) . "@{$domain}>\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    $emailBody = "--{$boundary}\r\n";
    $emailBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $emailBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $emailBody .= $plainText . "\r\n";
    $emailBody .= "--{$boundary}\r\n";
    $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
    $emailBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $emailBody .= $message . "\r\n";
    $emailBody .= "--{$boundary}--";
    
    // Проверяем, настроен ли SMTP
    if (!empty(MAIL_SMTP_USER) && !empty(MAIL_SMTP_PASS)) {
        // Используем SMTP отправку
        return sendSmtpEmail($to, $encodedSubject, $emailBody, $headers);
    } else {
        // Используем стандартную функцию mail()
        return mail($to, $encodedSubject, $emailBody, $headers, '-f ' . MAIL_FROM_EMAIL);
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
    
    error_log("Запрошена отправка через SMTP, но не реализована. Используется резервный вариант mail().");
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

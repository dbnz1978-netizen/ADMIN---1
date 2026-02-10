<?php
/**
 * Файл: /admin/functions/sanitization.php
 * Безопасное экранирование строки для вывода в HTML.
 *
 * Преобразует специальные символы в HTML-сущности, чтобы предотвратить XSS-атаки.
 * Использует флаги ENT_QUOTES (экранирует одинарные и двойные кавычки)
 * и ENT_HTML5 (соответствует спецификации HTML5).
 * Кодировка: UTF-8. Пустое или null-значение преобразуется в пустую строку.
 *
 * Пример:
 *   echo escape('<script>alert("XSS")</script>');
 *   // Выведет: &lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;
 *
 * @param mixed $value Значение для экранирования (строка, null, число и т.п.)
 * @return string Экранированная строка в кодировке UTF-8
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

// Защита от повторного объявления: проверяем, существует ли функция или уже определена константа-маркер
if (!function_exists('escape')) {
    function escape($value) {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
/**
 * 
// Безопасное экранирование строки для вывода в HTML.
$firstName = escape($userData['first_name'] ?? 'Пользователь');
 */


/**
 * Валидация email-адреса
 *
 * @param string $email
 * @return array Ассоциативный массив с ключами:
 *   - 'email': string|null — исходный email при успехе, иначе null или сообщение
 *   - 'valid': bool — true, если email валиден
 *   - 'error': string|null — сообщение об ошибке, если есть
 */
if (!function_exists('validateEmail')) {
    function validateEmail(string $email): array
    {
        // Проверка на корректность формата через filter_var
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'email' => null,
                'valid' => false,
                'error' => 'Введите корректный email адрес!'
            ];
        }

        // Общая длина не должна превышать 254 символа (RFC 5321)
        if (strlen($email) > 254) {
            return [
                'email' => null,
                'valid' => false,
                'error' => 'Email не должен превышать 254 символа.'
            ];
        }

        // Разделение на локальную часть и домен
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return [
                'email' => null,
                'valid' => false,
                'error' => 'Email имеет некорректную структуру.'
            ];
        }

        [$local, $domain] = $parts;

        // RFC 5321: локальная часть ≤ 64 символа, домен ≤ 253 символа
        if (strlen($local) > 64 || strlen($domain) > 253) {
            return [
                'email' => null,
                'valid' => false,
                'error' => 'Email имеет некорректную структуру.'
            ];
        }

        // Всё в порядке
        return [
            'email' => $email,
            'valid' => true,
            'error' => null
        ];
    }
}
/**
 *  
// Валидация email-адреса
$resultEmail = validateEmail("user@example.com");
if ($resultEmail['valid']) {
    echo "Email валиден: " . $resultEmail['email'];
} else {
    echo "Ошибка: " . $resultEmail['error'];
}
*/


/**
 * Валидация пароля
 *
 * Требования:
 * - 6–72 символа (6 — минимальный лимит по ТЗ, 72 — максимум для bcrypt)
 * - хотя бы одна латинская буква
 * - хотя бы одна цифра
 * - не входит в список распространённых слабых паролей
 *
 * @param string $password
 * @return array Ассоциативный массив:
 *   - 'valid'  : bool
 *   - 'error'  : string|null — первая ошибка (для простого UI)
 *   - 'value'  : string|null — исходный пароль при успехе
 */
if (!function_exists('validatePassword')) {
    function validatePassword(string $password): array
    {
        // 1. Проверка на пустоту
        if ($password === '') {
            return [
                'valid' => false,
                'error' => 'Пароль не может быть пустым.',
                'value' => null
            ];
        }

        $len = strlen($password);

        // 2. Максимальная длина — 72 символа (рекомендация для bcrypt)
        if ($len > 72) {
            return [
                'valid' => false,
                'error' => 'Пароль не должен превышать 72 символа (из соображений безопасности).',
                'value' => null
            ];
        }

        // 3. Минимальная длина — 6 символов (по вашему ТЗ)
        if ($len < 6) {
            return [
                'valid' => false,
                'error' => 'Пароль должен содержать не менее 6 символов.',
                'value' => null
            ];
        }

        // 4. Хотя бы одна латинская буква
        if (!preg_match('/[a-zA-Z]/', $password)) {
            return [
                'valid' => false,
                'error' => 'Пароль должен содержать хотя бы одну латинскую букву.',
                'value' => null
            ];
        }

        // 5. Хотя бы одна цифра
        if (!preg_match('/\d/', $password)) {
            return [
                'valid' => false,
                'error' => 'Пароль должен содержать хотя бы одну цифру.',
                'value' => null
            ];
        }

        // 6. Проверка на известные слабые пароли (регистронезависимо)
        $commonPasswords = ['123456', 'password', '123456789', '12345678', 'qwerty', 'abc123', 'password1', '111111', '123123', 'admin123'];
        if (in_array(strtolower($password), $commonPasswords, true)) {
            return [
                'valid' => false,
                'error' => 'Пароль слишком простой и небезопасен.',
                'value' => null
            ];
        }

        // Всё в порядке
        return [
            'valid' => true,
            'error' => null,
            'value' => $password
        ];
    }
}
/**
 * 
// Валидация пароля
$resultPass = validatePassword('@Dbnz1978@@');
if ($resultPass['valid']) {
    echo "Пароль валиден: " . htmlspecialchars($resultPass['value']);
} else {
    echo "Ошибка: " . htmlspecialchars($resultPass['error']);
}
*/


/**
 * Валидация текстового поля (имя, фамилия и т.п.)
 *
 * Разрешённые символы: буквы (кириллица, латиница), пробелы, дефис, апострофы (включая типографские: ', ’, ʼ)
 * Проверка длины — с учётом Unicode (mb_strlen)
 *
 * @param string $value
 * @param int $minLength Минимальная длина (по умолчанию 2)
 * @param int $maxLength Максимальная длина (по умолчанию 50)
 * @param string $fieldName Название поля для сообщения об ошибке (по умолчанию "Поле")
 * @return array Ассоциативный массив с ключами:
 *   - 'valid' (bool): true, если валидно
 *   - 'error' (string|null): сообщение об ошибке или null
 *   - 'value' (string|null): очищенное значение или null
 */
if (!function_exists('validateNameField')) {
    function validateNameField(
        string $value,
        int $minLength = 2,
        int $maxLength = 50,
        string $fieldName = 'Поле'
    ): array { // ← ИЗМЕНЕНО: было ?string → стало array
        // Убираем крайние пробелы
        $value = trim($value);

        // Пустая строка после trim — ошибка
        if ($value === '') {
            return [
                'valid' => false,
                'error' => "{$fieldName} не может быть пустым.",
                'value' => null
            ];
        }

        // Проверка на допустимые символы
        if (!preg_match('/^[а-яА-ЯёЁa-zA-Z\s\-\'ʼ’]+$/u', $value)) {
            return [
                'valid' => false,
                'error' => "{$fieldName} может содержать только буквы, пробелы, дефисы и апострофы.",
                'value' => null
            ];
        }

        $len = mb_strlen($value);

        if ($len < $minLength) {
            $errormin = "{$fieldName} должно содержать не менее {$minLength} " . (
                $minLength % 10 === 1 && $minLength % 100 !== 11 ? 'символа' :
                ($minLength % 10 >= 2 && $minLength % 10 <= 4 && !($minLength % 100 >= 12 && $minLength % 100 <= 14) ? 'символа' : 'символов')
            ) . '.';
            // ↑ Более точное склонение (но можно и упростить до "символов" всегда)

            return [
                'valid' => false,
                'error' => $errormin,
                'value' => null
            ];
        }

        if ($len > $maxLength) {
            $errormax = "{$fieldName} не должно превышать {$maxLength} " . (
                $maxLength % 10 === 1 && $maxLength % 100 !== 11 ? 'символа' :
                ($maxLength % 10 >= 2 && $maxLength % 10 <= 4 && !($maxLength % 100 >= 12 && $maxLength % 100 <= 14) ? 'символа' : 'символов')
            ) . '.';

            return [
                'valid' => false,
                'error' => $errormax,
                'value' => null
            ];
        }

        // Всё в порядке
        return [
            'valid' => true,
            'error' => null,
            'value' => $value
        ];
    }
}
/**
 * 
// Валидация текстового поля (имя, фамилия и т.п.)
$result = validateNameField('Слово для проверки', 2, 50, 'Название поля');
if ($result['valid']) {
    echo "Поле валидно: " . htmlspecialchars($result['value']);
} else {
    echo "Ошибка: " . htmlspecialchars($result['error']);
}
 */


/**
 * Валидация многострочного текстового поля (textarea: описание, комментарий, сообщение и т.п.)
 *
 * Разрешены: буквы (кириллица, латиница), цифры, знаки препинания, спецсимволы, пробелы, переносы строк.
 * Запрещены: управляющие символы (кроме \n, \r, \t), NULL-байты.
 * Подходит для описаний, отзывов, личных сообщений и т.д.
 *
 * @param string $value
 * @param int $minLength Минимальная длина (по умолчанию 1)
 * @param int $maxLength Максимальная длина (по умолчанию 1000)
 * @param string $fieldName Название поля для сообщения об ошибке (по умолчанию "Поле")
 * @return array Ассоциативный массив:
 *   - 'valid'  => bool
 *   - 'error'  => string|null
 *   - 'value'  => string|null (очищенное значение с trim-ом по краям, но с сохранением внутренних переносов)
 */
if (!function_exists('validateTextareaField')) {
    function validateTextareaField(
        string $value,
        int $minLength = 1,
        int $maxLength = 1000,
        string $fieldName = 'Поле'
    ): array {
        // Удаляем только пробелы/табы/переносы ПО КРАЯМ (внутри — оставляем!)
        $value = trim($value);

        // Пустое поле — ошибка (если minLength > 0)
        if ($value === '') {
            if ($minLength <= 0) {
                return ['valid' => true, 'error' => null, 'value' => null];
            }
            return [
                'valid' => false,
                'error' => "{$fieldName} не может быть пустым.",
                'value' => null
            ];
        }

        // Защита от NULL-байтов и опасных управляющих символов (кроме \n \r \t)
        // Удаляем/проверяем: NUL, BEL, ESC и др. (коды 0-8, 11-31 кроме 9=\t, 10=\n, 13=\r)
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            return [
                'valid' => false,
                'error' => "{$fieldName} содержит недопустимые управляющие символы.",
                'value' => null
            ];
        }

        // Проверка длины (в символах, а не байтах!)
        $len = mb_strlen($value);
        if ($len < $minLength) {
            $word = match (true) {
                $minLength % 10 === 1 && $minLength % 100 !== 11 => 'символ',
                $minLength % 10 >= 2 && $minLength % 10 <= 4 && !($minLength % 100 >= 12 && $minLength % 100 <= 14) => 'символа',
                default => 'символов'
            };
            return [
                'valid' => false,
                'error' => "{$fieldName} должно содержать не менее {$minLength} {$word}.",
                'value' => null
            ];
        }

        if ($len > $maxLength) {
            $word = match (true) {
                $maxLength % 10 === 1 && $maxLength % 100 !== 11 => 'символа',
                $maxLength % 10 >= 2 && $maxLength % 10 <= 4 && !($maxLength % 100 >= 12 && $maxLength % 100 <= 14) => 'символов',
                default => 'символов'
            };
            return [
                'valid' => false,
                'error' => "{$fieldName} не должно превышать {$maxLength} {$word}.",
                'value' => null
            ];
        }

        // Всё в порядке
        return [
            'valid' => true,
            'error' => null,
            'value' => $value  // сохраняем переносы строк, пробелы внутри и т.д.
        ];
    }
}
/**
// Валидация многострочного текстового поля
$result = validateTextareaField('Название поля', 5, 500, 'Название поля')
if ($result['valid']) {
    $cleanMessage = $result['value']; // ← содержит \n, \r\n, цифры, знаки — всё, что нужно
} else {
    $errors[] = $result['error'];
}
 */


/**
 * Валидация телефонного номера
 *
 * Поддерживает необязательное поле (пустая строка — валидна).
 * Если указан — проверяется длина (≤30 символов) и базовый формат:
 * - допускаются: цифры, пробелы, дефисы, скобки, плюс, точка
 * - должен содержать хотя бы 6 цифр (минимум для локального номера)
 *
 * @param string $phone
 * @return array Ассоциативный массив:
 *   - 'valid'  : bool
 *   - 'error'  : string|null — сообщение об ошибке или null
 *   - 'value'  : string|null — нормализованное значение (сам номер, без очистки) или null
 */
if (!function_exists('validatePhone')) {
    function validatePhone(string $phone): array
    {
        $phone = trim($phone);

        // Пустой телефон — допустим (необязательное поле)
        if ($phone === '') {
            return [
                'valid' => true,
                'error' => null,
                'value' => null
            ];
        }

        // Проверка максимальной длины — 30 символов (как в исходном коде)
        if (mb_strlen($phone) > 30) {
            return [
                'valid' => false,
                'error' => 'Телефон не должен превышать 30 символов.',
                'value' => null
            ];
        }

        // Базовая проверка: только допустимые символы
        // Разрешены: +, цифры, пробел, -, (, ), .
        if (!preg_match('/^[\+\d\s\-\(\)\.]+$/', $phone)) {
            return [
                'valid' => false,
                'error' => 'Телефон содержит недопустимые символы.',
                'value' => null
            ];
        }

        // Извлекаем только цифры для проверки минимальной длины
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 6) {
            return [
                'valid' => false,
                'error' => 'Телефон слишком короткий — укажите не менее 6 цифр.',
                'value' => null
            ];
        }

        // Всё в порядке
        return [
            'valid' => true,
            'error' => null,
            'value' => $phone
        ];
    }
}
/**
// Валидация телефонного номера
$resultPhone = validatePhone('+7 (123) 123-12-12');
if ($resultPhone['valid']) {
    $phone = $resultPhone['value'] ?? '';
} else {
    $errors[] = $resultPhone['error'];
    $phone = false;
}
 */


/**
 * Валидация строки вида "1,2,3,4" с ограничением по количеству элементов
 *
 * @param string $input     Входная строка
 * @param int    $maxCount  Макс. количество элементов (по умолчанию: 10)
 * @return array ['valid' => bool, 'value' => string, 'error' => string|null]
 */
function validateIdList(string $input, int $maxCount = 10): array
{
    // 1. Удаляем всё, кроме цифр и запятых
    $cleaned = preg_replace('/[^0-9,]/', '', $input);

    // 2. Нормализуем: убираем лишние запятые и по краям
    $cleaned = trim($cleaned, ',');
    $cleaned = preg_replace('/,{2,}/', ',', $cleaned);

    // 3. Если пусто — валидно
    if ($cleaned === '') {
        return [
            'valid' => true,
            'value' => '',
            'error' => null
        ];
    }

    // 4. Разбиваем на части
    $parts = explode(',', $cleaned);

    // 5. Фильтруем пустые (на случай ",,")
    $parts = array_filter($parts, 'strlen'); // оставляем только непустые
    $parts = array_values($parts); // сбрасываем ключи

    // 6. Обрезаем до $maxCount элементов
    if (count($parts) > $maxCount) {
        $parts = array_slice($parts, 0, $maxCount);
    }

    // 7. Формируем финальную строку
    $finalValue = implode(',', $parts);

    // 8. Возвращаем результат — всегда валидно, т.к. мы чистим и ограничиваем, а не отклоняем
    return [
        'valid' => true,
        'value' => $finalValue,
        'error' => null
    ];
}
/**
 * Валидация идентификатора секции (например, для DOM-элементов)
 *
 * Разрешены: латинские буквы, цифры, подчёркивания и дефисы.
 *
 * @param string $value
 * @param string $fieldName
 * @return array ['valid' => bool, 'value' => string|null, 'error' => string|null]
 */
if (!function_exists('validateSectionId')) {
    function validateSectionId(string $value, string $fieldName = 'Секция'): array
    {
        $value = trim($value);

        if ($value === '') {
            return [
                'valid' => false,
                'value' => null,
                'error' => "{$fieldName} не может быть пустой."
            ];
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            return [
                'valid' => false,
                'value' => null,
                'error' => "{$fieldName} содержит недопустимые символы."
            ];
        }

        return [
            'valid' => true,
            'value' => $value,
            'error' => null
        ];
    }
}
/**
 * 
// Валидация строки вида "123,456,789"
$input = "  abc123,,456x789, 0001  "; // "грязная" строка
$result = validateIdList($input, $maxDigits = 10);
if ($result['valid']) {
    $stringId = $result['value']; // '123,456,789,0001'
} else {
    $errors[] = $result['error']; // например: "Часть '12345678901' превышает допустимую длину (10 цифр)."
    $stringId = false;
}
 */


/**
 * Безопасная очистка HTML от пользователя
 * Поддерживает только разрешённые теги и атрибуты
 * Удаляет JavaScript, on*-атрибуты, data:, vbscript и др.
 */
/**
 * Безопасная очистка HTML от пользователя
 */
function sanitizeHtmlFromEditor($html) {
    if (empty($html)) {
        return '';
    }

    // Разрешённые теги и их атрибуты
    $allowedTags = [
        'p' => [],
        'br' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'table' => ['style'],
        'thead' => [],
        'tbody' => [],
        'tfoot' => [],
        'tr' => [],
        'th' => ['style'],
        'td' => ['style'],
        'span' => ['style', 'data-font-style'],
        'div' => ['style', 'class'],
        'section' => ['class'],
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'style', 'class'],
        'font' => ['color']
    ];

    $dangerousProtocols = ['javascript:', 'vbscript:', 'data:', 'mocha:', 'livescript:'];

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();

    // Оборачиваем в <div>
    $html = '<div>' . $html . '</div>';

    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, 
                   LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);

    // Удаляем фиктивный xml-тег, если он есть
    if ($doc->documentElement->firstChild && $doc->documentElement->firstChild->nodeName === 'xml') {
        $doc->removeChild($doc->documentElement->firstChild);
    }

    sanitizeDomNode($doc->documentElement, $allowedTags, $dangerousProtocols);

    $cleanHtml = '';
    foreach ($doc->documentElement->childNodes as $child) {
        $cleanHtml .= $doc->saveHTML($child);
    }

    // Починка <br>
    $cleanHtml = preg_replace('/<br\s*\/?>\s*<\/br>/i', '<br>', $cleanHtml);
    $cleanHtml = preg_replace('/<br\s*\/?>/i', '<br>', $cleanHtml);

    return trim($cleanHtml);
}

/**
 * Рекурсивная очистка DOM-узла
 */
function sanitizeDomNode($node, $allowedTags, $dangerousProtocols) {
    if ($node->nodeType === XML_ELEMENT_NODE) {
        $tagName = strtolower($node->tagName);
        
        if (!isset($allowedTags[$tagName])) {
            $fragment = $node->ownerDocument->createDocumentFragment();
            while ($node->firstChild) {
                $fragment->appendChild($node->firstChild);
            }
            $node->parentNode->replaceChild($fragment, $node);
            return;
        }

        $allowedAttrs = $allowedTags[$tagName];
        $attrsToRemove = [];

        foreach ($node->attributes as $attr) {
            $attrName = strtolower($attr->name);
            $attrValue = $attr->value;

            // Удаляем on*-атрибуты
            if (strpos($attrName, 'on') === 0) {
                $attrsToRemove[] = $attr->name;
                continue;
            }

            // Проверяем разрешённость атрибута
            if (!in_array($attrName, $allowedAttrs)) {
                $attrsToRemove[] = $attr->name;
                continue;
            }

            // Проверка протоколов для href/src
            if (in_array($attrName, ['href', 'src'])) {
                $lowerValue = strtolower(trim($attrValue));
                $isDangerous = false;
                foreach ($dangerousProtocols as $protocol) {
                    if (strpos($lowerValue, $protocol) === 0) {
                        $isDangerous = true;
                        break;
                    }
                }
                if ($isDangerous || (!preg_match('/^(https?:\/\/|mailto:|\/|#)/i', $lowerValue) && !empty($lowerValue))) {
                    $attrsToRemove[] = $attr->name;
                }
            }

            // Обработка style
            if ($attrName === 'style') {
                $safeStyles = [];
                $styles = explode(';', $attrValue);
                foreach ($styles as $style) {
                    $style = trim($style);
                    if (empty($style)) continue;
                    
                    $parts = explode(':', $style, 2);
                    if (count($parts) !== 2) continue;
                    
                    $prop = trim(strtolower($parts[0]));
                    $val = trim($parts[1]);
                    
                    if (in_array($prop, ['text-align', 'color', 'background-color', 'width', 'height', 'font-family', 'font-size'])) {
                        if (strpos($val, 'url(') !== false || strpos($val, 'expression') !== false) {
                            continue;
                        }
                        $val = preg_replace('/[\'"]/','',$val);
                        $safeStyles[] = "$prop: $val";
                    }
                }
                $newStyleValue = implode('; ', $safeStyles);
                if ($newStyleValue) {
                    $attr->value = $newStyleValue;
                } else {
                    $attrsToRemove[] = $attr->name;
                }
            }
        }

        foreach ($attrsToRemove as $attrName) {
            $node->removeAttribute($attrName);
        }

        // Рекурсия
        $childNodes = [];
        foreach ($node->childNodes as $child) {
            $childNodes[] = $child;
        }
        foreach ($childNodes as $child) {
            sanitizeDomNode($child, $allowedTags, $dangerousProtocols);
        }
    }
}
/**
 * 
// Валидация  HTML от пользователя
$postContent = '';
$postContent = sanitizeHtmlFromEditor($_POST['post_content']);
 */

// Транслитерация (кириллица → латиница)
function transliterate($text) {
    $cyr = [
        'а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п',
        'р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я',
        'А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О','П',
        'Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я'
    ];
    $lat = [
        'a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p',
        'r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya',
        'A','B','V','G','D','E','YO','ZH','Z','I','Y','K','L','M','N','O','P',
        'R','S','T','U','F','KH','TS','CH','SH','SHCH','','Y','','E','YU','YA'
    ];
    $text = str_replace($cyr, $lat, $text);
    $text = preg_replace('/[^a-zA-Z0-9\-_]+/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    $text = trim($text, '-');
    return strtolower($text);
}

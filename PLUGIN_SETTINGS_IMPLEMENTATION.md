# Plugin-Specific Settings Implementation

## Обзор

Данная реализация добавляет возможность настройки плагин-специфичных параметров для `news-plugin`, которые не влияют на глобальные настройки системы.

## Реализованные возможности

### 1. Настройки размеров изображений (Thumbnail)

Администратор может настроить размеры thumbnail специфично для плагина:
- **Ширина (px)**: положительное целое число >= 1
- **Высота (px)**: положительное целое число >= 1  
- **Режим обработки**: `cover` (обрезка) или `contain` (вписывание)

Если настройки не указаны, используются глобальные значения из таблицы `users.data` администратора.

### 2. Лимиты количества изображений

Для каждой страницы плагина можно задать свой лимит `maxDigits`:
- **add_article.php**: максимум изображений при добавлении новости
- **add_extra.php**: максимум изображений в дополнительном контенте

Диапазон значений: 0-1000. По умолчанию: 50.

## Технические детали

### Хранение настроек

Настройки сохраняются в таблице `plugins`, колонка `settings` (TEXT, JSON) для записи с `name='news-plugin'`.

**Структура JSON:**
```json
{
  "image_sizes": {
    "thumbnail": [100, 100, "cover"]
  },
  "limits": {
    "add_article": {
      "maxDigits": 50
    },
    "add_extra": {
      "maxDigits": 50
    }
  }
}
```

### Новые файлы

#### `/plugins/news-plugin/functions/plugin_settings.php`

Содержит helper-функции для работы с настройками плагина:

**`getPluginSettings($pdo, $pluginName)`**
- Читает настройки из таблицы `plugins`
- Возвращает массив настроек или пустой массив при ошибке/отсутствии
- Обрабатывает невалидный JSON

**`savePluginSettings($pdo, $pluginName, $settings)`**
- Сохраняет настройки в таблицу `plugins`
- Кодирует массив в JSON
- Возвращает `true` при успехе, `false` при ошибке

**`getPluginImageSizes($pdo, $pluginName)`**
- Возвращает размеры изображений с учётом переопределений плагина
- Берёт глобальные настройки и применяет override для thumbnail
- Валидирует переопределение перед применением

**`getPluginMaxDigits($pdo, $pluginName, $pageName, $defaultValue)`**
- Возвращает лимит maxDigits для конкретной страницы
- Использует настройки плагина, если они есть
- Возвращает значение по умолчанию, если настройки отсутствуют

### Изменённые файлы

#### `/plugins/news-plugin/pages/settings/access.php`

**Добавлено:**
- UI для настройки thumbnail (ширина, высота, режим)
- UI для настройки лимитов maxDigits
- Валидация входных данных:
  - Ширина/высота: `ctype_digit()` и >= 1
  - Режим: только `cover` или `contain`
  - maxDigits: от 0 до 1000
- CSRF-защита
- Загрузка текущих настроек из БД
- Сохранение настроек в `plugins.settings`

#### `/plugins/news-plugin/pages/articles/add_article.php`

**Изменено:**
- Подключён `plugin_settings.php`
- `$maxDigits = 50` → `$maxDigits = getPluginMaxDigits($pdo, 'news-plugin', 'add_article', 50)`
- `getGlobalImageSizes($pdo)` → `getPluginImageSizes($pdo, 'news-plugin')`

#### `/plugins/news-plugin/pages/articles/add_extra.php`

**Изменено:**
- Подключён `plugin_settings.php`
- `$maxDigits = 50` → `$maxDigits = getPluginMaxDigits($pdo, 'news-plugin', 'add_extra', 50)`
- `getGlobalImageSizes($pdo)` → `getPluginImageSizes($pdo, 'news-plugin')`

## Валидация

### Входные данные (POST)

- **thumbnail_width**: положительное целое число >= 1
- **thumbnail_height**: положительное целое число >= 1
- **thumbnail_fit**: только 'cover' или 'contain'
- **max_digits_add_article**: целое число от 0 до 1000
- **max_digits_add_extra**: целое число от 0 до 1000
- **csrf_token**: обязательная проверка через `hash_equals()`

### Обработка ошибок

- Невалидные значения → сообщение об ошибке, настройки не сохраняются
- Битый JSON в БД → возвращается пустой массив, используются дефолты
- Отсутствие настроек → используются глобальные значения

## Обратная совместимость

### Сценарий 1: Пустая таблица plugins.settings
```php
// settings = NULL или ''
$pluginSettings = getPluginSettings($pdo, 'news-plugin');
// Вернёт: []

$imageSizes = getPluginImageSizes($pdo, 'news-plugin');
// Вернёт глобальные размеры: ['thumbnail' => [100, 100, 'cover'], ...]

$maxDigits = getPluginMaxDigits($pdo, 'news-plugin', 'add_article', 50);
// Вернёт: 50 (default)
```

### Сценарий 2: Битый JSON
```php
// settings = '{invalid json}'
$pluginSettings = getPluginSettings($pdo, 'news-plugin');
// Вернёт: []
// Логирует ошибку (если включено логирование)
```

### Сценарий 3: Частичные настройки
```php
// settings = '{"limits": {"add_article": {"maxDigits": 30}}}'
$imageSizes = getPluginImageSizes($pdo, 'news-plugin');
// Вернёт глобальные размеры (нет переопределения thumbnail)

$maxDigits = getPluginMaxDigits($pdo, 'news-plugin', 'add_article', 50);
// Вернёт: 30 (из настроек)

$maxDigits2 = getPluginMaxDigits($pdo, 'news-plugin', 'add_extra', 50);
// Вернёт: 50 (default, нет в настройках)
```

## Безопасность

### Реализованные меры

1. **CSRF-защита**: проверка токена через `hash_equals()` при POST-запросе
2. **Валидация типов**: все входные данные проверяются на тип и диапазон
3. **SQL Injection**: использование prepared statements в PDO
4. **XSS**: экранирование вывода через `escape()` функцию
5. **Логирование**: все действия логируются с указанием ID пользователя и IP

### Права доступа

- Доступ к странице настроек: только `author='admin'` (через `pluginAccessGuard()`)
- Изменение настроек: только администраторы
- Чтение настроек: доступно всем страницам плагина

## Тестирование

### Проверенные сценарии

✅ Сохранение и загрузка настроек  
✅ Валидация некорректных значений  
✅ Обработка пустых/NULL настроек  
✅ Обработка битого JSON  
✅ Применение переопределений thumbnail  
✅ Использование дефолтов при отсутствии настроек  
✅ CSRF-защита  
✅ Права доступа (только admin)  

### Юнит-тесты

См. `/tmp/test_plugin_settings.php` и `/tmp/test_plugin_functions.php` для проверки функциональности.

## Использование

### Для администраторов

1. Войти в админ-панель под учётной записью с ролью `admin`
2. Открыть `/plugins/news-plugin/pages/settings/access.php`
3. Настроить желаемые параметры
4. Нажать "Сохранить настройки"

### Для разработчиков

```php
// Получить размеры изображений с учётом плагина
$imageSizes = getPluginImageSizes($pdo, 'news-plugin');
$_SESSION["imageSizes_{$sectionId}"] = $imageSizes;

// Получить лимит для страницы
$maxDigits = getPluginMaxDigits($pdo, 'news-plugin', 'add_article', 50);

// Прямой доступ к настройкам
$settings = getPluginSettings($pdo, 'news-plugin');
if (isset($settings['image_sizes']['thumbnail'])) {
    // Используем переопределение
}
```

## Расширение функциональности

Для добавления новых настроек:

1. Обновить структуру JSON в `savePluginSettings()`
2. Добавить поля в форму `/plugins/news-plugin/pages/settings/access.php`
3. Добавить валидацию в блок POST-обработки
4. Создать getter-функцию (аналогично `getPluginMaxDigits()`)
5. Использовать в нужных страницах плагина

## Зависимости

- PHP 7.4+
- PDO extension
- Таблица `plugins` с колонкой `settings` (TEXT)
- Функции из `admin/functions/init.php`
- Функции из `admin/functions/image_sizes.php`
- Функции из `admin/functions/plugin_access.php`

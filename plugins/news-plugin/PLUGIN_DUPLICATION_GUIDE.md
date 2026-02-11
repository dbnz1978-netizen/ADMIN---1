# Руководство по дублированию плагина

## Обзор

Плагин `news-plugin` теперь полностью поддерживает дублирование. Вы можете создать новый плагин на основе этого, просто скопировав директорию и переименовав её.

## Как дублировать плагин

### Шаг 1: Копирование директории

```bash
cd /plugins
cp -r news-plugin my-custom-plugin
```

### Шаг 2: Обновление метаданных плагина

Отредактируйте файл `plugin.json`:

```json
{
  "name": "my-custom-plugin",
  "display_name": "Моя система управления контентом",
  "version": "1.0.0",
  "author": "Ваше имя",
  "description": "Описание вашего плагина",
  "requires_php": "7.4",
  "requires_db": true,
  "has_settings_page": false
}
```

**Важно**: Поле `name` должно совпадать с именем директории плагина.

### Шаг 3: Настройка меню

Отредактируйте файл `nav-config.json`:

```json
{
  "menu_title": "Мой плагин",
  "menu_icon": "bi-box",
  "submenu": [
    {
      "title": "Список записей",
      "icon": "bi-list",
      "url": "/plugins/my-custom-plugin/pages/categories/category_list.php"
    }
  ]
}
```

**Важно**: URL должны указывать на ваш новый плагин (`/plugins/my-custom-plugin/...`).

### Шаг 4: Настройка базы данных

Отредактируйте файл `install.php`:

Измените имена таблиц на уникальные для вашего плагина:

```php
// Было:
CREATE TABLE IF NOT EXISTS `news_categories` ...
CREATE TABLE IF NOT EXISTS `news_articles` ...
CREATE TABLE IF NOT EXISTS `news_extra_content` ...

// Стало:
CREATE TABLE IF NOT EXISTS `mycustom_categories` ...
CREATE TABLE IF NOT EXISTS `mycustom_articles` ...
CREATE TABLE IF NOT EXISTS `mycustom_extra_content` ...
```

Также обновите файл `uninstall.php` с теми же именами таблиц.

### Шаг 5: Обновление настроек в файлах страниц

В каждом файле страницы (например, `category_list.php`, `article_list.php`, и т.д.) обновите переменную `$catalogTable`:

```php
// Было:
$catalogTable = 'news_categories';

// Стало:
$catalogTable = 'mycustom_categories';
```

То же самое для `$categoryTable`, если используется.

## Автоматическое определение имени плагина

Плагин использует функцию `getPluginName()` для автоматического определения своего имени из структуры директорий. Это означает, что:

- ✅ **НЕ НУЖНО** изменять вызовы `pluginAccessGuard($pdo, $pluginName)`
- ✅ **НЕ НУЖНО** изменять вызовы `getPluginMaxDigits($pdo, $pluginName, ...)`
- ✅ **НЕ НУЖНО** изменять вызовы `savePluginSettings($pdo, $pluginName, ...)`

Функция автоматически определит имя как `my-custom-plugin` на основе пути к файлу.

## Что изменить обязательно

### Обязательные изменения:

1. **Имя директории**: `news-plugin` → `my-custom-plugin`
2. **plugin.json**: поле `name` и другие метаданные
3. **nav-config.json**: заголовок меню, иконка, URL в подменю
4. **install.php**: имена таблиц БД
5. **uninstall.php**: имена таблиц БД
6. **Файлы страниц**: переменные `$catalogTable`, `$categoryTable`, `$categoryUrlPrefix`

### НЕ нужно изменять:

1. ❌ Вызовы `getPluginName()` - работают автоматически
2. ❌ Вызовы функций с параметром `$pluginName` - используют автоопределение
3. ❌ Структуру файлов и директорий

## Пример: Создание плагина "Товары"

```bash
# 1. Копируем плагин
cd /plugins
cp -r news-plugin products-plugin

# 2. Редактируем plugin.json
{
  "name": "products-plugin",
  "display_name": "Система управления товарами",
  ...
}

# 3. Редактируем nav-config.json
{
  "menu_title": "Товары",
  "menu_icon": "bi-cart",
  "submenu": [
    {
      "title": "Категории товаров",
      "icon": "bi-folder",
      "url": "/plugins/products-plugin/pages/categories/category_list.php"
    },
    {
      "title": "Список товаров",
      "icon": "bi-list",
      "url": "/plugins/products-plugin/pages/articles/article_list.php"
    }
  ]
}

# 4. Обновляем install.php
CREATE TABLE IF NOT EXISTS `products_categories` ...
CREATE TABLE IF NOT EXISTS `products_items` ...
CREATE TABLE IF NOT EXISTS `products_extra_content` ...

# 5. Обновляем переменные в файлах страниц
$catalogTable = 'products_categories';
$categoryTable = 'products_items';
$categoryUrlPrefix = 'product';
```

## Интеграция с системой меню

После создания плагина:

1. Перейдите в админ-панель → **Плагины** → **Список плагинов**
2. Найдите ваш новый плагин `my-custom-plugin`
3. Нажмите **Установить**
4. После установки нажмите **Активировать**

Меню плагина автоматически появится в боковой панели админки (`/admin/template/sidebar.php`).

## Управление правами доступа

Для каждого плагина можно настроить права доступа:

1. Откройте страницу настроек плагина (доступна только для admin)
2. Установите галочку "Разрешить доступ для роли user" если нужно
3. Сохраните изменения

Права доступа автоматически применяются через функцию `pluginAccessGuard()`.

## Troubleshooting

### Проблема: Меню плагина не отображается

**Решение**: 
- Проверьте, что плагин активирован в списке плагинов
- Убедитесь, что `name` в `plugin.json` совпадает с именем директории
- Проверьте, что URL в `nav-config.json` правильные

### Проблема: Ошибка при установке плагина

**Решение**:
- Проверьте синтаксис SQL в `install.php`
- Убедитесь, что имена таблиц уникальные (не совпадают с другими плагинами)
- Проверьте foreign keys - родительские таблицы должны существовать

### Проблема: Доступ запрещён при открытии страниц плагина

**Решение**:
- Проверьте настройки доступа в settings/access.php
- Убедитесь, что ваша роль имеет доступ к плагину
- Для admin доступ есть всегда, для user нужно включить в настройках

## Оптимизация

Плагин оптимизирован для дублирования:

- ✅ Автоопределение имени плагина
- ✅ Нет избыточного кода
- ✅ Минимальные изменения при дублировании
- ✅ Чистые блоки конфигурации

Удалены неиспользуемые файлы:
- `functions/get_image.php` (функция никогда не вызывалась)
- `functions/get_user_avatar.php` (функция никогда не вызывалась)

## Заключение

Теперь вы можете легко создавать новые плагины на основе `news-plugin`, просто скопировав директорию и изменив несколько ключевых параметров. Вся система автоматически адаптируется к новому имени плагина.

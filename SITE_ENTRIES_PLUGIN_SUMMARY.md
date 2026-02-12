# Плагин "Записи сайта" - Резюме создания

## Обзор
Создан новый плагин **site-entries-plugin** как полная копия плагина **news-plugin** с изменениями для управления записями сайта вместо новостей.

## Что было сделано

### 1. Структура плагина
- Скопирована вся директория `/plugins/news-plugin/` → `/plugins/site-entries-plugin/`
- Всего скопировано 19 файлов

### 2. Конфигурационные файлы

#### plugin.json
- **name**: `"news-plugin"` → `"site-entries-plugin"`
- **display_name**: `"Система управления новостями"` → `"Система управления записями сайта"`
- **description**: Обновлено описание

#### nav-config.json
- **menu_title**: `"Новости"` → `"Записи сайта"`
- **menu_icon**: `"bi-layout-wtf"` → `"bi-file-earmark-text"`
- **Подменю**:
  - "Категории новостей" → "Категории записей"
  - "Список новостей" → "Список записей"
  - "Добавить новость" → "Добавить запись"
- **URL**: Все пути обновлены с `/plugins/news-plugin/` на `/plugins/site-entries-plugin/`

### 3. Таблицы базы данных

#### install.php
Создаются новые таблицы:
- `news_categories` → `site_entries_categories`
- `news_articles` → `site_entries_articles`
- `news_extra_content` → `site_entries_extra_content`

Также обновлены:
- Все внешние ключи (CONSTRAINT)
- Колонка `news_id` → `entry_id` в таблице `site_entries_extra_content`

#### uninstall.php
Удаляются таблицы:
- `site_entries_extra_content`
- `site_entries_articles`
- `site_entries_categories`

### 4. PHP файлы

#### Все файлы обновлены:
- **Пути плагина**: `news-plugin` → `site-entries-plugin`
- **Названия таблиц**: Все обращения к таблицам обновлены
- **Текстовые метки**: Переведены с "новости" на "записи сайта"

#### Конкретные изменения по файлам:

**pages/articles/add_article.php**
- Заголовки: "Добавление новости" → "Добавление записи сайта"
- Таблица: `news_articles` → `site_entries_articles`
- Категории: `news_categories` → `site_entries_categories`

**pages/articles/article_list.php**
- Заголовок: "Список новостей" → "Список записей"
- Все запросы к БД обновлены

**pages/articles/add_extra.php**
- `news_id` → `entry_id` во всех местах
- Таблица: `news_extra_content` → `site_entries_extra_content`
- Связь: `news_articles` → `site_entries_articles`

**pages/categories/add_category.php**
- Заголовки: "Добавление категории" → "Добавление категории записей"
- Таблица: `news_categories` → `site_entries_categories`

**pages/categories/category_list.php**
- Заголовок: "Категории новостей" → "Категории записей"
- Все запросы обновлены

**pages/settings/access.php**
- Заголовок: "Управление доступом к плагину 'Новости'" → "Управление доступом к плагину 'Записи сайта'"

### 5. JavaScript файлы

**js/authorsearch.js** и **pages/articles/js/categorysearch.js**
- Все пути API обновлены с `news-plugin` на `site-entries-plugin`

### 6. Функции (functions/)

Все вспомогательные функции обновлены:
- `category_path.php` - работает с `site_entries_categories`
- `get_record_avatar.php` - обновлены комментарии
- `pagination.php` - без изменений в логике
- `plugin_helper.php` - автоматически определяет имя плагина
- `plugin_settings.php` - работает с настройками плагина

## Проверка

### Что было проверено:
✅ Никаких упоминаний `news_` в таблицах  
✅ Никаких путей `news-plugin` в коде  
✅ Все тексты переведены на "записи сайта"  
✅ Все файлы скопированы (19/19)  
✅ Конфигурация меню обновлена  
✅ Установка/удаление плагина обновлены  

### Команды для проверки:
```bash
# Проверка отсутствия старых названий таблиц
grep -r "news_" plugins/site-entries-plugin/ --include="*.php"

# Проверка отсутствия старых путей
grep -r "news-plugin" plugins/site-entries-plugin/ --include="*.php" --include="*.json"
```

## Независимость от news-plugin

Плагин **site-entries-plugin** полностью независим от **news-plugin**:

1. ✅ Использует собственные таблицы (`site_entries_*`)
2. ✅ Имеет собственное меню и навигацию
3. ✅ Использует собственный идентификатор плагина
4. ✅ Не пересекается с данными news-plugin
5. ✅ Может быть установлен и удален независимо

## Установка

Для установки плагина в админ-панели:

1. Перейдите в раздел управления плагинами
2. Найдите "Система управления записями сайта" (site-entries-plugin)
3. Нажмите "Установить"
4. Будут созданы таблицы:
   - `site_entries_categories`
   - `site_entries_articles`
   - `site_entries_extra_content`

## Использование

После установки в меню появится новый раздел "Записи сайта" с подразделами:
- Категории записей
- Добавить категорию
- Список записей
- Добавить запись
- Настройки

Функциональность аналогична плагину "Новости", но работает с отдельными таблицами для записей сайта.

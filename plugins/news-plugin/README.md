# Плагин "Система управления новостями"

## Описание

Полнофункциональная система управления новостями для платформы DEREKT с поддержкой иерархических категорий, статей и дополнительного контента.

## Возможности

### Категории новостей
- ✅ Создание и редактирование категорий
- ✅ Иерархическая структура (вложенные категории)
- ✅ SEO-оптимизация (meta title, meta description)
- ✅ ЧПУ URLs
- ✅ Загрузка изображений
- ✅ Описание категории с HTML-редактором
- ✅ Сортировка и статусы
- ✅ Корзина для удаленных категорий
- ✅ Массовые операции
- ✅ Поиск и фильтрация

### Статьи новостей
- ✅ Создание и редактирование статей
- ✅ Привязка к категориям
- ✅ SEO-оптимизация
- ✅ ЧПУ URLs
- ✅ Множественная загрузка изображений
- ✅ WYSIWYG редактор для контента
- ✅ Счетчик просмотров
- ✅ Сортировка и статусы
- ✅ Корзина для удаленных статей
- ✅ Массовые операции
- ✅ Поиск и фильтрация

### Дополнительный контент
- ✅ Блоки дополнительного контента для статей
- ✅ HTML-редактор
- ✅ Изображения для каждого блока
- ✅ Сортировка блоков
- ✅ Статусы (включить/выключить)

## Структура базы данных

### Таблица `news_categories`
```sql
- id (BIGINT UNSIGNED, PRIMARY KEY)
- users_id (BIGINT UNSIGNED, FK → users.id)
- parent_id (BIGINT UNSIGNED, FK → news_categories.id, NULL)
- name (VARCHAR 255)
- url (VARCHAR 255, UNIQUE)
- description (TEXT, NULL)
- meta_title (VARCHAR 255, NULL)
- meta_description (VARCHAR 255, NULL)
- image (VARCHAR 255, NULL)
- sorting (INT, DEFAULT 0)
- status (TINYINT 1, DEFAULT 1)
- created_at (TIMESTAMP)
- updated_at (DATETIME)
```

### Таблица `news_articles`
```sql
- id (BIGINT UNSIGNED, PRIMARY KEY)
- users_id (BIGINT UNSIGNED, FK → users.id)
- category_id (BIGINT UNSIGNED, FK → news_categories.id)
- title (VARCHAR 255)
- url (VARCHAR 255, UNIQUE)
- content (LONGTEXT)
- image (VARCHAR 255, NULL)
- meta_title (VARCHAR 255, NULL)
- meta_description (VARCHAR 255, NULL)
- views_count (INT, DEFAULT 0)
- sorting (INT, DEFAULT 0)
- status (TINYINT 1, DEFAULT 1)
- created_at (TIMESTAMP)
- updated_at (DATETIME)
```

### Таблица `news_extra_content`
```sql
- id (BIGINT UNSIGNED, PRIMARY KEY)
- users_id (BIGINT UNSIGNED, FK → users.id)
- news_id (BIGINT UNSIGNED, FK → news_articles.id)
- title (VARCHAR 255)
- content (LONGTEXT)
- image (VARCHAR 255, NULL)
- sorting (INT, DEFAULT 0)
- status (TINYINT 1, DEFAULT 1)
- created_at (TIMESTAMP)
- updated_at (DATETIME)
```

## Структура плагина

```
plugins/news-plugin/
├── plugin.json              # Метаданные плагина
├── nav-config.json          # Конфигурация меню
├── install.php              # Скрипт установки (создание таблиц)
├── uninstall.php            # Скрипт удаления (удаление таблиц)
├── functions/               # Вспомогательные функции
│   ├── category_path.php    # Построение пути категории
│   ├── get_image.php        # Получение изображений
│   └── pagination.php       # Генерация пагинации
├── js/                      # JavaScript файлы
│   └── authorsearch.js      # AJAX поиск категорий
└── pages/                   # Страницы плагина
    ├── categories/          # Управление категориями
    │   ├── category_list.php
    │   └── add_category.php
    └── articles/            # Управление статьями
        ├── article_list.php
        ├── add_article.php
        ├── extra_list.php
        ├── add_extra.php
        ├── header.php
        └── js/
            └── categorysearch.js
```

## Установка

1. Убедитесь, что плагин находится в директории `/plugins/news-plugin/`
2. Перейдите в админ-панель → Плагины
3. Найдите плагин "Система управления новостями"
4. Нажмите кнопку "Установить"
5. После установки нажмите "Включить"

При установке автоматически создаются три таблицы:
- `news_categories`
- `news_articles`
- `news_extra_content`

## Удаление

1. Перейдите в админ-панель → Плагины
2. Найдите плагин "Система управления новостями"
3. Нажмите кнопку "Удалить"
4. При необходимости отметьте "Удалить файлы плагина с диска"

**Внимание:** При удалении плагина все таблицы и данные будут безвозвратно удалены!

## Безопасность

Плагин включает следующие меры безопасности:
- ✅ CSRF защита для всех форм
- ✅ SQL injection защита через prepared statements
- ✅ XSS защита через экранирование вывода
- ✅ Контроль доступа по users_id
- ✅ Валидация и санитизация всех входных данных
- ✅ Логирование операций
- ✅ Rate limiting для AJAX запросов

## Требования

- PHP 7.4+
- MySQL 8.0+ (поддержка WITH RECURSIVE)
- Установленная система DEREKT

## Технические детали

### Система прав доступа
Плагин использует систему контроля доступа на основе:
- Авторизация через requireAuth()
- Фильтрация данных по users_id
- Проверка прав на операции (создание, редактирование, удаление)

### Логирование
Все операции логируются с учетом настроек администратора:
- LOG_INFO_ENABLED - успешные операции
- LOG_ERROR_ENABLED - ошибки и критические события

### Интеграция с медиа-библиотекой
Плагин интегрирован с медиа-библиотекой системы для управления изображениями.

## Автор

Команда разработки

## Версия

1.0.0

## Лицензия

Proprietary

# Plugins Directory

Эта папка содержит все плагины для системы.

## Структура плагина

Каждый плагин должен находиться в собственной директории и содержать файл `plugin.json`:

```
plugins/
  └── example-plugin/
      ├── plugin.json          (обязательно - манифест плагина)
      ├── nav-config.json      (опционально - конфигурация меню)
      ├── install.php          (опционально - скрипт установки)
      ├── uninstall.php        (опционально - скрипт удаления)
      └── pages/               (опционально - страницы плагина)
          └── settings.php
```

## Формат plugin.json

```json
{
  "name": "example-plugin",
  "display_name": "Example Plugin",
  "version": "1.0.0",
  "author": "Your Name",
  "description": "Plugin description",
  "requires_php": "7.4",
  "requires_db": true,
  "has_settings_page": true
}
```

## Формат nav-config.json

```json
{
  "menu_title": "Pages",
  "menu_icon": "bi-file-earmark",
  "submenu": [
    {
      "title": "List",
      "icon": "bi-list",
      "url": "/admin/plugins/example-plugin/pages/list.php"
    },
    {
      "title": "Add",
      "icon": "bi-plus-circle",
      "url": "/admin/plugins/example-plugin/pages/add.php"
    }
  ]
}
```

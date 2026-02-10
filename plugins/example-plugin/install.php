<?php

/**
 * Название файла:      install.php
 * Назначение:          Скрипт установки плагина Example Plugin
 *                      Создает необходимые таблицы и начальные данные
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-10
 * Последнее изменение: 2026-02-10
 */

// ========================================
// ПРОВЕРКА ДОСТУПА
// ========================================

if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Доступ запрещён');
}

/**
 * Функция установки плагина
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function plugin_install($pdo)
{
    try {
        // Создаем таблицу для плагина
        $sql = "CREATE TABLE IF NOT EXISTS `example_plugin_pages` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `content` text,
            `author_id` int(11) NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Таблица страниц плагина Example Plugin'";
        
        $pdo->exec($sql);
        
        return [
            'success' => true,
            'message' => 'Плагин успешно установлен. Таблицы созданы.'
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Ошибка при установке плагина: ' . $e->getMessage()
        ];
    }
}

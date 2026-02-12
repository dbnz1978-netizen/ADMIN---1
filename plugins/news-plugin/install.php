<?php

/**
 * Название файла:      install.php
 * Назначение:          Скрипт установки плагина News Plugin
 *                      Создает необходимые таблицы для работы системы новостей
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
        // Создаем таблицу категорий новостей
        $sql1 = "CREATE TABLE IF NOT EXISTS `news_categories` (
            `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            `users_id` BIGINT UNSIGNED NOT NULL,
            `parent_id` BIGINT UNSIGNED NULL COMMENT 'Родительская категория (для вложенности)',
            `name` VARCHAR(255) NOT NULL COMMENT 'Название категории',
            `url` VARCHAR(255) NOT NULL COMMENT 'ЧПУ-URL',
            `description` TEXT NULL COMMENT 'Описание категории',
            `meta_title` VARCHAR(255) NULL,
            `meta_description` VARCHAR(255) NULL,
            `image` VARCHAR(255) NULL,
            `sorting` INT DEFAULT 0,
            `status` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY `unique_url` (`url`),
            INDEX `idx_parent` (`parent_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_users` (`users_id`),
            CONSTRAINT `fk_news_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `news_categories`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_news_categories_users` FOREIGN KEY (`users_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql1);
        
        // Создаем таблицу новостей
        $sql2 = "CREATE TABLE IF NOT EXISTS `news_articles` (
            `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            `users_id` BIGINT UNSIGNED NOT NULL,
            `category_id` BIGINT UNSIGNED NOT NULL COMMENT 'ID категории',
            `title` VARCHAR(255) NOT NULL COMMENT 'Заголовок новости',
            `url` VARCHAR(255) NOT NULL COMMENT 'ЧПУ-URL',
            `content` LONGTEXT NOT NULL COMMENT 'Текст новости',
            `image` VARCHAR(255) NULL,
            `meta_title` VARCHAR(255) NULL,
            `meta_description` VARCHAR(255) NULL,
            `views_count` INT DEFAULT 0 COMMENT 'Количество просмотров',
            `sorting` INT DEFAULT 0,
            `status` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY `unique_url` (`url`),
            INDEX `idx_category` (`category_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_created` (`created_at`),
            INDEX `idx_users` (`users_id`),
            INDEX `idx_category_status` (`category_id`, `status`),
            CONSTRAINT `fk_news_articles_category` FOREIGN KEY (`category_id`) REFERENCES `news_categories`(`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_news_articles_users` FOREIGN KEY (`users_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql2);
        
        // Создаем таблицу дополнительного контента для новостей
        $sql3 = "CREATE TABLE IF NOT EXISTS `news_extra_content` (
            `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            `users_id` BIGINT UNSIGNED NOT NULL COMMENT 'ID пользователя, создавшего контент',
            `news_id` BIGINT UNSIGNED NOT NULL COMMENT 'ID связанной новости',
            `title` VARCHAR(255) NOT NULL COMMENT 'Заголовок дополнительного контента',
            `content` LONGTEXT NOT NULL COMMENT 'Дополнительный контент',
            `image` VARCHAR(255) NULL COMMENT 'Изображение для дополнительного контента',
            `sorting` INT DEFAULT 0 COMMENT 'Порядок сортировки',
            `status` TINYINT(1) DEFAULT 1 COMMENT 'Статус: 0 — выключен, 1 — активен',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания',
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата обновления',
            
            INDEX `idx_news` (`news_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_sorting` (`sorting`),
            INDEX `idx_users` (`users_id`),
            INDEX `idx_news_status` (`news_id`, `status`),
            CONSTRAINT `fk_news_extra_content_news` FOREIGN KEY (`news_id`) REFERENCES `news_articles`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_news_extra_content_users` FOREIGN KEY (`users_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql3);
        
        return [
            'success' => true,
            'message' => 'Плагин "Система управления новостями" успешно установлен. Созданы таблицы: news_categories, news_articles, news_extra_content.'
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Ошибка при установке плагина: ' . $e->getMessage()
        ];
    }
}

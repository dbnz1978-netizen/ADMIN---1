<?php

/**
 * Название файла:      uninstall.php
 * Назначение:          Скрипт удаления плагина Site Entries Plugin
 *                      Удаляет таблицы и данные плагина
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-11
 * Последнее изменение: 2026-02-11
 */

// ========================================
// ПРОВЕРКА ДОСТУПА
// ========================================

if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Доступ запрещён');
}

/**
 * Функция удаления плагина
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function plugin_uninstall($pdo)
{
    try {
        // Удаляем таблицы в обратном порядке (сначала зависимые)
        $pdo->exec("DROP TABLE IF EXISTS `site_entries_extra_content`");
        $pdo->exec("DROP TABLE IF EXISTS `site_entries_articles`");
        $pdo->exec("DROP TABLE IF EXISTS `site_entries_categories`");
        
        return [
            'success' => true,
            'message' => 'Плагин "Система управления записями сайта" успешно удален. Таблицы удалены.'
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Ошибка при удалении плагина: ' . $e->getMessage()
        ];
    }
}

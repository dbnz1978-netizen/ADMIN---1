<?php

/**
 * Название файла:      uninstall.php
 * Назначение:          Скрипт удаления плагина Example Plugin
 *                      Удаляет таблицы и данные плагина
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
 * Функция удаления плагина
 *
 * @param PDO $pdo Объект подключения к базе данных
 * @return array Результат операции ['success' => bool, 'message' => string]
 */
function plugin_uninstall($pdo)
{
    try {
        // Удаляем таблицу плагина
        $sql = "DROP TABLE IF EXISTS `example_plugin_pages`";
        $pdo->exec($sql);
        
        return [
            'success' => true,
            'message' => 'Плагин успешно удален. Таблицы удалены.'
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false,
            'message' => 'Ошибка при удалении плагина: ' . $e->getMessage()
        ];
    }
}

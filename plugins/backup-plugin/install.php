<?php

/**
 * Название файла:      install.php
 * Назначение:          Скрипт установки плагина Backup Plugin
 *                      Плагин не требует создания таблиц в базе данных
 * Автор:               Команда разработки
 * Версия:              1.0
 * Дата создания:       2026-02-12
 * Последнее изменение: 2026-02-12
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
        // Плагин не требует создания таблиц
        // Создаем директорию для хранения резервных копий
        $backupDir = __DIR__ . '/../../admin/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Устанавливаем права доступа к плагину: только для администраторов
        // Загружаем функции управления доступом к плагинам
        $pluginAccessPath = __DIR__ . '/../../admin/functions/plugin_access.php';
        if (file_exists($pluginAccessPath)) {
            require_once $pluginAccessPath;
            
            // Получаем текущие настройки доступа
            $accessSettings = getPluginAccessSettings($pdo);
            if ($accessSettings === false) {
                $accessSettings = [];
            }
            
            // Устанавливаем доступ только для администраторов
            $accessSettings['backup-plugin'] = [
                'user'  => false,  // запрещаем доступ для обычных пользователей
                'admin' => true    // разрешаем доступ только для администраторов
            ];
            
            // Сохраняем настройки
            savePluginAccessSettings($pdo, $accessSettings);
        }
        
        return [
            'success' => true,
            'message' => 'Плагин "Система резервного копирования" успешно установлен.'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Ошибка при установке плагина: ' . $e->getMessage()
        ];
    }
}

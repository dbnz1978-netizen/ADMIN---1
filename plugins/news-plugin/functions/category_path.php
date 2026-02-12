<?php
/**
 * Файл: functions/category_path.php
 * 
 * Назначение:
 * Функция для построения полного пути категории (breadcrumb) в иерархической структуре.
 * Использует рекурсивный запрос WITH RECURSIVE для получения цепочки родительских категорий
 * за один запрос к базе данных (вместо множества запросов).
 * 
 * Пример результата: "технологии/программирование/php"
 * 
 * Требования:
 * - MySQL 8.0+ (поддержка WITH RECURSIVE)
 * - Таблица news_categories с колонками: id, parent_id, url, users_id
 * 
 * @author Команда разработки
 * @version 1.0
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

function buildNewsCategoryPath(
   $pdo,                            // Объект подключения к базе данных PDO
   $categoryId,                     // ID текущей категории, для которой строится путь
   $currentUserId,                  // ID текущего пользователя (для фильтрации по владельцу)
   $maxDepth = 10)                  // Максимальная глубина рекурсии (защита от бесконечного цикла)
{
    if ($categoryId <= 0) {
        return '';
    }
    
    try {
        $categoryId = (int)$categoryId;
        $currentUserId = (int)$currentUserId;
        $maxDepth = (int)$maxDepth;
        
        $sql = "
        WITH RECURSIVE hierarchy AS (
            SELECT
                id,
                parent_id,
                url,
                0 AS level
            FROM news_categories
            WHERE id = ?
                AND users_id = ?
            UNION ALL
            SELECT
                t.id,
                t.parent_id,
                t.url,
                h.level + 1
            FROM news_categories t
            INNER JOIN hierarchy h ON t.id = h.parent_id
            WHERE h.level < ?
                AND h.parent_id IS NOT NULL
                AND h.parent_id != 0
                AND t.users_id = ?
        )
        SELECT url
        FROM hierarchy
        ORDER BY level DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$categoryId, $currentUserId, $maxDepth, $currentUserId]);
        $urls = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return implode('/', array_filter($urls));
        
    } catch (PDOException $e) {
        if (defined('LOG_ERROR_ENABLED')) {
            logEvent("Ошибка buildNewsCategoryPath для ID=$categoryId: " . $e->getMessage(), LOG_ERROR_ENABLED, 'error');
        }
        return '';
    }
}

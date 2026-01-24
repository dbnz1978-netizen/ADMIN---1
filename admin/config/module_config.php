<?php
/**
 * Configuration for different modules (news, record, shop)
 */

$moduleConfig = [
    'news' => [
        'catalogTable' => 'news',
        'relatedTable' => 'news',
        'parentRelatedTable' => 'category',
        'title' => 'Новости',
        'singularTitle' => 'Новость',
        'pluralTitle' => 'Новости',
        'addButtonTitle' => 'Добавить новую новость',
        'manageTitle' => 'Управление новостями',
        'successMessages' => [
            'deleted' => 'Новости успешно удалены',
            'moved_to_trash' => 'Новости перемещены в корзину',
            'restored' => 'Новости восстановлены'
        ]
    ],
    'record' => [
        'catalogTable' => 'record',
        'relatedTable' => 'record',
        'parentRelatedTable' => 'category',
        'title' => 'Записи',
        'singularTitle' => 'Запись',
        'pluralTitle' => 'Записи',
        'addButtonTitle' => 'Добавить новую запись',
        'manageTitle' => 'Управление записями',
        'successMessages' => [
            'deleted' => 'Записи успешно удалены',
            'moved_to_trash' => 'Записи перемещены в корзину',
            'restored' => 'Записи восстановлены'
        ]
    ],
    'shop' => [
        'catalogTable' => 'shop',
        'relatedTable' => 'product',
        'parentRelatedTable' => 'catalog',
        'title' => 'Магазин',
        'singularTitle' => 'Товар',
        'pluralTitle' => 'Товары',
        'addButtonTitle' => 'Добавить новую запись',
        'manageTitle' => 'Управление товаром',
        'successMessages' => [
            'deleted' => 'Записи успешно удалены',
            'moved_to_trash' => 'Записи перемещены в корзину',
            'restored' => 'Записи восстановлены'
        ]
    ],
    'pages' => [
        'catalogTable' => 'pages',
        'relatedTable' => 'pages',
        'parentRelatedTable' => 'pages',
        'title' => 'Страницы',
        'singularTitle' => 'Страница',
        'pluralTitle' => 'Страницы',
        'addButtonTitle' => 'Добавить новую страницу',
        'manageTitle' => 'Управление страницами',
        'successMessages' => [
            'deleted' => 'Страницы успешно удалены',
            'moved_to_trash' => 'Страницы перемещены в корзину',
            'restored' => 'Страницы восстановлены'
        ]
    ]
];

// Get the current module from the directory name
$currentModule = basename(dirname($_SERVER['SCRIPT_NAME']));
if (!isset($moduleConfig[$currentModule])) {
    // Fallback to a generic config if module not found
    $currentModule = 'record'; // Default to record as fallback
}

// Apply the current module's configuration
$config = $moduleConfig[$currentModule];
$catalogTable = $config['catalogTable'];
$relatedTable = $config['relatedTable'];
$parentRelatedTable = $config['parentRelatedTable'];
$RELATED_TABLE_FILTER = $config['relatedTable'];

// Additional configuration variables
$titlemeta = $config['title'];
$singularTitle = $config['singularTitle'];
$pluralTitle = $config['pluralTitle'];
$addButtonTitle = $config['addButtonTitle'];
$manageTitle = $config['manageTitle'];
$successMessagesConfig = $config['successMessages'];
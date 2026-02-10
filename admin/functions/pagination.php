<?php
/**
 * 
 * Файл: functions/pagination.php
 * 
 * Функция генерации HTML пагинации
 *
 * @param int $currentPage      Текущая страница
 * @param int $totalPages       Общее количество страниц
 * @param array $queryParams    Ассоциативный массив GET-параметров (например ['trash'=>1, 'search'=>'...'])
 * @param int $maxVisible       Максимальное количество страниц, которые показываются в середине (по умолчанию 3)
 * @return string               HTML пагинации (Bootstrap 5)
 */

// Разрешить доступ только через include, но не напрямую
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Прямой доступ запрещён');
}

function renderPagination(int $currentPage, int $totalPages, array $queryParams = [], int $maxVisible = 3): string
{
    if ($totalPages <= 1) {
        // Нет смысла показывать пагинацию, если всего одна страница
        return '';
    }

    $html = '<nav aria-label="Пагинация" class="mt-3">';
    $html .= '<ul class="pagination justify-content-center">';

    // ----- Кнопка "Назад" -----
    $prevPage = max(1, $currentPage - 1);
    $prevLink = $currentPage <= 1
        ? '#'
        : '?' . http_build_query(array_merge($queryParams, ['page' => $prevPage]));
    $html .= '<li class="page-item ' . ($currentPage <= 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $prevLink . '" title="Назад" aria-label="Назад">';
    $html .= '<i class="bi bi-arrow-left-short"></i>';
    $html .= '</a></li>';

    // ----- Вычисление диапазона страниц -----
    $pagesToShow = [1];
    $left = $currentPage - floor($maxVisible / 2);
    $right = $currentPage + ceil($maxVisible / 2) - 1;

    if ($left < 2) {
        $right += (2 - $left);
        $left = 2;
    }
    if ($right > $totalPages - 1) {
        $left -= ($right - ($totalPages - 1));
        $right = $totalPages - 1;
    }
    $left = max(2, $left);

    for ($i = $left; $i <= $right; $i++) {
        $pagesToShow[] = $i;
    }

    $pagesToShow[] = $totalPages;
    $pagesToShow = array_values(array_unique($pagesToShow));
    sort($pagesToShow);

    // ----- Формируем HTML страниц -----
    $last = 0;
    foreach ($pagesToShow as $p) {
        if ($p - $last > 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        $html .= '<li class="page-item' . ($p == $currentPage ? ' active' : '') . '">';
        $pageLink = '?' . http_build_query(array_merge($queryParams, ['page' => $p]));
        $html .= '<a class="page-link" href="' . $pageLink . '">' . $p . '</a>';
        $html .= '</li>';
        $last = $p;
    }

    // ----- Кнопка "Вперёд" -----
    $nextPage = min($totalPages, $currentPage + 1);
    $nextLink = $currentPage >= $totalPages
        ? '#'
        : '?' . http_build_query(array_merge($queryParams, ['page' => $nextPage]));
    $html .= '<li class="page-item ' . ($currentPage >= $totalPages ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $nextLink . '" title="Вперёд" aria-label="Вперёд">';
    $html .= '<i class="bi bi-arrow-right-short"></i>';
    $html .= '</a></li>';

    $html .= '</ul></nav>';

    return $html;
}

/**
 * 
<?php 
// Генерация HTML пагинации (Bootstrap 5) на основе текущей страницы, общего количества страниц
// и GET-параметров поиска/фильтров. Функция renderPagination возвращает готовый HTML.
echo renderPagination(
    $page,                                           // Текущая страница
    $totalPages,                                     // Общее количество страниц
    array_filter([                                   // Массив GET-параметров для формирования ссылок пагинации. Можно менять
        'trash' => $_GET['trash'] ?? null,           // Фильтр: показывать корзину (1) или нет (null)
        'search' => $search,                         // Фильтр: поиск по названию записи
        'search_catalog' => $searchCatalog,          // Фильтр: поиск по родительской категории
    ])
); 
?>
 */

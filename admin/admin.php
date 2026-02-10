<?php
// Запретить прямой доступ ко всем .php файлам
if (!defined('APP_ACCESS')) {
    define('APP_ACCESS', true);
}

require_once __DIR__ . '/functions/auth_check.php';
startSessionSafe();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$captchaTokenTtl = 600;
$tokenCreated = $_SESSION['captcha_token_created'] ?? 0;
if (empty($_SESSION['captcha_token']) ||
    empty($tokenCreated) ||
    (time() - $tokenCreated) > $captchaTokenTtl ||
    (!empty($_SESSION['captcha_token_used']) && $_SERVER['REQUEST_METHOD'] !== 'POST')) {
    $_SESSION['captcha_token'] = bin2hex(random_bytes(32));
    $_SESSION['captcha_token_created'] = time();
    $_SESSION['captcha_token_used'] = false;
}

$captchaToken = $_SESSION['captcha_token'];
$csrfToken = $_SESSION['csrf_token'];

require_once __DIR__ . '/functions/htmleditor.php';               // Редактор WYSIWYG
require_once __DIR__ . '/functions/sanitization.php';             // Валидация/экранирование

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель</title>

    <!-- Модуль управления светлой/тёмной темой -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/main.css">
    <!-- WYSIWYG-редактор -->
    <link rel="stylesheet" href="css/editor.css">
    <link rel="stylesheet" href="user_images/css/main.css">
</head>
<body>
    <div class="container-fluid">
        <!-- Боковое меню -->
        <div class="sidebar">
            <!-- Заголовок с логотипом -->
            <div class="sidebar-header">
                <a href="authorization.php" class="logo">
                    <i class="bi bi-shield-lock"></i>
                    AdminPanel
                </a>
            </div>

            <!-- Навигация -->
            <div class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a href="authorization.php" class="nav-link active">
                            <i class="bi bi-speedometer2"></i>
                            <span>Авторизация</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="forgot.php" class="nav-link">
                            <i class="bi bi-people"></i>
                            <span>Восстановление пароля</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <i class="bi bi-chat-dots"></i>
                            <span>Сообщения</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#analyticsSubmenu" class="nav-link collapsed" data-bs-toggle="submenu" aria-expanded="false">
                            <i class="bi bi-bar-chart"></i>
                            <span>Аналитика</span>
                            <i class="bi bi-chevron-down"></i>
                        </a>
                        <div class="submenu" id="analyticsSubmenu">
                            <a href="#" class="nav-link">
                                <i class="bi bi-graph-up"></i>
                                <span>Общая статистика</span>
                            </a>
                            <a href="#" class="nav-link">
                                <i class="bi bi-pie-chart"></i>
                                <span>Графики</span>
                            </a>
                            <a href="#" class="nav-link">
                                <i class="bi bi-table"></i>
                                <span>Отчеты</span>
                            </a>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a href="#settingsSubmenu" class="nav-link collapsed" data-bs-toggle="submenu" aria-expanded="false">
                            <i class="bi bi-gear"></i>
                            <span>Настройки</span>
                            <i class="bi bi-chevron-down"></i>
                        </a>
                        <div class="submenu" id="settingsSubmenu">
                            <a href="#" class="nav-link">
                                <i class="bi bi-sliders"></i>
                                <span>Основные настройки</span>
                            </a>
                            <a href="#" class="nav-link">
                                <i class="bi bi-palette"></i>
                                <span>Внешний вид</span>
                            </a>
                            <a href="#" class="nav-link">
                                <i class="bi bi-bell"></i>
                                <span>Уведомления</span>
                            </a>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <i class="bi bi-shield-check"></i>
                            <span>Безопасность</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#paymentsSubmenu" class="nav-link collapsed" data-bs-toggle="submenu" aria-expanded="false">
                            <i class="bi bi-credit-card"></i>
                            <span>Платежи</span>
                            <i class="bi bi-chevron-down"></i>
                        </a>
                        <div class="submenu" id="paymentsSubmenu">
                            <a href="#" class="nav-link">
                                <i class="bi bi-cash-coin"></i>
                                <span>Транзакции</span>
                            </a>
                            <a href="#" class="nav-link">
                                <i class="bi bi-receipt"></i>
                                <span>Счета</span>
                            </a>
                            <a href="#" class="nav-link">
                                <i class="bi bi-wallet2"></i>
                                <span>Баланс</span>
                            </a>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <i class="bi bi-question-circle"></i>
                            <span>Поддержка</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <i class="bi bi-file-text"></i>
                            <span>Документация</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Нижняя часть сайдбара -->
            <div class="sidebar-footer">
                <!-- Информация о пользователе -->
                <div class="user-info">
                    <div class="user-avatar">
                        <img src="https://i.pravatar.cc/150?img=32" alt="Администратор">
                    </div>
                    <div>
                        <div class="user-name">Администратор</div>
                        <div class="user-role">Super Admin</div>
                    </div>
                </div>

                <!-- Переключатель темы -->
                <div class="theme-toggle">
                    <span class="theme-label">Тема</span>
                    <label class="theme-switch-auth">
                        <input type="checkbox" id="themeToggleAuth">
                            <span class="theme-slider-auth">
                                <i class="bi bi-sun"></i>
                                <i class="bi bi-moon"></i>
                        </span>
                    </label>
                </div>

                <!-- Кнопка выхода -->
                <?php $csrfToken = $csrfToken ?? ($_SESSION['csrf_token'] ?? ''); ?>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                    <button class="logout-btn" type="submit">
                        <i class="bi bi-box-arrow-right"></i>
                        Выйти
                    </button>
                </form>
            </div>
        </div>

        <!-- Оверлей для мобильных устройств -->
        <div class="overlay"></div>

        <!-- Основной контент -->
        <div class="main-content">
            <!-- Верхняя панель -->
            <div class="topbar">
                <button class="menu-toggle">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="page-title">
                    <i class="bi bi-speedometer2"></i>
                    Дашборд
                </h1>
                <div class="topbar-actions">
                    <div class="notification-icon">
                        <i class="bi bi-bell"></i>
                        <span class="notification-badge">3</span>
                    </div>
                    <div class="user-avatar">
                        <img src="https://i.pravatar.cc/150?img=32" alt="Администратор">
                    </div>
                </div>
            </div>

            <!-- Хлебные крошки в виде текстовых ссылок с иконками -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb-custom">
                    <li class="breadcrumb-item">
                        <a href="#">
                            <i class="bi bi-house breadcrumb-icon"></i> Главная
                        </a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="#">
                            <i class="bi bi-speedometer2 breadcrumb-icon"></i> Дашборд
                        </a>
                    </li>
                    <li class="breadcrumb-item">
                        <a href="#">
                            <i class="bi bi-graph-up breadcrumb-icon"></i> Статистика
                        </a>
                    </li>
                    <li class="breadcrumb-item active">
                        <a href="#">
                            <i class="bi bi-bar-chart breadcrumb-icon"></i> Анализ продаж
                        </a>
                    </li>
                </ol>
            </nav>

            <!-- Статистические карточки -->
            <div class="row">
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="stats-value">1,254</div>
                        <div class="stats-label">Пользователи</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon">
                            <i class="bi bi-cart"></i>
                        </div>
                        <div class="stats-value">524</div>
                        <div class="stats-label">Заказы</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="stats-value">$12,458</div>
                        <div class="stats-label">Доход</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon">
                            <i class="bi bi-eye"></i>
                        </div>
                        <div class="stats-value">8,752</div>
                        <div class="stats-label">Просмотры</div>
                    </div>
                </div>
            </div>

            <!-- Примеры колонок от 1 до 6 -->
            <div class="content-card">
                <h3 class="card-title">
                    <i class="bi bi-grid-3x3-gap"></i>
                    Примеры колонок от 1 до 6
                </h3>
                
                <h5>6 колонок</h5>
                <div class="row mb-4 col-example-row">
                    <div class="col-2">
                        <div class="col-example">1 из 6</div>
                    </div>
                    <div class="col-2">
                        <div class="col-example">2 из 6</div>
                    </div>
                    <div class="col-2">
                        <div class="col-example">3 из 6</div>
                    </div>
                    <div class="col-2">
                        <div class="col-example">4 из 6</div>
                    </div>
                    <div class="col-2">
                        <div class="col-example">5 из 6</div>
                    </div>
                    <div class="col-2">
                        <div class="col-example">6 из 6</div>
                    </div>
                </div>
                
                <h5>4 колонки</h5>
                <div class="row mb-4 col-example-row">
                    <div class="col-3">
                        <div class="col-example">1 из 4</div>
                    </div>
                    <div class="col-3">
                        <div class="col-example">2 из 4</div>
                    </div>
                    <div class="col-3">
                        <div class="col-example">3 из 4</div>
                    </div>
                    <div class="col-3">
                        <div class="col-example">4 из 4</div>
                    </div>
                </div>
                
                <h5>3 колонки</h5>
                <div class="row mb-4 col-example-row">
                    <div class="col-4">
                        <div class="col-example">1 из 3</div>
                    </div>
                    <div class="col-4">
                        <div class="col-example">2 из 3</div>
                    </div>
                    <div class="col-4">
                        <div class="col-example">3 из 3</div>
                    </div>
                </div>
                
                <h5>2 колонки</h5>
                <div class="row mb-4 col-example-row">
                    <div class="col-6">
                        <div class="col-example">1 из 2</div>
                    </div>
                    <div class="col-6">
                        <div class="col-example">2 из 2</div>
                    </div>
                </div>
                
                <h5>1 колонка</h5>
                <div class="row">
                    <div class="col-12">
                        <div class="col-example">1 из 1</div>
                    </div>
                </div>
            </div>

            <!-- Демонстрация нескольких экземпляров редактора -->
            <div class="content-card">
                <h3 class="card-title">
                    <i class="bi bi-pencil-square"></i>
                    Редакторы (множественные экземпляры)
                </h3>
                
                <h5>Первый редактор</h5>
                <div class="mb-4">
                    <?php 
                    renderHtmlEditor('demo_editor_1', '<p>Это первый экземпляр редактора. Вы можете редактировать этот текст независимо от других редакторов на странице.</p>');
                    ?>
                </div>
                
                <h5>Второй редактор</h5>
                <div class="mb-4">
                    <?php 
                    renderHtmlEditor('demo_editor_2', '<p>Это второй экземпляр редактора. Он работает независимо от первого редактора.</p>');
                    ?>
                </div>
                
            </div>

            <!-- Блоки с заголовком в обводке -->
            <div class="row">
                <div class="col-md-6">
                    <div class="bordered-card">
                        <div class="card-header">
                            <i class="bi bi-info-circle"></i> Информационный блок
                        </div>
                        <div class="card-body">
                            <p>Это пример блока с заголовком в обводке. Такой стиль часто используется для выделения важной информации или группировки связанных элементов.</p>
                            <button class="btn btn-primary">Действие</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="bordered-card">
                        <div class="card-header">
                            <i class="bi bi-exclamation-triangle"></i> Предупреждение
                        </div>
                        <div class="card-body">
                            <p>Этот блок используется для отображения предупреждений или важных уведомлений, которые требуют внимания пользователя.</p>
                            <button class="btn btn-outline-warning">Подробнее</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Основной контент с разными элементами -->
            <div class="row">
                <div class="col-md-8">
                    <!-- Таблица пользователей -->
                    <div class="content-card table-card">
                        <h3 class="card-title">
                            <i class="bi bi-people"></i>
                            Пользователи
                        </h3>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Пользователь</th>
                                        <th>Email</th>
                                        <th>Статус</th>
                                        <th>Роль</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>#001</td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar" style="width: 30px; height: 30px;">
                                                    <img src="https://i.pravatar.cc/150?img=1" alt="Иван Иванов">
                                                </div>
                                                <div class="ms-2">
                                                    <a href="#" class="user-link">Иван Иванов</a>
                                                </div>
                                            </div>
                                        </td>
                                        <td>ivan@example.com</td>
                                        <td><span class="user-status status-online"></span> Активен</td>
                                        <td><span class="badge bg-primary">Админ</span></td>
                                        <td>
                                            <div class="btn-group">
                                                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></button>
                                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>#002</td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar" style="width: 30px; height: 30px;">
                                                    <img src="https://i.pravatar.cc/150?img=5" alt="Петр Петров">
                                                </div>
                                                <div class="ms-2">
                                                    <a href="#" class="user-link">Петр Петров</a>
                                                </div>
                                            </div>
                                        </td>
                                        <td>petr@example.com</td>
                                        <td><span class="user-status status-offline"></span> Неактивен</td>
                                        <td><span class="badge bg-success">Пользователь</span></td>
                                        <td>
                                            <div class="btn-group">
                                                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></button>
                                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>#003</td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar" style="width: 30px; height: 30px;">
                                                    <img src="https://i.pravatar.cc/150?img=8" alt="Сергей Сергеев">
                                                </div>
                                                <div class="ms-2">
                                                    <a href="#" class="user-link">Сергей Сергеев</a>
                                                </div>
                                            </div>
                                        </td>
                                        <td>sergey@example.com</td>
                                        <td><span class="user-status status-busy"></span> Занят</td>
                                        <td><span class="badge bg-warning">Модератор</span></td>
                                        <td>
                                            <div class="btn-group">
                                                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></button>
                                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- Пагинация -->
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center">
                                <li class="page-item disabled">
                                    <a class="page-link" href="#" tabindex="-1" aria-disabled="true">Назад</a>
                                </li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item"><a class="page-link" href="#">2</a></li>
                                <li class="page-item"><a class="page-link" href="#">3</a></li>
                                <li class="page-item">
                                    <a class="page-link" href="#">Вперед</a>
                                </li>
                            </ul>
                        </nav>
                    </div>

                    <!-- Формы -->
                    <div class="content-card">
                        <h3 class="card-title">
                            <i class="bi bi-pencil-square"></i>
                            Формы
                        </h3>
                        
                        <div class="form-section">
                            <h5>Текстовые поля</h5>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="exampleInput" class="form-label">Обычное поле</label>
                                    <input type="text" class="form-control" id="exampleInput" placeholder="Введите текст">
                                </div>
                                <div class="col-md-6">
                                    <label for="exampleInputDisabled" class="form-label">Отключенное поле</label>
                                    <input type="text" class="form-control" id="exampleInputDisabled" placeholder="Неактивное поле" disabled>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="exampleSelect" class="form-label">Выпадающий список</label>
                                    <select class="form-select" id="exampleSelect">
                                        <option selected>Выберите опцию</option>
                                        <option value="1">Опция 1</option>
                                        <option value="2">Опция 2</option>
                                        <option value="3">Опция 3</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="exampleTextarea" class="form-label">Текстовая область</label>
                                    <textarea class="form-control" id="exampleTextarea" rows="3" placeholder="Введите много текста"></textarea>
                                </div>
                            </div>

                            <h5 class="mt-4">Контактные данные</h5>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="demoPhone" class="form-label">Телефон</label>
                                    <input type="tel" class="form-control" id="demoPhone" name="demo_phone" placeholder="+7 (XXX) XXX-XX-XX или +Код Страны Номер" maxlength="30">
                                    <div class="form-text">Формат: +7 (XXX) XXX-XX-XX или +Код Страны Номер</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="demoEmail" class="form-label">Email адрес</label>
                                    <input type="email" class="form-control" id="demoEmail" name="demo_email" placeholder="your@email.com" maxlength="254">
                                    <div class="form-text">Максимум 254 символа</div>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="demoPassword" class="form-label">Пароль</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="demoPassword" name="demo_password" placeholder="Введите пароль" minlength="6" maxlength="128">
                                        <button type="button" class="password-toggle" aria-label="Показать пароль">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <h5 class="mt-4">Чекбоксы и радиокнопки</h5>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="" id="flexCheckDefault">
                                        <label class="form-check-label" for="flexCheckDefault">
                                            Чекбокс по умолчанию
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="" id="flexCheckChecked" checked>
                                        <label class="form-check-label" for="flexCheckChecked">
                                            Отмеченный чекбокс
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="flexRadioDefault" id="flexRadioDefault1">
                                        <label class="form-check-label" for="flexRadioDefault1">
                                            Радиокнопка по умолчанию
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="flexRadioDefault" id="flexRadioDefault2" checked>
                                        <label class="form-check-label" for="flexRadioDefault2">
                                            Отмеченная радиокнопка
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <h5 class="mt-4">Переключатели</h5>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="flexSwitchCheckDefault">
                                        <label class="form-check-label" for="flexSwitchCheckDefault">Переключатель по умолчанию</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="flexSwitchCheckChecked" checked>
                                        <label class="form-check-label" for="flexSwitchCheckChecked">Включенный переключатель</label>
                                    </div>
                                </div>
                            </div>

                            <h5 class="mt-4">Подтверждение</h5>
                            <div class="captcha-container">
                                <div class="captcha-text">Перетащите ползунок вправо для подтверждения</div>
                                <div class="captcha-slider" id="captchaSlider">
                                    <div class="captcha-track">
                                        <div class="captcha-progress" id="captchaProgress"></div>
                                        <div class="captcha-progress-extended" id="captchaProgressExtended"></div>
                                    </div>
                                    <div class="captcha-handle" id="captchaHandle" role="slider" aria-label="Подтверждение" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                                        <i class="bi bi-arrow-right"></i>
                                    </div>
                                </div>
                                <div class="captcha-instruction">Перетащите кружок со стрелкой до конца</div>
                                <input type="hidden" name="captcha_verified" id="captchaVerified" value="false">
                                <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                <input type="hidden" name="captcha_token" id="captchaToken" value="<?= escape($captchaToken) ?>">
                            </div>
                            
                            <h5 class="mt-4">Выбор цвета</h5>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Цвет фона</label>
                                    <div id="colorPicker1" class="color-picker-init" data-initial-color="#4a6cf7"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Цвет текста</label>
                                    <div id="colorPicker2" class="color-picker-init" data-initial-color="#333333"></div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary mt-3">
                                <i class="bi bi-check-lg" aria-hidden="true"></i> Сохранить изменения
                            </button>
                            <button type="reset" class="btn btn-outline-secondary mt-3">Сбросить</button>
                        </div>
                    </div>
                    <div class="content-card">
                        <h3 class="card-title">
                            <i class="bi bi-layers"></i>
                            Дополнительные элементы
                        </h3>

                        <h5>Карточки</h5>
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="bordered-card h-100">
                                    <div class="card-header">
                                        <i class="bi bi-gem"></i> Презентация
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-3">Краткое описание карточки с акцентом на ценность.</p>
                                        <button class="btn btn-sm btn-primary">Подробнее</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="bordered-card h-100">
                                    <div class="card-header">
                                        <i class="bi bi-rocket"></i> Запуск
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-3">Добавьте показатели и быстрые действия в карточке.</p>
                                        <div class="d-flex gap-2">
                                            <span class="badge bg-success">Готово</span>
                                            <span class="badge bg-info">4 шага</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="bordered-card h-100">
                                    <div class="card-header">
                                        <i class="bi bi-graph-up-arrow"></i> Аналитика
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-3">Покажите прогресс, KPI или статус выполнения.</p>
                                        <button class="btn btn-sm btn-outline-primary">Смотреть отчет</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h5>Вкладки</h5>
                        <ul class="nav nav-tabs mb-3" id="demoTabs" role="tablist"
                            style="--bs-nav-tabs-border-color: var(--border-color); --bs-nav-tabs-link-active-bg: var(--card-bg); --bs-nav-tabs-link-active-color: var(--text-color);">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview-tab-pane" type="button" role="tab" aria-controls="overview-tab-pane" aria-selected="true">
                                    Обзор
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="details-tab" data-bs-toggle="tab" data-bs-target="#details-tab-pane" type="button" role="tab" aria-controls="details-tab-pane" aria-selected="false">
                                    Детали
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings-tab-pane" type="button" role="tab" aria-controls="settings-tab-pane" aria-selected="false">
                                    Настройки
                                </button>
                            </li>
                        </ul>
                        <div class="tab-content mb-4" id="demoTabsContent"
                             style="background-color: var(--card-bg); border: 1px solid var(--border-color); border-top: 0; padding: 16px; border-radius: 0 0 10px 10px;">
                            <div class="tab-pane fade show active" id="overview-tab-pane" role="tabpanel" aria-labelledby="overview-tab" tabindex="0">
                                <p class="mb-2">Сводная информация с ключевыми метриками и последними обновлениями.</p>
                                <span class="badge bg-primary">Обновлено 5 минут назад</span>
                            </div>
                            <div class="tab-pane fade" id="details-tab-pane" role="tabpanel" aria-labelledby="details-tab" tabindex="0">
                                <p class="mb-0">Подробные данные, отчеты и аналитика по выбранному разделу.</p>
                            </div>
                            <div class="tab-pane fade" id="settings-tab-pane" role="tabpanel" aria-labelledby="settings-tab" tabindex="0">
                                <p class="mb-0">Управляйте доступом, уведомлениями и параметрами отображения.</p>
                            </div>
                        </div>

                        <h5>Вопрос-ответ</h5>
                        <div class="accordion mb-4" id="faqAccordion"
                             style="--bs-accordion-bg: var(--card-bg); --bs-accordion-color: var(--text-color); --bs-accordion-border-color: var(--border-color); --bs-accordion-btn-bg: var(--card-bg); --bs-accordion-btn-color: var(--text-color); --bs-accordion-active-bg: rgba(74, 108, 247, 0.12); --bs-accordion-active-color: var(--text-color); --bs-accordion-btn-focus-box-shadow: 0 0 0 0.2rem rgba(74, 108, 247, 0.2);">
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faqHeadingOne">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseOne" aria-expanded="true" aria-controls="faqCollapseOne">
                                        Как добавить нового пользователя?
                                    </button>
                                </h2>
                                <div id="faqCollapseOne" class="accordion-collapse collapse show" aria-labelledby="faqHeadingOne" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Перейдите в раздел «Пользователи», нажмите «Добавить» и заполните форму.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faqHeadingTwo">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseTwo" aria-expanded="false" aria-controls="faqCollapseTwo">
                                        Где посмотреть историю действий?
                                    </button>
                                </h2>
                                <div id="faqCollapseTwo" class="accordion-collapse collapse" aria-labelledby="faqHeadingTwo" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        История событий доступна в разделе «Логи» и обновляется в реальном времени.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="faqHeadingThree">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faqCollapseThree" aria-expanded="false" aria-controls="faqCollapseThree">
                                        Как переключить тему оформления?
                                    </button>
                                </h2>
                                <div id="faqCollapseThree" class="accordion-collapse collapse" aria-labelledby="faqHeadingThree" data-bs-parent="#faqAccordion">
                                    <div class="accordion-body">
                                        Используйте переключатель темы в боковом меню для выбора светлого или тёмного режима.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h5>Заголовки H</h5>
                        <div class="mb-4">
                            <h1 class="mb-2">H1 Заголовок</h1>
                            <h2 class="mb-2">H2 Заголовок</h2>
                            <h3 class="mb-2">H3 Заголовок</h3>
                            <h4 class="mb-2">H4 Заголовок</h4>
                            <h5 class="mb-2">H5 Заголовок</h5>
                            <h6 class="mb-0">H6 Заголовок</h6>
                        </div>

                        <h5>Маркированные списки</h5>
                        <ul class="ps-3 mb-0">
                            <li>Пункт списка с описанием функции или преимущества.</li>
                            <li>Второй пункт для отображения структуры контента.</li>
                            <li>Третий пункт, показывающий дополнительные возможности.</li>
                        </ul>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <!-- Уведомления -->
                    <div class="content-card">
                        <h3 class="card-title">
                            <i class="bi bi-bell"></i>
                            Уведомления
                        </h3>
                        <div class="alert alert-primary" role="alert">
                            <i class="bi bi-info-circle"></i> Это информационное уведомление
                        </div>
                        <div class="alert alert-success" role="alert">
                            <i class="bi bi-check-circle"></i> Это уведомление об успехе
                        </div>
                        <div class="alert alert-warning" role="alert">
                            <i class="bi bi-exclamation-triangle"></i> Это предупреждающее уведомление
                        </div>
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-x-circle"></i> Это уведомление об ошибке
                        </div>
                    </div>

                    <!-- Прогресс-бары с эффектом бегущих полос -->
                    <div class="content-card">
                        <h3 class="card-title">
                            <i class="bi bi-graph-up"></i>
                            Прогресс
                        </h3>
                        <div class="mb-3">
                            <label>Загрузка проекта</label>
                            <div class="progress">
                                <div class="progress-bar striped animated" role="progressbar" style="width: 75%" aria-valuenow="75" aria-valuemin="0" aria-valuemax="100">
                                    <span class="progress-bar-text">75%</span>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Использование диска</label>
                            <div class="progress">
                                <div class="progress-bar bg-success striped animated" role="progressbar" style="width: 45%" aria-valuenow="45" aria-valuemin="0" aria-valuemax="100">
                                    <span class="progress-bar-text">45%</span>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Пропускная способность</label>
                            <div class="progress">
                                <div class="progress-bar bg-warning striped animated" role="progressbar" style="width: 60%" aria-valuenow="60" aria-valuemin="0" aria-valuemax="100">
                                    <span class="progress-bar-text">60%</span>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Загрузка памяти</label>
                            <div class="progress">
                                <div class="progress-bar bg-danger striped animated" role="progressbar" style="width: 90%" aria-valuenow="90" aria-valuemin="0" aria-valuemax="100">
                                    <span class="progress-bar-text">90%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Разные элементы интерфейса -->
                    <div class="content-card">
                        <h3 class="card-title">
                            <i class="bi bi-collection"></i>
                            Элементы интерфейса
                        </h3>
                        
                        <h5>Бейджи</h5>
                        <div class="mb-3">
                            <span class="badge bg-primary badge-example">Основной</span>
                            <span class="badge bg-secondary badge-example">Вторичный</span>
                            <span class="badge bg-success badge-example">Успех</span>
                            <span class="badge bg-danger badge-example">Ошибка</span>
                            <span class="badge bg-warning badge-example">Предупреждение</span>
                            <span class="badge bg-info badge-example">Инфо</span>
                            <span class="badge bg-light badge-example">Светлый</span>
                            <span class="badge bg-dark badge-example">Темный</span>
                        </div>
                        



<h5>Модальное окно</h5>
<div class="mb-3">
  <div class="d-flex flex-wrap justify-content-center">
    <button type="button" class="btn btn-primary btn-open-modal m-2" data-bs-toggle="modal" data-bs-target="#modalFullscreen">
      Открыть Fullscreen
    </button>
    <button type="button" class="btn btn-secondary btn-open-modal m-2" data-bs-toggle="modal" data-bs-target="#modalMedium">
      Открыть Medium
    </button>
    <button type="button" class="btn btn-info btn-open-modal m-2" data-bs-toggle="modal" data-bs-target="#modal500">
      Открыть 500px
    </button>
  </div>

  <!-- Модальные окна с эффектом мутного стекла -->
  <div class="modal fade" id="modalFullscreen" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-custom">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Полноэкранное окно</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
        </div>
        <div class="modal-body">
          <p>Прокручиваемый контент с эффектом мутного стекла:</p>
          <div style="
            height: 200vh; 
            background: linear-gradient(135deg, var(--light-color) 0%, var(--card-bg) 100%);
            padding: 30px; 
            border-radius: 10px;
            border: 1px solid var(--border-color);
          ">
            <h3 style="color: var(--primary-color)">Длинный контент...</h3>
            <p style="color: var(--text-color)">Прокрутите вниз чтобы увидеть эффект скролла</p>
            <div style="height: 1500px; display: flex; align-items: center; justify-content: center; color: var(--muted-color);">
              Конец контента ↓
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
          <button type="button" class="btn btn-primary">Сохранить</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="modalMedium" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-medium">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Среднее окно (600px)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
        </div>
        <div class="modal-body">
          <p style="color: var(--text-color)">Это модальное окно средней ширины с эффектом мутного стекла.</p>
          <p style="color: var(--muted-color)">Подложка имеет blur-эффект, а само окно плавно появляется из центра.</p>
          <div style="
            background-color: var(--light-color);
            padding: 20px;
            border-radius: 8px;
            margin-top: 15px;
            border: 1px solid var(--border-color);
          ">
            <h6 style="color: var(--primary-color)">Детали оформления:</h6>
            <ul style="color: var(--text-color)">
              <li>Эффект матового стекла (backdrop-filter: blur)</li>
              <li>Анимация появления scale(0.95) → scale(1)</li>
              <li>Кастомный скроллбар с темизацией</li>
              <li>Поддержка светлой/тёмной темы</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
          <button type="button" class="btn btn-primary">Подтвердить</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="modal500" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-500px">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Узкое окно (500px)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
        </div>
        <div class="modal-body">
          <p style="color: var(--text-color)">Это узкое модальное окно шириной 500 пикселей.</p>
          <p style="color: var(--muted-color)">Идеально подходит для уведомлений, подтверждений действий или коротких форм.</p>
          
          <div class="form-group mt-3">
            <label style="color: var(--text-color)" class="form-label">Пример поля ввода:</label>
          </div>




<button type="button"
        class="btn btn-outline-primary btn-sm"
        data-open-custom-modal>
  <i class="bi bi-bell"></i> Показать поверх всех
</button>




        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Готово</button>
        </div>
      </div>
    </div>
  </div>
</div>



<!-- Модальное окно поверх всех (Overlay модалка) -->
<!-- Кастомное модальное окно (независимое) -->
<div id="customOverlayModal" class="custom-overlay-modal" role="dialog" aria-modal="true" aria-labelledby="customModalTitle">
  <div class="custom-modal-dialog">
    <div class="custom-modal-content">
      <div class="custom-modal-header">
        <h5 id="customModalTitle" class="custom-modal-title">Подтверждение действия</h5>
        <button type="button" class="custom-modal-close" aria-label="Закрыть">&times;</button>
      </div>
      <div class="custom-modal-body">
        <p>Вы уверены, что хотите продолжить? Это действие повлияет на обработку данных.</p>
        <div class="text-center mt-3">
          <i class="bi bi-info-circle text-primary" style="font-size: 2.2rem; color: var(--primary-color);"></i>
        </div>
      </div>
      <div class="custom-modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-dismiss="custom-modal">
                    <i class="bi bi-x-lg"></i> Отмена
                </button>
                <button type="button" class="btn btn-primary btn-sm">
                    <i class="bi bi-check-lg"></i> Обновить
                </button>
      </div>
    </div>
  </div>
</div>







                        <h5>Кнопки</h5>
                        <div class="mb-3">
                            <button type="button" class="btn btn-primary btn-sm tooltip-example">Основная</button>
                            <button type="button" class="btn btn-secondary btn-sm tooltip-example">Вторичная</button>
                            <button type="button" class="btn btn-success btn-sm tooltip-example">Успех</button>
                            <button type="button" class="btn btn-danger btn-sm tooltip-example">Ошибка</button>
                            <button type="button" class="btn btn-warning btn-sm tooltip-example">Предупреждение</button>
                            <button type="button" class="btn btn-info btn-sm tooltip-example">Инфо</button>
                            <button type="button" class="btn btn-light btn-sm tooltip-example">Светлая</button>
                            <button type="button" class="btn btn-dark btn-sm tooltip-example">Темная</button>
                        </div>
                        
                        <div class="mb-3">
                            <button type="button" class="btn btn-outline-primary btn-sm tooltip-example">Контурная</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm tooltip-example">Контурная</button>
                            <button type="button" class="btn btn-outline-success btn-sm tooltip-example">Контурная</button>
                        </div>
                        
                        <h5>Спиннеры</h5>
                        <div class="mb-3">
                            <div class="spinner-border spinner-example text-primary" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                            <div class="spinner-border spinner-example text-success" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                            <div class="spinner-border spinner-example text-danger" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                            <div class="spinner-grow spinner-example text-primary" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                            <div class="spinner-grow spinner-example text-success" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>







    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- Модульный JS admin -->
    <script type="module" src="js/main.js"></script>

<!-- WYSIWYG-редактор -->
<script src="js/editor.js"></script>
<script type="module" src="user_images/js/main.js"></script>

</body>
</html>

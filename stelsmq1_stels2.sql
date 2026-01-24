-- phpMyAdmin SQL Dump
-- version 4.9.7
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Янв 24 2026 г., 12:41
-- Версия сервера: 8.0.34-26-beget-1-1
-- Версия PHP: 5.6.40

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `stelsmq1_stels2`
--

-- --------------------------------------------------------

--
-- Структура таблицы `media_files`
--
-- Создание: Янв 16 2026 г., 08:00
-- Последнее обновление: Янв 22 2026 г., 15:27
--

DROP TABLE IF EXISTS `media_files`;
CREATE TABLE `media_files` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alt_text` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int NOT NULL,
  `file_versions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `upload_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_public` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Дамп данных таблицы `media_files`
--

INSERT INTO `media_files` (`id`, `user_id`, `title`, `description`, `alt_text`, `file_size`, `file_versions`, `upload_date`, `is_public`) VALUES
(26, 1, 'stels', 'stels', 'stels', 0, '{\"original\":{\"path\":\"2026\\/01\\/6969adb224e3b_1768533426.webp\",\"size\":4976,\"dimensions\":\"198x198\"},\"thumbnail\":{\"path\":\"2026\\/01\\/6969adb224e3b_1768533426_thumbnail.webp\",\"size\":2892,\"dimensions\":\"100x100\",\"mode\":\"cover\"},\"small\":{\"path\":\"2026\\/01\\/6969adb224e3b_1768533426_small.webp\",\"size\":10512,\"dimensions\":\"300x300\",\"mode\":\"contain\"},\"medium\":{\"path\":\"2026\\/01\\/6969adb224e3b_1768533426_medium.webp\",\"size\":20826,\"dimensions\":\"600x600\",\"mode\":\"contain\"},\"large\":{\"path\":\"2026\\/01\\/6969adb224e3b_1768533426_large.webp\",\"size\":36280,\"dimensions\":\"1200x1200\",\"mode\":\"contain\"}}', '2026-01-16 03:17:06', 1);

-- --------------------------------------------------------

--
-- Структура таблицы `pages`
--
-- Создание: Янв 23 2026 г., 09:34
-- Последнее обновление: Янв 23 2026 г., 18:48
--

DROP TABLE IF EXISTS `pages`;
CREATE TABLE `pages` (
  `id` bigint UNSIGNED NOT NULL,
  `users_id` bigint NOT NULL COMMENT 'ID пользователя',
  `naime` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название элемента каталога',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ЧПУ-URL элемента каталога',
  `author` bigint UNSIGNED NOT NULL COMMENT 'Родительский ID (ID связанной записи другой таблицы)',
  `related_table` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Имя таблицы, к которой относится author',
  `data` json DEFAULT NULL COMMENT 'Дополнительные данные в формате JSON',
  `sorting` int NOT NULL DEFAULT '0' COMMENT 'Порядок сортировки (меньше = выше)',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Статус записи: 0 — выключен, 1 — активен',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время создания записи',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время последнего редактирования'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Триггеры `pages`
--
DROP TRIGGER IF EXISTS `trg_pages_delete_cascade_extra`;
DELIMITER $$
CREATE TRIGGER `trg_pages_delete_cascade_extra` AFTER DELETE ON `pages` FOR EACH ROW BEGIN
    -- Удаляем все связанные записи из pages_extra где author = удалённый pages.id
    DELETE FROM pages_extra 
    WHERE author = OLD.id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Структура таблицы `pages_extra`
--
-- Создание: Янв 23 2026 г., 09:34
-- Последнее обновление: Янв 23 2026 г., 10:45
--

DROP TABLE IF EXISTS `pages_extra`;
CREATE TABLE `pages_extra` (
  `id` bigint UNSIGNED NOT NULL,
  `users_id` bigint NOT NULL COMMENT 'ID пользователя',
  `naime` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название элемента каталога',
  `author` bigint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Родительский ID (берётся из GET author=...)',
  `data` json DEFAULT NULL COMMENT 'Дополнительные данные в формате JSON',
  `sorting` int NOT NULL DEFAULT '0' COMMENT 'Порядок сортировки (меньше = выше)',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 — выключен, 1 — активен',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время создания записи',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время обновления записи'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `record`
--
-- Создание: Янв 22 2026 г., 16:13
-- Последнее обновление: Янв 23 2026 г., 22:07
--

DROP TABLE IF EXISTS `record`;
CREATE TABLE `record` (
  `id` bigint UNSIGNED NOT NULL,
  `users_id` bigint NOT NULL COMMENT 'ID пользователя',
  `naime` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название элемента каталога',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ЧПУ-URL элемента каталога',
  `author` bigint UNSIGNED NOT NULL COMMENT 'Родительский ID (ID связанной записи другой таблицы)',
  `related_table` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Имя таблицы, к которой относится author',
  `data` json DEFAULT NULL COMMENT 'Дополнительные данные в формате JSON',
  `sorting` int NOT NULL DEFAULT '0' COMMENT 'Порядок сортировки (меньше = выше)',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Статус записи: 0 — выключен, 1 — активен',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время создания записи',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время последнего редактирования'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Триггеры `record`
--
DROP TRIGGER IF EXISTS `trg_record_delete_cascade_extra`;
DELIMITER $$
CREATE TRIGGER `trg_record_delete_cascade_extra` AFTER DELETE ON `record` FOR EACH ROW BEGIN
    -- Удаляем все связанные записи из record_extra где author = удалённый record.id
    DELETE FROM record_extra 
    WHERE author = OLD.id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Структура таблицы `record_extra`
--
-- Создание: Янв 22 2026 г., 16:13
-- Последнее обновление: Янв 23 2026 г., 22:07
--

DROP TABLE IF EXISTS `record_extra`;
CREATE TABLE `record_extra` (
  `id` bigint UNSIGNED NOT NULL,
  `users_id` bigint NOT NULL COMMENT 'ID пользователя',
  `naime` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название элемента каталога',
  `author` bigint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Родительский ID (берётся из GET author=...)',
  `data` json DEFAULT NULL COMMENT 'Дополнительные данные в формате JSON',
  `sorting` int NOT NULL DEFAULT '0' COMMENT 'Порядок сортировки (меньше = выше)',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 — выключен, 1 — активен',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время создания записи',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время обновления записи'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `shop`
--
-- Создание: Янв 22 2026 г., 15:19
-- Последнее обновление: Янв 23 2026 г., 20:07
--

DROP TABLE IF EXISTS `shop`;
CREATE TABLE `shop` (
  `id` bigint UNSIGNED NOT NULL,
  `users_id` bigint NOT NULL COMMENT 'ID пользователя',
  `naime` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название элемента каталога',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ЧПУ-URL элемента каталога',
  `author` bigint UNSIGNED NOT NULL COMMENT 'Родительский ID (ID связанной записи другой таблицы)',
  `related_table` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Имя таблицы, к которой относится author',
  `data` json DEFAULT NULL COMMENT 'Дополнительные данные в формате JSON',
  `sorting` int NOT NULL DEFAULT '0' COMMENT 'Порядок сортировки (меньше = выше)',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Статус записи: 0 — выключен, 1 — активен',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время создания записи',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время последнего редактирования'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Триггеры `shop`
--
DROP TRIGGER IF EXISTS `trg_shop_delete_cascade_extra`;
DELIMITER $$
CREATE TRIGGER `trg_shop_delete_cascade_extra` AFTER DELETE ON `shop` FOR EACH ROW BEGIN
    -- Удаляем все связанные записи из shop_extra где author = удалённый shop.id
    DELETE FROM shop_extra 
    WHERE author = OLD.id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Структура таблицы `shop_extra`
--
-- Создание: Янв 21 2026 г., 08:16
-- Последнее обновление: Янв 23 2026 г., 21:19
--

DROP TABLE IF EXISTS `shop_extra`;
CREATE TABLE `shop_extra` (
  `id` bigint UNSIGNED NOT NULL,
  `users_id` bigint NOT NULL COMMENT 'ID пользователя',
  `naime` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название элемента каталога',
  `author` bigint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Родительский ID (берётся из GET author=...)',
  `data` json DEFAULT NULL COMMENT 'Дополнительные данные в формате JSON',
  `sorting` int NOT NULL DEFAULT '0' COMMENT 'Порядок сортировки (меньше = выше)',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 — выключен, 1 — активен',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время создания записи',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время обновления записи'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `support_messages`
--
-- Создание: Янв 16 2026 г., 03:08
-- Последнее обновление: Янв 22 2026 г., 08:26
--

DROP TABLE IF EXISTS `support_messages`;
CREATE TABLE `support_messages` (
  `id` int UNSIGNED NOT NULL,
  `ticket_id` int UNSIGNED NOT NULL,
  `author_type` enum('user','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `author_id` int NOT NULL COMMENT 'ID отправителя ($user[''id''])',
  `author_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Email отправителя ($userDataAdmin[''email''] или $adminData[''email''])',
  `message` varchar(10000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attachment_path` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `support_tickets`
--
-- Создание: Янв 16 2026 г., 03:08
-- Последнее обновление: Янв 22 2026 г., 08:26
--

DROP TABLE IF EXISTS `support_tickets`;
CREATE TABLE `support_tickets` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL COMMENT 'Значение $user[''id'']',
  `user_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Значение $userDataAdmin[''email''] на момент создания',
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `status` enum('new','in_progress','closed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'new',
  `last_author_type` enum('user','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--
-- Создание: Янв 16 2026 г., 07:58
-- Последнее обновление: Янв 23 2026 г., 22:16
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL,
  `author` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified` tinyint(1) DEFAULT '0',
  `verification_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_expires` datetime DEFAULT NULL,
  `reset_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reset_expire` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `data` json DEFAULT NULL COMMENT 'Данные в формато JSON',
  `status` tinyint DEFAULT NULL,
  `pending_email` varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_change_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_change_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `author`, `email`, `phone`, `password`, `email_verified`, `verification_token`, `token_expires`, `reset_token`, `reset_expire`, `created_at`, `data`, `status`, `pending_email`, `email_change_token`, `email_change_expires`) VALUES
(1, 'admin', '5954399@mail.ru', '+7 (134) 231-43-24', '$2y$12$51IrRpRY9c.Fl3XZ3iJrxu2dupYWzP5smjx3c/CCY3YYQnI7sWxry', 1, NULL, NULL, NULL, NULL, '2025-11-06 06:08:51', '{\"email\": \"5954399@mail.ru\", \"phone\": \"\", \"terms\": \"<h6>1. Общие положения</h6><p>1.1. Настоящие Условия использования регулируют отношения между Администрацией сайта и Пользователем.</p><p>1.2. Используя сайт, Пользователь соглашается с настоящими Условиями.</p><h6>2. Регистрация и учетная запись</h6><p>2.1. Для доступа к некоторым функциям сайта Пользователь должен зарегистрироваться.</p><p>2.2. Пользователь обязуется предоставлять достоверную информацию при регистрации.</p><p>2.3. Пользователь несет ответственность за сохранность своих учетных данных.</p><h6>3. Конфиденциальность</h6><p>3.1. Администрация обязуется защищать персональные данные Пользователя.</p><p>3.2. Пользователь соглашается на обработку своих персональных данных.</p><h6>4. Ограничения ответственности</h6><p>4.1. Администрация не несет ответственности за временные сбои в работе сайта.</p><p>4.2. Пользователь самостоятельно несет ответственность за свои действия на сайте.</p><h6>5. Интеллектуальная собственность</h6><p>5.1. Все материалы сайта защищены авторским правом.</p><p>5.2. Копирование материалов без разрешения запрещено.</p><h6>6. Изменения условий</h6><p>6.1. Администрация оставляет право изменять настоящие Условия.</p><p>6.2. Изменения вступают в силу с момента их публикации на сайте.</p><h6>7. Заключительные положения</h6><p>7.1. Настоящие Условия составляют соглашение между Пользователем и Администрацией.</p><p>7.2. Все споры решаются в соответствии с действующим законодательством.</p><div><i></i>Нажимая \\\"Принять условия\\\", вы подтверждаете свое согласие со всеми пунктами данного соглашения. </div>\", \"privacy\": \"<p><strong>Настоящая Политика конфиденциальности</strong> определяет порядок обработки и защиты персональных данных пользователей сайта.</p><h6>1. Сбор информации</h6><p>При регистрации вы предоставляете следующие данные: имя, фамилию, email, телефон (опционально). Также автоматически сохраняются IP-адрес и данные устройства.</p><h6>2. Цели обработки</h6><p>Ваши данные используются для:</p><ul><li>Создания и управления учётной записью</li><li>Отправки уведомлений (подтверждение регистрации, ответы техподдержки)</li><li>Обеспечения безопасности и предотвращения мошенничества</li></ul><h6>3. Хранение и защита</h6><p>Данные хранятся на защищённом сервере. Пароли хешируются. Доступ к данным имеют только авторизованные администраторы.</p><h6>4. Передача третьим лицам</h6><p>Ваши данные <strong>не передаются</strong> третьим лицам, кроме случаев, предусмотренных законодательством (например, по запросу суда).</p><h6>5. Ваши права</h6><p>Вы имеете право:</p><ul><li>Получить информацию о наличии ваших данных</li><li>Исправить неточные данные</li><li>Удалить аккаунт и все связанные данные</li></ul><h6>6. Изменения</h6><p>Администрация оставляет за собой право обновлять настоящую Политику. Актуальная версия всегда доступна на сайте.</p><div>Нажимая «Принять», вы даёте добровольное согласие на обработку персональных данных в соответствии с данной Политикой. </div>\", \"editor_1\": \"<h2>Сайт находится в разработке </h2><div><ul><li>Мы работаем над новой версией и скоро запустим её в работу. </li><li>Благодарим за ваше терпение! </li><li>Пожалуйста, зайдите позже. </li></ul></div>\", \"last_name\": \"stels\", \"AdminPanel\": \"StelsMoto\", \"first_name\": \"stels\", \"status_gpt\": true, \"updated_at\": \"2026-01-24 01:16:46\", \"image_limit\": 100, \"profile_logo\": \"26\", \"notifications\": true, \"profile_images\": \"26\", \"allow_news_admin\": true, \"allow_news_users\": true, \"allow_shop_admin\": true, \"allow_shop_users\": true, \"deepseek_api_key\": \"sk-928a7b8a638848a1b6ca0c1554a03247\", \"log_info_enabled\": true, \"allow_online_chat\": true, \"allow_pages_admin\": true, \"allow_pages_users\": false, \"log_error_enabled\": true, \"allow_photo_upload\": true, \"allow_registration\": true, \"allow_catalog_admin\": true, \"allow_catalog_users\": true}', 1, '5954399tel@mail.ru', 'ecb6a32ce7ae5777c86db27dcefc472db60da6748287cb920ba1a6d6e823cccd', '2026-01-12 19:10:29'),
(84, 'user', '75676595599@mail.ru', NULL, '$2y$12$51IrRpRY9c.Fl3XZ3iJrxu2dupYWzP5smjx3c/CCY3YYQnI7sWxry', 1, NULL, NULL, NULL, NULL, '2026-01-22 15:40:38', '{\"email\": \"5954399@mail.ru\", \"phone\": \"+7 (792) 678-38-34\", \"terms\": \"<h6>1. Общие положения</h6><p>1.1. Настоящие Условия использования регулируют отношения между Администрацией сайта и Пользователем.</p><p>1.2. Используя сайт, Пользователь соглашается с настоящими Условиями.</p><h6>2. Регистрация и учетная запись</h6><p>2.1. Для доступа к некоторым функциям сайта Пользователь должен зарегистрироваться.</p><p>2.2. Пользователь обязуется предоставлять достоверную информацию при регистрации.</p><p>2.3. Пользователь несет ответственность за сохранность своих учетных данных.</p><h6>3. Конфиденциальность</h6><p>3.1. Администрация обязуется защищать персональные данные Пользователя.</p><p>3.2. Пользователь соглашается на обработку своих персональных данных.</p><h6>4. Ограничения ответственности</h6><p>4.1. Администрация не несет ответственности за временные сбои в работе сайта.</p><p>4.2. Пользователь самостоятельно несет ответственность за свои действия на сайте.</p><h6>5. Интеллектуальная собственность</h6><p>5.1. Все материалы сайта защищены авторским правом.</p><p>5.2. Копирование материалов без разрешения запрещено.</p><h6>6. Изменения условий</h6><p>6.1. Администрация оставляет право изменять настоящие Условия.</p><p>6.2. Изменения вступают в силу с момента их публикации на сайте.</p><h6>7. Заключительные положения</h6><p>7.1. Настоящие Условия составляют соглашение между Пользователем и Администрацией.</p><p>7.2. Все споры решаются в соответствии с действующим законодательством.</p><div><i></i>Нажимая \\\"Принять условия\\\", вы подтверждаете свое согласие со всеми пунктами данного соглашения. </div>\", \"privacy\": \"<p><strong>Настоящая Политика конфиденциальности</strong> определяет порядок обработки и защиты персональных данных пользователей сайта.</p><h6>1. Сбор информации</h6><p>При регистрации вы предоставляете следующие данные: имя, фамилию, email, телефон (опционально). Также автоматически сохраняются IP-адрес и данные устройства.</p><h6>2. Цели обработки</h6><p>Ваши данные используются для:</p><ul><li>Создания и управления учётной записью</li><li>Отправки уведомлений (подтверждение регистрации, ответы техподдержки)</li><li>Обеспечения безопасности и предотвращения мошенничества</li></ul><h6>3. Хранение и защита</h6><p>Данные хранятся на защищённом сервере. Пароли хешируются. Доступ к данным имеют только авторизованные администраторы.</p><h6>4. Передача третьим лицам</h6><p>Ваши данные <strong>не передаются</strong> третьим лицам, кроме случаев, предусмотренных законодательством (например, по запросу суда).</p><h6>5. Ваши права</h6><p>Вы имеете право:</p><ul><li>Получить информацию о наличии ваших данных</li><li>Исправить неточные данные</li><li>Удалить аккаунт и все связанные данные</li></ul><h6>6. Изменения</h6><p>Администрация оставляет за собой право обновлять настоящую Политику. Актуальная версия всегда доступна на сайте.</p><div>Нажимая «Принять», вы даёте добровольное согласие на обработку персональных данных в соответствии с данной Политикой. </div>\", \"editor_1\": \"<h2>Сайт находится в разработке </h2><div><ul><li>Мы работаем над новой версией и скоро запустим её в работу. </li><li>Благодарим за ваше терпение! </li><li>Пожалуйста, зайдите позже. </li></ul></div>\", \"last_name\": \"пробба\", \"AdminPanel\": \"StelsMoto\", \"first_name\": \"lyudmila\", \"status_gpt\": true, \"updated_at\": \"2026-01-22 18:40:38\", \"image_limit\": 100, \"custom_field\": \"\", \"profile_logo\": \"26\", \"notifications\": true, \"profile_images\": \"\", \"allow_news_admin\": true, \"allow_news_users\": true, \"allow_shop_admin\": true, \"allow_shop_users\": true, \"deepseek_api_key\": \"sk-928a7b8a638848a1b6ca0c1554a03247\", \"log_info_enabled\": true, \"allow_online_chat\": true, \"allow_pages_admin\": true, \"allow_pages_users\": true, \"log_error_enabled\": true, \"allow_photo_upload\": true, \"allow_registration\": true, \"allow_catalog_admin\": true, \"allow_catalog_users\": true}', 1, NULL, NULL, NULL);

--
-- Триггеры `users`
--
DROP TRIGGER IF EXISTS `after_user_delete`;
DELIMITER $$
CREATE TRIGGER `after_user_delete` AFTER DELETE ON `users` FOR EACH ROW BEGIN
    DELETE FROM user_sessions WHERE user_id = OLD.id;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_users_delete_cascade_shop`;
DELIMITER $$
CREATE TRIGGER `trg_users_delete_cascade_shop` AFTER DELETE ON `users` FOR EACH ROW BEGIN
    -- Удаляем все связанные записи из shop где users_id = удалённый users.id
    DELETE FROM shop 
    WHERE users_id = OLD.id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Структура таблицы `user_sessions`
--
-- Создание: Янв 16 2026 г., 07:59
--

DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

--
-- Дамп данных таблицы `user_sessions`
--

INSERT INTO `user_sessions` (`id`, `user_id`, `token`, `expires_at`, `created_at`) VALUES
(53, 1, '1060666b9e7613c7399d3c3bb4c08c19ebc1acfec45bea00fc0e4b7ddec9b5c5', '2026-02-12 17:27:26', '2026-01-13 11:27:26');

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `media_files`
--
ALTER TABLE `media_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `pages`
--
ALTER TABLE `pages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `naime` (`naime`),
  ADD KEY `author` (`author`),
  ADD KEY `related_table` (`related_table`),
  ADD KEY `users_id` (`users_id`),
  ADD KEY `url` (`url`);

--
-- Индексы таблицы `pages_extra`
--
ALTER TABLE `pages_extra`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_author` (`author`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sorting` (`sorting`),
  ADD KEY `idx_users_id` (`users_id`) USING BTREE;

--
-- Индексы таблицы `record`
--
ALTER TABLE `record`
  ADD PRIMARY KEY (`id`),
  ADD KEY `naime` (`naime`),
  ADD KEY `author` (`author`),
  ADD KEY `related_table` (`related_table`),
  ADD KEY `users_id` (`users_id`),
  ADD KEY `url` (`url`);

--
-- Индексы таблицы `record_extra`
--
ALTER TABLE `record_extra`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_author` (`author`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sorting` (`sorting`),
  ADD KEY `idx_users_id` (`users_id`) USING BTREE;

--
-- Индексы таблицы `shop`
--
ALTER TABLE `shop`
  ADD PRIMARY KEY (`id`),
  ADD KEY `naime` (`naime`),
  ADD KEY `author` (`author`),
  ADD KEY `related_table` (`related_table`),
  ADD KEY `users_id` (`users_id`),
  ADD KEY `url` (`url`);

--
-- Индексы таблицы `shop_extra`
--
ALTER TABLE `shop_extra`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_author` (`author`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sorting` (`sorting`),
  ADD KEY `idx_users_id` (`users_id`) USING BTREE;

--
-- Индексы таблицы `support_messages`
--
ALTER TABLE `support_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`);

--
-- Индексы таблицы `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `status` (`status`),
  ADD KEY `author` (`author`);

--
-- Индексы таблицы `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `media_files`
--
ALTER TABLE `media_files`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT для таблицы `pages`
--
ALTER TABLE `pages`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `pages_extra`
--
ALTER TABLE `pages_extra`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `record`
--
ALTER TABLE `record`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `record_extra`
--
ALTER TABLE `record_extra`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `shop`
--
ALTER TABLE `shop`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `shop_extra`
--
ALTER TABLE `shop_extra`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `support_messages`
--
ALTER TABLE `support_messages`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=193;

--
-- AUTO_INCREMENT для таблицы `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

--
-- AUTO_INCREMENT для таблицы `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `support_messages`
--
ALTER TABLE `support_messages`
  ADD CONSTRAINT `support_messages_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

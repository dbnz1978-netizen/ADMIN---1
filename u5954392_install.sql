-- phpMyAdmin SQL Dump
-- version 4.9.7
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Фев 10 2026 г., 12:18
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
-- База данных: `u5954392_install`
--

-- --------------------------------------------------------

--
-- Структура таблицы `media_files`
--
-- Создание: Янв 24 2026 г., 10:24
-- Последнее обновление: Фев 10 2026 г., 05:33
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

-- --------------------------------------------------------

--
-- Структура таблицы `plugins`
--
-- Создание: Фев 10 2026 г., 07:51
-- Последнее обновление: Фев 10 2026 г., 08:58
--

DROP TABLE IF EXISTS `plugins`;
CREATE TABLE `plugins` (
  `id` int NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'Уникальное имя плагина (slug)',
  `display_name` varchar(255) NOT NULL COMMENT 'Отображаемое название',
  `version` varchar(50) DEFAULT NULL COMMENT 'Версия плагина',
  `author` varchar(255) DEFAULT NULL COMMENT 'Автор плагина',
  `description` text COMMENT 'Описание плагина',
  `is_installed` tinyint(1) DEFAULT '0' COMMENT 'Установлен ли плагин (0/1)',
  `is_enabled` tinyint(1) DEFAULT '0' COMMENT 'Включен ли плагин (0/1)',
  `delete_tables_on_uninstall` tinyint(1) DEFAULT '0' COMMENT 'Удалять таблицы при удалении (0/1)',
  `settings` text COMMENT 'JSON настроек плагина',
  `installed_at` datetime DEFAULT NULL COMMENT 'Дата установки',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата обновления'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COMMENT='Таблица плагинов';

-- --------------------------------------------------------

--
-- Структура таблицы `support_messages`
--
-- Создание: Фев 05 2026 г., 07:07
-- Последнее обновление: Фев 10 2026 г., 08:31
--

DROP TABLE IF EXISTS `support_messages`;
CREATE TABLE `support_messages` (
  `id` bigint UNSIGNED NOT NULL,
  `ticket_id` bigint UNSIGNED NOT NULL,
  `author_type` enum('user','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `author_id` bigint UNSIGNED NOT NULL COMMENT 'ID отправителя',
  `author_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Email отправителя на момент отправки',
  `message` varchar(10000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attachment_path` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `support_tickets`
--
-- Создание: Фев 05 2026 г., 07:07
-- Последнее обновление: Фев 10 2026 г., 08:31
--

DROP TABLE IF EXISTS `support_tickets`;
CREATE TABLE `support_tickets` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL COMMENT 'Значение $user[''id'']',
  `user_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Email на момент создания',
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
-- Создание: Фев 05 2026 г., 07:06
-- Последнее обновление: Фев 10 2026 г., 09:14
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL,
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
  `data` json DEFAULT NULL COMMENT 'Данные в формате JSON',
  `status` tinyint DEFAULT NULL,
  `pending_email` varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_change_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_change_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `author`, `email`, `phone`, `password`, `email_verified`, `verification_token`, `token_expires`, `reset_token`, `reset_expire`, `created_at`, `data`, `status`, `pending_email`, `email_change_token`, `email_change_expires`) VALUES
(1, 'admin', '5954399@mail.ru', NULL, '$2y$12$G16/QcwPDzfVWyqHe.jp2.MdHDSTrm859lEHbdyiWQyeyuSSDMr8u', 1, NULL, NULL, NULL, NULL, '2025-11-06 06:08:51', '{\"email\": \"5954399@mail.ru\", \"phone\": \"\", \"terms\": \"<h6>1. Общие положения</h6><p>1.1. Настоящие Условия использования регулируют отношения между Администрацией сайта и Пользователем.</p><p>1.2. Используя сайт, Пользователь соглашается с настоящими Условиями.</p><h6>2. Регистрация и учетная запись</h6><p>2.1. Для доступа к некоторым функциям сайта Пользователь должен зарегистрироваться.</p><p>2.2. Пользователь обязуется предоставлять достоверную информацию при регистрации.</p><p>2.3. Пользователь несет ответственность за сохранность своих учетных данных.</p><h6>3. Конфиденциальность</h6><p>3.1. Администрация обязуется защищать персональные данные Пользователя.</p><p>3.2. Пользователь соглашается на обработку своих персональных данных.</p><h6>4. Ограничения ответственности</h6><p>4.1. Администрация не несет ответственности за временные сбои в работе сайта.</p><p>4.2. Пользователь самостоятельно несет ответственность за свои действия на сайте.</p><h6>5. Интеллектуальная собственность</h6><p>5.1. Все материалы сайта защищены авторским правом.</p><p>5.2. Копирование материалов без разрешения запрещено.</p><h6>6. Изменения условий</h6><p>6.1. Администрация оставляет право изменять настоящие Условия.</p><p>6.2. Изменения вступают в силу с момента их публикации на сайте.</p><h6>7. Заключительные положения</h6><p>7.1. Настоящие Условия составляют соглашение между Пользователем и Администрацией.</p><p>7.2. Все споры решаются в соответствии с действующим законодательством.</p><div><i></i>Нажимая \\\"Принять условия\\\", вы подтверждаете свое согласие со всеми пунктами данного соглашения. </div>\", \"images\": \"15\", \"privacy\": \"<p>Настоящая Политика конфиденциальности определяет порядок обработки и защиты персональных данных пользователей сайта.</p><h6>1. Сбор информации</h6><p>При регистрации вы предоставляете следующие данные: имя, фамилию, email, телефон (опционально). Также автоматически сохраняются IP-адрес и данные устройства.</p><h6>2. Цели обработки</h6><p>Ваши данные используются для:</p><ul><li>Создания и управления учётной записью</li><li>Отправки уведомлений (подтверждение регистрации, ответы техподдержки)</li><li>Обеспечения безопасности и предотвращения мошенничества</li></ul><h6>3. Хранение и защита</h6><p>Данные хранятся на защищённом сервере. Пароли хешируются. Доступ к данным имеют только авторизованные администраторы.</p><h6>4. Передача третьим лицам</h6><p>Ваши данные <strong>не передаются</strong> третьим лицам, кроме случаев, предусмотренных законодательством (например, по запросу суда).</p><h6>5. Ваши права</h6><p>Вы имеете право:</p><ul><li>Получить информацию о наличии ваших данных</li><li>Исправить неточные данные</li><li>Удалить аккаунт и все связанные данные</li></ul><h6>6. Изменения</h6><p>Администрация оставляет за собой право обновлять настоящую Политику. Актуальная версия всегда доступна на сайте.</p><div>Нажимая «Принять», вы даёте добровольное согласие на обработку персональных данных в соответствии с данной Политикой. </div>\", \"editor_1\": \"<img src=\\\"https://catalog-soft.ru/uploads/2026/02/69805dd39ba85_1770020307_medium.webp\\\" alt=\\\"Media\\\" class=\\\"float-left\\\" style=\\\"width: 199px; height: 199px\\\"><h2>Сайт находится в разработке </h2><div><ul><li>Мы работаем над новой версией и скоро запустим её в работу.</li><li>Благодарим за ваше терпение! </li><li> Пожалуйста, зайдите позже.</li></ul></div>\", \"last_name\": \"stels\", \"AdminPanel\": \"StelsMoto\", \"first_name\": \"stels\", \"status_gpt\": true, \"updated_at\": \"2026-02-09 23:11:23\", \"image_limit\": 222, \"profile_logo\": \"15,15\", \"notifications\": true, \"profile_images\": \"18,15\", \"deepseek_api_key\": \"sk-928a7b8a638848a1b6ca0c1554a03247\", \"log_info_enabled\": true, \"allow_online_chat\": true, \"log_error_enabled\": true, \"allow_photo_upload\": true, \"allow_registration\": true}', 1, '5954399@mail.ru', '', NULL),
(144, 'user', '5954399tel@mail.ru', NULL, '$2y$12$G16/QcwPDzfVWyqHe.jp2.MdHDSTrm859lEHbdyiWQyeyuSSDMr8u', 1, NULL, NULL, NULL, NULL, '2026-02-07 20:38:56', '{\"email\": \"5954399@mail.ru\", \"phone\": \"+7 (566) 757-56-76\", \"terms\": \"<h6>1. Общие положения</h6><p>1.1. Настоящие Условия использования регулируют отношения между Администрацией сайта и Пользователем.</p><p>1.2. Используя сайт, Пользователь соглашается с настоящими Условиями.</p><h6>2. Регистрация и учетная запись</h6><p>2.1. Для доступа к некоторым функциям сайта Пользователь должен зарегистрироваться.</p><p>2.2. Пользователь обязуется предоставлять достоверную информацию при регистрации.</p><p>2.3. Пользователь несет ответственность за сохранность своих учетных данных.</p><h6>3. Конфиденциальность</h6><p>3.1. Администрация обязуется защищать персональные данные Пользователя.</p><p>3.2. Пользователь соглашается на обработку своих персональных данных.</p><h6>4. Ограничения ответственности</h6><p>4.1. Администрация не несет ответственности за временные сбои в работе сайта.</p><p>4.2. Пользователь самостоятельно несет ответственность за свои действия на сайте.</p><h6>5. Интеллектуальная собственность</h6><p>5.1. Все материалы сайта защищены авторским правом.</p><p>5.2. Копирование материалов без разрешения запрещено.</p><h6>6. Изменения условий</h6><p>6.1. Администрация оставляет право изменять настоящие Условия.</p><p>6.2. Изменения вступают в силу с момента их публикации на сайте.</p><h6>7. Заключительные положения</h6><p>7.1. Настоящие Условия составляют соглашение между Пользователем и Администрацией.</p><p>7.2. Все споры решаются в соответствии с действующим законодательством.</p><div><i></i>Нажимая \\\"Принять условия\\\", вы подтверждаете свое согласие со всеми пунктами данного соглашения. </div>\", \"images\": \"15\", \"privacy\": \"<p>Настоящая Политика конфиденциальности определяет порядок обработки и защиты персональных данных пользователей сайта.</p><h6>1. Сбор информации</h6><p>При регистрации вы предоставляете следующие данные: имя, фамилию, email, телефон (опционально). Также автоматически сохраняются IP-адрес и данные устройства.</p><h6>2. Цели обработки</h6><p>Ваши данные используются для:</p><ul><li>Создания и управления учётной записью</li><li>Отправки уведомлений (подтверждение регистрации, ответы техподдержки)</li><li>Обеспечения безопасности и предотвращения мошенничества</li></ul><h6>3. Хранение и защита</h6><p>Данные хранятся на защищённом сервере. Пароли хешируются. Доступ к данным имеют только авторизованные администраторы.</p><h6>4. Передача третьим лицам</h6><p>Ваши данные <strong>не передаются</strong> третьим лицам, кроме случаев, предусмотренных законодательством (например, по запросу суда).</p><h6>5. Ваши права</h6><p>Вы имеете право:</p><ul><li>Получить информацию о наличии ваших данных</li><li>Исправить неточные данные</li><li>Удалить аккаунт и все связанные данные</li></ul><h6>6. Изменения</h6><p>Администрация оставляет за собой право обновлять настоящую Политику. Актуальная версия всегда доступна на сайте.</p><div>Нажимая «Принять», вы даёте добровольное согласие на обработку персональных данных в соответствии с данной Политикой. </div>\", \"editor_1\": \"<img src=\\\"https://catalog-soft.ru/uploads/2026/02/69805dd39ba85_1770020307_medium.webp\\\" alt=\\\"Media\\\" class=\\\"float-left\\\" style=\\\"width: 199px; height: 199px\\\"><h2>Сайт находится в разработке </h2><div><ul><li>Мы работаем над новой версией и скоро запустим её в работу.</li><li>Благодарим за ваше терпение! </li><li> Пожалуйста, зайдите позже.</li></ul></div>\", \"last_name\": \"кеен\", \"AdminPanel\": \"StelsMoto\", \"first_name\": \"ttruy\", \"status_gpt\": true, \"updated_at\": \"2026-02-08 09:34:10\", \"image_limit\": 222, \"custom_field\": \"\", \"profile_logo\": \"1,2\", \"notifications\": true, \"profile_images\": \"16,16\", \"deepseek_api_key\": \"sk-928a7b8a638848a1b6ca0c1554a03247\", \"log_info_enabled\": true, \"allow_online_chat\": true, \"log_error_enabled\": true, \"allow_photo_upload\": true, \"allow_registration\": true}', 1, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Структура таблицы `user_sessions`
--
-- Создание: Фев 05 2026 г., 07:06
-- Последнее обновление: Фев 10 2026 г., 05:03
--

DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` bigint UNSIGNED NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `user_sessions`
--

INSERT INTO `user_sessions` (`id`, `user_id`, `token`, `expires_at`, `created_at`) VALUES
(10, 1, '38a62ff3c70ba1280747e6cc3b11a8ea64864850be1914642f7804eb2821f7be', '2026-03-12 08:03:16', '2026-02-10 05:03:16');

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
-- Индексы таблицы `plugins`
--
ALTER TABLE `plugins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Индексы таблицы `support_messages`
--
ALTER TABLE `support_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `author_id` (`author_id`),
  ADD KEY `idx_created_at` (`created_at`);

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
  ADD KEY `idx_expires_at` (`expires_at`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `media_files`
--
ALTER TABLE `media_files`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `plugins`
--
ALTER TABLE `plugins`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `support_messages`
--
ALTER TABLE `support_messages`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `support_tickets`
--
ALTER TABLE `support_tickets`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=146;

--
-- AUTO_INCREMENT для таблицы `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `support_messages`
--
ALTER TABLE `support_messages`
  ADD CONSTRAINT `support_messages_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `support_messages_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `support_tickets`
--
ALTER TABLE `support_tickets`
  ADD CONSTRAINT `support_tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

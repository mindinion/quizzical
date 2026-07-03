-- AI Quiz tables
-- Run once on the live database to set up AI-generated quiz support.

CREATE TABLE `AIQuiz` (
  `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`         enum('Morning','Afternoon') NOT NULL,
  `date`         date NOT NULL,
  `generated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status`       enum('active','hidden') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_date` (`type`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `AIQuestion` (
  `id`            int UNSIGNED NOT NULL AUTO_INCREMENT,
  `quiz_id`       int UNSIGNED NOT NULL,
  `position`      tinyint UNSIGNED NOT NULL COMMENT '1–15',
  `question_text` text NOT NULL,
  `category`      varchar(50) NOT NULL,
  `format`        enum('mc','tf') NOT NULL DEFAULT 'mc',
  PRIMARY KEY (`id`),
  KEY `quiz_id` (`quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `AIOption` (
  `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
  `question_id` int UNSIGNED NOT NULL,
  `position`    tinyint UNSIGNED NOT NULL COMMENT '1–4 for MC, 1–2 for T/F',
  `option_text` varchar(500) NOT NULL,
  `is_correct`  tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `question_id` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores each user's per-question answer during a wizard session.
-- user_id + question_id is unique to prevent re-answering.
CREATE TABLE `AIAnswer` (
  `id`               int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          int NOT NULL,
  `quiz_id`          int UNSIGNED NOT NULL,
  `question_id`      int UNSIGNED NOT NULL,
  `chosen_option_id` int UNSIGNED DEFAULT NULL,
  `is_correct`       tinyint(1) NOT NULL DEFAULT 0,
  `answered_at`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_question` (`user_id`, `question_id`),
  KEY `user_quiz` (`user_id`, `quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

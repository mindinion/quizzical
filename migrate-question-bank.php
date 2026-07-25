<?php
/**
 * migrate-question-bank.php
 *
 * Creates QuizQuestionBank tables and optional AIQuestion columns.
 * Run once on production after deploy:
 *   php migrate-question-bank.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/dblogin.php';

$statements = [
    "CREATE TABLE IF NOT EXISTS `QuizQuestionBank` (
      `id`              int UNSIGNED NOT NULL AUTO_INCREMENT,
      `source`          varchar(30) NOT NULL,
      `source_id`       varchar(120) NOT NULL,
      `category`        varchar(50) NOT NULL,
      `question_text`   text NOT NULL,
      `format`          enum('mc','tf') NOT NULL DEFAULT 'mc',
      `difficulty`      varchar(10) DEFAULT NULL,
      `attribution`     varchar(255) DEFAULT NULL,
      `imported_at`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `last_used_at`    datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `source_unique` (`source`, `source_id`),
      KEY `category_available` (`category`, `last_used_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `QuizQuestionBankOption` (
      `id`          int UNSIGNED NOT NULL AUTO_INCREMENT,
      `bank_id`     int UNSIGNED NOT NULL,
      `position`    tinyint UNSIGNED NOT NULL,
      `option_text` varchar(500) NOT NULL,
      `is_correct`  tinyint(1) NOT NULL DEFAULT 0,
      PRIMARY KEY (`id`),
      KEY `bank_id` (`bank_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($statements as $sql) {
    if (!$conn->query($sql)) {
        fwrite(STDERR, "Migration failed: " . $conn->error . "\n");
        exit(1);
    }
    echo "OK: " . substr($sql, 0, 40) . "…\n";
}

$alterColumns = [
    'bank_id' => 'ALTER TABLE `AIQuestion` ADD COLUMN `bank_id` int UNSIGNED DEFAULT NULL',
    'source'  => 'ALTER TABLE `AIQuestion` ADD COLUMN `source` varchar(30) DEFAULT NULL',
];

foreach ($alterColumns as $col => $sql) {
    $r = $conn->query("SHOW COLUMNS FROM AIQuestion LIKE '$col'");
    if ($r && $r->num_rows > 0) {
        echo "Skip: AIQuestion.$col already exists\n";
        continue;
    }
    if (!$conn->query($sql)) {
        fwrite(STDERR, "ALTER failed ($col): " . $conn->error . "\n");
        exit(1);
    }
    echo "OK: added AIQuestion.$col\n";
}

echo "Migration complete.\n";
$conn->close();
exit(0);

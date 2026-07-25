<?php
/**
 * action-setup-question-bank.php
 *
 * One-time (or repeat-safe) bank migration + seed via HTTP.
 * Auth: QA_RUN_TOKEN (same as action-qa-generate-quiz.php).
 *
 * POST JSON: { "token": "...", "mode": "migrate"|"seed-full"|"seed-incremental"|"all" }
 */

require_once __DIR__ . '/dblogin.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/qa-run-lib.php';
require_once __DIR__ . '/question-bank.php';

header('Content-Type: application/json');

@ignore_user_abort(true);
@set_time_limit(3600);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}

$token = $body['token'] ?? ($_SERVER['HTTP_X_QA_TOKEN'] ?? '');
if (!bankSetupTokenIsValid(is_string($token) ? $token : null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$mode = isset($body['mode']) ? strtolower(trim((string)$body['mode'])) : 'all';
$validModes = ['migrate', 'seed-full', 'seed-incremental', 'seed-otqa', 'seed-otdb-full', 'status', 'all'];
if (!in_array($mode, $validModes, true)) {
    $mode = 'all';
}

$out = ['ok' => true, 'mode' => $mode, 'steps' => []];

try {
    if ($mode === 'status') {
        $out['steps']['status'] = [
            'tables_exist' => bankTableExists($conn),
            'totals'       => bankTableExists($conn) ? bankTotalCounts($conn) : [],
            'health'       => bankTableExists($conn) ? bankPoolHealth($conn) : [],
        ];
        $conn->close();
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($mode === 'migrate' || $mode === 'all') {
        $out['steps']['migrate'] = runQuestionBankMigration($conn);
    }

    if ($mode === 'seed-full' || $mode === 'all') {
        if (!bankTableExists($conn)) {
            throw new RuntimeException('QuizQuestionBank table missing — run migrate first');
        }
        $seedOut = ['opentriviaqa' => [], 'opentdb' => []];
        $seedOut['opentriviaqa'] = bankSeedOpenTriviaQa($conn);
        $seedOut['opentdb'] = bankSeedOpenTdb($conn, true);
        $seedOut['totals'] = bankTotalCounts($conn);
        $seedOut['health'] = bankPoolHealth($conn);
        $out['steps']['seed_full'] = $seedOut;
    } elseif ($mode === 'seed-otqa') {
        if (!bankTableExists($conn)) {
            throw new RuntimeException('QuizQuestionBank table missing — run migrate first');
        }
        $out['steps']['seed_otqa'] = [
            'opentriviaqa' => bankSeedOpenTriviaQa($conn),
            'totals'       => bankTotalCounts($conn),
            'health'       => bankPoolHealth($conn),
        ];
    } elseif ($mode === 'seed-otdb-full') {
        if (!bankTableExists($conn)) {
            throw new RuntimeException('QuizQuestionBank table missing — run migrate first');
        }
        $out['steps']['seed_otdb'] = [
            'opentdb' => bankSeedOpenTdb($conn, true),
            'totals'  => bankTotalCounts($conn),
            'health'  => bankPoolHealth($conn),
        ];
    } elseif ($mode === 'seed-incremental') {
        if (!bankTableExists($conn)) {
            throw new RuntimeException('QuizQuestionBank table missing — run migrate first');
        }
        $seedOut = ['opentdb' => bankSeedOpenTdb($conn, false)];
        $seedOut['totals'] = bankTotalCounts($conn);
        $seedOut['health'] = bankPoolHealth($conn);
        $out['steps']['seed_incremental'] = $seedOut;
    }

    $conn->close();
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

/** @return array{tables: list<string>, aiquestion_columns: list<string>} */
function runQuestionBankMigration(mysqli $conn): array {
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

    $tables = [];
    foreach ($statements as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException('Migration failed: ' . $conn->error);
        }
        if (preg_match('/CREATE TABLE IF NOT EXISTS `(\w+)`/', $sql, $m)) {
            $tables[] = $m[1];
        }
    }

    $added = [];
    foreach (['bank_id' => 'int UNSIGNED DEFAULT NULL', 'source' => 'varchar(30) DEFAULT NULL'] as $col => $def) {
        $r = $conn->query("SHOW COLUMNS FROM AIQuestion LIKE '$col'");
        if ($r && $r->num_rows > 0) {
            continue;
        }
        if (!$conn->query("ALTER TABLE `AIQuestion` ADD COLUMN `$col` $def")) {
            throw new RuntimeException("ALTER AIQuestion.$col failed: " . $conn->error);
        }
        $added[] = $col;
    }

    return ['tables' => $tables, 'aiquestion_columns' => $added];
}

function bankSetupTokenIsValid(?string $token): bool {
    if (qaTokenIsValid($token)) {
        return true;
    }
    return defined('BANK_SETUP_TOKEN')
        && BANK_SETUP_TOKEN !== ''
        && is_string($token)
        && hash_equals(BANK_SETUP_TOKEN, $token);
}

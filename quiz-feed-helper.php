<?php
/**
 * Helpers for quiz-centric feed (v2): quiz keys, discussion shells, column detection.
 */

require_once __DIR__ . '/ai-quiz-stats.php';

function quizFeedHasDiscussionColumns(mysqli $conn): bool {
    static $has = null;
    if ($has === null) {
        $r = $conn->query("SHOW COLUMNS FROM QuizFeed LIKE 'ai_quiz_id'");
        $has = $r && $r->num_rows > 0;
    }
    return $has;
}

function quizFeedEnsureDiscussionColumns(mysqli $conn): bool {
    if (quizFeedHasDiscussionColumns($conn)) {
        return true;
    }
    if (!$conn->query(
        "ALTER TABLE `QuizFeed`
         ADD COLUMN `ai_quiz_id` int UNSIGNED DEFAULT NULL,
         ADD COLUMN `quiz_type` varchar(50) DEFAULT NULL,
         ADD COLUMN `quiz_date` date DEFAULT NULL"
    )) {
        return false;
    }
    $conn->query(
        "ALTER TABLE `QuizFeed`
         ADD KEY `idx_quiz_discussion` (`ai_quiz_id`, `quiz_type`, `quiz_date`)"
    );
    return quizFeedHasDiscussionColumns($conn);
}

/** @return array{key: string, ai_quiz_id: int|null, quiz_type: string|null, quiz_date: string|null} */
function feedQuizKeyFromResultRow(array $row): array {
    $quizId = isset($row['result_quiz_id']) && $row['result_quiz_id'] !== null
        ? (int)$row['result_quiz_id'] : 0;
    if ($quizId > 0) {
        return [
            'key'         => 'ai:' . $quizId,
            'ai_quiz_id'  => $quizId,
            'quiz_type'   => null,
            'quiz_date'   => $row['result_quiz_date'] ?? null,
        ];
    }
    $type = (string)($row['result_type'] ?? '');
    $date = $row['result_quiz_date'] ?? null;
    if (!$date && !empty($row['result_date'])) {
        $date = substr((string)$row['result_date'], 0, 10);
    }
    return [
        'key'         => 'legacy:' . $type . ':' . $date,
        'ai_quiz_id'  => null,
        'quiz_type'   => $type !== '' ? $type : null,
        'quiz_date'   => $date,
    ];
}

function feedQuizTitle(mysqli $conn, ?int $aiQuizId, ?string $quizType, ?string $quizDate): string {
    if ($aiQuizId > 0) {
        $q = $conn->query(
            "SELECT type, date FROM AIQuiz WHERE id = " . (int)$aiQuizId . " LIMIT 1"
        );
        if ($q && $row = $q->fetch_assoc()) {
            $dt = new DateTime($row['date']);
            return $row['type'] . ' Quiz — ' . $dt->format('j M');
        }
    }
    $label = preg_replace('/^Quizzical\s+/i', '', (string)$quizType);
    if ($quizDate) {
        try {
            $dt = new DateTime($quizDate);
            return ($label !== '' ? $label : 'Quiz') . ' — ' . $dt->format('j M');
        } catch (Exception $e) {
            // fall through
        }
    }
    return $label !== '' ? $label . ' Quiz' : 'Quiz';
}

function feedGroupMemberCount(mysqli $conn, int $groupId): int {
    $q = $conn->query(
        "SELECT COUNT(DISTINCT user_id) AS c FROM Memberships WHERE group_id = " . (int)$groupId
    );
    if ($q && $row = $q->fetch_assoc()) {
        return max(1, (int)$row['c']);
    }
    return 1;
}

/**
 * Find or create a QuizFeed shell for quiz-level discussion.
 * @return int QuizFeed post id, or 0 on failure
 */
function ensureQuizShell(
    mysqli $conn,
    int $userId,
    ?int $aiQuizId,
    ?string $quizType,
    ?string $quizDate,
    string $timezone
): int {
    if (!quizFeedHasDiscussionColumns($conn)) {
        return 0;
    }

    $aiQuizId = $aiQuizId > 0 ? $aiQuizId : null;
    $quizTypeEsc = $quizType !== null && $quizType !== ''
        ? "'" . $conn->real_escape_string($quizType) . "'" : 'NULL';
    $quizDateEsc = $quizDate !== null && $quizDate !== ''
        ? "'" . $conn->real_escape_string($quizDate) . "'" : 'NULL';
    $aiEsc = $aiQuizId !== null ? (int)$aiQuizId : 'NULL';

    if ($aiQuizId !== null) {
        $find = $conn->query(
            "SELECT id FROM QuizFeed
             WHERE status = 'active' AND result_id IS NULL AND ai_quiz_id = $aiEsc
             LIMIT 1"
        );
    } else {
        $find = $conn->query(
            "SELECT id FROM QuizFeed
             WHERE status = 'active' AND result_id IS NULL
               AND ai_quiz_id IS NULL
               AND quiz_type = $quizTypeEsc AND quiz_date = $quizDateEsc
             LIMIT 1"
        );
    }
    if ($find && $row = $find->fetch_assoc()) {
        return (int)$row['id'];
    }

    date_default_timezone_set($timezone);
    $now = date('Y-m-d H:i:s');
    $uid = (int)$userId;
    $conn->query(
        "INSERT INTO QuizFeed (user_id, comment, timestamp, ai_quiz_id, quiz_type, quiz_date)
         VALUES ($uid, '', '$now', $aiEsc, $quizTypeEsc, $quizDateEsc)"
    );
    return (int)$conn->insert_id;
}

/** @return list<array{type: string, date: string, quiz_id: int}> */
function feedTodaysAiQuizzes(mysqli $conn): array {
    $nztz = new DateTimeZone('Pacific/Auckland');
    $today = (new DateTime('now', $nztz))->format('Y-m-d');
    $out = [];
    $q = $conn->query(
        "SELECT id, type, date FROM AIQuiz
         WHERE status = 'active' AND date = '$today'
         ORDER BY FIELD(type, 'Morning', 'Afternoon')"
    );
    while ($q && $row = $q->fetch_assoc()) {
        $out[] = [
            'type'    => $row['type'],
            'date'    => $row['date'],
            'quiz_id' => (int)$row['id'],
        ];
    }
    return $out;
}

function feedMaxActivityTimestamp(array $timestamps): ?string {
    $best = null;
    foreach ($timestamps as $ts) {
        if ($ts === null || $ts === '') continue;
        if ($best === null || strcmp($ts, $best) > 0) {
            $best = $ts;
        }
    }
    return $best;
}

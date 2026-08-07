<?php
/**
 * ai-lifeline.php — 50/50 lifeline helpers for AI quizzes.
 */

const AI_LIFELINE_MAX_PER_QUIZ = 2;

function ensureAiLifelineTable(mysqli $conn): void {
    static $ready = false;
    if ($ready) {
        return;
    }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `AILifeline` (
          `id`                     int UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id`                int NOT NULL,
          `quiz_id`                int UNSIGNED NOT NULL,
          `question_id`            int UNSIGNED NOT NULL,
          `eliminated_option_id_1` int UNSIGNED NOT NULL,
          `eliminated_option_id_2` int UNSIGNED NOT NULL,
          `created_at`             datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `user_quiz_question` (`user_id`, `quiz_id`, `question_id`),
          KEY `user_quiz` (`user_id`, `quiz_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ready = true;
}

function aiLifelineUsesForQuiz(mysqli $conn, int $userId, int $quizId): int {
    ensureAiLifelineTable($conn);
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS c FROM AILifeline WHERE user_id = ? AND quiz_id = ?'
    );
    $stmt->bind_param('ii', $userId, $quizId);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
}

function aiLifelineRemaining(mysqli $conn, int $userId, int $quizId): int {
    return max(0, AI_LIFELINE_MAX_PER_QUIZ - aiLifelineUsesForQuiz($conn, $userId, $quizId));
}

/**
 * @return array<int, list<int>> question_id => [option_id, option_id]
 */
function aiLifelineEliminationsForQuiz(mysqli $conn, int $userId, int $quizId): array {
    ensureAiLifelineTable($conn);
    $stmt = $conn->prepare(
        'SELECT question_id, eliminated_option_id_1, eliminated_option_id_2
         FROM AILifeline WHERE user_id = ? AND quiz_id = ?'
    );
    $stmt->bind_param('ii', $userId, $quizId);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $qid = (int)$row['question_id'];
        $out[$qid] = [
            (int)$row['eliminated_option_id_1'],
            (int)$row['eliminated_option_id_2'],
        ];
    }
    $stmt->close();
    return $out;
}

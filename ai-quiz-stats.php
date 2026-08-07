<?php
/**
 * Shared helpers for AI quiz answer statistics.
 */

/** @return array{option_stats: list<array{option_id: int, count: int, pct: int}>, total_answers: int} */
function aiAnswerOptionStats(mysqli $conn, int $questionId): array {
    $stmt = $conn->prepare(
        'SELECT chosen_option_id, COUNT(*) AS cnt FROM AIAnswer WHERE question_id = ? GROUP BY chosen_option_id'
    );
    $stmt->bind_param('i', $questionId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $countsByOption = [];
    $total = 0;
    foreach ($rows as $row) {
        $cnt = (int)$row['cnt'];
        $countsByOption[(int)$row['chosen_option_id']] = $cnt;
        $total += $cnt;
    }

    $optStmt = $conn->prepare('SELECT id FROM AIOption WHERE question_id = ? ORDER BY position');
    $optStmt->bind_param('i', $questionId);
    $optStmt->execute();
    $optionRows = $optStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $optStmt->close();

    $stats = [];
    foreach ($optionRows as $row) {
        $optId = (int)$row['id'];
        $cnt = $countsByOption[$optId] ?? 0;
        $stats[] = [
            'option_id' => $optId,
            'count'     => $cnt,
            'pct'       => $total > 0 ? (int)round($cnt / $total * 100) : 0,
        ];
    }

    return ['option_stats' => $stats, 'total_answers' => $total];
}

/**
 * Results.ai_quiz_id links a posted result to the AIQuiz it came from. Without it the
 * app can only guess from the posting timestamp, which misattributes any quiz played
 * on a later day than its own date. Added by result-ai-quiz-id-migration.sql.
 */
function resultsHasAiQuizId(mysqli $conn): bool {
    static $has = null;
    if ($has === null) {
        $r = $conn->query("SHOW COLUMNS FROM Results LIKE 'ai_quiz_id'");
        $has = $r && $r->num_rows > 0;
    }
    return $has;
}

/**
 * SQL fragment matching the logged-in user's posted result for one AI quiz.
 * Prefers the explicit link and falls back to the old type + date guess for rows
 * predating the migration. Placeholders: ai_quiz_id, type, date.
 */
function aiResultMatchSql(mysqli $conn): string {
    if (resultsHasAiQuizId($conn)) {
        return "(ai_quiz_id = ? OR (ai_quiz_id IS NULL AND type = ? AND DATE(date) = ?))";
    }
    return "(? IS NOT NULL AND type = ? AND DATE(date) = ?)";
}

function aiQuestionHasColumn(mysqli $conn, string $column): bool {
    $col = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM AIQuestion LIKE '$col'");
    return $r && $r->num_rows > 0;
}

function aiQuestionHasBankColumns(mysqli $conn): bool {
    return aiQuestionHasColumn($conn, 'bank_id');
}

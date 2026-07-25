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

    $total = 0;
    foreach ($rows as $row) {
        $total += (int)$row['cnt'];
    }

    $stats = [];
    foreach ($rows as $row) {
        $cnt = (int)$row['cnt'];
        $stats[] = [
            'option_id' => (int)$row['chosen_option_id'],
            'count'     => $cnt,
            'pct'       => $total > 0 ? (int)round($cnt / $total * 100) : 0,
        ];
    }

    return ['option_stats' => $stats, 'total_answers' => $total];
}

<?php
/**
 * Category labels, emojis, display order, and helpers for category rankings / feed specialty.
 */

const CATEGORY_EMOJI_DEFAULT = '🧩';

const CATEGORY_DISPLAY_ORDER = [
    'History',
    'Geography',
    'NZ Current Events',
    'Aussie Current Events',
    'Current Events',
    'General Knowledge',
    'Sports',
    'Music',
    'Television',
    'Movies',
    'World',
    'People',
    'Science & Technology',
    'Literature',
    'NZ Trivia',
    'Australia Trivia',
    'Mythology',
    'Art',
    'Food',
    'Words',
    'Science',
    'Science & Nature',
    'Science: Computers',
    'Science: Mathematics',
    'Animals',
];

const CATEGORY_EMOJI_MAP = [
    'History'               => '📜',
    'Geography'             => '🌍',
    'NZ Current Events'     => '🥝',
    'Aussie Current Events' => '🦘',
    'Current Events'        => '📰',
    'General Knowledge'     => '🧠',
    'Sports'                => '🏉',
    'Music'                 => '🎵',
    'Television'            => '📺',
    'Movies'                => '🎬',
    'World'                 => '🗺️',
    'People'                => '👤',
    'Science & Technology'  => '🧪',
    'Literature'            => '📖',
    'NZ Trivia'             => '🇳🇿',
    'Australia Trivia'      => '🇦🇺',
    'Mythology'             => '⚡',
    'Art'                   => '🎨',
    'Food'                  => '🍽️',
    'Words'                 => '🔤',
    'Science'               => '🔬',
    'Science & Nature'      => '🌿',
    'Science: Computers'    => '💻',
    'Science: Mathematics'  => '➗',
    'Animals'               => '🐾',
];

function categoryEmoji(string $category): string {
    return CATEGORY_EMOJI_MAP[$category] ?? CATEGORY_EMOJI_DEFAULT;
}

function categoryMinAnswers(string $period): int {
    return $period === 'weekly' ? 5 : 8;
}

function categoryFeedMinAnswers(): int {
    return 8;
}

function categorySourceCategorySql(string $questionAlias = 'q'): string {
    $col = $questionAlias . '.source_category';
    $slot = $questionAlias . '.category';
    return "COALESCE(NULLIF($col, ''), $slot)";
}

function categoryPickBest(array $rows, int $minAnswers): ?array {
    $eligible = array_values(array_filter(
        $rows,
        fn($r) => ($r['answers'] ?? 0) >= $minAnswers && trim((string)($r['category'] ?? '')) !== ''
    ));
    if (!$eligible) {
        return null;
    }
    usort($eligible, function ($a, $b) {
        if ($a['avg_pct'] !== $b['avg_pct']) {
            return $b['avg_pct'] <=> $a['avg_pct'];
        }
        if ($a['answers'] !== $b['answers']) {
            return $b['answers'] <=> $a['answers'];
        }
        return strcmp($a['category'], $b['category']);
    });
    $best = $eligible[0];
    return [
        'category' => $best['category'],
        'emoji'    => categoryEmoji($best['category']),
        'avg_pct'  => (int)$best['avg_pct'],
        'answers'  => (int)$best['answers'],
    ];
}

function categorySortKeys(array $categories): array {
    $order = array_flip(CATEGORY_DISPLAY_ORDER);
    $unique = array_values(array_unique($categories));
    usort($unique, function ($a, $b) use ($order) {
        $oa = $order[$a] ?? 9999;
        $ob = $order[$b] ?? 9999;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        return strcmp($a, $b);
    });
    return $unique;
}

function categoryPeriodWindow(string $period): array {
    if ($period === 'monthly') {
        return ['days' => 30, 'start' => null, 'end' => null];
    }
    if ($period === 'yearly') {
        return ['days' => 365, 'start' => null, 'end' => null];
    }
    if ($period === 'alltime') {
        return ['days' => null, 'start' => null, 'end' => null];
    }
    return ['days' => 7, 'start' => null, 'end' => null];
}

function categoryFetchUserStats(mysqli $conn, int $groupId, string $period, ?array $userIds = null): array {
    if (!aiQuestionHasColumn($conn, 'source_category')) {
        return [];
    }

    $window = categoryPeriodWindow($period);
    $catExpr = categorySourceCategorySql('q');
    $types = 'i';
    $params = [$groupId];

    $userFilter = '';
    if ($userIds !== null && $userIds) {
        $ids = array_map('intval', $userIds);
        $userFilter = ' AND u.id IN (' . implode(',', $ids) . ')';
    }

    $dateFilter = '';
    if ($window['days'] !== null) {
        $dateFilter = ' AND z.date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
        $types .= 'i';
        $params[] = $window['days'];
    }

    $sql = "SELECT
                u.id AS userid,
                $catExpr AS source_category,
                COUNT(*) AS answers,
                ROUND(AVG(a.is_correct) * 100, 0) AS avg_pct
            FROM AIAnswer a
            INNER JOIN AIQuestion q ON q.id = a.question_id
            INNER JOIN AIQuiz z ON z.id = a.quiz_id AND z.status = 'active'
            INNER JOIN Users u ON u.id = a.user_id
            INNER JOIN Memberships m ON m.user_id = u.id AND m.group_id = ?
            WHERE 1=1
            $dateFilter
            $userFilter
            GROUP BY u.id, $catExpr";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byUser = [];
    foreach ($rows as $row) {
        $uid = (int)$row['userid'];
        $cat = trim((string)$row['source_category']);
        if ($cat === '') {
            continue;
        }
        $byUser[$uid][] = [
            'category' => $cat,
            'avg_pct'  => (int)$row['avg_pct'],
            'answers'  => (int)$row['answers'],
        ];
    }
    return $byUser;
}

/**
 * Users tied for first place in one category (same accuracy and answer count).
 *
 * @param list<array{userid: int, avg_pct: int, answers: int}> $entries
 * @return list<array{userid: int, avg_pct: int, answers: int}>
 */
function categoryPickGroupLeaders(array $entries): array {
    if (!$entries) {
        return [];
    }
    usort($entries, function ($a, $b) {
        if ($a['avg_pct'] !== $b['avg_pct']) {
            return $b['avg_pct'] <=> $a['avg_pct'];
        }
        if ($a['answers'] !== $b['answers']) {
            return $b['answers'] <=> $a['answers'];
        }
        return $a['userid'] <=> $b['userid'];
    });
    $bestPct = $entries[0]['avg_pct'];
    $bestAnswers = $entries[0]['answers'];
    return array_values(array_filter(
        $entries,
        fn($e) => $e['avg_pct'] === $bestPct && $e['answers'] === $bestAnswers
    ));
}

/**
 * Categories where this user is #1 in the group for the period.
 *
 * @return list<array{category: string, emoji: string, avg_pct: int, answers: int}>
 */
function categoryFetchUserLeaderCategories(
    mysqli $conn,
    int $groupId,
    int $userId,
    string $period = 'monthly'
): array {
    $minAnswers = categoryMinAnswers($period);
    $allStats = categoryFetchUserStats($conn, $groupId, $period, null);
    $byCategory = [];

    foreach ($allStats as $uid => $userRows) {
        foreach ($userRows as $row) {
            if ($row['answers'] < $minAnswers) {
                continue;
            }
            $byCategory[$row['category']][] = [
                'userid'  => (int)$uid,
                'avg_pct' => (int)$row['avg_pct'],
                'answers' => (int)$row['answers'],
            ];
        }
    }

    $leaderIn = [];
    foreach ($byCategory as $cat => $entries) {
        foreach (categoryPickGroupLeaders($entries) as $leader) {
            if ($leader['userid'] === $userId) {
                $leaderIn[] = [
                    'category' => $cat,
                    'emoji'    => categoryEmoji($cat),
                    'avg_pct'  => $leader['avg_pct'],
                    'answers'  => $leader['answers'],
                ];
                break;
            }
        }
    }

    $order = array_flip(CATEGORY_DISPLAY_ORDER);
    usort($leaderIn, function ($a, $b) use ($order) {
        $oa = $order[$a['category']] ?? 9999;
        $ob = $order[$b['category']] ?? 9999;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        return strcmp($a['category'], $b['category']);
    });

    return $leaderIn;
}

function categoryFetchFeedSpecialties(mysqli $conn, int $groupId, array $userIds): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) {
        return [];
    }
    $stats = categoryFetchUserStats($conn, $groupId, 'monthly', $userIds);
    $min = categoryFeedMinAnswers();
    $out = [];
    foreach ($userIds as $uid) {
        $best = categoryPickBest($stats[$uid] ?? [], $min);
        if ($best) {
            $out[$uid] = $best;
        }
    }
    return $out;
}

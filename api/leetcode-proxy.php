<?php
// MAMCET Placement & Learning Portal - LeetCode GraphQL Proxy API
// Server-side proxy to fetch public LeetCode profile data via GraphQL

header('Content-Type: application/json');

require_once(__DIR__ . '/../config/database.php');
require_once(__DIR__ . '/../includes/auth.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/functions.php');

if (!isLoggedIn() || (int)$_SESSION['role_id'] !== ROLE_STUDENT) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!validateCsrfToken()) {
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed.']);
    exit;
}

$db = Database::getInstance()->getConnection();
$student = getActiveStudent($db);

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student record not found.']);
    exit;
}

$studentId = $student['student_id'];
$action = trim($_POST['action'] ?? '');

// Auto-create tables if missing
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `leetcode_profiles` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_id` INT NOT NULL UNIQUE,
        `leetcode_username` VARCHAR(100) NOT NULL,
        `is_verified` TINYINT(1) NOT NULL DEFAULT 1,
        `layout_config` JSON DEFAULT NULL,
        `linked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $db->exec("ALTER TABLE leetcode_profiles ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER leetcode_username");
    } catch (Exception $ex) {
        // Column already exists
    }

    $db->exec("CREATE TABLE IF NOT EXISTS `leetcode_cache` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_id` INT NOT NULL,
        `cache_key` VARCHAR(100) NOT NULL,
        `cache_data` LONGTEXT,
        `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_student_cache` (`student_id`, `cache_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Ignore if tables already exist or schema variation
}

// Cache TTL in seconds (1 hour)
define('LEETCODE_CACHE_TTL', 3600);
define('LEETCODE_GRAPHQL_URL', 'https://leetcode.com/graphql');

// ─── Helper: Execute single LeetCode GraphQL query via cURL ──────────────────
if (!function_exists('leetcodeGraphQL')) {
    function leetcodeGraphQL($query, $variables = []) {
        $payload = json_encode([
            'query' => $query,
            'variables' => $variables
        ]);

        $ch = curl_init(LEETCODE_GRAPHQL_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Referer: https://leetcode.com',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ],
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => 'cURL error: ' . $error];
        }
        if ($httpCode !== 200) {
            return ['error' => 'LeetCode API returned HTTP ' . $httpCode];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON response from LeetCode'];
        }
        if (isset($data['errors'])) {
            return ['error' => $data['errors'][0]['message'] ?? 'GraphQL query error'];
        }

        return $data;
    }
}

// ─── Helper: Fetch all 8 LeetCode data types in parallel via curl_multi ───────
if (!function_exists('fetchLeetCodeAllParallel')) {
    function fetchLeetCodeAllParallel($username) {
        $year = (int)date('Y');
        $queries = [
            'user_profile' => [
                'query' => 'query getUserProfile($username: String!) { matchedUser(username: $username) { username profile { realName aboutMe userAvatar reputation ranking solutionCount categoryDiscussCount postViewCount } } }',
                'variables' => ['username' => $username]
            ],
            'problem_stats' => [
                'query' => 'query userProblemsSolved($username: String!) { allQuestionsCount { difficulty count } matchedUser(username: $username) { submitStatsGlobal { acSubmissionNum { difficulty count submissions } } problemsSolvedBeatsStats { difficulty percentage } } }',
                'variables' => ['username' => $username]
            ],
            'calendar' => [
                'query' => 'query userCalendar($username: String!, $year: Int) { matchedUser(username: $username) { userCalendar(year: $year) { activeYears streak totalActiveDays submissionCalendar } } }',
                'variables' => ['username' => $username, 'year' => $year]
            ],
            'recent_submissions' => [
                'query' => 'query recentAcSubmissions($username: String!, $limit: Int!) { recentAcSubmissionList(username: $username, limit: $limit) { id title titleSlug timestamp statusDisplay lang } }',
                'variables' => ['username' => $username, 'limit' => 15]
            ],
            'contest_ranking' => [
                'query' => 'query userContestRankingInfo($username: String!) { userContestRanking(username: $username) { attendedContestsCount rating globalRanking totalParticipants topPercentage } userContestRankingHistory(username: $username) { attended rating ranking trendDirection problemsSolved totalProblems contest { title startTime } } }',
                'variables' => ['username' => $username]
            ],
            'badges' => [
                'query' => 'query userBadges($username: String!) { matchedUser(username: $username) { badges { id displayName icon creationDate } upcomingBadges { name icon } } }',
                'variables' => ['username' => $username]
            ],
            'languages' => [
                'query' => 'query languageStats($username: String!) { matchedUser(username: $username) { languageProblemCount { languageName problemsSolved } } }',
                'variables' => ['username' => $username]
            ],
            'skill_stats' => [
                'query' => 'query skillStats($username: String!) { matchedUser(username: $username) { tagProblemCounts { advanced { tagName tagSlug problemsSolved } intermediate { tagName tagSlug problemsSolved } fundamental { tagName tagSlug problemsSolved } } } }',
                'variables' => ['username' => $username]
            ]
        ];

        $mh = curl_multi_init();
        $curlHandles = [];

        foreach ($queries as $key => $q) {
            $ch = curl_init(LEETCODE_GRAPHQL_URL);
            $payload = json_encode(['query' => $q['query'], 'variables' => $q['variables']]);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Referer: https://leetcode.com',
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0
            ]);
            curl_multi_add_handle($mh, $ch);
            $curlHandles[$key] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh);
            }
        } while ($running > 0 && $status == CURLM_OK);

        $raw = [];
        foreach ($curlHandles as $key => $ch) {
            $content = curl_multi_getcontent($ch);
            $raw[$key] = json_decode($content, true);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        // Format results to standard schema
        $formatted = [];

        // 1. Profile
        $formatted['user_profile'] = $raw['user_profile']['data']['matchedUser'] ?? null;

        // 2. Problem stats
        $pData = $raw['problem_stats']['data'] ?? [];
        $pMatched = $pData['matchedUser'] ?? [];
        $formatted['problem_stats'] = [
            'allQuestions' => $pData['allQuestionsCount'] ?? [],
            'solved' => $pMatched['submitStatsGlobal']['acSubmissionNum'] ?? [],
            'beats' => $pMatched['problemsSolvedBeatsStats'] ?? []
        ];

        // 3. Calendar
        $formatted['calendar'] = $raw['calendar']['data']['matchedUser']['userCalendar'] ?? null;

        // 4. Recent submissions
        $formatted['recent_submissions'] = $raw['recent_submissions']['data']['recentAcSubmissionList'] ?? [];

        // 5. Contest ranking
        $cData = $raw['contest_ranking']['data'] ?? [];
        $formatted['contest_ranking'] = [
            'ranking' => $cData['userContestRanking'] ?? null,
            'history' => $cData['userContestRankingHistory'] ?? []
        ];

        // 6. Badges
        $bMatched = $raw['badges']['data']['matchedUser'] ?? [];
        $formatted['badges'] = [
            'badges' => $bMatched['badges'] ?? [],
            'upcoming' => $bMatched['upcomingBadges'] ?? []
        ];

        // 7. Languages
        $formatted['languages'] = $raw['languages']['data']['matchedUser']['languageProblemCount'] ?? [];

        // 8. Skills
        $formatted['skill_stats'] = $raw['skill_stats']['data']['matchedUser']['tagProblemCounts'] ?? null;

        return $formatted;
    }
}

// ─── Helper: Get cached data or null ─────────────────────────────────────────
if (!function_exists('getCachedData')) {
    function getCachedData($db, $studentId, $cacheKey, $ttl = LEETCODE_CACHE_TTL) {
        $ttlSeconds = (int)$ttl;
        $stmt = $db->prepare("
            SELECT cache_data, fetched_at 
            FROM leetcode_cache 
            WHERE student_id = ? AND cache_key = ? 
            AND fetched_at > DATE_SUB(NOW(), INTERVAL {$ttlSeconds} SECOND)
        ");
        $stmt->execute([$studentId, $cacheKey]);
        $row = $stmt->fetch();
        if ($row && $row['cache_data'] !== null) {
            return json_decode($row['cache_data'], true);
        }
        return null;
    }
}

// ─── Helper: Save data to cache ──────────────────────────────────────────────
if (!function_exists('setCachedData')) {
    function setCachedData($db, $studentId, $cacheKey, $data) {
        $stmt = $db->prepare("
            INSERT INTO leetcode_cache (student_id, cache_key, cache_data, fetched_at) 
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE cache_data = VALUES(cache_data), fetched_at = NOW()
        ");
        $stmt->execute([$studentId, $cacheKey, json_encode($data)]);
    }
}

// ─── Helper: Get linked LeetCode username ────────────────────────────────────
if (!function_exists('getLinkedUsername')) {
    function getLinkedUsername($db, $studentId) {
        $stmt = $db->prepare("SELECT leetcode_username FROM leetcode_profiles WHERE student_id = ?");
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        return $row ? $row['leetcode_username'] : null;
    }
}

// ─── Action Router ───────────────────────────────────────────────────────────
try {
    switch ($action) {

        // ─── Link LeetCode Account ───────────────────────────────────────
        case 'link_account':
            $username = trim($_POST['username'] ?? '');
            if (empty($username) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $username)) {
                echo json_encode(['success' => false, 'message' => 'Please enter a valid LeetCode username (letters, numbers, hyphens, underscores only).']);
                exit;
            }

            // Verify username exists via official LeetCode GraphQL
            $verifyResult = leetcodeGraphQL('
                query verifyUser($username: String!) {
                    matchedUser(username: $username) { username }
                }
            ', ['username' => $username]);

            $matchedUsername = $verifyResult['data']['matchedUser']['username'] ?? null;

            if (!$matchedUsername) {
                echo json_encode(['success' => false, 'message' => 'LeetCode username "' . esc($username) . '" was not found. Please check and try again.']);
                exit;
            }

            // Save to database
            $stmt = $db->prepare("
                INSERT INTO leetcode_profiles (student_id, leetcode_username, is_verified, linked_at) 
                VALUES (?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE leetcode_username = VALUES(leetcode_username), is_verified = 1, updated_at = NOW()
            ");
            $stmt->execute([$studentId, $matchedUsername]);

            // Clear old cache for this student
            $db->prepare("DELETE FROM leetcode_cache WHERE student_id = ?")->execute([$studentId]);

            // Pre-fetch and cache all data in parallel
            $allData = fetchLeetCodeAllParallel($matchedUsername);
            foreach ($allData as $type => $data) {
                if ($data !== null) {
                    setCachedData($db, $studentId, $type, $data);
                }
            }

            logActivity($db, $_SESSION['user_id'], 'leetcode_link', 'Linked LeetCode account: ' . $matchedUsername);

            echo json_encode([
                'success' => true,
                'message' => 'LeetCode account linked successfully!',
                'username' => $matchedUsername,
                'data' => $allData
            ]);
            break;

        // ─── Unlink LeetCode Account ─────────────────────────────────────
        case 'unlink_account':
            $db->prepare("DELETE FROM leetcode_cache WHERE student_id = ?")->execute([$studentId]);
            $db->prepare("DELETE FROM leetcode_profiles WHERE student_id = ?")->execute([$studentId]);

            logActivity($db, $_SESSION['user_id'], 'leetcode_unlink', 'Unlinked LeetCode account');

            echo json_encode(['success' => true, 'message' => 'LeetCode account unlinked successfully.']);
            break;

        // ─── Fetch all data at once ──────────────────────────────────────
        case 'fetch_all':
            $username = getLinkedUsername($db, $studentId);
            if (!$username) {
                echo json_encode(['success' => false, 'message' => 'No LeetCode account linked.']);
                exit;
            }

            $forceRefresh = (bool)($_POST['force_refresh'] ?? false);
            $types = ['user_profile', 'problem_stats', 'calendar', 'recent_submissions', 'contest_ranking', 'badges', 'languages', 'skill_stats'];
            
            $allData = [];
            $hasAllCached = true;

            if (!$forceRefresh) {
                foreach ($types as $type) {
                    $cached = getCachedData($db, $studentId, $type);
                    if ($cached !== null) {
                        $allData[$type] = $cached;
                    } else {
                        $hasAllCached = false;
                        break;
                    }
                }
            } else {
                $hasAllCached = false;
            }

            // If not fully cached or forcing refresh, fetch fresh data in parallel
            if (!$hasAllCached) {
                $allData = fetchLeetCodeAllParallel($username);
                foreach ($allData as $type => $data) {
                    if ($data !== null) {
                        setCachedData($db, $studentId, $type, $data);
                    }
                }
            }

            // Get layout config
            $stmt = $db->prepare("SELECT layout_config FROM leetcode_profiles WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $profile = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'username' => $username,
                'data' => $allData,
                'layout_config' => ($profile && $profile['layout_config']) ? json_decode($profile['layout_config'], true) : null
            ]);
            break;

        // ─── Force refresh all cached data ───────────────────────────────
        case 'refresh':
            $username = getLinkedUsername($db, $studentId);
            if (!$username) {
                echo json_encode(['success' => false, 'message' => 'No LeetCode account linked.']);
                exit;
            }

            $allData = fetchLeetCodeAllParallel($username);
            foreach ($allData as $type => $data) {
                if ($data !== null) {
                    setCachedData($db, $studentId, $type, $data);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Data refreshed successfully.',
                'username' => $username,
                'data' => $allData
            ]);
            break;

        // ─── Save layout customization ───────────────────────────────────
        case 'save_layout':
            $layoutConfig = $_POST['layout_config'] ?? '';
            $decoded = json_decode($layoutConfig, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'message' => 'Invalid layout configuration.']);
                exit;
            }

            $stmt = $db->prepare("UPDATE leetcode_profiles SET layout_config = ? WHERE student_id = ?");
            $stmt->execute([json_encode($decoded), $studentId]);

            echo json_encode(['success' => true, 'message' => 'Layout saved.']);
            break;

        // ─── Get link status ─────────────────────────────────────────────
        case 'get_status':
            $stmt = $db->prepare("SELECT leetcode_username, is_verified, layout_config, linked_at FROM leetcode_profiles WHERE student_id = ?");
            $stmt->execute([$studentId]);
            $profile = $stmt->fetch();

            if ($profile) {
                echo json_encode([
                    'success' => true,
                    'linked' => true,
                    'username' => $profile['leetcode_username'],
                    'verified' => (bool)$profile['is_verified'],
                    'layout_config' => $profile['layout_config'] ? json_decode($profile['layout_config'], true) : null,
                    'linked_at' => $profile['linked_at']
                ]);
            } else {
                echo json_encode(['success' => true, 'linked' => false]);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . esc($action)]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

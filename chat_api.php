<?php
/**
 * chat_api.php — Backend API for AI Chatbot Knowledge Base & Live Messaging
 */
error_reporting(0);
@ini_set('display_errors', '0');
if (function_exists('ob_start')) {
    ob_start();
}

// Free-hosting & ProFreeHost/ByetHost AJAX headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, Origin, Cache-Control, Pragma, Authorization, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Credentials: true');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();

function sendJsonResponse(array $data): never {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$db = db();
$action = $_REQUEST['action'] ?? '';
if (empty($action)) {
    $rawInput = @file_get_contents('php://input');
    $input = !empty($rawInput) ? json_decode($rawInput, true) : null;
    $action = $input['action'] ?? '';
}
// ─── 1. CHATBOT KNOWLEDGE BASE QUERY (Database Driven) ─────────────────────
if ($action === 'kb_query') {
    // Extract user query from GET, POST, REQUEST, or raw JSON body
    $userQuery = trim($_REQUEST['query'] ?? ($_POST['query'] ?? ($_GET['query'] ?? '')));
    if (empty($userQuery)) {
        $rawInput = @file_get_contents('php://input');
        if (!empty($rawInput)) {
            $input = json_decode($rawInput, true);
            if (is_array($input) && !empty($input['query'])) {
                $userQuery = trim($input['query']);
            } else {
                parse_str($rawInput, $parsed);
                if (!empty($parsed['query'])) {
                    $userQuery = trim($parsed['query']);
                }
            }
        }
    }

    if (empty($userQuery)) {
        sendJsonResponse([
            'success' => false,
            'error'   => 'Empty query string provided.',
            'answer'  => 'Please enter a question to search the knowledge base.'
        ]);
    }

    $rawQuery = strtolower($userQuery);

    // Instant greeting & informal salutation handler
    if (preg_match('/^(hi|hello|hey|greetings|wassup|what\'?s\s*up|yo|hiya|good\s*(morning|afternoon|evening)|help|support|sup|howdy|ola|hi\s*there)$/i', $rawQuery)) {
        sendJsonResponse([
            'success'          => true,
            'matched_question' => 'Greeting',
            'answer'           => "Hello! 👋 How may I assist you today? You can ask me questions about creating monthly reports, calculating church dues, managing subscriptions, or unlocking submitted reports."
        ]);
    }

    try {
        // Ensure table exists
        ensureChatbotTablesExist();

        // 1. Direct database search on chatbot_knowledge_base table
        $stmtKb = $db->query("SELECT id, question, answer, keywords FROM chatbot_knowledge_base ORDER BY id ASC");
        $allKb  = $stmtKb->fetchAll();

        if (empty($allKb)) {
            sendJsonResponse([
                'success'          => true,
                'matched_question' => null,
                'answer'           => "Hello! 👋 I am here to help. For immediate assistance, please switch to the **Live Chat** tab to message an Admin directly."
            ]);
        }

        $bestMatch    = null;
        $highestScore = 0;

        // Keyword synonyms for enhanced recall
        $synonyms = [
            'trial'        => ['free', 'demo', 'period', 'month', 'test', '3'],
            'subscription' => ['sub', 'pay', 'payment', 'paystack', 'fee', 'charge', 'cost', 'renew', 'renewal', 'price', 'amount', 'money'],
            'report'       => ['create', 'submit', 'financial', 'spiritual', 'fill', 'entry', 'monthly', 'file', 'form', 'make', 'start'],
            'dues'         => ['due', 'percentage', 'calc', 'calculate', 'national', 'district', 'regional', 'zonal', 'rate'],
            'unlock'       => ['edit', 'modify', 'change', 'submitted', 'locked', 'token', 'fee'],
            'admin'        => ['contact', 'super', 'zonal', 'support', 'help', 'leader', 'pastor', 'user']
        ];

        // Tokenise user input
        $queryWords = array_unique(array_filter(
            preg_split('/[\s\.,;:?!\-\/\(\)]+/', $rawQuery),
            fn($w) => strlen($w) >= 2
        ));

        $stopWords = ['the','and','are','for','that','this','how','can','does',
                      'did','what','when','who','why','will','you','your','with',
                      'have','has','been','from','not','its','but','about','into','can','i'];

        $contentWords = array_values(array_filter($queryWords, fn($w) => !in_array($w, $stopWords)));
        if (empty($contentWords)) $contentWords = $queryWords;

        // Expand with synonyms
        $expandedWords = $contentWords;
        foreach ($contentWords as $cw) {
            foreach ($synonyms as $key => $synList) {
                if ($cw === $key || in_array($cw, $synList)) {
                    $expandedWords[] = $key;
                    $expandedWords = array_merge($expandedWords, $synList);
                }
            }
        }
        $expandedWords = array_unique($expandedWords);

        // Search Q&A items
        foreach ($allKb as $kb) {
            $score = 0;
            $qText = strtolower($kb['question']);
            $aText = strtolower($kb['answer']);
            $kText = strtolower($kb['keywords'] ?? '');
            $kList = array_filter(array_map('trim', explode(',', $kText)));

            // Phrase match
            if (strpos($qText, $rawQuery) !== false) {
                $score += 100;
            }
            if (strpos($aText, $rawQuery) !== false) {
                $score += 40;
            }

            // Word & Keyword matches
            foreach ($expandedWords as $word) {
                if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $qText)) {
                    $score += 25;
                } elseif (strpos($qText, $word) !== false) {
                    $score += 10;
                }

                foreach ($kList as $kw) {
                    if ($kw === $word || strpos($kw, $word) !== false || strpos($word, $kw) !== false) {
                        $score += 20;
                    }
                }

                if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $aText)) {
                    $score += 5;
                }
            }

            if ($score > $highestScore) {
                $highestScore = $score;
                $bestMatch    = $kb;
            }
        }

        // Return best match
        if ($bestMatch && $highestScore > 0) {
            sendJsonResponse([
                'success'          => true,
                'matched_question' => $bestMatch['question'],
                'answer'           => $bestMatch['answer']
            ]);
        } else {
            // Return professional, polite fallback without technical database jargon
            $topics = array_map(fn($k) => '• ' . $k['question'], array_slice($allKb, 0, 5));
            $topicList = implode("\n", $topics);

            sendJsonResponse([
                'success'          => true,
                'matched_question' => null,
                'answer'           => "I'm sorry, I don't have specific details on **\"{$userQuery}\"** right now.\n\nHere are some common topics I can help you with:\n\n{$topicList}\n\nFor direct assistance, feel free to switch to the **Live Chat** tab to message an Admin."
            ]);
        }
    } catch (Exception $e) {
        // Return full database query error for diagnostic transparency
        sendJsonResponse([
            'success' => false,
            'error'   => 'Database query exception: ' . $e->getMessage(),
            'answer'  => 'Database Query Error: ' . $e->getMessage()
        ]);
    }
}

// Ensure logged in for direct messaging features
if (!isLoggedIn()) {
    sendJsonResponse([
        'success' => false,
        'error'   => 'Authentication Required: Session user_id is empty.',
        'message' => 'Please log in to your account to load Live Chat contacts.'
    ]);
}

$currentUserId = (int)$_SESSION['user_id'];

// ─── Update heartbeat ───────────────────────────────────────────────────────
try {
    $db->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$currentUserId]);
} catch (Exception $e) {}

if ($action === 'heartbeat') {
    sendJsonResponse(['success' => true, 'ts' => time()]);
}

// ─── 2. FETCH USERS FOR LIVE CHAT ──────────────────────────────────────────
if ($action === 'fetch_users') {
    try {
        try {
            $stmt = $db->prepare("
                SELECT u.id, u.full_name, u.role, u.email, u.phone, u.profile_photo, u.last_active,
                CASE WHEN u.last_active >= NOW() - INTERVAL 2 MINUTE THEN 1 ELSE 0 END AS is_online,
                (SELECT COUNT(*) FROM user_messages m WHERE m.sender_id = u.id AND m.receiver_id = ? AND m.is_read = 0) AS unread_count
                FROM users u
                WHERE u.id != ?
                ORDER BY is_online DESC, unread_count DESC, u.full_name ASC
            ");
            $stmt->execute([$currentUserId, $currentUserId]);
            $users = $stmt->fetchAll();
        } catch (Exception $ex) {
            ensureChatbotTablesExist();
            $stmt = $db->prepare("
                SELECT u.id, u.full_name, u.role, u.email, u.phone, u.profile_photo, u.last_active,
                CASE WHEN u.last_active >= NOW() - INTERVAL 2 MINUTE THEN 1 ELSE 0 END AS is_online,
                (SELECT COUNT(*) FROM user_messages m WHERE m.sender_id = u.id AND m.receiver_id = ? AND m.is_read = 0) AS unread_count
                FROM users u
                WHERE u.id != ?
                ORDER BY is_online DESC, unread_count DESC, u.full_name ASC
            ");
            $stmt->execute([$currentUserId, $currentUserId]);
            $users = $stmt->fetchAll();
        }

        foreach ($users as &$u) {
            $u['role_label'] = ucfirst(str_replace('_', ' ', $u['role']));
            $u['has_photo'] = (!empty($u['profile_photo']) && file_exists(__DIR__ . '/' . $u['profile_photo']));
            $u['is_online'] = (int)$u['is_online'] === 1;
        }

        sendJsonResponse(['success' => true, 'users' => $users]);
    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ─── 3. SEND DIRECT MESSAGE ────────────────────────────────────────────────
if ($action === 'send_message') {
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $msgText = trim($_POST['message'] ?? '');

    if ($receiverId <= 0 || empty($msgText)) {
        $rawInput = @file_get_contents('php://input');
        $input = !empty($rawInput) ? json_decode($rawInput, true) : null;
        $receiverId = (int)($input['receiver_id'] ?? 0);
        $msgText = trim($input['message'] ?? '');
    }

    if ($receiverId <= 0 || empty($msgText)) {
        sendJsonResponse(['success' => false, 'message' => 'Recipient and message cannot be empty.']);
    }

    try {
        $stmt = $db->prepare("INSERT INTO user_messages (sender_id, receiver_id, message, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$currentUserId, $receiverId, $msgText]);
        sendJsonResponse(['success' => true, 'message_id' => $db->lastInsertId()]);
    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'message' => 'Failed to send message: ' . $e->getMessage()]);
    }
}

// ─── 4. FETCH CONVERSATION MESSAGES ─────────────────────────────────────────
if ($action === 'fetch_messages') {
    $partnerId = (int)($_GET['partner_id'] ?? ($_POST['partner_id'] ?? 0));
    if ($partnerId <= 0) {
        sendJsonResponse(['success' => false, 'messages' => []]);
    }

    try {
        $upd = $db->prepare("UPDATE user_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $upd->execute([$partnerId, $currentUserId]);

        $stmt = $db->prepare("
            SELECT m.*, u.full_name AS sender_name
            FROM user_messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?)
               OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$currentUserId, $partnerId, $partnerId, $currentUserId]);
        $messages = $stmt->fetchAll();

        foreach ($messages as &$m) {
            $m['is_mine'] = ((int)$m['sender_id'] === $currentUserId);
            $m['time_formatted'] = date('h:i A', strtotime($m['created_at']));
            $m['date_formatted'] = date('M d, Y', strtotime($m['created_at']));
        }

        sendJsonResponse(['success' => true, 'messages' => $messages]);
    } catch (Exception $e) {
        sendJsonResponse(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ─── 5. FETCH UNREAD COUNT ──────────────────────────────────────────────────
if ($action === 'fetch_unread_count') {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_messages WHERE receiver_id = ? AND is_read = 0");
        $stmt->execute([$currentUserId]);
        $unreadCount = (int)$stmt->fetchColumn();
        sendJsonResponse(['success' => true, 'unread_count' => $unreadCount]);
    } catch (Exception $e) {
        sendJsonResponse(['success' => true, 'unread_count' => 0]);
    }
}

sendJsonResponse(['success' => false, 'message' => 'Invalid action.']);

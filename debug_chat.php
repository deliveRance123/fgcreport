<?php
/**
 * debug_chat.php — Free Hosting Deployment & Chat System Diagnostic Tool
 * 
 * Access via browser: https://yourdomain.com/debug_chat.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chatbot & System Deployment Diagnostic Tool</title>
<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; color: #333; margin: 0; padding: 24px; }
    .card { background: #fff; border-radius: 10px; padding: 24px; max-width: 850px; margin: 0 auto 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    h1 { color: #1A1040; margin-top: 0; font-size: 24px; border-bottom: 2px solid #E31E24; padding-bottom: 10px; }
    h2 { color: #2E1B6A; font-size: 18px; margin-top: 20px; }
    .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 12px; }
    .badge-pass { background: #D1FAE5; color: #047857; }
    .badge-fail { background: #FEE2E2; color: #B91C1C; }
    .badge-info { background: #DBEAFE; color: #1E40AF; }
    pre { background: #1E1E1E; color: #D4D4D4; padding: 14px; border-radius: 6px; overflow-x: auto; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 10px; border: 1px solid #E5E7EB; text-align: left; font-size: 13px; }
    th { background: #F9FAFB; font-weight: 600; }
    .btn { display: inline-block; background: #1A1040; color: #fff; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; margin-right: 8px; }
</style>
</head>
<body>

<div class="card">
    <h1>🛠️ Chatbot & Deployment Diagnostic Tool</h1>
    <p>Use this page to verify database connectivity, session integrity, chatbot knowledge base data, and live chat functionality on free hosting environments.</p>

    <!-- 1. PHP & SERVER ENVIRONMENT -->
    <h2>1. PHP Environment</h2>
    <table>
        <tr>
            <th>PHP Version</th>
            <td><?= PHP_VERSION ?> <?= PHP_VERSION_ID >= 80000 ? '<span class="badge badge-pass">PHP 8+ Compatible</span>' : '<span class="badge badge-info">PHP 7.x</span>' ?></td>
        </tr>
        <tr>
            <th>PDO MySQL Extension</th>
            <td><?= extension_loaded('pdo_mysql') ? '<span class="badge badge-pass">Installed</span>' : '<span class="badge badge-fail">Missing!</span>' ?></td>
        </tr>
        <tr>
            <th>Session Support</th>
            <td><?= extension_loaded('session') ? '<span class="badge badge-pass">Enabled</span>' : '<span class="badge badge-fail">Disabled!</span>' ?></td>
        </tr>
        <tr>
            <th>JSON Extension</th>
            <td><?= extension_loaded('json') ? '<span class="badge badge-pass">Enabled</span>' : '<span class="badge badge-fail">Disabled!</span>' ?></td>
        </tr>
    </table>

    <!-- 2. DATABASE CONNECTION TEST -->
    <h2>2. Database Connection</h2>
    <?php
    $dbConnected = false;
    $dbError = '';
    try {
        $pdo = db();
        $dbConnected = true;
        echo '<p><span class="badge badge-pass">Connected Successfully</span> (Host: <code>' . DB_HOST . '</code>, Database: <code>' . DB_NAME . '</code>)</p>';
    } catch (Exception $e) {
        $dbError = $e->getMessage();
        echo '<p><span class="badge badge-fail">Connection Failed</span>: ' . htmlspecialchars($dbError) . '</p>';
    }
    ?>

    <!-- 3. USER SESSION STATUS -->
    <h2>3. Active User Session</h2>
    <?php
    $userId = $_SESSION['user_id'] ?? null;
    $userRole = $_SESSION['role'] ?? null;
    $userName = $_SESSION['full_name'] ?? null;

    if ($userId) {
        echo '<p><span class="badge badge-pass">Logged In</span> User ID: <strong>' . htmlspecialchars($userId) . '</strong> (' . htmlspecialchars($userName) . ') — Role: <code>' . htmlspecialchars($userRole) . '</code></p>';
    } else {
        echo '<p><span class="badge badge-info">Guest / Not Logged In</span> (Live Chat messaging requires logging into a user account)</p>';
    }
    ?>

    <!-- 4. CHATBOT KNOWLEDGE BASE TABLE TEST -->
    <h2>4. Chatbot Knowledge Base Table</h2>
    <?php
    if ($dbConnected) {
        try {
            ensureChatbotTablesExist();
            $stmt = $pdo->query("SELECT COUNT(*) FROM chatbot_knowledge_base");
            $kbCount = $stmt->fetchColumn();
            echo '<p><span class="badge badge-pass">Table Active</span> Total Q&A Items: <strong>' . $kbCount . '</strong></p>';

            if ($kbCount > 0) {
                $items = $pdo->query("SELECT id, question, keywords FROM chatbot_knowledge_base LIMIT 5")->fetchAll();
                echo '<table><tr><th>ID</th><th>Question</th><th>Keywords</th></tr>';
                foreach ($items as $it) {
                    echo '<tr><td>' . $it['id'] . '</td><td>' . htmlspecialchars($it['question']) . '</td><td>' . htmlspecialchars($it['keywords']) . '</td></tr>';
                }
                echo '</table>';
            }
        } catch (Exception $e) {
            echo '<p><span class="badge badge-fail">Table Error</span>: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }
    ?>

    <!-- 5. LIVE CHAT MESSAGES TABLE TEST -->
    <h2>5. User Messages Table</h2>
    <?php
    if ($dbConnected) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM user_messages");
            $msgCount = $stmt->fetchColumn();
            echo '<p><span class="badge badge-pass">Table Active</span> Total Messages Stored: <strong>' . $msgCount . '</strong></p>';
        } catch (Exception $e) {
            echo '<p><span class="badge badge-fail">Table Error</span>: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }
    ?>

    <!-- 6. CHATBOT SIMULATION API TEST -->
    <h2>6. Simulated Chatbot Query Test</h2>
    <form method="GET" action="">
        <input type="text" name="test_query" placeholder="Type a test question..." value="<?= htmlspecialchars($_GET['test_query'] ?? 'How do I create a report?') ?>" style="padding:8px 12px; width:60%; border:1px solid #ccc; border-radius:4px;">
        <button type="submit" class="btn" style="border:none; cursor:pointer;">Test Chatbot API</button>
    </form>

    <?php
    if (!empty($_GET['test_query'])) {
        $testQ = trim($_GET['test_query']);
        echo '<h3>Response for query: <em>"' . htmlspecialchars($testQ) . '"</em></h3>';

        $_REQUEST['action'] = 'kb_query';
        $_REQUEST['query']  = $testQ;

        ob_start();
        include __DIR__ . '/chat_api.php';
        $apiOut = ob_get_clean();

        echo '<pre>' . htmlspecialchars($apiOut) . '</pre>';
    }
    ?>
    
    <div style="margin-top:30px; text-align:center;">
        <a href="index.php" class="btn">Go to Landing Page</a>
        <a href="login.php" class="btn" style="background:#E31E24;">Log in to Portal</a>
    </div>
</div>

</body>
</html>

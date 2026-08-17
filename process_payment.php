<?php
/**
 * process_payment.php — AJAX & Redirect endpoint for Paystack payment verification & report unlocking
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();

if (!isLoggedIn()) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header("Location: login.php");
        exit;
    }
    jsonResponse(['success' => false, 'message' => 'Unauthorized access. Please log in.'], 401);
}

$db = db();
$userId = (int)$_SESSION['user_id'];
$settings = getPaymentSettings();

$secretKey = trim($settings['payment_secret_key'] ?? '');
if (empty($secretKey)) {
    $err = 'Paystack secret key is not configured in Admin Dashboard.';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        die(htmlspecialchars($err));
    }
    jsonResponse(['success' => false, 'message' => $err], 400);
}

// Extract input from GET, POST, or JSON body
$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true) ?: $_POST;

$reference   = trim($_GET['reference']   ?? ($_GET['trxref'] ?? ($input['reference'] ?? '')));
$paymentType = trim($_GET['payment_type'] ?? ($input['payment_type'] ?? 'subscription'));
$reportId    = (int)($_GET['report_id']    ?? ($input['report_id'] ?? 0));
$reportType  = trim($_GET['report_type']  ?? ($input['report_type'] ?? 'church'));

if (empty($reference)) {
    $err = 'Missing transaction reference.';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        die(htmlspecialchars($err));
    }
    jsonResponse(['success' => false, 'message' => $err], 400);
}

try {
    // 1. Replay & Duplicate Payment Protection: Check if reference already verified
    $stmtCheck = $db->prepare("SELECT * FROM user_payments WHERE reference = ? AND status = 'success' LIMIT 1");
    $stmtCheck->execute([$reference]);
    $existingPay = $stmtCheck->fetch();

    if ($existingPay) {
        // Already processed — ensure report is unlocked
        if ($existingPay['payment_type'] === 'report_unlock' && !empty($existingPay['report_id'])) {
            $rId   = (int)$existingPay['report_id'];
            $rType = $existingPay['report_type'] ?: 'church';
            unlockReportDatabaseStatus($db, $rId, $rType);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            redirectBackToReport($db, $existingPay['report_id'], $existingPay['report_type'], "Payment verified successfully! Report unlocked for editing.");
        }
        jsonResponse([
            'success' => true,
            'message' => 'Payment has already been verified and processed.',
            'report_id' => $existingPay['report_id']
        ]);
    }

    // 2. Verify with Paystack API
    $verification = verifyPaystackTransaction($reference, $secretKey);
    if (!$verification['status']) {
        $err = 'Paystack verification failed: ' . $verification['message'];
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            die(htmlspecialchars($err));
        }
        jsonResponse(['success' => false, 'message' => $err], 400);
    }

    $paidAmount = (float)$verification['amount'];

    $db->beginTransaction();

    if ($paymentType === 'subscription') {
        // Calculate new expiration date (1 full year / +1 year from now or extend existing active subscription)
        $stmtCurr = $db->prepare("SELECT expires_at FROM user_payments WHERE user_id = ? AND payment_type = 'subscription' AND status = 'success' AND expires_at >= NOW() ORDER BY expires_at DESC LIMIT 1");
        $stmtCurr->execute([$userId]);
        $currentSub = $stmtCurr->fetchColumn();

        if ($currentSub && strtotime($currentSub) > time()) {
            $newExpires = date('Y-m-d H:i:s', strtotime("+1 year", strtotime($currentSub)));
        } else {
            $newExpires = date('Y-m-d H:i:s', strtotime("+1 year"));
        }

        $ins = $db->prepare("INSERT INTO user_payments (user_id, payment_type, amount, reference, status, expires_at, created_at) VALUES (?, 'subscription', ?, ?, 'success', ?, NOW()) ON DUPLICATE KEY UPDATE status = 'success', expires_at = VALUES(expires_at)");
        $ins->execute([$userId, $paidAmount, $reference, $newExpires]);

        $db->commit();

        $redirectPage = ($_SESSION['role'] ?? '') === 'zonal_admin' ? 'zone-dashboard.php' : 'church-dashboard.php';

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header("Location: {$redirectPage}?msg=" . urlencode("Annual Subscription activated successfully until " . date('M d, Y', strtotime($newExpires))));
            exit;
        }

        jsonResponse([
            'success' => true,
            'message' => 'Annual subscription payment verified successfully! Your portal access is active until ' . date('M d, Y', strtotime($newExpires)) . '.',
            'expires_at' => $newExpires
        ]);

    } elseif ($paymentType === 'report_unlock') {
        if ($reportId <= 0) {
            $db->rollBack();
            $err = 'Invalid report ID provided for unlock.';
            if ($_SERVER['REQUEST_METHOD'] === 'GET') die(htmlspecialchars($err));
            jsonResponse(['success' => false, 'message' => $err], 400);
        }

        // Insert payment log into user_payments table
        $ins = $db->prepare("INSERT INTO user_payments (user_id, report_id, report_type, payment_type, amount, reference, status, created_at) VALUES (?, ?, ?, 'report_unlock', ?, ?, 'success', NOW()) ON DUPLICATE KEY UPDATE status = 'success'");
        $ins->execute([$userId, $reportId, $reportType, $paidAmount, $reference]);

        // Unlock report in database (set status back to draft so user can edit)
        unlockReportDatabaseStatus($db, $reportId, $reportType);

        $db->commit();

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            redirectBackToReport($db, $reportId, $reportType, "Report unlocked successfully! You can now edit and update your report.");
        }

        jsonResponse([
            'success' => true,
            'message' => 'Report unlocked successfully! You can now edit and update your report.',
            'report_id' => $reportId
        ]);
    } else {
        $db->rollBack();
        jsonResponse(['success' => false, 'message' => 'Invalid payment type.'], 400);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $err = 'Database transaction error: ' . $e->getMessage();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        die(htmlspecialchars($err));
    }
    jsonResponse(['success' => false, 'message' => $err], 500);
}

if (!function_exists('unlockReportDatabaseStatus')) {
    /**
     * Helper: Unlock report status to 'draft' in database
     */
    function unlockReportDatabaseStatus(PDO $db, int $reportId, string $reportType): void {
        if ($reportType === 'zonal') {
            $stmtUpd = $db->prepare("UPDATE zonal_reports SET status = 'draft' WHERE id = ?");
            $stmtUpd->execute([$reportId]);
        } else {
            $stmtUpd = $db->prepare("UPDATE church_financial_reports SET status = 'draft' WHERE id = ?");
            $stmtUpd->execute([$reportId]);

            // Fetch church_id, month, year from financial report to update spiritual report
            $stmtFin = $db->prepare("SELECT church_id, report_month, report_year FROM church_financial_reports WHERE id = ?");
            $stmtFin->execute([$reportId]);
            $fin = $stmtFin->fetch();

            if ($fin) {
                $stmtUpdSp = $db->prepare("UPDATE church_spiritual_reports SET status = 'draft' WHERE church_id = ? AND report_month = ? AND report_year = ?");
                $stmtUpdSp->execute([$fin['church_id'], $fin['report_month'], $fin['report_year']]);
            }
        }
    }
}

if (!function_exists('redirectBackToReport')) {
    /**
     * Helper: Redirect browser back to target report URL on GET completion
     */
    function redirectBackToReport(PDO $db, int $reportId, string $reportType, string $message): void {
        if ($reportType === 'zonal') {
            $stmt = $db->prepare("SELECT report_month, report_year FROM zonal_reports WHERE id = ?");
            $stmt->execute([$reportId]);
            $r = $stmt->fetch();
            $m = $r['report_month'] ?? date('n');
            $y = $r['report_year'] ?? date('Y');
            header("Location: zonal_reports.php?month={$m}&year={$y}&msg=" . urlencode($message));
        } else {
            $stmt = $db->prepare("SELECT report_month, report_year FROM church_financial_reports WHERE id = ?");
            $stmt->execute([$reportId]);
            $r = $stmt->fetch();
            $m = $r['report_month'] ?? date('n');
            $y = $r['report_year'] ?? date('Y');
            header("Location: church_report.php?month={$m}&year={$y}&msg=" . urlencode($message));
        }
        exit;
    }
}

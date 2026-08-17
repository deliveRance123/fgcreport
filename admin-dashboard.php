<?php
/**
 * admin-dashboard.php — Super Admin Dashboard
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();
requireLogin();
requireRole('super_admin');

$db = db();
$page = $_GET['page'] ?? 'dashboard';

// Fetch Admin Info
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$user_initials = '';
if ($user && !empty($user['full_name'])) {
    $names = explode(' ', trim($user['full_name']));
    $first = $names[0] ?? '';
    $last = count($names) > 1 ? end($names) : '';
    $user_initials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : ''));
}

// Selected month/year for admin overview
$curMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$curYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Fetch Stats
$total_churches_count = (int)$db->query("
    SELECT COUNT(*) FROM churches c
    INNER JOIN users u ON c.created_by = u.id
    WHERE u.role = 'church_admin'
")->fetchColumn();
if ($total_churches_count === 0) {
    $total_churches_count = (int)$db->query("SELECT COUNT(*) FROM churches")->fetchColumn();
}

$total_zones_count = (int)$db->query("
    SELECT COUNT(*) FROM zones z
    INNER JOIN users u ON z.created_by = u.id
    WHERE u.role = 'zonal_admin'
")->fetchColumn();
if ($total_zones_count === 0) {
    $total_zones_count = (int)$db->query("SELECT COUNT(*) FROM zones")->fetchColumn();
}

$stmt_submitted = $db->prepare("
    SELECT COUNT(DISTINCT f.church_id) 
    FROM church_financial_reports f
    INNER JOIN churches c ON f.church_id = c.id
    INNER JOIN users u ON c.created_by = u.id
    WHERE f.report_month = ? AND f.report_year = ? AND f.status = 'submitted' AND u.role = 'church_admin'
");
$stmt_submitted->execute([$curMonth, $curYear]);
$reports_submitted_count = (int)$stmt_submitted->fetchColumn();
$reports_outstanding_count = max(0, $total_churches_count - $reports_submitted_count);

// Check if site_settings table exists
$hasSiteSettings = false;
try {
    $db->query("SELECT id FROM site_settings LIMIT 0");
    $hasSiteSettings = true;
} catch (Exception $e) {}

$successMsg = '';
$errorMsg = '';

// Handle POST: Update Due Percentage Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_rates'])) {
    try {
        $db->beginTransaction();
        $ratesSubmitted = $_POST['rates'] ?? [];
        $locksSubmitted  = $_POST['locks']  ?? [];

        $stmtAll = $db->query("SELECT * FROM due_percentage_settings");
        $allSettingsDb = $stmtAll->fetchAll();

        foreach ($allSettingsDb as $currentSetting) {
            $id = (int)$currentSetting['id'];
            $oldVal = (float)$currentSetting['percentage_value'];
            $currentLockState = (int)$currentSetting['is_locked'];

            $newLockState = isset($locksSubmitted[$id]) ? 0 : 1;
            $lockChanged = $currentLockState !== $newLockState;

            $newVal = isset($ratesSubmitted[$id]) ? toFloat($ratesSubmitted[$id]) : $oldVal;
            $valChanged = false;

            if ($newLockState === 0 || $currentLockState === 0) {
                if (abs($newVal - $oldVal) > 0.0001) { $valChanged = true; }
            }

            if ($valChanged || $lockChanged) {
                $stmtUpdate = $db->prepare("UPDATE due_percentage_settings SET percentage_value = ?, is_locked = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $stmtUpdate->execute([$valChanged ? $newVal : $oldVal, $newLockState, $_SESSION['user_id'], $id]);

                if ($valChanged) {
                    $stmtAudit = $db->prepare("INSERT INTO due_percentage_audit_log (due_setting_id, old_value, new_value, changed_by, action, note) VALUES (?, ?, ?, ?, 'rate_change', NULL)");
                    $stmtAudit->execute([$id, $oldVal, $newVal, $_SESSION['user_id']]);
                }
                if ($lockChanged) {
                    $lockNote = $newLockState === 1 ? 'Locked by admin' : 'Unlocked by admin';
                    $stmtAudit = $db->prepare("INSERT INTO due_percentage_audit_log (due_setting_id, old_value, new_value, changed_by, action, note) VALUES (?, ?, ?, ?, 'lock_change', ?)");
                    $stmtAudit->execute([$id, $currentLockState, $newLockState, $_SESSION['user_id'], $lockNote]);
                }
            }
        }
        $db->commit();
        $successMsg = 'Percentages and settings updated successfully!';
    } catch (Exception $e) {
        $db->rollBack();
        $errorMsg = 'Error saving percentages: ' . $e->getMessage();
    }
}

// Handle POST: Site Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_site_settings']) && $hasSiteSettings) {
    try {
        $keys = [
            'site_name','site_tagline','hero_title','hero_lead',
            'strip_item_1','strip_item_2','strip_item_3','strip_item_4',
            'paths_title','paths_subtitle','footer_org_name',
            'contact_email','contact_phone','how_title','hero_video_url','showcase_video_url',
            'smtp_email','smtp_secret_key','smtp_sender_name',
            'payment_public_key','payment_secret_key','monthly_sub_amount','report_unlock_fee','free_trial_months','free_trial_days'
        ];

        $upd = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
                              VALUES (?, ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()");

        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                $val = trim($_POST[$k]);
                $upd->execute([$k, $val, $_SESSION['user_id']]);
            }
        }

        // Checkboxes:
        $smtpEnabled = isset($_POST['smtp_enabled']) ? '1' : '0';
        $upd->execute(['smtp_enabled', $smtpEnabled, $_SESSION['user_id']]);

        $paymentEnabled = isset($_POST['payment_enabled']) ? '1' : '0';
        $upd->execute(['payment_enabled', $paymentEnabled, $_SESSION['user_id']]);

        $freeTrialEnabled = isset($_POST['free_trial_enabled']) ? '1' : '0';
        $upd->execute(['free_trial_enabled', $freeTrialEnabled, $_SESSION['user_id']]);

        // File uploads
        $uploadFileDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadFileDir)) {
            mkdir($uploadFileDir, 0777, true);
        }
        $allowedfileExtensions = ['mp4', 'webm', 'ogg', 'mov'];

        // Background Hero Video Upload
        if (isset($_FILES['hero_video']) && $_FILES['hero_video']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['hero_video']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['hero_video']['tmp_name'];
                $fileName = $_FILES['hero_video']['name'];
                $fileNameCmps = explode(".", $fileName);
                $fileExtension = strtolower(end($fileNameCmps));

                if (in_array($fileExtension, $allowedfileExtensions)) {
                    $newFileName = time() . '_bg_' . preg_replace('/[^a-zA-Z0-9_.]/', '', $fileName);
                    $dest_path = $uploadFileDir . $newFileName;

                    if (move_uploaded_file($fileTmpPath, $dest_path)) {
                        $videoPath = 'uploads/' . $newFileName;
                        $db->query("UPDATE hero_videos SET is_active = 0");
                        $stmtVid = $db->prepare("INSERT INTO hero_videos (video_path, is_active) VALUES (?, 1)");
                        $stmtVid->execute([$videoPath]);
                        $successMsg = 'Site settings and background video updated successfully!';
                    } else {
                        $errorMsg = 'There was an error moving the uploaded background video file.';
                    }
                } else {
                    $errorMsg = 'Background video failed. Allowed formats: MP4, WebM, OGG, MOV.';
                }
            } else {
                $errCode = $_FILES['hero_video']['error'];
                if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                    $errorMsg = 'Background video file is too large! Maximum server limit exceeded.';
                } else {
                    $errorMsg = 'Background video upload failed (Error Code: ' . $errCode . ').';
                }
            }
        }

        // Showcase Video Upload
        if (isset($_FILES['showcase_video']) && $_FILES['showcase_video']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['showcase_video']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['showcase_video']['tmp_name'];
                $fileName = $_FILES['showcase_video']['name'];
                $fileNameCmps = explode(".", $fileName);
                $fileExtension = strtolower(end($fileNameCmps));

                if (in_array($fileExtension, $allowedfileExtensions)) {
                    $newFileName = time() . '_showcase_' . preg_replace('/[^a-zA-Z0-9_.]/', '', $fileName);
                    $dest_path = $uploadFileDir . $newFileName;

                    if (move_uploaded_file($fileTmpPath, $dest_path)) {
                        $videoPath = 'uploads/' . $newFileName;
                        $db->query("UPDATE hero_showcase_videos SET is_active = 0");
                        $stmtVid = $db->prepare("INSERT INTO hero_showcase_videos (video_path, is_active) VALUES (?, 1)");
                        $stmtVid->execute([$videoPath]);
                        $successMsg = 'Site settings and showcase video updated successfully!';
                    } else {
                        $errorMsg = 'There was an error moving the uploaded showcase video file.';
                    }
                } else {
                    $errorMsg = 'Showcase video failed. Allowed formats: MP4, WebM, OGG, MOV.';
                }
            } else {
                $errCode = $_FILES['showcase_video']['error'];
                if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                    $errorMsg = 'Showcase video file is too large! Maximum server limit exceeded.';
                } else {
                    $errorMsg = 'Showcase video upload failed (Error Code: ' . $errCode . ').';
                }
            }
        }

        if (empty($errorMsg) && empty($successMsg)) {
            $successMsg = 'Site settings saved successfully!';
        }
    } catch (Exception $e) {
        $errorMsg = 'Error saving settings: ' . $e->getMessage();
    }
}

// Handle POST: Send Test Email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {
    $siteSettings = getSiteSettings();
    $targetEmail = !empty($user['email']) ? $user['email'] : ($siteSettings['smtp_email'] ?? '');
    if (!empty($targetEmail)) {
        $ok = sendAppEmail($targetEmail, $user['full_name'] ?? 'Admin', '🧪 Test Email Notification — Foursquare Reports', '<p>Great news! Your Gmail SMTP configuration is working perfectly on the Foursquare Reports platform.</p>', 'admin-dashboard.php?page=settings', 'View Admin Settings');
        if ($ok) {
            $successMsg = '✅ Test email sent successfully to ' . h($targetEmail) . '! Check your inbox (and spam folder).';
        } else {
            $smtpErr = $GLOBALS['email_last_error'] ?? '';
            $errorMsg = '❌ Failed to send test email. ' . ($smtpErr ? '<br><strong>Reason:</strong> ' . h($smtpErr) : 'Please verify your Gmail address and App Password in Email Settings.');
        }
    } else {
        $errorMsg = 'No recipient email address available to send test email.';
    }
}

// Handle POST: Add Chatbot Knowledge Base Q&A Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_kb_item'])) {
    $q = trim($_POST['kb_question'] ?? '');
    $a = trim($_POST['kb_answer'] ?? '');
    $k = trim($_POST['kb_keywords'] ?? '');

    if (!empty($q) && !empty($a)) {
        try {
            $stmtKb = $db->prepare("INSERT INTO chatbot_knowledge_base (question, answer, keywords) VALUES (?, ?, ?)");
            $stmtKb->execute([$q, $a, $k]);
            $successMsg = 'Chatbot Knowledge Base Q&A item added successfully!';
        } catch (Exception $e) {
            $errorMsg = 'Error adding Knowledge Base item: ' . $e->getMessage();
        }
    } else {
        $errorMsg = 'Question and Answer are required for Knowledge Base items.';
    }
}

// Handle POST: Delete Chatbot Knowledge Base Q&A Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_kb_item'])) {
    $kbId = (int)($_POST['kb_id'] ?? 0);
    if ($kbId > 0) {
        try {
            $stmtKbDel = $db->prepare("DELETE FROM chatbot_knowledge_base WHERE id = ?");
            $stmtKbDel->execute([$kbId]);
            $successMsg = 'Knowledge Base Q&A item deleted successfully!';
        } catch (Exception $e) {
            $errorMsg = 'Error deleting Knowledge Base item: ' . $e->getMessage();
        }
    }
}

// Handle POST: Update Chatbot Knowledge Base Q&A Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_kb_item'])) {
    $kbId = (int)($_POST['kb_id'] ?? 0);
    $q   = trim($_POST['kb_question'] ?? '');
    $a   = trim($_POST['kb_answer']   ?? '');
    $k   = trim($_POST['kb_keywords'] ?? '');
    if ($kbId > 0 && !empty($q) && !empty($a)) {
        try {
            $stmtKbUpd = $db->prepare("UPDATE chatbot_knowledge_base SET question = ?, answer = ?, keywords = ? WHERE id = ?");
            $stmtKbUpd->execute([$q, $a, $k, $kbId]);
            $successMsg = 'Knowledge Base Q&A item updated successfully!';
        } catch (Exception $e) {
            $errorMsg = 'Error updating Knowledge Base item: ' . $e->getMessage();
        }
    } else {
        $errorMsg = 'Question and Answer are required.';
    }
}

// Handle POST: Send Outstanding Report Email Reminders
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_outstanding_reminders'])) {
    $stmtChurches = $db->prepare("
        SELECT c.name AS church_name, u.email, u.full_name
        FROM churches c
        JOIN users u ON c.created_by = u.id
        WHERE c.id NOT IN (
            SELECT church_id FROM church_financial_reports WHERE report_month = ? AND report_year = ? AND status = 'submitted'
        )
    ");
    $stmtChurches->execute([$curMonth, $curYear]);
    $unsubmitted = $stmtChurches->fetchAll();

    $sentCount = 0;
    $mName = monthName($curMonth) . ' ' . $curYear;
    foreach ($unsubmitted as $target) {
        if (!empty($target['email'])) {
            $msg = "This is a friendly reminder that the monthly report for <strong>" . h($target['church_name']) . "</strong> for <strong>" . h($mName) . "</strong> has not yet been submitted. Please log in to your portal and complete your report submission.";
            $ok = sendAppEmail($target['email'], $target['full_name'], "⏰ Reminder: Monthly Report Due — " . $target['church_name'] . " (" . $mName . ")", $msg, "login.php", "Log In to Submit Report");
            if ($ok) $sentCount++;
        }
    }

    if ($sentCount > 0) {
        $successMsg = "Successfully sent {$sentCount} reminder email(s) for {$mName}!";
    } else {
        $errorMsg = "No reminder emails were sent. Either all churches have submitted or email configuration is not enabled/set.";
    }
}

// Handle POST: Send Congratulations Emails to Submitted Churches
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_submitted_congratulations'])) {
    $stmtChurches = $db->prepare("
        SELECT c.name AS church_name, u.email, u.full_name
        FROM churches c
        JOIN users u ON c.created_by = u.id
        WHERE c.id IN (
            SELECT church_id FROM church_financial_reports WHERE report_month = ? AND report_year = ? AND status = 'submitted'
        )
    ");
    $stmtChurches->execute([$curMonth, $curYear]);
    $submitted = $stmtChurches->fetchAll();

    $sentCount = 0;
    $mName = monthName($curMonth) . ' ' . $curYear;
    foreach ($submitted as $target) {
        if (!empty($target['email'])) {
            $msg = "Congratulations! We want to commend <strong>" . h($target['church_name']) . "</strong> for successfully submitting your monthly report for <strong>" . h($mName) . "</strong>. Thank you for your faithful reporting and dedication to the ministry!";
            $ok = sendAppEmail($target['email'], $target['full_name'], "🎉 Congratulations on Report Submission — " . $target['church_name'] . " (" . $mName . ")", $msg, "login.php", "View Portal");
            if ($ok) $sentCount++;
        }
    }

    if ($sentCount > 0) {
        $successMsg = "Successfully sent {$sentCount} congratulations email(s) for {$mName}!";
    } else {
        $errorMsg = "No congratulations emails were sent. Either no churches have submitted for {$mName} yet or email configuration is disabled.";
    }
}

// Handle POST: Delete Uploaded Background Video
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_uploaded_video'])) {
    try {
        $stmt = $db->query("SELECT id, video_path FROM hero_videos WHERE is_active = 1");
        $activeVids = $stmt->fetchAll();
        foreach ($activeVids as $vid) {
            $fullPath = __DIR__ . '/' . $vid['video_path'];
            if (file_exists($fullPath) && is_file($fullPath)) {
                unlink($fullPath);
            }
        }
        $db->query("UPDATE hero_videos SET is_active = 0");
        $successMsg = 'Active uploaded background video deleted successfully!';
    } catch (Exception $e) {
        $errorMsg = 'Error deleting background video: ' . $e->getMessage();
    }
}

// Handle POST: Delete Uploaded Showcase Video
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_showcase_video'])) {
    try {
        $stmt = $db->query("SELECT id, video_path FROM hero_showcase_videos WHERE is_active = 1");
        $activeVids = $stmt->fetchAll();
        foreach ($activeVids as $vid) {
            $fullPath = __DIR__ . '/' . $vid['video_path'];
            if (file_exists($fullPath) && is_file($fullPath)) {
                unlink($fullPath);
            }
        }
        $db->query("UPDATE hero_showcase_videos SET is_active = 0");
        $successMsg = 'Active uploaded showcase video deleted successfully!';
    } catch (Exception $e) {
        $errorMsg = 'Error deleting showcase video: ' . $e->getMessage();
    }
}

// Handle POST: User Management Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user_status'])) {
    $targetId  = (int)($_POST['target_user_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    if ($targetId > 0 && in_array($newStatus, ['active','pending','suspended'])) {
        $stTarget = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stTarget->execute([$targetId]);
        $tRole = $stTarget->fetchColumn();

        if ($tRole === 'super_admin' || $targetId === (int)$_SESSION['user_id']) {
            $errorMsg = 'Super Admin accounts cannot be suspended.';
        } else {
            $upd = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newStatus, $targetId]);
            $successMsg = 'User status updated to "' . ucfirst($newStatus) . '".';
        }
    }
}

// Handle POST: Delete User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $targetId = (int)($_POST['target_user_id'] ?? 0);
    if ($targetId > 0) {
        $stTarget = $db->prepare("SELECT role, full_name FROM users WHERE id = ?");
        $stTarget->execute([$targetId]);
        $tUser = $stTarget->fetch();
        if (!$tUser) {
            $errorMsg = 'User not found.';
        } elseif ($tUser['role'] === 'super_admin' || $targetId === (int)$_SESSION['user_id']) {
            $errorMsg = 'Super Admin accounts cannot be deleted.';
        } else {
            try {
                $db->beginTransaction();
                $adminId = (int)$_SESSION['user_id'];

                // Safely reassign foreign key dependencies to active admin before deletion
                $db->prepare("UPDATE churches SET created_by = ? WHERE created_by = ?")->execute([$adminId, $targetId]);
                $db->prepare("UPDATE zones SET created_by = ? WHERE created_by = ?")->execute([$adminId, $targetId]);
                $db->prepare("UPDATE due_percentage_settings SET updated_by = ? WHERE updated_by = ?")->execute([$adminId, $targetId]);
                $db->prepare("UPDATE due_percentage_audit_log SET changed_by = ? WHERE changed_by = ?")->execute([$adminId, $targetId]);
                $db->prepare("UPDATE site_settings SET updated_by = ? WHERE updated_by = ?")->execute([$adminId, $targetId]);

                // Delete target user
                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);
                $db->commit();
                $successMsg = 'User "' . h($tUser['full_name']) . '" deleted successfully.';
            } catch (Exception $e) {
                $db->rollBack();
                $errorMsg = 'Error deleting user: ' . $e->getMessage();
            }
        }
    }
}

// Fetch Dues Settings Grouped by church_type
$stmt = $db->prepare("SELECT * FROM due_percentage_settings ORDER BY id ASC");
$stmt->execute();
$allSettings = $stmt->fetchAll();
$charteredSettings = [];
$uncharteredSettings = [];
foreach ($allSettings as $s) {
    if ($s['church_type'] === 'chartered') $charteredSettings[] = $s;
    else $uncharteredSettings[] = $s;
}

// Fetch Audit Logs (Limit to 10)
$audit_logs = [];
try {
    $stmt = $db->query("SELECT l.*, s.label, s.church_type, u.full_name AS admin_name
                        FROM due_percentage_audit_log l
                        JOIN due_percentage_settings s ON l.due_setting_id = s.id
                        JOIN users u ON l.changed_by = u.id
                        ORDER BY l.changed_at DESC LIMIT 10");
    $audit_logs = $stmt->fetchAll();
} catch (Exception $e) {}

// Fetch Site Settings
$siteSettings = [];
if ($hasSiteSettings) {
    $rows = $db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll();
    foreach ($rows as $r) $siteSettings[$r['setting_key']] = $r['setting_value'];
}
function ss($key, $default = '', $siteSettings = []) {
    global $siteSettings;
    return $siteSettings[$key] ?? $default;
}

// Fetch All Users for Users page
$allUsers = [];
if ($page === 'users') {
    // Ensure only 1 default Super Admin account exists (auto-remove secondary preview super admin)
    try {
        $primaryAdminId = $db->query("SELECT id FROM users WHERE role = 'super_admin' AND email != 'preview_admin@foursquare.org' ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($primaryAdminId) {
            $prevAdmins = $db->query("SELECT id FROM users WHERE role = 'super_admin' AND email = 'preview_admin@foursquare.org'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($prevAdmins as $pId) {
                $db->prepare("UPDATE churches SET created_by = ? WHERE created_by = ?")->execute([$primaryAdminId, $pId]);
                $db->prepare("UPDATE zones SET created_by = ? WHERE created_by = ?")->execute([$primaryAdminId, $pId]);
                $db->prepare("UPDATE due_percentage_settings SET updated_by = ? WHERE updated_by = ?")->execute([$primaryAdminId, $pId]);
                $db->prepare("UPDATE site_settings SET updated_by = ? WHERE updated_by = ?")->execute([$primaryAdminId, $pId]);
                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$pId]);
            }
        }
    } catch (Exception $e) {}

    $allUsers = $db->query("SELECT u.*,
        (SELECT c.name FROM churches c WHERE c.created_by = u.id LIMIT 1) AS church_name,
        (SELECT z.zone_name FROM zones z WHERE z.created_by = u.id LIMIT 1) AS zone_name
        FROM users u ORDER BY u.created_at DESC")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin | Foursquare Reports</title>
<link rel="icon" type="image/jpeg" href="assets/logo.jpg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/dashboard.css">
<link rel="stylesheet" href="assets/bootstrap.min.css">
<style>
  .shell { display: flex; min-height: 100vh; background: #FAF9F6; }
  aside.sidebar { background: #1A1040 !important; color: rgba(255,255,255,0.75) !important; }
  main.main { flex: 1; padding: 36px 40px; overflow-x: hidden; box-sizing: border-box; }
  .sidebar .brand { color:#fff; margin-bottom:20px; display:flex; align-items:center; gap:10px; text-decoration:none; }
  .sidebar .brand img { height:32px;width:32px;border-radius:7px;object-fit:cover;border:2px solid rgba(255,255,255,0.15); }
  .sidebar .brand-name { color:#fff !important; font-family:'Outfit',sans-serif; font-size:15px; font-weight:700; }
  .sidebar .nav-label { color:rgba(255,255,255,0.30) !important; font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; margin-bottom:6px; padding:0 8px; }
  .sidebar .nav-item { color:rgba(255,255,255,0.65) !important; padding:9px 10px; border-radius:8px; margin-bottom:2px; display:flex; align-items:center; gap:10px; font-size:13px; font-weight:500; text-decoration:none; transition:all .18s ease; }
  .sidebar .nav-item:hover { background:rgba(255,255,255,0.08) !important; color:#fff !important; }
  .sidebar .nav-item.active { background:#E31E24 !important; color:#fff !important; box-shadow:0 2px 8px rgba(227,30,36,.3); }
  .sidebar .u-name { color:#fff !important; }
  .sidebar .u-role { color:rgba(255,255,255,0.45) !important; }
  .portal-tag { display:inline-flex; align-items:center; gap:6px; padding:5px 11px; border-radius:99px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; background:rgba(245,164,29,0.18) !important; color:#ffd080 !important; border:1px solid rgba(245,164,29,0.25); margin-bottom:20px; }
  .dot { width:6px; height:6px; border-radius:50%; display:inline-block; }
  .pill { font-size:11px; font-weight:700; padding:3px 9px; border-radius:99px; display:inline-block; }
  .pill-draft { background:#FFF7ED; color:#EA580C; }
  .pill-submitted { background:#ECFDF5; color:#059669; }
  .pill-locked { background:#EDE9FE; color:#6D28D9; }
  .modal-backdrop-custom { position:fixed; inset:0; background:rgba(26,16,64,0.4); backdrop-filter:blur(3px); display:none; align-items:center; justify-content:center; z-index:2000; }
  .modal-backdrop-custom.open { display:flex; }
  .modal-box { background:var(--card); border-radius:var(--radius-lg); padding:28px; width:90%; max-width:440px; box-shadow:var(--shadow-navy); border:1px solid var(--line); }
</style>
</head>
<body>

<!-- Mobile navigation header & backdrop overlay -->
<div class="mobile-header">
  <a href="admin-dashboard.php" class="mobile-brand">
    <img src="assets/logo.jpg" alt="Logo">
    <div>
      <span>Foursquare</span>
      <small>Reports</small>
    </div>
  </a>
  <button class="mobile-nav-toggle" aria-label="Toggle navigation" onclick="document.body.classList.toggle('mobile-open');">
    <span></span><span></span><span></span>
  </button>
</div>
<div class="sidebar-overlay" onclick="document.body.classList.remove('mobile-open');"></div>

<div class="shell">
  <aside class="sidebar" id="adminSidebar">
    <!-- Mobile close button (X) — only shown on mobile -->
    <button class="sidebar-close-btn" aria-label="Close navigation" onclick="document.body.classList.remove('mobile-open');">&times;</button>
    <a href="admin-dashboard.php" class="brand" style="margin-bottom:20px;">
      <img src="assets/logo.jpg" alt="Logo">
      <div>
        <span class="brand-name">Foursquare</span>
        <small style="font-size:9px;opacity:.5;display:block;font-family:'Inter',sans-serif;font-weight:500;letter-spacing:.04em;text-transform:uppercase;color:rgba(255,255,255,0.5);">Reports</small>
      </div>
    </a>

    <span class="portal-tag" style="background:rgba(245,164,29,0.15);color:var(--gold-deep);"><span class="dot" style="background:var(--gold-deep);"></span>Platform Admin</span>

    <div class="nav-section">
      <div class="nav-label">Overview</div>
      <a class="nav-item <?= $page === 'dashboard' ? 'active' : '' ?>" href="admin-dashboard.php">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
        Dashboard
      </a>
      <a class="nav-item <?= $page === 'dues' ? 'active' : '' ?>" href="admin-dashboard.php?page=dues">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 100 20A10 10 0 0012 2z"/><path d="M12 6v6l4 2"/></svg>
        Manage Dues
      </a>
      <div class="nav-label">Management</div>
      <a class="nav-item <?= $page === 'users' ? 'active' : '' ?>" href="admin-dashboard.php?page=users">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M2 21v-1a7 7 0 0114 0v1"/><path d="M22 21v-1a5 5 0 00-4-4.9"/><circle cx="19" cy="7" r="3"/></svg>
        Manage Users
      </a>
      <?php if ($hasSiteSettings): ?>
      <a class="nav-item <?= $page === 'settings' ? 'active' : '' ?>" href="admin-dashboard.php?page=settings">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09a1.65 1.65 0 00-1-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09a1.65 1.65 0 001.51-1 1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
        Site Settings
      </a>
      <?php else: ?>
      <a class="nav-item" href="migrate.php" style="opacity:0.6;">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/></svg>
        Site Settings <small style="font-size:10px;opacity:0.7;">(run migrate.php)</small>
      </a>
      <?php endif; ?>
      <a class="nav-item" href="index.php" target="_blank">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
        View Public Portal
      </a>
    </div>

    <div class="sidebar-footer" style="display:flex;flex-direction:column;gap:6px;">
      <a class="nav-item" href="profile.php" style="margin-bottom:0;">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>
        My Profile
      </a>
      <a href="logout.php" class="logout-btn" style="width:100%;text-align:center;margin-top:2px;">Log out</a>
    </div>
  </aside>

  <main class="main">
    <div class="page-head" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
      <div>
        <?php if ($page === 'dues'): ?>
          <h1>Manage Dues &amp; Percentages</h1>
          <p class="sub">Adjust calculation rates and toggle lock status for national, regional, district, and zonal dues.</p>
        <?php elseif ($page === 'users'): ?>
          <h1>Manage Users</h1>
          <p class="sub">View, activate, suspend, or reset passwords for all registered users on the platform.</p>
        <?php elseif ($page === 'settings'): ?>
          <h1>Site Settings</h1>
          <p class="sub">Control the content displayed on the public landing page. Changes take effect immediately.</p>
        <?php else: ?>
          <h1>Platform overview</h1>
          <p class="sub">View registered churches, zones, and submission status across the reporting network (<?= monthName($curMonth) ?> <?= $curYear ?>).</p>
        <?php endif; ?>
      </div>
      <?php if ($page === 'dashboard'): ?>
      <form method="GET" action="" class="d-flex gap-2">
        <select name="month" class="form-select form-select-sm" style="width:110px;" onchange="this.form.submit()">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m == $curMonth ? 'selected' : '' ?>><?= monthName($m) ?></option>
          <?php endfor; ?>
        </select>
        <select name="year" class="form-select form-select-sm" style="width:90px;" onchange="this.form.submit()">
          <?php 
          $currentYear = (int)date('Y');
          $startYear = 2020;
          $endYear = $currentYear + 2;
          for ($y = $endYear; $y >= $startYear; $y--): 
          ?>
            <option value="<?= $y ?>" <?= $y == $curYear ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </form>
      <?php endif; ?>
    </div>

    <?php if ($successMsg): ?>
      <div class="alert alert-success p-2 small mb-3"><?= h($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
      <div class="alert alert-danger p-2 small mb-3"><?= h($errorMsg) ?></div>
    <?php endif; ?>

    <!-- DASHBOARD -->
    <?php if ($page === 'dashboard'): ?>
      <div class="stat-grid">
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:#FDEBE9;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>
          </div></div>
          <div class="stat-value"><?= $total_churches_count ?></div>
          <div class="stat-label">Registered churches</div>
        </div>
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:#ECE9F7;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--purple)" stroke-width="2"><path d="M3 21V8l9-5 9 5v13"/></svg>
          </div></div>
          <div class="stat-value"><?= $total_zones_count ?></div>
          <div class="stat-label">Registered zones</div>
        </div>
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:#FDF1DC;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--gold-deep)" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18"/></svg>
          </div></div>
          <div class="stat-value"><?= $reports_submitted_count ?></div>
          <div class="stat-label">Reports submitted this month</div>
        </div>
        <div class="card stat-card">
          <div class="stat-top"><div class="stat-icon" style="background:#F3EAF0;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--magenta)" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
          </div></div>
          <div class="stat-value"><?= $reports_outstanding_count ?></div>
          <div class="stat-label">Reports outstanding</div>
        </div>
      </div>

      <div class="card section-card" style="margin-top:24px;">
        <div class="section-card-head d-flex justify-content-between align-items-center flex-wrap gap-2">
          <h3>Quick Actions &amp; Notifications</h3>
          <div class="d-flex gap-2 flex-wrap">
            <form method="POST" action="" onsubmit="return confirm('Send email reminders to all churches with unsubmitted reports for <?= monthName($curMonth) ?> <?= $curYear ?>?');">
              <input type="hidden" name="send_outstanding_reminders" value="1">
              <button type="submit" class="btn btn-primary btn-sm fw-semibold" style="font-size:12px;padding:6px 14px;display:inline-flex;align-items:center;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                Send Reminders (Unsubmitted: <?= $reports_outstanding_count ?>)
              </button>
            </form>
            <form method="POST" action="" onsubmit="return confirm('Send congratulations email to all churches that submitted reports for <?= monthName($curMonth) ?> <?= $curYear ?>?');">
              <input type="hidden" name="send_submitted_congratulations" value="1">
              <button type="submit" class="btn btn-success btn-sm fw-semibold" style="font-size:12px;padding:6px 14px;background:#10B981;border-color:#10B981;display:inline-flex;align-items:center;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Send Congratulations (Submitted: <?= $reports_submitted_count ?>)
              </button>
            </form>
          </div>
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;padding:8px 0;">
          <a href="admin-dashboard.php?page=users" class="btn btn-outline btn-sm">Manage Users</a>
          <a href="admin-dashboard.php?page=dues" class="btn btn-outline btn-sm">Manage Dues</a>
          <?php if ($hasSiteSettings): ?>
          <a href="admin-dashboard.php?page=settings" class="btn btn-outline btn-sm">Edit Site &amp; Email Content</a>
          <?php else: ?>
          <a href="migrate.php" class="btn btn-outline btn-sm">Run Migration (enable settings)</a>
          <?php endif; ?>
          <a href="profile.php" class="btn btn-outline btn-sm">My Profile</a>
          <a href="index.php" target="_blank" class="btn btn-outline btn-sm">View Public Site &#8599;</a>
        </div>
      </div>
    <?php endif; ?>

    <!-- DUES PAGE -->
    <?php if ($page === 'dues'): ?>
      <div class="card section-card">
        <div class="section-card-head">
          <h3>Manage Due Percentages</h3>
          <span class="pill pill-pending">Changes apply immediately to new monthly reports</span>
        </div>
        <div class="tab-row" id="adminTabs">
          <a class="tab-btn active" href="#" onclick="switchTab('chartered');return false;" id="tabBtnChartered">Chartered Churches</a>
          <a class="tab-btn" href="#" onclick="switchTab('unchartered');return false;" id="tabBtnUnchartered">Unchartered Churches</a>
        </div>
        <form method="POST" action="">
          <input type="hidden" name="update_rates" value="1">
          <div id="tabChartered">
            <?php foreach ($charteredSettings as $s): $isLocked = (int)$s['is_locked'] === 1; ?>
            <div class="rate-row">
              <div>
                <div class="rate-label"><?= h($s['label']) ?>
                  <span class="pill pill-locked ms-2" id="badge_lock_<?= $s['id'] ?>" style="<?= $isLocked ? '' : 'display:none;' ?>"><?= $isLocked ? '&#128274; Locked' : '' ?></span>
                </div>
                <div class="rate-base">Calculated from <?= h(str_replace('_', ' ', $s['base_field'])) ?></div>
              </div>
              <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" role="switch" name="locks[<?= $s['id'] ?>]" value="1" <?= !$isLocked ? 'checked' : '' ?> onchange="toggleLockInput(<?= $s['id'] ?>, this.checked)">
                  <label class="form-check-label small text-muted">Unlocked</label>
                </div>
                <div class="rate-input-group">
                  <input type="text" name="rates[<?= $s['id'] ?>]" id="rate_input_<?= $s['id'] ?>" value="<?= number_format($s['percentage_value'], 2) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                  <span>%</span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div id="tabUnchartered" style="display:none;">
            <?php foreach ($uncharteredSettings as $s): $isLocked = (int)$s['is_locked'] === 1; ?>
            <div class="rate-row">
              <div>
                <div class="rate-label"><?= h($s['label']) ?>
                  <span class="pill pill-locked ms-2" id="badge_lock_<?= $s['id'] ?>" style="<?= $isLocked ? '' : 'display:none;' ?>"><?= $isLocked ? '&#128274; Locked' : '' ?></span>
                </div>
                <div class="rate-base">Calculated from <?= h(str_replace('_', ' ', $s['base_field'])) ?></div>
              </div>
              <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" role="switch" name="locks[<?= $s['id'] ?>]" value="1" <?= !$isLocked ? 'checked' : '' ?> onchange="toggleLockInput(<?= $s['id'] ?>, this.checked)">
                  <label class="form-check-label small text-muted">Unlocked</label>
                </div>
                <div class="rate-input-group">
                  <input type="text" name="rates[<?= $s['id'] ?>]" id="rate_input_<?= $s['id'] ?>" value="<?= number_format($s['percentage_value'], 2) ?>" <?= $isLocked ? 'disabled' : '' ?>>
                  <span>%</span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;justify-content:flex-end;margin-top:20px;">
            <button type="submit" class="btn btn-primary btn-sm px-4 py-2 fw-semibold">Save Changes</button>
          </div>
        </form>
      </div>
      <div class="card section-card">
        <div class="section-card-head"><h3>Recent Rate Adjustment Audit Log</h3></div>
        <?php if (empty($audit_logs)): ?>
          <p class="text-muted p-4 text-center">No rate adjustments have been logged yet.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="data-table">
              <thead><tr><th>Date/Time</th><th>Due Setting</th><th>Church Type</th><th>Action</th><th>Old Value</th><th>New Value</th><th>Note</th><th>Admin</th></tr></thead>
              <tbody>
                <?php foreach ($audit_logs as $al): ?>
                <tr>
                  <td><?= date('M d, Y H:i', strtotime($al['changed_at'])) ?></td>
                  <td><?= h($al['label']) ?></td>
                  <td class="strong text-capitalize"><?= h($al['church_type']) ?></td>
                  <td><?php if (($al['action'] ?? 'rate_change') === 'lock_change'): ?><span class="pill pill-locked">Lock Change</span><?php else: ?><span class="pill pill-pending">Rate Change</span><?php endif; ?></td>
                  <td><?= number_format($al['old_value'], 4) ?></td>
                  <td class="strong" style="color:#2C7A3D;"><?= number_format($al['new_value'], 4) ?></td>
                  <td style="color:var(--ink-faint);font-size:12px;"><?= h($al['note'] ?? '&#8212;') ?></td>
                  <td><?= h($al['admin_name']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- USERS PAGE -->
    <?php if ($page === 'users'): ?>
      <div class="card section-card">
        <div class="section-card-head">
          <h3>All Registered Users <span class="pill pill-pending ms-2"><?= count($allUsers) ?> total</span></h3>
        </div>
        <?php if (empty($allUsers)): ?>
          <p class="text-muted p-4 text-center">No users registered yet.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="users-table data-table">
              <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Entity</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($allUsers as $u):
                  $uNames = explode(' ', trim($u['full_name']));
                  $uInit  = strtoupper(substr($uNames[0] ?? '', 0, 1) . (isset($uNames[1]) ? substr($uNames[1], 0, 1) : ''));
                  $entity = $u['church_name'] ?? $u['zone_name'] ?? '&#8212;';
                  $roleClass = match($u['role']) { 'super_admin' => 'role-super', 'zonal_admin' => 'role-zonal', default => 'role-church' };
                  $statusClass = match($u['status']) { 'active' => 'status-active', 'pending' => 'status-pending', default => 'status-suspended' };
                  $roleLabel = match($u['role']) { 'super_admin' => 'Super Admin', 'zonal_admin' => 'Zonal Admin', default => 'Church Admin' };
                  $isSelf = ((int)$u['id'] === (int)$_SESSION['user_id']);
                  $isSuperAdmin = ($u['role'] === 'super_admin');
                  $isProtected = ($isSelf || $isSuperAdmin);
                ?>
                <tr>
                  <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                      <div style="width:34px;height:34px;border-radius:50%;background:var(--purple);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;"><?= h($uInit) ?></div>
                      <div>
                        <div style="font-weight:600;font-size:13.5px;"><?= h($u['full_name']) ?></div>
                        <?php if ($u['phone']): ?><div style="font-size:11px;color:var(--ink-faint);"><?= h($u['phone']) ?></div><?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td style="font-size:13px;"><?= h($u['email']) ?></td>
                  <td><span class="role-badge <?= $roleClass ?>"><?= $roleLabel ?></span></td>
                  <td style="font-size:13px;color:var(--ink-soft);"><?= h($entity) ?></td>
                  <td><span class="status-badge <?= $statusClass ?>"><?= ucfirst($u['status']) ?></span></td>
                  <td style="font-size:12px;color:var(--ink-faint);"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                  <td>
                    <?php if (!$isProtected): ?>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                      <?php if ($u['status'] !== 'active'): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="update_user_status" value="1"><input type="hidden" name="target_user_id" value="<?= $u['id'] ?>"><input type="hidden" name="new_status" value="active"><button type="submit" class="btn-xs btn-activate">Activate</button></form>
                      <?php endif; ?>
                      <?php if ($u['status'] !== 'suspended'): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="update_user_status" value="1"><input type="hidden" name="target_user_id" value="<?= $u['id'] ?>"><input type="hidden" name="new_status" value="suspended"><button type="submit" class="btn-xs btn-suspend" onclick="return confirm('Suspend this user?')">Suspend</button></form>
                      <?php endif; ?>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete user <?= h(addslashes($u['full_name'])) ?>? This cannot be undone.');">
                        <input type="hidden" name="delete_user" value="1">
                        <input type="hidden" name="target_user_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn-xs" style="background:#FEE2E2;color:#DC2626;border:1px solid #FCA5A5;border-radius:6px;padding:3px 9px;font-size:11px;font-weight:600;">Delete</button>
                      </form>
                    </div>
                    <?php else: ?>
                      <span style="font-size:11px;color:var(--ink-faint);font-weight:600;"><?= $isSelf ? 'You (Protected)' : 'Protected (Admin)' ?></span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-backdrop-custom" id="resetModal">
        <div class="modal-box">
          <h4>Reset Password</h4>
          <p id="resetModalName" style="font-size:13px;color:var(--ink-soft);margin-bottom:16px;"></p>
          <form method="POST" id="resetForm">
            <input type="hidden" name="reset_user_password" value="1">
            <input type="hidden" name="target_user_id" id="resetTargetId" value="">
            <div style="margin-bottom:14px;">
              <label style="font-size:12px;font-weight:700;color:var(--ink-soft);">New Password (min. 6 characters)</label>
              <input type="password" name="new_user_password" id="resetPwInput" required style="width:100%;margin-top:6px;padding:9px 13px;border:1.5px solid var(--line);border-radius:9px;font-size:14px;">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
              <button type="button" class="btn btn-outline btn-sm" onclick="closeResetModal()">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm">Set Password</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- SITE SETTINGS PAGE -->
    <?php if ($page === 'settings' && $hasSiteSettings): ?>
      <form method="POST" action="admin-dashboard.php?page=settings" enctype="multipart/form-data" id="settingsMainForm">
        <input type="hidden" name="update_site_settings" value="1">
        <!-- EMAIL NOTIFICATION SETTINGS (GMAIL SMTP) -->
        <div class="card section-card" style="border-left: 4px solid #E31E24;">
          <div class="section-card-head d-flex justify-content-between align-items-center">
            <div>
              <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#E31E24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                Gmail SMTP &amp; Email Notifications
              </h3>
              <p class="sub mb-0" style="font-size:12px;color:var(--ink-soft);">Configure your Gmail address and Secret Key (App Password) to send automated congratulations and reminder emails.</p>
            </div>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="smtp_enabled" value="1" id="smtpEnabledSwitch" <?= ($siteSettings['smtp_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
              <label class="form-check-label small fw-bold" for="smtpEnabledSwitch">Enable Emails</label>
            </div>
          </div>
          <div class="settings-grid">
            <div class="settings-group">
              <label>Gmail Address</label>
              <input type="email" name="smtp_email" placeholder="e.g. yourchurch@gmail.com" value="<?= h($siteSettings['smtp_email'] ?? '') ?>">
              <small style="color:var(--ink-faint);font-size:11px;margin-top:4px;display:block;">Your admin Gmail account used to send system emails.</small>
            </div>
            <div class="settings-group">
              <label>Secret Key / App Password</label>
              <input type="password" name="smtp_secret_key" placeholder="•••• •••• •••• ••••" value="<?= h($siteSettings['smtp_secret_key'] ?? '') ?>">
              <small style="color:var(--ink-faint);font-size:11px;margin-top:4px;display:block;">Generated in your Google Account &rarr; Security &rarr; App Passwords (16-char secret key).</small>
            </div>
            <div class="settings-group full">
              <label>Sender Display Name</label>
              <input type="text" name="smtp_sender_name" placeholder="Foursquare National Reports Admin" value="<?= h($siteSettings['smtp_sender_name'] ?? 'Foursquare Reports Admin') ?>">
            </div>
          </div>
          <div class="mt-3 pt-3 border-top d-flex justify-content-between align-items-center">
            <small style="color:var(--ink-soft);font-size:12px;">Save settings first, then send a test email to verify your Gmail connection.</small>
            <div class="d-flex gap-2">
              <button type="submit" name="send_test_email" value="1" class="btn btn-outline btn-sm px-3 fw-semibold d-inline-flex align-items-center">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Send Test Email
              </button>
              <button type="submit" name="update_site_settings" value="1" class="btn btn-primary btn-sm px-3 fw-semibold">Save Settings</button>
            </div>
          </div>
        </div>

        <!-- PAYSTACK PAYMENT GATEWAY & SUBSCRIPTION SETTINGS -->
        <div class="card section-card" style="border-left: 4px solid #10B981;">
          <div class="section-card-head d-flex justify-content-between align-items-center">
            <div>
              <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Paystack Payment Gateway &amp; Subscriptions
              </h3>
              <p class="sub mb-0" style="font-size:12px;color:var(--ink-soft);">Configure Paystack API keys, monthly subscription fee, free trial period, and report unlock fee.</p>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="payment_enabled" value="1" id="paymentEnabledSwitch" <?= ($siteSettings['payment_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label small fw-bold" for="paymentEnabledSwitch">Enable Payments</label>
              </div>
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="free_trial_enabled" value="1" id="freeTrialEnabledSwitch" <?= ($siteSettings['free_trial_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label small fw-bold" for="freeTrialEnabledSwitch">Enable Free Trial</label>
              </div>
            </div>
          </div>
          <div class="settings-grid">
            <div class="settings-group">
              <label>Paystack Public Key</label>
              <input type="text" name="payment_public_key" placeholder="pk_test_..." value="<?= h($siteSettings['payment_public_key'] ?? '') ?>">
              <small style="color:var(--ink-faint);font-size:11px;margin-top:4px;display:block;">Found in Paystack Dashboard &rarr; Settings &rarr; API Keys &amp; Webhooks.</small>
            </div>
            <div class="settings-group">
              <label>Paystack Secret Key</label>
              <input type="password" name="payment_secret_key" placeholder="sk_test_..." value="<?= h($siteSettings['payment_secret_key'] ?? '') ?>">
              <small style="color:var(--ink-faint);font-size:11px;margin-top:4px;display:block;">Used for server-side payment verification (kept secure).</small>
            </div>
            <div class="settings-group">
              <label>Monthly Subscription Fee (₦)</label>
              <input type="number" step="0.01" name="monthly_sub_amount" placeholder="e.g. 5000" value="<?= h($siteSettings['monthly_sub_amount'] ?? '5000') ?>">
              <small style="color:var(--ink-faint);font-size:11px;margin-top:4px;display:block;">Amount charged per month after free trial expires.</small>
            </div>
            <div class="settings-group">
              <label>Report Unlock Fee (₦)</label>
              <input type="number" step="0.01" name="report_unlock_fee" placeholder="e.g. 2000" value="<?= h($siteSettings['report_unlock_fee'] ?? '2000') ?>">
              <small style="color:var(--ink-faint);font-size:11px;margin-top:4px;display:block;">Token fee charged to unlock and edit a submitted report.</small>
            </div>
            <div class="settings-group">
              <label>Free Trial Duration (Months)</label>
              <input type="number" min="1" max="24" name="free_trial_months" placeholder="e.g. 3" value="<?= h($siteSettings['free_trial_months'] ?? '3') ?>">
              <small style="color:var(--ink-faint);font-size:11px;margin-top:4px;display:block;">Default trial duration in months (e.g. 1, 3, 6, 12).</small>
            </div>
            <div class="settings-group">
              <label>Free Trial Duration in Days (Optional)</label>
              <input type="number" min="1" max="365" name="free_trial_days" placeholder="e.g. 30" value="<?= h($siteSettings['free_trial_days'] ?? '') ?>">
              <small style="color:var(--ink-faint);font-size:11px;margin-top:4px;display:block;">Set exact days (e.g. 1, 7, 14, 30, 60, 90). Leave empty to use Months.</small>
            </div>
          </div>
          <div class="mt-3 pt-3 border-top d-flex justify-content-end">
            <button type="submit" name="update_site_settings" value="1" class="btn btn-success btn-sm px-4 fw-semibold" style="background:#10B981;border-color:#10B981;">Save Payment Settings</button>
          </div>
        </div>

        <!-- CHATBOT KNOWLEDGE BASE MANAGER -->
        <div class="card section-card" style="border-left: 4px solid #3B82F6;">
          <div class="section-card-head">
            <h3>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
              Chatbot Knowledge Base &amp; Q&amp;A Manager
            </h3>
            <p class="sub mb-0" style="font-size:12px;color:var(--ink-soft);">Add questions, answers, and keywords to train the AI Chatbot assistant for all platform users.</p>
          </div>
          
          <?php
          $kbItems = [];
          try {
              ensureChatbotTablesExist();
              $kbItems = $db->query("SELECT * FROM chatbot_knowledge_base ORDER BY id DESC")->fetchAll();
          } catch(Exception $e) {}
          ?>

          <div style="background:#F9FAFB; border:1px solid #E5E7EB; border-radius:10px; padding:16px; margin-bottom:20px;">
            <h5 style="font-size:13px; font-weight:700; color:#1A1040; margin-bottom:12px;">+ Add New Question &amp; Answer Pair</h5>
            <div class="settings-grid">
              <div class="settings-group full">
                <label>User Question</label>
                <input type="text" name="kb_question" placeholder="e.g. How do I change my pastor profile details?">
              </div>
              <div class="settings-group full">
                <label>AI Bot Answer</label>
                <textarea name="kb_answer" placeholder="e.g. Go to your Profile page from the sidebar menu to update your phone number, bio, and photo." style="min-height:70px;"></textarea>
              </div>
              <div class="settings-group full">
                <label>Matching Keywords (comma-separated)</label>
                <input type="text" name="kb_keywords" placeholder="profile, pastor, photo, change, edit, phone">
              </div>
            </div>
            <div class="mt-2 text-end">
              <button type="submit" name="add_kb_item" value="1" class="btn btn-primary btn-sm px-4 fw-semibold" style="background:#3B82F6;border-color:#3B82F6;">Add Q&amp;A to Knowledge Base</button>
            </div>
          </div>

          <h5 style="font-size:13px; font-weight:700; color:#1A1040; margin-bottom:12px;">Existing Knowledge Base Items (<?= count($kbItems) ?>)</h5>
          <?php if (empty($kbItems)): ?>
            <p class="text-muted small">No knowledge base items configured yet.</p>
          <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:10px;">
              <?php foreach ($kbItems as $kb): ?>
                <div style="background:#fff; border:1px solid #E5E7EB; border-radius:10px; padding:14px 16px;">
                  <div style="font-size:13.5px; font-weight:700; color:#111827; margin-bottom:4px;"><?= h($kb['question']) ?></div>
                  <div style="font-size:12.5px; color:#4B5563; line-height:1.45; margin-bottom:6px;"><?= nl2br(h($kb['answer'])) ?></div>
                  <?php if (!empty($kb['keywords'])): ?>
                    <div style="font-size:11px; color:#9CA3AF; margin-bottom:10px;"><strong>Keywords:</strong> <?= h($kb['keywords']) ?></div>
                  <?php endif; ?>
                  <div style="display:flex; gap:8px; justify-content:flex-end;">
                    <button type="button"
                      onclick="openKbEditModal(<?= $kb['id'] ?>, <?= json_encode($kb['question']) ?>, <?= json_encode($kb['answer']) ?>, <?= json_encode($kb['keywords']) ?>)"
                      style="background:#EFF6FF;color:#2563EB;border:1px solid #BFDBFE;border-radius:6px;padding:4px 12px;font-size:11px;font-weight:600;cursor:pointer;">Edit</button>
                    <button type="button"
                      onclick="confirmDeleteKb(<?= $kb['id'] ?>)"
                      style="background:#FEE2E2;color:#DC2626;border:1px solid #FCA5A5;border-radius:6px;padding:4px 12px;font-size:11px;font-weight:600;cursor:pointer;">Delete</button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="card section-card">
          <div class="section-card-head"><h3>General Settings</h3></div>
          <div class="settings-grid">
            <div class="settings-group"><label>Site Name</label><input type="text" name="site_name" value="<?= h($siteSettings['site_name'] ?? 'Foursquare Reports') ?>"></div>
            <div class="settings-group"><label>Site Tagline</label><input type="text" name="site_tagline" value="<?= h($siteSettings['site_tagline'] ?? 'Church & Zonal Reporting System') ?>"></div>
            <div class="settings-group"><label>Contact Email</label><input type="text" name="contact_email" value="<?= h($siteSettings['contact_email'] ?? '') ?>" placeholder="info@foursquarechurch.org"></div>
            <div class="settings-group"><label>Contact Phone</label><input type="text" name="contact_phone" value="<?= h($siteSettings['contact_phone'] ?? '') ?>" placeholder="+234 ..."></div>
            <div class="settings-group full"><label>Footer Organisation Name</label><input type="text" name="footer_org_name" value="<?= h($siteSettings['footer_org_name'] ?? 'Foursquare Gospel Church, Isara Zone') ?>"></div>
          </div>
        </div>
        <div class="card section-card">
          <div class="section-card-head"><h3>Hero Section Settings</h3></div>
          <div class="settings-grid">
            <div class="settings-group full"><label>Hero Headline</label><input type="text" name="hero_title" value="<?= h($siteSettings['hero_title'] ?? 'Monthly reports, finally in order.') ?>"><small style="color:var(--ink-faint);font-size:11px;margin-top:6px;display:block;">Use [em]text[/em] to colour a word.</small></div>
            <div class="settings-group full"><label>Hero Lead Paragraph</label><textarea name="hero_lead"><?= h($siteSettings['hero_lead'] ?? '') ?></textarea></div>
            
            <!-- Hero Video Upload Section -->
            <div class="settings-group full" style="border-top:1px solid var(--line);margin-top:15px;padding-top:15px;">
              <label style="font-weight:700;margin-bottom:8px;">Background Hero Video</label>
              
              <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:12px;">
                <div style="flex:1 1 200px;min-width:0;">
                  <label style="font-size:12px;color:var(--ink-soft);font-weight:600;margin-bottom:4px;display:block;">Option A: Upload Video File</label>
                  <input type="file" name="hero_video" accept="video/mp4,video/webm,video/ogg,video/quicktime" style="display:block;font-size:13px;max-width:100%;">
                  <small style="color:var(--ink-faint);font-size:11px;display:block;margin-top:4px;">Upload video. Allowed: MP4, WebM, OGG, MOV. (Note: Free hosting may limit file uploads to 2MB - 10MB).</small>
                </div>
                <div style="flex:1 1 200px;min-width:0;">
                  <label style="font-size:12px;color:var(--ink-soft);font-weight:600;margin-bottom:4px;display:block;">Option B: Paste Direct Video URL (Recommended for speed & free hosting)</label>
                  <input type="url" name="hero_video_url" placeholder="https://example.com/assets/hero-bg.mp4" value="<?= h($siteSettings['hero_video_url'] ?? '') ?>" style="width:100%;padding:8px 12px;border:1.5px solid var(--line);border-radius:8px;font-size:13px;outline:none;">
                  <small style="color:var(--ink-faint);font-size:11px;display:block;margin-top:4px;">Paste a direct link from a fast CDN (Cloudinary, Vercel, BunnyCDN, Dropbox) to load animation instantly and save free hosting bandwidth.</small>
                </div>
              </div>

              <?php
                try {
                  $activeVid = $db->query("SELECT video_path FROM hero_videos WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
                  if ($activeVid) {
                    echo '<div style="font-size:12px;color:#10B981;font-weight:600;margin-top:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;word-break:break-all;">';
                    echo '<span style="word-break:break-all;">✔ Currently Active Uploaded Video: <code style="word-break:break-all;white-space:normal;">' . h($activeVid) . '</code></span>';
                    echo '<button type="submit" name="delete_uploaded_video" value="1" onclick="return confirm(\'Are you sure you want to delete the uploaded background video? This will remove the file from the server.\');" style="background:#EF4444;color:#fff;border:none;padding:5px 10px;font-size:11px;border-radius:6px;font-weight:600;cursor:pointer;flex-shrink:0;">Delete File</button>';
                    echo '</div>';
                  } else {
                    echo '<div style="font-size:12px;color:var(--ink-faint);margin-top:8px;">No uploaded file active.</div>';
                  }
                } catch(Exception $e) {}
              ?>
            </div>

            <!-- Hero Showcase Video Section (Right side of Hero, fallback to Logo) -->
            <div class="settings-group full" style="border-top:1px solid var(--line);margin-top:20px;padding-top:20px;">
              <label style="font-weight:700;margin-bottom:8px;">Hero Showcase Video (Right Side of Hero - Fallback to Logo)</label>
              
              <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:12px;">
                <div style="flex:1 1 200px;min-width:0;">
                  <label style="font-size:12px;color:var(--ink-soft);font-weight:600;margin-bottom:4px;display:block;">Option A: Upload Showcase Video File</label>
                  <input type="file" name="showcase_video" accept="video/mp4,video/webm,video/ogg,video/quicktime" style="display:block;font-size:13px;max-width:100%;">
                  <small style="color:var(--ink-faint);font-size:11px;display:block;margin-top:4px;">Upload video. Allowed: MP4, WebM, OGG, MOV. If uploaded, it plays on the right side of the hero section instead of the logo.</small>
                </div>
                <div style="flex:1 1 200px;min-width:0;">
                  <label style="font-size:12px;color:var(--ink-soft);font-weight:600;margin-bottom:4px;display:block;">Option B: Paste Direct Showcase Video URL</label>
                  <input type="url" name="showcase_video_url" placeholder="https://example.com/assets/report-animation.mp4" value="<?= h($siteSettings['showcase_video_url'] ?? '') ?>" style="width:100%;padding:8px 12px;border:1.5px solid var(--line);border-radius:8px;font-size:13px;outline:none;">
                  <small style="color:var(--ink-faint);font-size:11px;display:block;margin-top:4px;">Paste a direct video link of the reports animation (e.g. from Cloudinary) to showcase report sheets with high speed on free hosting.</small>
                </div>
              </div>

              <?php
                try {
                  $activeShowcaseVid = $db->query("SELECT video_path FROM hero_showcase_videos WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
                  if ($activeShowcaseVid) {
                    echo '<div style="font-size:12px;color:#10B981;font-weight:600;margin-top:8px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;word-break:break-all;">';
                    echo '<span style="word-break:break-all;">✔ Currently Active Uploaded Showcase: <code style="word-break:break-all;white-space:normal;">' . h($activeShowcaseVid) . '</code></span>';
                    echo '<button type="submit" name="delete_showcase_video" value="1" onclick="return confirm(\'Are you sure you want to delete the uploaded showcase video? This will revert back to the logo or URL fallback.\');" style="background:#EF4444;color:#fff;border:none;padding:5px 10px;font-size:11px;border-radius:6px;font-weight:600;cursor:pointer;flex-shrink:0;">Delete Showcase File</button>';
                    echo '</div>';
                  } else {
                    echo '<div style="font-size:12px;color:var(--ink-faint);margin-top:8px;">No uploaded showcase file active (reverts to logo or URL fallback).</div>';
                  }
                } catch(Exception $e) {}
              ?>
            </div>
          </div>
        </div>
        <div class="card section-card">
          <div class="section-card-head"><h3>Feature Strip (4 items)</h3></div>
          <div class="settings-grid">
            <?php for ($i = 1; $i <= 4; $i++): ?>
            <div class="settings-group"><label>Strip Item <?= $i ?></label><input type="text" name="strip_item_<?= $i ?>" value="<?= h($siteSettings["strip_item_$i"] ?? '') ?>"></div>
            <?php endfor; ?>
          </div>
        </div>
        <div class="card section-card">
          <div class="section-card-head"><h3>Paths &amp; Registration Settings</h3></div>
          <div class="settings-grid">
            <div class="settings-group full"><label>Section Title</label><input type="text" name="paths_title" value="<?= h($siteSettings['paths_title'] ?? 'Two kinds of reporting, one system.') ?>"></div>
            <div class="settings-group full"><label>Section Subtitle</label><textarea name="paths_subtitle"><?= h($siteSettings['paths_subtitle'] ?? '') ?></textarea></div>
          </div>
        </div>
        <div class="card section-card">
          <div class="section-card-head"><h3>"How It Works" Section</h3></div>
          <div class="settings-grid">
            <div class="settings-group full"><label>Section Title</label><input type="text" name="how_title" value="<?= h($siteSettings['how_title'] ?? 'From paper form to filed report.') ?>"></div>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:4px;margin-bottom:40px;">
          <a href="index.php" target="_blank" class="btn btn-outline btn-sm">Preview Public Site &#8599;</a>
          <button type="submit" name="update_site_settings" value="1" class="btn btn-primary btn-sm px-4 py-2 fw-semibold">Save All Settings</button>
        </div>
<!-- ═══ KB STANDALONE FORMS (outside the outer settings form) ════════════════ -->
<!-- These forms are intentionally placed OUTSIDE the site-settings <form> to avoid nested-form issues -->

<!-- Delete KB Form -->
<form method="POST" action="admin-dashboard.php?page=settings" id="kbDeleteForm" style="display:none;">
  <input type="hidden" name="delete_kb_item" value="1">
  <input type="hidden" name="kb_id" id="kbDeleteId">
</form>

<!-- Edit KB Form -->
<form method="POST" action="admin-dashboard.php?page=settings" id="kbEditForm" style="display:none;">
  <input type="hidden" name="update_kb_item" value="1">
  <input type="hidden" name="kb_id" id="kbEditId">
  <input type="hidden" name="kb_question" id="kbEditQuestion">
  <input type="hidden" name="kb_answer" id="kbEditAnswer">
  <input type="hidden" name="kb_keywords" id="kbEditKeywords">
</form>

<!-- KB EDIT MODAL -->
<div id="kbEditModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:99990; align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:14px; padding:28px 28px 24px; width:100%; max-width:540px; margin:16px; box-shadow:0 16px 48px rgba(0,0,0,0.22); position:relative;">
    <button type="button" onclick="closeKbEditModal()" style="position:absolute; top:14px; right:16px; background:none; border:none; font-size:22px; cursor:pointer; color:#9CA3AF; line-height:1;">&times;</button>
    <h4 style="font-size:16px; font-weight:700; color:#1A1040; margin:0 0 18px;">✏️ Edit Knowledge Base Q&amp;A</h4>
    <div style="display:flex; flex-direction:column; gap:14px;">
      <div>
        <label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:5px;">User Question</label>
        <input type="text" id="kbModalQuestion" required
          style="width:100%; padding:9px 12px; border:1.5px solid #D1D5DB; border-radius:8px; font-size:13.5px; outline:none; font-family:inherit; box-sizing:border-box;">
      </div>
      <div>
        <label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:5px;">AI Bot Answer</label>
        <textarea id="kbModalAnswer" required rows="4"
          style="width:100%; padding:9px 12px; border:1.5px solid #D1D5DB; border-radius:8px; font-size:13.5px; outline:none; font-family:inherit; resize:vertical; box-sizing:border-box;"></textarea>
      </div>
      <div>
        <label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:5px;">Matching Keywords (comma-separated)</label>
        <input type="text" id="kbModalKeywords"
          style="width:100%; padding:9px 12px; border:1.5px solid #D1D5DB; border-radius:8px; font-size:13.5px; outline:none; font-family:inherit; box-sizing:border-box;"
          placeholder="e.g. report, dues, payment, edit">
      </div>
      <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:4px;">
        <button type="button" onclick="closeKbEditModal()" style="background:#F3F4F6; color:#374151; border:1px solid #D1D5DB; border-radius:8px; padding:9px 18px; font-size:13px; font-weight:600; cursor:pointer;">Cancel</button>
        <button type="button" onclick="submitKbEdit()" style="background:#2563EB; color:#fff; border:none; border-radius:8px; padding:9px 22px; font-size:13px; font-weight:600; cursor:pointer;">Save Changes</button>
      </div>
    </div>
  </div>
</div>
<script>
function openKbEditModal(id, question, answer, keywords) {
    document.getElementById('kbEditId').value        = id;
    document.getElementById('kbModalQuestion').value = question;
    document.getElementById('kbModalAnswer').value   = answer;
    document.getElementById('kbModalKeywords').value = keywords || '';
    document.getElementById('kbEditModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function submitKbEdit() {
    let q = document.getElementById('kbModalQuestion').value.trim();
    let a = document.getElementById('kbModalAnswer').value.trim();
    if (!q || !a) { alert('Question and Answer are required.'); return; }
    document.getElementById('kbEditQuestion').value = q;
    document.getElementById('kbEditAnswer').value   = a;
    document.getElementById('kbEditKeywords').value = document.getElementById('kbModalKeywords').value.trim();
    document.getElementById('kbEditForm').submit();
}
function closeKbEditModal() {
    document.getElementById('kbEditModal').style.display = 'none';
    document.body.style.overflow = '';
}
function confirmDeleteKb(id) {
    if (!confirm('Delete this Knowledge Base Q&A item?')) return;
    document.getElementById('kbDeleteId').value = id;
    document.getElementById('kbDeleteForm').submit();
}
document.getElementById('kbEditModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeKbEditModal();
});
</script>

    <?php elseif ($page === 'settings' && !$hasSiteSettings): ?>
      <div class="card section-card"><div class="p-4 text-center"><p class="text-muted">The <code>site_settings</code> table does not exist yet.</p><a href="migrate.php" class="btn btn-primary btn-sm mt-2">Run migrate to enable Site Settings</a></div></div>
    <?php endif; ?>

  </main>
</div>

<script>
function switchTab(type) {
    document.getElementById('tabChartered').style.display = type === 'chartered' ? 'block' : 'none';
    document.getElementById('tabUnchartered').style.display = type === 'unchartered' ? 'block' : 'none';
    document.getElementById('tabBtnChartered').classList.toggle('active', type === 'chartered');
    document.getElementById('tabBtnUnchartered').classList.toggle('active', type === 'unchartered');
}
function toggleLockInput(id, isUnlocked) {
    const input = document.getElementById('rate_input_' + id);
    const badge = document.getElementById('badge_lock_' + id);
    const label = document.querySelector(`[onchange="toggleLockInput(${id}, this.checked)"] + label`);
    if (isUnlocked) {
        input.disabled = false;
        if (badge) { badge.textContent = ''; badge.style.display = 'none'; }
        if (label) label.textContent = 'Unlocked';
    } else {
        input.disabled = true;
        if (badge) { badge.textContent = '🔒 Locked'; badge.style.display = ''; badge.className = 'pill pill-locked ms-2'; }
        if (label) label.textContent = 'Locked';
    }
}
function openResetModal(userId, userName) {
    document.getElementById('resetTargetId').value = userId;
    document.getElementById('resetModalName').textContent = 'Resetting password for: ' + userName;
    document.getElementById('resetPwInput').value = '';
    document.getElementById('resetModal').classList.add('open');
}
function closeResetModal() {
    document.getElementById('resetModal').classList.remove('open');
}
document.getElementById('resetModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeResetModal();
});
</script>
<?php require_once __DIR__ . '/includes/chat_widget.php'; ?>
</body>
</html>

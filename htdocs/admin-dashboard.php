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

// Current month/year
$curMonth = (int)date('n');
$curYear  = (int)date('Y');

// Fetch Stats
$total_churches_count = (int)$db->query("SELECT COUNT(*) FROM churches")->fetchColumn();
$total_zones_count = (int)$db->query("SELECT COUNT(*) FROM zones")->fetchColumn();

$stmt_submitted = $db->prepare("SELECT COUNT(*) FROM church_financial_reports WHERE report_month = ? AND report_year = ? AND status = 'submitted'");
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
            'contact_email','contact_phone','how_title','hero_video_url','showcase_video_url'
        ];
        $upd = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
                              VALUES (?, ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()");
        foreach ($keys as $k) {
            $val = trim($_POST[$k] ?? '');
            $upd->execute([$k, $val, $_SESSION['user_id']]);
        }
        
        $uploadFileDir = __DIR__ . '/uploads/';
        if (!is_dir($uploadFileDir)) {
            mkdir($uploadFileDir, 0777, true);
        }
        $allowedfileExtensions = ['mp4', 'webm', 'ogg', 'mov'];

        // Handle Background Hero Video Upload
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

        // Handle Showcase Video Upload
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

// Handle POST: User Management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user_status'])) {
    $targetId  = (int)($_POST['target_user_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    if ($targetId > 0 && in_array($newStatus, ['active','pending','suspended'])) {
        if ($targetId === (int)$_SESSION['user_id']) {
            $errorMsg = 'You cannot change your own account status.';
        } else {
            $upd = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newStatus, $targetId]);
            $successMsg = 'User status updated to "' . ucfirst($newStatus) . '".';
        }
    }
}

// Handle POST: Reset User Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_user_password'])) {
    $targetId = (int)($_POST['target_user_id'] ?? 0);
    $newPass  = trim($_POST['new_user_password'] ?? '');
    if ($targetId > 0 && strlen($newPass) >= 6) {
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $upd  = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
        $upd->execute([$hash, $targetId]);
        $successMsg = 'Password reset successfully!';
    } else {
        $errorMsg = 'Password must be at least 6 characters.';
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
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
    <div class="page-head" style="display:flex;justify-content:space-between;align-items:center;">
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
          <p class="sub">View registered churches, zones, and submission status across the reporting network.</p>
        <?php endif; ?>
      </div>
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
        <div class="section-card-head"><h3>Quick Actions</h3></div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;padding:8px 0;">
          <a href="admin-dashboard.php?page=users" class="btn btn-outline btn-sm">Manage Users</a>
          <a href="admin-dashboard.php?page=dues" class="btn btn-outline btn-sm">Manage Dues</a>
          <?php if ($hasSiteSettings): ?>
          <a href="admin-dashboard.php?page=settings" class="btn btn-outline btn-sm">Edit Site Content</a>
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
                    <?php if (!$isSelf): ?>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                      <?php if ($u['status'] !== 'active'): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="update_user_status" value="1"><input type="hidden" name="target_user_id" value="<?= $u['id'] ?>"><input type="hidden" name="new_status" value="active"><button type="submit" class="btn-xs btn-activate">Activate</button></form>
                      <?php endif; ?>
                      <?php if ($u['status'] !== 'suspended'): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="update_user_status" value="1"><input type="hidden" name="target_user_id" value="<?= $u['id'] ?>"><input type="hidden" name="new_status" value="suspended"><button type="submit" class="btn-xs btn-suspend" onclick="return confirm('Suspend this user?')">Suspend</button></form>
                      <?php endif; ?>
                      <button class="btn-xs btn-reset" onclick="openResetModal(<?= $u['id'] ?>, '<?= h(addslashes($u['full_name'])) ?>')">Reset PW</button>
                    </div>
                    <?php else: ?><span style="font-size:11px;color:var(--ink-faint);">You</span><?php endif; ?>
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
      <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="update_site_settings" value="1">
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
                  <small style="color:var(--ink-faint);font-size:11px;display:block;margin-top:4px;">Paste a direct link from a fast CDN (e.g. Cloudinary, Vercel, BunnyCDN, Dropbox) to load animation instantly and save free hosting bandwidth.</small>
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
          <button type="submit" class="btn btn-primary btn-sm px-4 py-2 fw-semibold">Save All Settings</button>
        </div>
      </form>
    <?php elseif ($page === 'settings' && !$hasSiteSettings): ?>
      <div class="card section-card"><div class="p-4 text-center"><p class="text-muted">The <code>site_settings</code> table does not exist yet.</p><a href="migrate.php" class="btn btn-primary btn-sm mt-2">Run migrate.php to enable Site Settings</a></div></div>
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
</body>
</html>

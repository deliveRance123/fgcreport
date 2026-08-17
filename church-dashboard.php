<?php
/**
 * church-dashboard.php — Local Church Dashboard
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();
requireLogin();
requireRole('church_admin');

$db = db();
$churchId = currentChurchId();

if (!$churchId) {
    // Auto-create a default church record if missing for this user
    try {
        $stmtIns = $db->prepare("INSERT INTO churches (name, district, address, pastor_name, pastor_address, church_type, created_by) VALUES ('My Local Church', 'Lagos District', '123 Church Rd', ?, 'Pastor House', 'unchartered', ?)");
        $stmtIns->execute([$_SESSION['full_name'] ?? 'Pastor', $_SESSION['user_id']]);
        $churchId = (int)$db->lastInsertId();
        $_SESSION['church_id'] = $churchId;
    } catch (Exception $e) {
        die("Error loading church details: " . htmlspecialchars($e->getMessage()));
    }
}

// Fetch Church Info
$stmt = $db->prepare("SELECT * FROM churches WHERE id = ?");
$stmt->execute([$churchId]);
$church = $stmt->fetch();
if (!$church) {
    die("Error: Church details not found.");
}

// Fetch Admin User Info
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

// Delete draft report handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_report'])) {
    $delReportId = (int)$_POST['report_id'];
    if ($delReportId > 0) {
        $stmt = $db->prepare("SELECT * FROM church_financial_reports WHERE id = ? AND church_id = ?");
        $stmt->execute([$delReportId, $churchId]);
        $rep = $stmt->fetch();
        if ($rep && $rep['status'] === 'draft') {
            $db->prepare("DELETE FROM church_expense_items WHERE report_id = ?")->execute([$delReportId]);
            $db->prepare("DELETE FROM church_spiritual_reports WHERE church_id = ? AND report_month = ? AND report_year = ?")->execute([$churchId, $rep['report_month'], $rep['report_year']]);
            $db->prepare("DELETE FROM church_financial_reports WHERE id = ?")->execute([$delReportId]);
            $successMsg = "Draft report for " . monthName($rep['report_month']) . " " . $rep['report_year'] . " has been deleted successfully.";
        } else {
            $errorMsg = "Only draft reports can be deleted.";
        }
    }
}

// Start new report handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_report'])) {
    $newMonth = (int)$_POST['report_month'];
    $newYear = (int)$_POST['report_year'];
    $maxAllowYear = (int)date('Y') + 10;
    if ($newMonth >= 1 && $newMonth <= 12 && $newYear >= 2020 && $newYear <= $maxAllowYear) {
        header("Location: church_report.php?month={$newMonth}&year={$newYear}");
        exit;
    }
}

// Fetch All Reports for this church
$stmt = $db->prepare("
    SELECT f.*, s.total_new_comers, s.membership_total, s.credential_workers_data
    FROM church_financial_reports f
    LEFT JOIN church_spiritual_reports s 
        ON f.church_id = s.church_id 
        AND f.report_month = s.report_month 
        AND f.report_year = s.report_year
    WHERE f.church_id = ?
    ORDER BY f.report_year DESC, f.report_month DESC
");
$stmt->execute([$churchId]);
$reports = $stmt->fetchAll();

// Current/Latest report stats for the top summary cards
$latestReport = $reports[0] ?? null;
$total_receipts_current = $latestReport ? formatNaira((float)($latestReport['total_receipts'] ?? 0)) : '0.00';
$total_dues_current = $latestReport ? formatNaira((float)($latestReport['payable'] ?? 0)) : '0.00';

$newcomers_count_current = 0;
$total_membership_count = 0;

if ($latestReport) {
    // New comers
    $nc = (int)($latestReport['total_new_comers'] ?? 0);
    if ($nc === 0 && !empty($latestReport['credential_workers_data'])) {
        $cwd = json_decode($latestReport['credential_workers_data'], true) ?: [];
        $nc = (int)($cwd['new_comers'] ?? $cwd['total_new_comers'] ?? 0);
    }
    $newcomers_count_current = $nc;

    // Membership total
    $mem = (int)($latestReport['membership_total'] ?? 0);
    if ($mem === 0 && !empty($latestReport['credential_workers_data'])) {
        $cwd = json_decode($latestReport['credential_workers_data'], true) ?: [];
        $mem = (int)($cwd['membership_total'] ?? $cwd['membership'] ?? 0);
    }
    $total_membership_count = $mem;
}

$current_report_month = $latestReport ? monthName($latestReport['report_month']) . ' ' . $latestReport['report_year'] : date('F Y');
$current_report_status = $latestReport ? ucfirst($latestReport['status']) : 'No reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($church['name']); ?> — Church Dashboard Foursquare Reports</title>
<link rel="icon" type="image/jpeg" href="assets/logo.jpg">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/dashboard.css">
<style>
  /* Church Dashboard — navy sidebar override */
  aside.sidebar {
    background: #1A1040 !important;
    color: rgba(255,255,255,0.75) !important;
  }
  .sidebar .brand-name { color: #fff !important; }
  .sidebar .nav-label { color: rgba(255,255,255,0.30) !important; }
  .sidebar .nav-item { color: rgba(255,255,255,0.65) !important; }
  .sidebar .nav-item:hover { background: rgba(255,255,255,0.08) !important; color: #fff !important; }
  .sidebar .nav-item.active { background: #E31E24 !important; color: #fff !important; box-shadow: 0 2px 8px rgba(227,30,36,0.30) !important; }
  .sidebar .u-name { color: #fff !important; }
  .sidebar .u-role { color: rgba(255,255,255,0.45) !important; }


  .portal-tag {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 11px; border-radius: 99px;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
    background: rgba(227,30,36,0.18) !important; color: #ff8a8a !important;
    border: 1px solid rgba(227,30,36,0.25); margin-bottom: 20px;
  }
  .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }

  /* Pills */
  .pill { font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 99px; }
  .pill-draft    { background: #FFF7ED; color: #EA580C; }
  .pill-submitted{ background: #ECFDF5; color: #059669; }
  .pill-locked   { background: #EDE9FE; color: #6D28D9; }

  .start-report-box {
    display: flex; flex-wrap: wrap; gap: 10px;
    background: #fff; border: 1px solid #E4E4E7;
    padding: 18px; border-radius: 12px; align-items: flex-end;
  }
  .start-report-box > div { min-width: 120px; flex: 1; }

  /* Notification panel */
  .notif-panel {
    position: fixed; top: 70px; right: 24px; width: 320px;
    background: #fff; border: 1px solid #E4E4E7; border-radius: 14px;
    box-shadow: 0 10px 30px rgba(26,16,64,0.12); z-index: 3000;
    display: none;
  }
  .notif-panel.open { display: block; }
  .notif-panel-head {
    padding: 16px 18px 12px; border-bottom: 1px solid #F4F4F5;
    font-family: 'Outfit', sans-serif; font-size: 14px; font-weight: 700;
    color: #1A1040; display: flex; align-items: center; justify-content: space-between;
  }
  .notif-panel-head span { font-size: 11px; font-weight: 600; color: #E31E24; cursor: pointer; }
  .notif-item {
    padding: 12px 18px; border-bottom: 1px solid #F4F4F5;
    display: flex; gap: 12px; align-items: flex-start; transition: background 0.12s;
    cursor: pointer;
  }
  .notif-item:hover { background: #FAFAFA; }
  .notif-item:last-child { border-bottom: none; }
  .notif-dot { width: 8px; height: 8px; border-radius: 50%; background: #E31E24; flex-shrink: 0; margin-top: 5px; }
  .notif-dot.read { background: #E4E4E7; }
  .notif-text { font-size: 13px; color: #3F3F46; line-height: 1.5; }
  .notif-time { font-size: 11px; color: #A1A1AA; margin-top: 2px; }
  .notif-empty { padding: 32px 18px; text-align: center; font-size: 13px; color: #A1A1AA; }

  /* Mobile Responsiveness for Subscription & Report Creation Cards */
  @media (max-width: 767px) {
    .sub-banner-card {
      padding: 14px 14px !important;
      border-radius: 12px !important;
      border-left-width: 4px !important;
      margin-top: 10px !important;
    }
    .sub-banner-title {
      font-size: 13.5px !important;
      margin-bottom: 3px !important;
    }
    .sub-banner-sub {
      font-size: 11.5px !important;
      line-height: 1.35 !important;
    }
    .sub-pay-btn-wrap {
      width: 100% !important;
      margin-top: 8px !important;
      max-width: 100% !important;
    }
    .sub-pay-btn {
      width: 100% !important;
      max-width: 100% !important;
      padding: 10px 8px !important;
      font-size: 12px !important;
      white-space: normal !important;
      word-break: break-word !important;
      overflow-wrap: break-word !important;
      line-height: 1.25 !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      text-align: center !important;
      box-sizing: border-box !important;
    }
    .create-report-card {
      padding: 16px 14px !important;
      border-radius: 12px !important;
    }
    .create-report-inner {
      padding: 12px !important;
      gap: 12px !important;
      flex-direction: column !important;
    }
    .create-report-col {
      min-width: 100% !important;
      width: 100% !important;
      flex: none !important;
    }
    .create-report-btn {
      width: 100% !important;
      font-size: 13px !important;
      padding: 11px 12px !important;
      white-space: normal !important;
      line-height: 1.3 !important;
      height: auto !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      text-align: center !important;
    }
  }
</style>
</head>
<body>

<!-- Mobile navigation header & backdrop overlay -->
<div class="mobile-header">
  <a href="church-dashboard.php" class="mobile-brand">
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
  <aside class="sidebar" id="churchSidebar">
    <!-- Mobile close button (X) — only shown on mobile -->
    <button class="sidebar-close-btn" aria-label="Close navigation" onclick="document.body.classList.remove('mobile-open');">&times;</button>
    <!-- Brand -->
    <a href="church-dashboard.php" class="brand" style="margin-bottom:20px;">
      <img src="assets/logo.jpg" alt="Logo">
      <div>
        <span class="brand-name">Foursquare</span>
        <small class="brand-name" style="font-size:9px;opacity:.5;display:block;font-family:'Inter',sans-serif;font-weight:500;letter-spacing:.04em;text-transform:uppercase;">Reports</small>
      </div>
    </a>


    <span class="portal-tag" style="background:rgba(239,35,28,0.12); color:var(--red);">
      <span class="dot" style="background:var(--red);"></span>
      <?= h($church['church_type']); ?>
    </span>

    <div class="nav-section">
      <div class="nav-label">Overview</div>
      <a class="nav-item active" href="#">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
        Dashboard
      </a>
    </div>

    <div class="nav-section">
      <div class="nav-label">Monthly Reports</div>
      <?php if ($latestReport): ?>
        <a class="nav-item" href="church_report.php?month=<?= $latestReport['report_month'] ?>&year=<?= $latestReport['report_year'] ?>">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/></svg>
          Current Report
        </a>
      <?php endif; ?>
      <a class="nav-item" href="index.php">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
        View Public Portal
      </a>
    </div>

    <div class="sidebar-footer" style="display:flex; flex-direction:column; gap:6px;">
      <a class="nav-item" href="profile.php" style="margin-bottom:0;">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>
        My Profile
      </a>
      <a href="logout.php" class="logout-btn" style="width:100%; text-align:center; margin-top:2px;">Log out</a>
    </div>
  </aside>

  <main class="main">
    <div class="page-head">
      <div>
        <h1>Welcome back, <?= h($user ? explode(' ', $user['full_name'])[0] : 'Pastor') ?>!</h1>
        <p class="sub"><?= h($church['name']); ?> &nbsp;·&nbsp; <?= h($church['district']); ?> District &nbsp;·&nbsp; <?= h($church['church_type']); ?> Church</p>
      </div>
    </div>

    <?php
    $paySettings = getPaymentSettings();
    $subStatus = getUserTrialAndSubStatus($_SESSION['user_id']);
    $canCreateReport = canUserCreateReport($_SESSION['user_id']);
    $subAmount = (float)($paySettings['monthly_sub_amount'] ?? 5000);
    if (($paySettings['payment_enabled'] ?? '0') === '1'):
    ?>
    <!-- SUBSCRIPTION & TRIAL BADGE CARD -->
    <div class="card mb-4 border-0 shadow-sm sub-banner-card" style="padding: 20px 24px !important; border-radius:14px !important; background: <?= $subStatus['is_active'] ? ($subStatus['in_trial'] ? 'linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%)' : 'linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%)') : 'linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%)' ?> !important; border-left: 5px solid <?= $subStatus['is_active'] ? ($subStatus['in_trial'] ? '#2563EB' : '#10B981') : '#EF4444' ?> !important;">
      <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap flex-md-nowrap" style="width:100%;">
        <div style="flex:1; min-width:0;">
          <h6 class="fw-bold mb-1 sub-banner-title" style="color: <?= $subStatus['is_active'] ? ($subStatus['in_trial'] ? '#1E40AF' : '#065F46') : '#991B1B' ?>; font-size:15px; margin:0 0 4px 0;">
            <?php
            $_icoGift  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;vertical-align:-2px;"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5" rx="1"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>';
            $_icoShield= '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;vertical-align:-2px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>';
            $_icoWarn  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;vertical-align:-2px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
            
            if ($subStatus['in_trial']) {
                echo $_icoGift . h($subStatus['trial_title'] ?? 'Free Trial Active');
            } elseif ($subStatus['is_active']) {
                echo $_icoShield . 'Active 1-Year Annual Subscription';
            } else {
                echo $_icoWarn . h($subStatus['trial_title'] ?? 'Annual Portal Subscription Required');
            }
            ?>
          </h6>
          <p class="mb-0 small sub-banner-sub" style="color: <?= $subStatus['is_active'] ? ($subStatus['in_trial'] ? '#1E3A8A' : '#047857') : '#7F1D1D' ?>; margin:0;">
            <?= h($subStatus['status_label']) ?>
          </p>
        </div>
        <?php if (!$subStatus['is_active'] && !empty($paySettings['payment_public_key'])): ?>
        <div class="sub-pay-btn-wrap" style="flex-shrink:0;">
          <button type="button" class="btn btn-danger btn-sm fw-bold shadow-sm sub-pay-btn" style="border-radius:9px; padding:10px 18px; background:linear-gradient(135deg, #E31E24 0%, #B91C1C 100%); border:none;" onclick="openFgcCheckoutModal('subscription', 'Annual Portal Subscription (1-Year Access)', <?= $subAmount ?>)">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px;vertical-align:-2px;"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Pay 1-Year Subscription (₦<?= formatNaira($subAmount) ?>)
          </button>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Start New Report Dropdown Form -->
    <div class="card mb-4 create-report-card" style="padding:28px 30px; border-radius:14px; border:1px solid #E5E7EB; background:#FFFFFF; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
      <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#E31E24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
          <h4 class="fw-bold m-0" style="font-size:15px; color:#1A1040; text-transform:uppercase; letter-spacing:0.05em; display:inline-block;">Start a New Report</h4>
        </div>
        <?php if (!$canCreateReport): ?>
          <span class="badge bg-danger text-uppercase fw-bold d-inline-flex align-items-center gap-1" style="font-size:11px; padding:6px 12px; border-radius:8px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Subscription Locked
          </span>
        <?php endif; ?>
      </div>

      <form method="POST" action="" class="start-report-box pt-1">
        <input type="hidden" name="new_report" value="1">
        <div class="create-report-inner" style="padding:16px; border:1px solid #E5E7EB; border-radius:12px; background:#FAFAFA; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; width:100%;">
          <div class="create-report-col" style="flex:1; min-width:180px;">
            <label style="font-size:13px; font-weight:700; color:#1A1040; display:block; margin-bottom:8px; letter-spacing:0.02em;">Month</label>
            <select name="report_month" class="form-select" <?= !$canCreateReport ? 'disabled' : 'required' ?> style="font-size:14px; height:46px; font-weight:600; color:#1A1040; border:1.5px solid #D1D5DB; border-radius:9px; padding:0 14px; background:#fff;">
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>><?= monthName($m) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="create-report-col" style="flex:1; min-width:140px;">
            <label style="font-size:13px; font-weight:700; color:#1A1040; display:block; margin-bottom:8px; letter-spacing:0.02em;">Year</label>
            <select name="report_year" class="form-select" <?= !$canCreateReport ? 'disabled' : 'required' ?> style="font-size:14px; height:46px; font-weight:600; color:#1A1040; border:1.5px solid #D1D5DB; border-radius:9px; padding:0 14px; background:#fff;">
              <?php 
              $currentYear = (int)date('Y');
              $startYear = 2020;
              $endYear = $currentYear + 2;
              for ($y = $endYear; $y >= $startYear; $y--): 
              ?>
                <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary px-4 fw-semibold create-report-btn" <?= !$canCreateReport ? 'disabled style="height:46px; font-size:14px; border-radius:9px; background:#60A5FA; border:none; opacity:0.85; cursor:not-allowed;"' : 'style="height:46px; font-size:14px; border-radius:9px;"' ?>>
            <?php if (!$canCreateReport): ?>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:5px;vertical-align:-2px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              Create Report (Subscription Required)
            <?php else: ?>
              Create Report
            <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="stat-grid">
      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#FDEBE9;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#E31E24" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16M18 4v16M6 4l12 16M4 10h16M4 14h16"/></svg>
          </div>
          <?php if ($latestReport): ?>
            <span class="pill pill-<?= $latestReport['status'] ?>"><?= ucfirst($latestReport['status']) ?></span>
          <?php endif; ?>
        </div>
        <div class="stat-value">₦<?= $total_receipts_current; ?></div>
        <div class="stat-label">Total receipts <?= $current_report_month; ?></div>
      </div>

      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#ECE9F7;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#2E1B6A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16M18 4v16M6 4l12 16M4 10h16M4 14h16"/></svg>
          </div>
        </div>
        <div class="stat-value">₦<?= $total_dues_current; ?></div>
        <div class="stat-label">Total dues payable</div>
      </div>

      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#FEF3C7;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="17" y1="11" x2="23" y2="11"/></svg>
          </div>
        </div>
        <div class="stat-value"><?= $newcomers_count_current; ?></div>
        <div class="stat-label">New comers this month</div>
      </div>

      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#F3EAF0;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#8B5CF6" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-3-3.87"/><path d="M9 21v-2a4 4 0 0 0-4-4H3a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
        </div>
        <div class="stat-value"><?= $total_membership_count; ?></div>
        <div class="stat-label">Total church membership</div>
      </div>
    </div>

    <!-- Recent Reports Section -->
    <div class="card section-card">
      <div class="section-card-head">
        <h3>Recent Monthly Reports</h3>
      </div>
      <?php if (empty($reports)): ?>
        <p class="text-muted p-4 text-center">No reports submitted yet. Use the tool above to start your first report.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr><th>Month/Year</th><th>Total Receipts</th><th>Dues Payable</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $r): ?>
              <tr>
                <td class="strong"><?= monthName($r['report_month']) . ' ' . $r['report_year']; ?></td>
                <td>₦<?= formatNaira($r['total_receipts']); ?></td>
                <td>₦<?= formatNaira($r['payable']); ?></td>
                <td><span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']); ?></span></td>
                <td>
                  <div class="d-flex gap-2 align-items-center">
                    <a href="church_report.php?month=<?= $r['report_month'] ?>&year=<?= $r['report_year'] ?>" class="btn btn-outline btn-sm">
                      <?= $r['status'] === 'submitted' ? 'View Report' : 'Edit Draft' ?>
                    </a>
                    <?php if ($r['status'] === 'draft'): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this draft report?');">
                      <input type="hidden" name="delete_report" value="1">
                      <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm py-1 px-2" style="font-size:12px;">Delete Draft</button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<?php require_once __DIR__ . '/includes/payment_modal.php'; ?>

<script>
  (function () {
    var toggle = document.getElementById('churchNavToggle');
    var sidebar = document.getElementById('churchSidebar');
    if (!toggle || !sidebar) return;
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('mobile-open');
      toggle.classList.toggle('open');
    });
    // Close sidebar when a nav link is tapped on mobile
    sidebar.querySelectorAll('.nav-item').forEach(function (link) {
      link.addEventListener('click', function () {
        if (window.innerWidth <= 767) {
          sidebar.classList.remove('mobile-open');
          toggle.classList.remove('open');
        }
      });
    });
  })();
</script>
<?php require_once __DIR__ . '/includes/chat_widget.php'; ?>
</body>
</html>

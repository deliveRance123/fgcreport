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
    die("Error: No church is associated with this administrator account.");
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

// Start new report handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_report'])) {
    $newMonth = (int)$_POST['report_month'];
    $newYear = (int)$_POST['report_year'];
    if ($newMonth >= 1 && $newMonth <= 12 && $newYear >= 2020 && $newYear <= 2040) {
        header("Location: church_report.php?month={$newMonth}&year={$newYear}");
        exit;
    }
}

// Fetch All Reports for this church
$stmt = $db->prepare("
    SELECT f.*, s.total_new_comers, s.membership_total
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
$total_receipts_current = $latestReport ? formatNaira($latestReport['total_receipts']) : '0.00';
$total_dues_current = $latestReport ? formatNaira($latestReport['payable']) : '0.00';
$newcomers_count_current = $latestReport ? (int)$latestReport['total_new_comers'] : 0;
$total_membership_count = $latestReport ? (int)$latestReport['membership_total'] : 0;

$current_report_month = $latestReport ? monthName($latestReport['report_month']) . ' ' . $latestReport['report_year'] : date('F Y');
$current_report_status = $latestReport ? ucfirst($latestReport['status']) : 'No reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($church['name']); ?> — Church Dashboard | Foursquare Reports</title>
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
        <h1>Welcome back, <?= h($user ? explode(' ', $user['full_name'])[0] : 'Pastor'); ?></h1>
        <p class="sub"><?= h($church['name']); ?> &nbsp;·&nbsp; <?= h($church['district']); ?> District &nbsp;·&nbsp; <?= ucfirst($church['church_type']); ?> Church</p>
      </div>
    </div>

    <!-- Start New Report Dropdown Form -->
    <div class="card mb-4 p-4">
      <h4 class="fw-bold mb-3" style="font-size:15px; text-transform:uppercase; letter-spacing:0.04em;">Start a New Report</h4>
      <form method="POST" action="" class="start-report-box">
        <input type="hidden" name="new_report" value="1">
        <div style="flex:1;">
          <label style="font-size:13px; font-weight:700; color:#1A1040; display:block; margin-bottom:6px; letter-spacing:0.02em;">Month</label>
          <select name="report_month" class="form-select" required style="font-size:15px; height:44px; font-weight:600; color:#1A1040; border:2px solid #C7C5D0; border-radius:8px; padding:0 12px;">
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>><?= monthName($m) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div style="flex:1;">
          <label style="font-size:13px; font-weight:700; color:#1A1040; display:block; margin-bottom:6px; letter-spacing:0.02em;">Year</label>
          <select name="report_year" class="form-select" required style="font-size:15px; height:44px; font-weight:600; color:#1A1040; border:2px solid #C7C5D0; border-radius:8px; padding:0 12px;">
            <?php for ($y = date('Y') + 1; $y >= 2024; $y--): ?>
              <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary px-4 fw-semibold" style="height:44px; font-size:14px; align-self:flex-end;">Create Report</button>
      </form>
    </div>

    <div class="stat-grid">
      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#FDEBE9;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
          </div>
          <?php if ($latestReport): ?>
            <span class="pill pill-<?= $latestReport['status'] ?>"><?= ucfirst($latestReport['status']) ?></span>
          <?php endif; ?>
        </div>
        <div class="stat-value">₦<?= $total_receipts_current; ?></div>
        <div class="stat-label">Total receipts — <?= $current_report_month; ?></div>
      </div>

      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#ECE9F7;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--purple)" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
          </div>
        </div>
        <div class="stat-value">₦<?= $total_dues_current; ?></div>
        <div class="stat-label">Total dues payable</div>
      </div>

      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#FDF1DC;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--gold-deep)" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>
          </div>
        </div>
        <div class="stat-value"><?= $newcomers_count_current; ?></div>
        <div class="stat-label">New comers this month</div>
      </div>

      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#F3EAF0;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--magenta)" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18"/></svg>
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
                  <a href="church_report.php?month=<?= $r['report_month'] ?>&year=<?= $r['report_year'] ?>" class="btn btn-outline btn-sm">
                    <?= $r['status'] === 'submitted' ? 'View Report' : 'Edit Draft' ?>
                  </a>
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
</body>
</html>

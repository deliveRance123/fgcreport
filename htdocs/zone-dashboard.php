<?php
/**
 * zone-dashboard.php — Zonal Admin Dashboard
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();
requireLogin();
requireRole('zonal_admin');

$db = db();
$zoneId = currentZoneId();

if (!$zoneId) {
    die("Error: No zone is associated with this administrator account.");
}

// Fetch Zone Info
$stmt = $db->prepare("SELECT * FROM zones WHERE id = ?");
$stmt->execute([$zoneId]);
$zone = $stmt->fetch();
if (!$zone) {
    die("Error: Zone details not found.");
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

// Selected month/year for status view
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Handle actions (Add / Delete Church)
$successMsg = '';
$errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_church') {
        $cName = trim($_POST['church_name'] ?? '');
        if (!empty($cName)) {
            try {
                // Get next display order
                $order = (int)$db->query("SELECT MAX(display_order) FROM zone_churches WHERE zone_id = $zoneId")->fetchColumn() + 1;
                $stmt = $db->prepare("INSERT INTO zone_churches (zone_id, church_name, display_order) VALUES (?, ?, ?)");
                $stmt->execute([$zoneId, $cName, $order]);
                $successMsg = "Church '$cName' added to zone successfully!";
            } catch (Exception $e) {
                $errorMsg = "Error adding church: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_church') {
        $cId = (int)$_POST['church_row_id'];
        if ($cId > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM zone_churches WHERE id = ? AND zone_id = ?");
                $stmt->execute([$cId, $zoneId]);
                $successMsg = "Church removed from zone.";
            } catch (Exception $e) {
                $errorMsg = "Error removing church: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['new_zonal_report'])) {
        $newMonth = (int)$_POST['report_month'];
        $newYear = (int)$_POST['report_year'];
        header("Location: zonal_reports.php?month={$newMonth}&year={$newYear}");
        exit;
    }
}

// Fetch Churches in Zone
$stmt = $db->prepare("SELECT * FROM zone_churches WHERE zone_id = ? ORDER BY display_order ASC");
$stmt->execute([$zoneId]);
$zoneChurches = $stmt->fetchAll();
$church_count = count($zoneChurches);

// Calculate totals for the selected month/year based on submitted local church reports
// We match name case-insensitively
$zone_newcomers_total = 0;
$zone_avg_worship_attendance = 0;
$zone_new_members_total = 0;
$matchingSubmitsCount = 0;

$churchesStatusList = [];
foreach ($zoneChurches as $zc) {
    $cName = $zc['church_name'];
    
    // Find matching church account
    $stmt = $db->prepare("SELECT id FROM churches WHERE name LIKE ? LIMIT 1");
    $stmt->execute(["%{$cName}%"]);
    $match = $stmt->fetch();
    
    $status = 'No Report';
    $receipts = 0;

    if ($match) {
        // Check report status
        $stmt = $db->prepare("SELECT * FROM church_financial_reports WHERE church_id = ? AND report_month = ? AND report_year = ?");
        $stmt->execute([$match['id'], $selectedMonth, $selectedYear]);
        $fin = $stmt->fetch();
        
        if ($fin) {
            $status = ucfirst($fin['status']); // 'Draft' or 'Submitted'
            if ($fin['status'] === 'submitted') {
                $matchingSubmitsCount++;
                
                // Fetch spiritual details
                $stmt = $db->prepare("SELECT * FROM church_spiritual_reports WHERE church_id = ? AND report_month = ? AND report_year = ?");
                $stmt->execute([$match['id'], $selectedMonth, $selectedYear]);
                $sp = $stmt->fetch();
                
                if ($sp) {
                    $spDetail = json_decode($sp['credential_workers_data'] ?? '{}', true);
                    $zone_newcomers_total += (int)($spDetail['new_comers'] ?? 0);
                    $zone_avg_worship_attendance += $sp['sun_worship_total'];
                    $zone_new_members_total += $sp['intake_total'];
                }
            }
        }
    }
    
    $churchesStatusList[] = [
        'id' => $zc['id'],
        'name' => $cName,
        'status' => $status
    ];
}

if ($matchingSubmitsCount > 0) {
    $zone_avg_worship_attendance = round($zone_avg_worship_attendance / $matchingSubmitsCount);
}

// Fetch recent zonal reports submitted or drafted
$stmt = $db->prepare("SELECT * FROM zonal_reports WHERE zone_id = ? ORDER BY report_year DESC, report_month DESC LIMIT 10");
$stmt->execute([$zoneId]);
$zonalReports = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($zone['zone_name']); ?> — Zone Dashboard | Foursquare Reports</title>
<link rel="icon" type="image/jpeg" href="assets/logo.jpg">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/dashboard.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
  /* Zone Dashboard — navy sidebar */
  .shell { display: flex; min-height: 100vh; background: #FAF9F6; }
  aside.sidebar {
    background: #1A1040 !important;
    color: rgba(255,255,255,0.75) !important;
  }
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
  .portal-tag { display:inline-flex; align-items:center; gap:6px; padding:5px 11px; border-radius:99px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; background:rgba(227,30,36,0.18) !important; color:#ff8a8a !important; border:1px solid rgba(227,30,36,0.25); margin-bottom:20px; }
  .dot { width:6px; height:6px; border-radius:50%; display:inline-block; }
  .pill { font-size:11px; font-weight:700; padding:3px 9px; border-radius:99px; display:inline-block; }
  .pill-draft { background:#FFF7ED; color:#EA580C; }
  .pill-submitted { background:#ECFDF5; color:#059669; }
  .pill-locked { background:#EDE9FE; color:#6D28D9; }
  .start-report-box { display:flex; gap:10px; background:#fff; border:1px solid #E4E4E7; padding:18px; border-radius:12px; align-items:flex-end; flex-wrap:wrap; }
  .btn-notification { background:rgba(26,16,64,.06); border:1.5px solid #E4E4E7; color:#1A1040; width:38px; height:38px; border-radius:8px; display:flex; align-items:center; justify-content:center; cursor:pointer; position:relative; transition:all .18s ease; }
  .btn-notification:hover { background:rgba(26,16,64,.1); }
  .btn-notification::after { content:''; position:absolute; top:8px; right:8px; width:7px; height:7px; background:#E31E24; border-radius:50%; border:1.5px solid #FAF9F6; }
  .notif-panel { position:fixed; top:70px; right:24px; width:320px; background:#fff; border:1px solid #E4E4E7; border-radius:14px; box-shadow:0 10px 30px rgba(26,16,64,.12); z-index:3000; display:none; }
  .notif-panel.open { display:block; }
  .notif-panel-head { padding:16px 18px 12px; border-bottom:1px solid #F4F4F5; font-family:'Outfit',sans-serif; font-size:14px; font-weight:700; color:#1A1040; display:flex; align-items:center; justify-content:space-between; }
  .notif-panel-head span { font-size:11px; font-weight:600; color:#E31E24; cursor:pointer; }
  .notif-item { padding:12px 18px; border-bottom:1px solid #F4F4F5; display:flex; gap:12px; align-items:flex-start; cursor:pointer; transition:background .12s; }
  .notif-item:hover { background:#FAFAFA; }
  .notif-item:last-child { border-bottom:none; }
  .notif-dot { width:8px; height:8px; border-radius:50%; background:#E31E24; flex-shrink:0; margin-top:5px; }
  .notif-dot.read { background:#E4E4E7; }
  .notif-text { font-size:13px; color:#3F3F46; line-height:1.5; }
  .notif-time { font-size:11px; color:#A1A1AA; margin-top:2px; }
</style>
</style>
</head>
</style>
</head>
<body>

<!-- Mobile navigation header & backdrop overlay -->
<div class="mobile-header">
  <a href="zone-dashboard.php" class="mobile-brand">
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
  <aside class="sidebar" id="zoneSidebar">
    <!-- Mobile close button (X) — only shown on mobile -->
    <button class="sidebar-close-btn" aria-label="Close navigation" onclick="document.body.classList.remove('mobile-open');">&times;</button>
    <!-- Brand -->

    <a href="zone-dashboard.php" class="brand">
      <img src="assets/logo.jpg" alt="Logo">
      <div>
        <span class="brand-name">Foursquare</span>
        <small style="font-size:9px;opacity:.5;display:block;font-family:'Inter',sans-serif;font-weight:500;letter-spacing:.04em;text-transform:uppercase;color:rgba(255,255,255,0.5);">Reports</small>
      </div>
    </a>


    <span class="portal-tag">
      <span class="dot" style="background:#ff8a8a;"></span>Zonal Office
    </span>

    <div class="nav-section">
      <div class="nav-label">Overview</div>
      <a class="nav-item active" href="#">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
        Dashboard
      </a>
    </div>

    <div class="nav-section">
      <div class="nav-label">Zonal Reports</div>
      <?php if (!empty($zonalReports)): ?>
        <a class="nav-item" href="zonal_reports.php?month=<?= $zonalReports[0]['report_month'] ?>&year=<?= $zonalReports[0]['report_year'] ?>">
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
        <h1>Welcome back, <?= h($user ? explode(' ', $user['full_name'])[0] : 'Superintendent') ?>!</h1>
        <p class="sub"><?= h($zone['zone_name']); ?> Zone &nbsp;·&nbsp; <?= $church_count; ?> Churches Registered</p>
      </div>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert alert-success p-2 small mb-3"><?= h($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-danger p-2 small mb-3"><?= h($errorMsg) ?></div>
    <?php endif; ?>

    <!-- Start Zonal Report Form -->
    <div class="card mb-4 p-4">
      <h4 class="fw-bold mb-3" style="font-size:15px; text-transform:uppercase; letter-spacing:0.04em;">Start a New Zonal Report</h4>
      <form method="POST" action="" class="start-report-box">
        <input type="hidden" name="new_zonal_report" value="1">
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
          <div class="stat-icon" style="background:#ECE9F7;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--purple)" stroke-width="2"><path d="M3 21V8l9-5 9 5v13"/></svg>
          </div>
        </div>
        <div class="stat-value"><?= $church_count; ?></div>
        <div class="stat-label">Churches in this zone</div>
      </div>

      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#FDF1DC;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--gold-deep)" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>
          </div>
        </div>
        <div class="stat-value"><?= $zone_newcomers_total; ?></div>
        <div class="stat-label">Total new comers (submitted)</div>
      </div>

      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#FDEBE9;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><path d="M12 2 L15 9 L22 10 L17 15 L18 22 L12 18 L6 22 L7 15 L2 10 L9 9 Z"/></svg>
          </div>
        </div>
        <div class="stat-value"><?= $zone_avg_worship_attendance; ?></div>
        <div class="stat-label">Avg. Worship Service Attend.</div>
      </div>

      <div class="card stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#F3EAF0;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--magenta)" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
          </div>
        </div>
        <div class="stat-value"><?= $zone_new_members_total; ?></div>
        <div class="stat-label">Total new members (submitted)</div>
      </div>
    </div>

    <!-- Dynamic Zonal Church Status list -->
    <div class="card section-card">
      <div class="section-card-head d-flex justify-content-between align-items-center">
        <h3>Churches under <?= h($zone['zone_name']); ?> (Status for Selected Month)</h3>
        
        <form method="GET" action="" class="d-flex gap-2">
            <select name="month" class="form-select form-select-sm" style="width:110px;" onchange="this.form.submit()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m == $selectedMonth ? 'selected' : '' ?>><?= monthName($m) ?></option>
                <?php endfor; ?>
            </select>
            <select name="year" class="form-select form-select-sm" style="width:90px;" onchange="this.form.submit()">
                <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                    <option value="<?= $y ?>" <?= $y == $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
      </div>

      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr><th>#</th><th>Church Name</th><th>Status (<?= monthName($selectedMonth) ?>)</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php 
            $n = 1;
            foreach ($churchesStatusList as $c): 
            ?>
            <tr>
              <td><?= $n++; ?></td>
              <td class="strong"><?= h($c['name']); ?></td>
              <td>
                <?php if ($c['status'] === 'Submitted'): ?>
                  <span class="badge bg-success">Submitted (Online)</span>
                <?php elseif ($c['status'] === 'Draft'): ?>
                  <span class="badge bg-warning text-dark">Draft Saved (Online)</span>
                <?php else: ?>
                  <span class="badge bg-secondary">No Report Received</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to remove this church from the zone?');">
                  <input type="hidden" name="action" value="delete_church">
                  <input type="hidden" name="church_row_id" value="<?= $c['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger btn-sm py-1 px-2" style="font-size:11px;">Remove</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Inline Add Church Form -->
      <div class="p-3 bg-light border-top">
        <h5 class="fw-bold mb-2 small text-uppercase">Add Church to Zone</h5>
        <form method="POST" action="" class="d-flex gap-2" style="max-width:400px;">
          <input type="hidden" name="action" value="add_church">
          <input type="text" name="church_name" class="form-control form-control-sm" required placeholder="e.g. ODE INTAKE">
          <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">Add</button>
        </form>
      </div>
    </div>

    <!-- Zonal Reports History -->
    <div class="card section-card">
      <div class="section-card-head">
        <h3>Zonal Monthly Reports History</h3>
      </div>
      <?php if (empty($zonalReports)): ?>
        <p class="text-muted p-4 text-center">No zonal reports created yet. Use the tool above to start your first report.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr><th>Month/Year</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php foreach ($zonalReports as $zr): ?>
              <tr>
                <td class="strong"><?= monthName($zr['report_month']) . ' ' . $zr['report_year']; ?></td>
                <td><span class="pill pill-<?= $zr['status'] ?>"><?= ucfirst($zr['status']); ?></span></td>
                <td>
                  <a href="zonal_reports.php?month=<?= $zr['report_month'] ?>&year=<?= $zr['report_year'] ?>" class="btn btn-outline btn-sm">
                    <?= $zr['status'] === 'submitted' ? 'View Report' : 'Edit Draft' ?>
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

</body>
</html>

<?php
/**
 * profile.php — Shared profile management page.
 * Works for all roles: super_admin, zonal_admin, church_admin.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();
requireLogin();

$db   = db();
$role = currentRole();
$uid  = currentUserId();

// Fetch current user
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$uid]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: login.php');
    exit;
}

$successMsg = '';
$errorMsg   = '';

// Dashboard back-link per role
$dashLinks = [
    'super_admin'  => 'admin-dashboard.php',
    'zonal_admin'  => 'zone-dashboard.php',
    'church_admin' => 'church-dashboard.php',
];
$dashLink   = $dashLinks[$role] ?? 'index.php';
$dashLabel  = match($role) {
    'super_admin'  => 'Admin Dashboard',
    'zonal_admin'  => 'Zone Dashboard',
    'church_admin' => 'Church Dashboard',
    default        => 'Dashboard',
};

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_profile';

    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $bio      = trim($_POST['bio'] ?? '');

        if (empty($fullName) || empty($email)) {
            $errorMsg = 'Full name and email are required.';
        } else {
            // Check email uniqueness (exclude self)
            $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$email, $uid]);
            if ($chk->fetch()) {
                $errorMsg = 'That email address is already in use by another account.';
            } else {
                try {
                    // Check if bio column exists
                    $hasBio = false;
                    try {
                        $db->query("SELECT bio FROM users LIMIT 0");
                        $hasBio = true;
                    } catch (Exception $e) {}

                    if ($hasBio) {
                        $upd = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, bio = ?, updated_at = NOW() WHERE id = ?");
                        $upd->execute([$fullName, $email, $phone, $bio, $uid]);
                    } else {
                        $upd = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?");
                        $upd->execute([$fullName, $email, $phone, $uid]);
                    }

                    // Update session name
                    $_SESSION['full_name'] = $fullName;

                    // Re-fetch
                    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$uid]);
                    $user = $stmt->fetch();
                    $successMsg = 'Profile updated successfully!';
                } catch (Exception $e) {
                    $errorMsg = 'Error updating profile: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'change_password') {
        $currentPw  = $_POST['current_password'] ?? '';
        $newPw      = $_POST['new_password'] ?? '';
        $confirmPw  = $_POST['confirm_password'] ?? '';

        if (empty($currentPw) || empty($newPw) || empty($confirmPw)) {
            $errorMsg = 'All password fields are required.';
        } elseif (!password_verify($currentPw, $user['password_hash'])) {
            $errorMsg = 'Current password is incorrect.';
        } elseif ($newPw !== $confirmPw) {
            $errorMsg = 'New passwords do not match.';
        } elseif (strlen($newPw) < 8) {
            $errorMsg = 'New password must be at least 8 characters.';
        } else {
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $upd  = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$hash, $uid]);
            $successMsg = 'Password changed successfully!';
        }
    }
}

// Initials
$names     = explode(' ', trim($user['full_name']));
$first     = $names[0] ?? '';
$last      = count($names) > 1 ? end($names) : '';
$initials  = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : ''));

$roleLabels = [
    'super_admin'  => 'Super Administrator',
    'zonal_admin'  => 'Zonal Administrator',
    'church_admin' => 'Church Administrator',
];
$roleLabel = $roleLabels[$role] ?? ucfirst($role);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — Foursquare Reports</title>
<link rel="icon" type="image/jpeg" href="assets/logo.jpg">
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/dashboard.css">
<style>
  /* Sidebar styling overrides */
  aside.sidebar {
    background: #1A1040 !important;
    color: rgba(255,255,255,0.75) !important;
  }
  .sidebar .brand { color: #fff; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; text-decoration: none; }
  .sidebar .brand img { height: 32px; width: 32px; border-radius: 7px; object-fit: cover; border: 2px solid rgba(255,255,255,0.15); }
  .sidebar .brand-name { color: #fff !important; font-family: 'Outfit', sans-serif; font-size: 15px; font-weight: 700; }
  .sidebar .nav-label { color: rgba(255,255,255,0.30) !important; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
  .sidebar .nav-item { color: rgba(255,255,255,0.65) !important; }
  .sidebar .nav-item:hover { background: rgba(255,255,255,0.08) !important; color: #fff !important; }
  .sidebar .nav-item.active { background: #E31E24 !important; color: #fff !important; box-shadow: 0 2px 8px rgba(227,30,36,0.30) !important; }
  .sidebar .u-name { color: #fff !important; }
  .sidebar .u-role { color: rgba(255,255,255,0.45) !important; }


  .profile-avatar-area {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 24px;
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    margin-bottom: 24px;
  }
  .profile-big-avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: var(--ink);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-display);
    font-size: 30px;
    font-weight: 800;
    flex-shrink: 0;
  }
  .profile-meta h2 {
    font-family: var(--font-display);
    font-size: 22px;
    margin: 0 0 4px;
    color: var(--ink);
    font-weight: 700;
  }
  .badge-role {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 3px 10px;
    border-radius: 99px;
    background: rgba(9, 9, 11, 0.05);
    color: var(--ink-soft);
  }
  .badge-status {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 99px;
    margin-left: 6px;
    background: var(--success-light);
    color: var(--success-hover);
    text-transform: uppercase;
  }
  .card-section {
    background: var(--card);
    border: 1px solid var(--line);
    border-radius: var(--radius);
    padding: 32px;
    margin-bottom: 24px;
  }
  .card-section h3 {
    font-family: var(--font-display);
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 20px;
    color: var(--ink);
    display: flex;
    align-items: center;
  }
  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }
  .form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .form-group.full {
    grid-column: 1 / -1;
  }
  .form-group label {
    font-size: 12.5px;
    font-weight: 500;
    color: var(--ink-soft);
  }
  .form-group input,
  .form-group textarea {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    font-size: 13.5px;
    color: var(--ink);
    background: #fff;
    font-family: var(--font-body);
    outline: none;
    transition: var(--transition);
  }
  .form-group input:focus,
  .form-group textarea:focus {
    border-color: var(--ink);
    box-shadow: 0 0 0 3px rgba(9,9,11,0.06);
  }
  .form-group input:disabled {
    background: var(--line-light) !important;
    color: var(--ink-faint) !important;
    cursor: not-allowed;
  }
  .btn-save {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    border: none;
    cursor: pointer;
    font-family: var(--font-body);
    font-size: 13.5px;
    font-weight: 600;
    background: var(--ink);
    color: #fff;
    transition: var(--transition);
  }
  .btn-save:hover {
    background: var(--ink-soft);
  }
  .btn-save.btn-danger {
    background: var(--red);
  }
  .btn-save.btn-danger:hover {
    background: var(--red-hover);
  }
  
  /* Alert Banners */
  .alert {
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 24px;
    border: 1px solid transparent;
  }
  .alert-success {
    background: #F0FDF4;
    border-color: #BBF7D0;
    color: #166534;
  }
  .alert-error {
    background: #FEF2F2;
    border-color: #FECACA;
    color: #DC2626;
  }

  @media (max-width: 767px) {
    .form-row {
      grid-template-columns: 1fr;
    }
    .profile-avatar-area {
      flex-direction: column;
      text-align: center;
      padding: 24px 16px;
    }
    .badge-status {
      margin-left: 0;
      margin-top: 4px;
    }
  }
</style>
</head>
<body>

<!-- Mobile navigation header & backdrop overlay -->
<div class="mobile-header">
  <a href="<?= h($dashLink) ?>" class="mobile-brand">
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
  <aside class="sidebar" id="profileSidebar">
    <!-- Mobile close button (X) — only shown on mobile -->
    <button class="sidebar-close-btn" aria-label="Close navigation" onclick="document.body.classList.remove('mobile-open');">&times;</button>
    <!-- Brand -->
    <a href="<?= h($dashLink) ?>" class="brand" style="margin-bottom:20px;">
      <img src="assets/logo.jpg" alt="Logo">
      <div>
        <span class="brand-name">Foursquare</span>
        <small style="font-size:9px;opacity:.5;display:block;font-family:'Inter',sans-serif;font-weight:500;letter-spacing:.04em;text-transform:uppercase;color:rgba(255,255,255,0.5);">Reports</small>
      </div>
    </a>

    
    <div class="nav-section">
      <div class="nav-label">Navigation</div>
      <a class="nav-item" href="<?= h($dashLink) ?>">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
        <?= h($dashLabel) ?>
      </a>
    </div>
    <div class="sidebar-footer" style="display:flex; flex-direction:column; gap:6px;">
      <a class="nav-item active" href="profile.php" style="margin-bottom:0;">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>
        My Profile
      </a>
      <a href="logout.php" class="logout-btn" style="width:100%; text-align:center; margin-top:2px;">Log out</a>
    </div>
  </aside>

  <main class="main">
    <div class="page-head">
      <h1>My Profile</h1>
      <p>Manage your account information and password.</p>
    </div>

    <?php if ($successMsg): ?>
      <div class="alert alert-success"><?= h($successMsg) ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
      <div class="alert alert-error"><?= h($errorMsg) ?></div>
    <?php endif; ?>

    <!-- Avatar / Info Card -->
    <div class="profile-avatar-area">
      <div class="profile-big-avatar"><?= h($initials) ?></div>
      <div class="profile-meta">
        <h2><?= h($user['full_name']) ?></h2>
        <span class="badge-role"><?= h($roleLabel) ?></span>
        <span class="badge-status"><?= ucfirst($user['status']) ?></span>
        <div style="margin-top:8px;font-size:13px;color:var(--ink-faint);">Member since <?= date('F Y', strtotime($user['created_at'])) ?></div>
      </div>
    </div>

    <!-- Profile Info Form -->
    <div class="card-section">
      <h3>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px;vertical-align:-2px;"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>
        Personal Information
      </h3>
      <form method="POST">
        <input type="hidden" name="action" value="update_profile">
        <div class="form-row">
          <div class="form-group">
            <label for="full_name">Full Name</label>
            <input type="text" id="full_name" name="full_name" value="<?= h($user['full_name']) ?>" required>
          </div>
          <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" value="<?= h($user['email']) ?>" required>
          </div>
          <div class="form-group">
            <label for="phone">Phone Number</label>
            <input type="tel" id="phone" name="phone" value="<?= h($user['phone'] ?? '') ?>" placeholder="+234 ...">
          </div>
          <div class="form-group">
            <label>Role</label>
            <input type="text" value="<?= h($roleLabel) ?>" disabled style="background:#f3f4f6;color:#888;">
          </div>
          <?php
            $hasBio = false;
            try { $db->query("SELECT bio FROM users LIMIT 0"); $hasBio = true; } catch(Exception $e){}
          ?>
          <?php if ($hasBio): ?>
          <div class="form-group full">
            <label for="bio">Bio / Notes</label>
            <textarea id="bio" name="bio" placeholder="A short description about yourself..."><?= h($user['bio'] ?? '') ?></textarea>
          </div>
          <?php endif; ?>
        </div>
        <div style="margin-top:20px;">
          <button type="submit" class="btn-save">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save Profile
          </button>
        </div>
      </form>
    </div>

    <!-- Change Password Form -->
    <div class="card-section">
      <h3>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px;vertical-align:-2px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        Change Password
      </h3>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="form-row">
          <div class="form-group full">
            <label for="current_password">Current Password</label>
            <input type="password" id="current_password" name="current_password" placeholder="Enter current password">
          </div>
          <div class="form-group">
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" placeholder="Min. 8 characters">
          </div>
          <div class="form-group">
            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat new password">
          </div>
        </div>
        <div style="margin-top:20px;">
          <button type="submit" class="btn-save btn-danger">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            Update Password
          </button>
        </div>
      </form>
    </div>

    <div style="font-size:13px;color:var(--ink-faint);">
      ← <a href="<?= h($dashLink) ?>" style="color:var(--purple);text-decoration:none;font-weight:600;"><?= h($dashLabel) ?></a>
    </div>
  </main>
</div>
</body>
</html>

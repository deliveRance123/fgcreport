<?php
/**
 * includes/header.php — Unified Premium Responsive SaaS Header
 */

// Safely load dependencies if not already imported
if (!function_exists('db')) {
    require_once __DIR__ . '/../config/db.php';
}
if (!function_exists('currentChurchId')) {
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/auth.php';
}

$header_user = null;
$header_initials = '';
$header_role = '';
$header_dash_link = '';
$header_links = [];

if (isset($_SESSION['user_id'])) {
    $header_db = db();
    $stmt = $header_db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $header_user = $stmt->fetch();

    if ($header_user) {
        $header_role = $header_user['role'];
        
        // Compute Initials
        if (!empty($header_user['full_name'])) {
            $names = explode(' ', trim($header_user['full_name']));
            $first = $names[0] ?? '';
            $last = count($names) > 1 ? end($names) : '';
            $header_initials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : ''));
        }

        // Setup Role-specific links
        if ($header_role === 'super_admin') {
            $header_dash_link = 'admin-dashboard.php';
            $header_links = [
                ['label' => 'Dashboard', 'url' => 'admin-dashboard.php'],
                ['label' => 'Manage Dues', 'url' => 'admin-dashboard.php?page=dues'],
                ['label' => 'My Profile', 'url' => 'profile.php']
            ];
        } elseif ($header_role === 'zonal_admin') {
            $header_dash_link = 'zone-dashboard.php';
            $header_links = [
                ['label' => 'Dashboard', 'url' => 'zone-dashboard.php'],
                ['label' => 'My Profile', 'url' => 'profile.php']
            ];
        } elseif ($header_role === 'church_admin') {
            $header_dash_link = 'church-dashboard.php';
            $header_links = [
                ['label' => 'Dashboard', 'url' => 'church-dashboard.php']
            ];
            
            // Get latest report link
            $churchId = currentChurchId();
            if ($churchId) {
                $stmt = $header_db->prepare("SELECT report_month, report_year FROM church_financial_reports WHERE church_id = ? ORDER BY report_year DESC, report_month DESC LIMIT 1");
                $stmt->execute([$churchId]);
                $headerLatestReport = $stmt->fetch();
                if ($headerLatestReport) {
                    $header_links[] = [
                        'label' => 'Current Report', 
                        'url' => 'church_report.php?month=' . $headerLatestReport['report_month'] . '&year=' . $headerLatestReport['report_year']
                    ];
                }
            }
            $header_links[] = ['label' => 'My Profile', 'url' => 'profile.php'];
        }
    }
}
?>

<header class="site-header">
  <div class="nav-row">
    <!-- Left: Brand -->
    <a href="index.php" class="brand">
      <img src="assets/logo.jpg" alt="Logo">
      <span class="brand-name">Foursquare Reports</span>
    </a>

    <!-- Right: Desktop Actions -->
    <div class="header-actions desktop-only">
      <?php if ($header_user): ?>


        <!-- User Profile Dropdown -->
        <div class="profile-dropdown">
          <button class="profile-trigger" id="profileTrigger" aria-label="User Profile">
            <span class="profile-avatar"><?= htmlspecialchars($header_initials ?: 'U') ?></span>
            <span class="profile-arrow">&#9662;</span>
          </button>
          <div class="profile-dropdown-menu" id="profileMenu">
            <div class="dropdown-user-info">
              <strong><?= htmlspecialchars($header_user['full_name']) ?></strong>
              <span><?= htmlspecialchars($header_user['email']) ?></span>
            </div>
            <hr>
            <a href="profile.php" class="dropdown-item">Profile</a>
            <a href="profile.php" class="dropdown-item">Settings</a>
            <hr>
            <a href="logout.php" class="dropdown-item text-danger">Logout</a>
          </div>
        </div>
      <?php else: ?>
        <!-- Guest: Login + Get Started -->
        <a href="login.php" class="btn btn-solid hdr-login">Login</a>

        <div class="get-started-dropdown">
          <button class="btn btn-outline hdr-getstarted" id="getStartedTrigger">
            Get Started <span class="arrow">&#9662;</span>
          </button>
          <div class="get-started-menu" id="getStartedMenu">
            <a href="register_church.php" class="dropdown-item">Register Church</a>
            <a href="register_zone.php" class="dropdown-item">Register Zone</a>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Right: Mobile Hamburger -->
    <button class="menu-toggle" id="menuToggle" aria-label="Open navigation">
      <span></span><span></span><span></span>
    </button>

    <!-- Mobile Drawer Menu -->
    <nav class="nav-menu" id="navMenu">
      <?php if ($header_user): ?>
        <div class="mobile-user-profile">
          <div class="mobile-avatar"><?= htmlspecialchars($header_initials ?: 'U') ?></div>
          <div class="mobile-user-details">
            <strong><?= htmlspecialchars($header_user['full_name']) ?></strong>
            <span><?= htmlspecialchars($header_user['email']) ?></span>
          </div>
        </div>
        <hr>
        <div class="mobile-section-title">Navigation</div>
        <?php foreach ($header_links as $link): ?>
          <a href="<?= htmlspecialchars($link['url']) ?>" class="nav-link"><?= htmlspecialchars($link['label']) ?></a>
        <?php endforeach; ?>
        <hr>
        <a href="profile.php" class="nav-link">Settings</a>
        <a href="logout.php" class="nav-link text-danger">Logout</a>
      <?php else: ?>
        <a href="login.php" class="btn btn-solid mobile-btn" style="width:100%; text-align:center;">Login</a>
        <div class="mobile-section-title">Get Started</div>
        <a href="register_church.php" class="nav-link">&bull; Register Church</a>
        <a href="register_zone.php" class="nav-link">&bull; Register Zone</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<script>
// Prevent layout flash by ensuring body has-header class is immediately present
document.body.classList.add('has-header');

(function() {
  // Mobile Hamburger Toggle
  const toggle = document.getElementById('menuToggle');
  const menu   = document.getElementById('navMenu');
  
  if (toggle && menu) {
    toggle.addEventListener('click', function(e) {
      e.stopPropagation();
      const isOpen = menu.classList.toggle('open');
      toggle.classList.toggle('open', isOpen);
      toggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
      
      // Close any other open dropdowns
      closeAllDropdowns();
    });
  }

  // Get Started Dropdown (Guest)
  const getStartedTrigger = document.getElementById('getStartedTrigger');
  const getStartedMenu = document.getElementById('getStartedMenu');
  if (getStartedTrigger && getStartedMenu) {
    getStartedTrigger.addEventListener('click', function(e) {
      e.stopPropagation();
      const isOpen = getStartedMenu.classList.toggle('open');
      closeAllDropdowns(getStartedMenu);
    });
  }

  // Profile Dropdown (Logged in)
  const profileTrigger = document.getElementById('profileTrigger');
  const profileMenu = document.getElementById('profileMenu');
  if (profileTrigger && profileMenu) {
    profileTrigger.addEventListener('click', function(e) {
      e.stopPropagation();
      const isOpen = profileMenu.classList.toggle('open');
      closeAllDropdowns(profileMenu);
    });
  }

  // Helper to close all dropdowns except current
  function closeAllDropdowns(except = null) {
    if (getStartedMenu && getStartedMenu !== except) getStartedMenu.classList.remove('open');
    if (profileMenu && profileMenu !== except) profileMenu.classList.remove('open');
  }

  // Close menus when clicking outside
  document.addEventListener('click', function(e) {
    closeAllDropdowns();
    if (menu && toggle && !menu.contains(e.target) && !toggle.contains(e.target)) {
      menu.classList.remove('open');
      toggle.classList.remove('open');
    }
  });

  // Close mobile drawer on navigation link tap
  if (menu) {
    menu.querySelectorAll('a').forEach(function(a) {
      a.addEventListener('click', function() {
        menu.classList.remove('open');
        if (toggle) toggle.classList.remove('open');
      });
    });
  }
})();
</script>

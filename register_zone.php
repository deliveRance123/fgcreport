<?php
/**
 * register_zone.php — Zone Registration
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

session_start();

$db = db();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $zoneName   = trim($_POST['zone_name'] ?? '');
    $fullName   = trim($_POST['full_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Repeatable list of church names
    $churches = $_POST['churches'] ?? [];
    $churchNamesClean = [];
    foreach ($churches as $cName) {
        $cName = trim((string)$cName);
        if ($cName !== '') {
            $churchNamesClean[] = $cName;
        }
    }

    if (empty($zoneName) || empty($fullName) || empty($email) || empty($password)) {
        $error = 'All required fields must be filled.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (empty($churchNamesClean)) {
        $error = 'You must add at least one church under this zone.';
    } else {
        try {
            $db->beginTransaction();

            // Check if email already exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception('Email address is already registered.');
            }

            // 1. Create User
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, 'zonal_admin', 'active')");
            $stmt->execute([$fullName, $email, $phone, $passwordHash]);
            $userId = $db->lastInsertId();

            // 2. Create Zone
            $stmt = $db->prepare("INSERT INTO zones (zone_name, created_by) VALUES (?, ?)");
            $stmt->execute([$zoneName, $userId]);
            $zoneId = $db->lastInsertId();

            // 3. Create Zonal Churches
            $stmt = $db->prepare("INSERT INTO zone_churches (zone_id, church_name, display_order) VALUES (?, ?, ?)");
            $order = 1;
            foreach ($churchNamesClean as $cName) {
                $stmt->execute([$zoneId, $cName, $order++]);
            }

            $db->commit();
            $success = 'Zone and admin account registered successfully! You can now log in.';
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register a Zone Foursquare Reports</title>
<meta name="description" content="Register your zone on the Foursquare Reports portal.">
<link rel="icon" type="image/jpeg" href="assets/logo.jpg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Inter', system-ui, sans-serif;
    min-height: 100svh; background: #0f0a2e;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 40px 16px; position: relative; overflow-y: auto;
  }
  body::before {
    content: ''; position: fixed; inset: 0;
    background:
      radial-gradient(ellipse 60% 50% at 20% 20%, rgba(227,30,36,0.18) 0%, transparent 60%),
      radial-gradient(ellipse 50% 60% at 80% 80%, rgba(99,60,180,0.22) 0%, transparent 60%),
      radial-gradient(ellipse 40% 40% at 60% 10%, rgba(245,164,29,0.10) 0%, transparent 55%);
    animation: blobshift 12s ease-in-out infinite alternate;
    pointer-events: none;
  }
  @keyframes blobshift {
    0%   { opacity: 1; transform: scale(1); }
    100% { opacity: 0.85; transform: scale(1.05); }
  }
  body::after {
    content: ''; position: fixed; inset: 0;
    background-image:
      repeating-linear-gradient(0deg, transparent, transparent 39px, rgba(255,255,255,0.025) 39px, rgba(255,255,255,0.025) 40px),
      repeating-linear-gradient(90deg, transparent, transparent 39px, rgba(255,255,255,0.025) 39px, rgba(255,255,255,0.025) 40px);
    pointer-events: none;
  }

  /* ── Top bar ── */
  .auth-topbar {
    position: fixed; top: 0; left: 0; right: 0;
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 32px; z-index: 10;
  }
  .auth-topbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
  .auth-topbar-brand img { width: 32px; height: 32px; border-radius: 8px; object-fit: cover; border: 1.5px solid rgba(255,255,255,0.15); }
  .auth-topbar-brand span { font-family: 'Outfit', sans-serif; font-size: 15px; font-weight: 700; color: rgba(255,255,255,0.9); }
  .auth-back-link {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 13px; font-weight: 500; color: rgba(255,255,255,0.65);
    text-decoration: none; transition: all 0.15s;
    border: 1px solid rgba(255,255,255,0.12); padding: 7px 16px;
    border-radius: 99px; backdrop-filter: blur(10px);
    background: rgba(255,255,255,0.06);
  }
  .auth-back-link:hover { color: #fff; background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.25); }
  .auth-back-link:hover svg { transform: translateX(-3px); }
  .auth-back-link svg { transition: transform 0.15s; }

  .auth-wrap { width: 100%; max-width: 680px; margin: 0 auto; position: relative; z-index: 2; }
  .auth-card {
    background: #fff; border-radius: 20px;
    padding: 40px 36px;
    box-shadow: 0 0 0 1px rgba(255,255,255,0.08), 0 32px 80px rgba(0,0,0,0.45), 0 4px 20px rgba(0,0,0,0.25);
    animation: cardIn 0.45s cubic-bezier(.22,1,.36,1) both;
  }
  @keyframes cardIn {
    from { opacity: 0; transform: translateY(28px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
  }
  .auth-card-head { margin-bottom: 28px; }
  .auth-card-head .eyebrow { display: inline-block; font-size: 10.5px; font-weight: 700; letter-spacing: 0.09em; text-transform: uppercase; color: #E31E24; margin-bottom: 10px; }
  .auth-card-head h1 { font-family: "Outfit", sans-serif; font-size: 22px; font-weight: 800; color: #1A1040; margin-bottom: 6px; letter-spacing: -0.02em; }
  .auth-card-head p { font-size: 13.5px; color: #71717A; }
  .form-section { margin-bottom: 24px; }
  .form-section-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #1A1040; margin-bottom: 16px; border-bottom: 1px solid #F0F0F0; padding-bottom: 6px; }
  .form-section-sub { font-size: 12px; color: #71717A; margin-bottom: 14px; margin-top: -10px; line-height: 1.5; }
  .field-group { margin-bottom: 16px; }
  .field-label { font-size: 12px; font-weight: 600; color: #3F3F46; margin-bottom: 6px; display: block; }
  .field-req { color: #E31E24; margin-left: 2px; }
  .field-input, .field-select {
    width: 100%; padding: 11px 14px;
    border: 1.5px solid #E4E4E7; border-radius: 9px;
    font-size: 13.5px; color: #0D0D12; background: #FAFAFA;
    transition: border-color 0.15s, box-shadow 0.15s; outline: none; font-family: inherit;
  }
  .field-input:focus, .field-select:focus { border-color: #E31E24; box-shadow: 0 0 0 3px rgba(227,30,36,0.10); background: #fff; }
  .field-grid { display: grid; grid-template-columns: 1fr; gap: 16px; }
  .field-grid.cols-2 { grid-template-columns: 1fr 1fr; }

  .church-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
  .church-row .field-input { flex: 1; }
  .remove-btn {
    background: none; border: 1px solid #E4E4E7; color: #71717A;
    padding: 10px 16px; border-radius: 9px; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all 0.15s;
  }
  .remove-btn:hover:not(:disabled) { background: #FEF2F2; border-color: #FECACA; color: #DC2626; }
  .remove-btn:disabled { opacity: 0.5; cursor: not-allowed; }

  .add-church-btn {
    display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600;
    color: #E31E24; background: rgba(227,30,36,0.07); border: 1.5px solid rgba(227,30,36,0.15);
    border-radius: 8px; padding: 8px 16px; cursor: pointer; transition: all 0.15s;
  }
  .add-church-btn:hover { background: rgba(227,30,36,0.12); border-color: rgba(227,30,36,0.25); }

  .submit-row { display: flex; align-items: center; justify-content: flex-end; gap: 16px; margin-top: 24px; }
  .submit-btn {
    padding: 13px 28px;
    background: linear-gradient(135deg, #E31E24 0%, #B81018 100%);
    color: #fff; border: none; border-radius: 9px;
    font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit;
    box-shadow: 0 4px 16px rgba(227,30,36,0.38);
    transition: box-shadow 0.15s, transform 0.1s, opacity 0.15s;
  }
  .submit-btn:hover { box-shadow: 0 6px 22px rgba(227,30,36,0.48); opacity: 0.93; }
  .submit-btn:active { transform: scale(0.99); }
  .alert-bar {
    border-radius: 9px; padding: 12px 16px; font-size: 13.5px;
    margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px;
  }
  .alert-error { background: #FEF2F2; border: 1px solid #FECACA; color: #DC2626; }
  .alert-success { background: #F0FDF4; border: 1px solid #BBF7D0; color: #059669; }
  .alert-success-link { font-weight: 700; color: #059669; text-decoration: underline; display:block; margin-top:4px;}
  .auth-footer { text-align: center; margin-top: 22px; }
  .auth-footer a { font-size: 13px; font-weight: 700; color: rgba(255,255,255,0.7); text-decoration: underline; text-decoration-color: rgba(255,255,255,0.3); text-underline-offset: 3px; transition: color 0.15s; }
  .auth-footer a:hover { color: #fff; text-decoration-color: #fff; }
  @media (max-width: 600px) {
    .auth-topbar { padding: 14px 18px; }
    .auth-topbar-brand span { display: none; }
    .auth-card { padding: 32px 20px; }
    .field-grid.cols-2 { grid-template-columns: 1fr; }
    .submit-row { flex-direction: column-reverse; align-items: stretch; }
    .submit-btn { width: 100%; text-align: center; }
  }
</style>
</head>
<body>

<!-- Top bar -->
<nav class="auth-topbar">
  <a href="index.php" class="auth-back-link">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Back to home
  </a>
</nav>

<div class="auth-wrap">

  <!-- Card -->
  <div class="auth-card">

    <div class="auth-card-head">
      <span class="eyebrow">Zone registration</span>
      <h1>Register a Zone</h1>
      <p>Set up your zone and zonal Secretary account. Add all the churches in your zone below.</p>
    </div>

    <?php if ($error): ?>
    <div class="alert-bar alert-error" role="alert">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="flex-shrink:0;margin-top:1px">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <?= h($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert-bar alert-success" role="status">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="flex-shrink:0;margin-top:1px">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
      <div>
        <?= h($success) ?>
        <a href="login.php" class="alert-success-link">Proceed to Login →</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST" action="">

      <!-- Zone Information -->
      <div class="form-section">
        <span class="form-section-label">Zone information</span>
        <div class="field-group">
          <label class="field-label" for="zone_name">Zone Name<span class="field-req">*</span></label>
          <input type="text" id="zone_name" name="zone_name" class="field-input"
                 placeholder="Imeko Zone" required
                 value="<?= h($_POST['zone_name'] ?? '') ?>">
        </div>
      </div>

      <!-- Churches in Zone -->
      <div class="form-section">
        <span class="form-section-label">Churches in this zone</span>
        <p class="form-section-sub">Add the names of all churches under this zone. You can add more later from your dashboard.</p>
        <div id="churches-container">
          <div class="church-row">
            <input type="text" name="churches[]" class="field-input"
                   placeholder="Zonal HQ" required
                   value="<?= isset($_POST['churches'][0]) ? h($_POST['churches'][0]) : '' ?>">
            <button type="button" class="remove-btn" disabled>Remove</button>
          </div>
          <?php if (!empty($_POST['churches'])): ?>
            <?php for ($i = 1; $i < count($_POST['churches']); $i++): ?>
            <div class="church-row">
              <input type="text" name="churches[]" class="field-input"
                     placeholder="Church Name" required
                     value="<?= h($_POST['churches'][$i]) ?>">
              <button type="button" class="remove-btn">Remove</button>
            </div>
            <?php endfor; ?>
          <?php endif; ?>
        </div>
        <button type="button" class="add-church-btn" id="add-church-btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add another church
        </button>
      </div>

      <!-- Admin Account -->
      <div class="form-section">
        <span class="form-section-label">Zonal Secretary account</span>
        <div class="field-grid cols-2">
          <div class="field-group">
            <label class="field-label" for="full_name">Full Name<span class="field-req">*</span></label>
            <input type="text" id="full_name" name="full_name" class="field-input"
                   placeholder="Your full name" required
                   value="<?= h($_POST['full_name'] ?? '') ?>">
          </div>
          <div class="field-group">
            <label class="field-label" for="reg_email">Email Address (Login)<span class="field-req">*</span></label>
            <input type="email" id="reg_email" name="email" class="field-input"
                   placeholder="joshua@gmail.com" required
                   value="<?= h($_POST['email'] ?? '') ?>">
          </div>
          <div class="field-group">
            <label class="field-label" for="phone">Phone Number</label>
            <input type="text" id="phone" name="phone" class="field-input"
                   placeholder="+234..."
                   value="<?= h($_POST['phone'] ?? '') ?>">
          </div>
          <div class="field-group"><!-- spacer --></div>
          <div class="field-group">
            <label class="field-label" for="reg_password">Password<span class="field-req">*</span></label>
            <input type="password" id="reg_password" name="password" class="field-input"
                   required placeholder="••••••••">
          </div>
          <div class="field-group">
            <label class="field-label" for="confirm_password">Confirm Password<span class="field-req">*</span></label>
            <input type="password" id="confirm_password" name="confirm_password" class="field-input"
                   required placeholder="••••••••">
          </div>
        </div>
      </div>

      <div class="submit-row">
        <button type="submit" class="submit-btn" id="register-zone-submit">Register Zone</button>
      </div>

    </form>
    <?php endif; ?>

  </div><!-- /.auth-card -->

  <div class="auth-footer">
    <a href="login.php">Already registered? Sign in →</a>
  </div>

</div><!-- /.auth-wrap -->

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('churches-container');
    const addBtn    = document.getElementById('add-church-btn');

    function updateRemoveButtons() {
      const rows = container.querySelectorAll('.church-row');
      const removeBtns = container.querySelectorAll('.remove-btn');
      removeBtns.forEach(btn => {
        btn.disabled = rows.length <= 1;
      });
    }

    addBtn.addEventListener('click', function() {
      const newRow = document.createElement('div');
      newRow.className = 'church-row';
      newRow.innerHTML = `
        <input type="text" name="churches[]" class="field-input" placeholder="e.g. Church Name" required>
        <button type="button" class="remove-btn">Remove</button>
      `;
      container.appendChild(newRow);
      updateRemoveButtons();
      newRow.querySelector('input').focus();
    });

    container.addEventListener('click', function(e) {
      if (e.target.classList.contains('remove-btn')) {
        e.target.closest('.church-row').remove();
        updateRemoveButtons();
      }
    });
  });
</script>

</body>
</html>

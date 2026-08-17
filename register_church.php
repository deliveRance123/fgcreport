<?php
/**
 * register_church.php — Local Church Registration
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

session_start();

$db = db();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $churchName = trim($_POST['church_name'] ?? '');
    $district   = trim($_POST['district'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $pastorName = trim($_POST['pastor_name'] ?? '');
    $pastorAddress = trim($_POST['pastor_address'] ?? '');
    $churchType = $_POST['church_type'] ?? ''; // 'chartered' or 'unchartered'
    
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Simple validation
    if (empty($churchName) || empty($churchType) || empty($fullName) || empty($email) || empty($password)) {
        $error = 'All required fields must be filled.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (!in_array($churchType, ['chartered', 'unchartered'], true)) {
        $error = 'Invalid church type selected.';
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
            $stmt = $db->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, 'church_admin', 'active')");
            $stmt->execute([$fullName, $email, $phone, $passwordHash]);
            $userId = $db->lastInsertId();

            // 2. Create Church
            $stmt = $db->prepare("INSERT INTO churches (name, district, address, pastor_name, pastor_address, church_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$churchName, $district, $address, $pastorName, $pastorAddress, $churchType, $userId]);
            $churchId = $db->lastInsertId();

            // 3. Seed 29 Default Expense Items for this church
            $defaults = defaultExpenseItems();
            $stmt = $db->prepare("INSERT INTO church_expense_items (church_id, report_id, item_key, label, amount, is_custom, display_order) VALUES (?, NULL, ?, ?, 0.00, 0, ?)");
            foreach ($defaults as $item) {
                $stmt->execute([$churchId, $item['item_key'], $item['label'], $item['display_order']]);
            }

            $db->commit();
            $success = 'Church and admin account registered successfully! You can now log in.';
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register a Church — Foursquare Reports</title>
<meta name="description" content="Register your local church on the Foursquare Reports portal.">
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
  .field-input::placeholder { color: #C4C4C8; }
  .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .submit-row { display: flex; align-items: center; justify-content: flex-end; gap: 16px; margin-top: 24px; }
  .submit-btn {
    padding: 13px 28px;
    background: linear-gradient(135deg, #E31E24 0%, #B81018 100%);
    color: #fff; border: none; border-radius: 9px;
    font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit;
    letter-spacing: 0.01em; box-shadow: 0 4px 16px rgba(227,30,36,0.38);
    transition: box-shadow 0.15s, transform 0.1s, opacity 0.15s;
  }
  .submit-btn:hover { box-shadow: 0 6px 22px rgba(227,30,36,0.48); opacity: 0.93; }
  .submit-btn:active { transform: scale(0.99); }
  .error-bar, .alert-error {
    background: #FEF2F2; border: 1px solid #FECACA; color: #DC2626;
    border-radius: 9px; padding: 10px 14px; font-size: 13px; font-weight: 500;
    margin-bottom: 20px; display: flex; align-items: center; gap: 8px;
  }
  .alert-success { background: #F0FDF4; border: 1px solid #BBF7D0; color: #059669; border-radius: 9px; padding: 12px 16px; font-size: 13.5px; }
  .alert-success-link { font-weight: 700; color: #059669; text-decoration: underline; }
  .auth-footer { text-align: center; margin-top: 22px; }
  .auth-footer a { font-size: 13px; font-weight: 700; color: rgba(255,255,255,0.7); text-decoration: underline; text-decoration-color: rgba(255,255,255,0.3); text-underline-offset: 3px; transition: color 0.15s; }
  .auth-footer a:hover { color: #fff; text-decoration-color: #fff; }
  @media (max-width: 600px) {
    .auth-topbar { padding: 14px 18px; }
    .auth-topbar-brand span { display: none; }
    .auth-card { padding: 32px 20px; }
    .field-grid { grid-template-columns: 1fr; }
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
      <span class="eyebrow">Church registration</span>
      <h1>Register a Local Church</h1>
      <p>Set up your church reporting account. Dues and expense categories will be pre-populated automatically.</p>
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
        <br>
        <a href="login.php" class="alert-success-link">Proceed to Login →</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST" action="">

      <!-- Church Information -->
      <div class="form-section">
        <span class="form-section-label">Church information</span>
        <div class="field-grid">
          <div class="field-group">
            <label class="field-label" for="church_name">Church Name<span class="field-req">*</span></label>
            <input type="text" id="church_name" name="church_name" class="field-input"
                   placeholder="Church" required
                   value="<?= h($_POST['church_name'] ?? '') ?>">
          </div>
          <div class="field-group">
            <label class="field-label" for="church_type">Church Type<span class="field-req">*</span></label>
            <select id="church_type" name="church_type" class="field-select" required>
              <option value="">— Select type —</option>
              <option value="chartered" <?= isset($_POST['church_type']) && $_POST['church_type'] === 'chartered' ? 'selected' : '' ?>>Chartered</option>
              <option value="unchartered" <?= isset($_POST['church_type']) && $_POST['church_type'] === 'unchartered' ? 'selected' : '' ?>>Unchartered</option>
            </select>
          </div>
          <div class="field-group">
            <label class="field-label" for="district">District Name</label>
            <input type="text" id="district" name="district" class="field-input"
                   placeholder="District"
                   value="<?= h($_POST['district'] ?? '') ?>">
          </div>
          <div class="field-group">
            <label class="field-label" for="address">Church Physical Address</label>
            <input type="text" id="address" name="address" class="field-input"
                   placeholder="Physical location of church"
                   value="<?= h($_POST['address'] ?? '') ?>">
          </div>
          <div class="field-group">
            <label class="field-label" for="pastor_name">Pastor's Full Name</label>
            <input type="text" id="pastor_name" name="pastor_name" class="field-input"
                   placeholder="Rev. / Pastor Name"
                   value="<?= h($_POST['pastor_name'] ?? '') ?>">
          </div>
          <div class="field-group">
            <label class="field-label" for="pastor_address">Pastor's Residential Address</label>
            <input type="text" id="pastor_address" name="pastor_address" class="field-input"
                   placeholder="Pastor's home address"
                   value="<?= h($_POST['pastor_address'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Admin Account -->
      <div class="form-section">
        <span class="form-section-label">Local Church Secretary account</span>
        <div class="field-grid">
          <div class="field-group">
            <label class="field-label" for="full_name">Full Name<span class="field-req">*</span></label>
            <input type="text" id="full_name" name="full_name" class="field-input"
                   placeholder="Your name" required
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
        <button type="submit" class="submit-btn" id="register-church-submit">Register Church</button>
      </div>

    </form>
    <?php endif; ?>

  </div><!-- /.auth-card -->

  <div class="auth-footer">
    <a href="login.php">Already registered? Sign in →</a>
  </div>

</div><!-- /.auth-wrap -->
</body>
</html>

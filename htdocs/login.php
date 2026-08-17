<?php
/**
 * login.php — Login page with authentication and direct preview bypasses.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();

$db = db();
$error = '';

// Check if user is already logged in, redirect them
if (isLoggedIn()) {
    $role = currentRole();
    $map = [
        'church_admin' => 'church-dashboard.php',
        'zonal_admin'  => 'zone-dashboard.php',
        'super_admin'  => 'admin-dashboard.php',
    ];
    header('Location: ' . ($map[$role] ?? 'index.php'));
    exit;
}

// Handle Preview Bypass
if (isset($_GET['preview'])) {
    $role = $_GET['preview'];
    try {
        $db->beginTransaction();
        if ($role === 'church_admin') {
            // Find or create preview church
            $stmt = $db->query("SELECT u.id, u.full_name, c.id AS church_id FROM users u JOIN churches c ON c.created_by = u.id WHERE u.role = 'church_admin' LIMIT 1");
            $previewUser = $stmt->fetch();
            
            if (!$previewUser) {
                // Create user
                $passwordHash = password_hash('password', PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES ('Preview Church Admin', 'preview_church@foursquare.org', '123', ?, 'church_admin', 'active')");
                $stmt->execute([$passwordHash]);
                $userId = $db->lastInsertId();

                // Create church
                $stmt = $db->prepare("INSERT INTO churches (name, district, address, pastor_name, pastor_address, church_type, created_by) VALUES ('Preview Local Church', 'Lagos District', '123 Church Rd', 'Pastor John Doe', 'Pastor House', 'unchartered', ?)");
                $stmt->execute([$userId]);
                $churchId = $db->lastInsertId();

                // Seed 29 Default Expense Items for this church
                $defaults = defaultExpenseItems();
                $stmtInsert = $db->prepare("INSERT INTO church_expense_items (church_id, report_id, item_key, label, amount, is_custom, display_order) VALUES (?, NULL, ?, ?, 0.00, 0, ?)");
                foreach ($defaults as $item) {
                    $stmtInsert->execute([$churchId, $item['item_key'], $item['label'], $item['display_order']]);
                }
                
                $previewUser = ['id' => $userId, 'full_name' => 'Preview Church Admin', 'church_id' => $churchId];
            }
            
            $_SESSION['user_id'] = $previewUser['id'];
            $_SESSION['role'] = 'church_admin';
            $_SESSION['full_name'] = $previewUser['full_name'];
            $_SESSION['church_id'] = $previewUser['church_id'];

            $db->commit();
            header('Location: church-dashboard.php');
            exit;

        } elseif ($role === 'zonal_admin') {
            // Find or create preview zone
            $stmt = $db->query("SELECT u.id, u.full_name, z.id AS zone_id FROM users u JOIN zones z ON z.created_by = u.id WHERE u.role = 'zonal_admin' LIMIT 1");
            $previewUser = $stmt->fetch();

            if (!$previewUser) {
                // Create user
                $passwordHash = password_hash('password', PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES ('Preview Zonal Admin', 'preview_zone@foursquare.org', '123', ?, 'zonal_admin', 'active')");
                $stmt->execute([$passwordHash]);
                $userId = $db->lastInsertId();

                // Create zone
                $stmt = $db->prepare("INSERT INTO zones (zone_name, created_by) VALUES ('Preview Zone (Isara Zone)', ?)");
                $stmt->execute([$userId]);
                $zoneId = $db->lastInsertId();

                // Add churches to zone
                $stmtInsert = $db->prepare("INSERT INTO zone_churches (zone_id, church_name, display_order) VALUES (?, ?, ?)");
                $stmtInsert->execute([$zoneId, 'ZONAL HQTS', 1]);
                $stmtInsert->execute([$zoneId, 'ISARA II', 2]);
                $stmtInsert->execute([$zoneId, 'IPARA', 3]);
                $stmtInsert->execute([$zoneId, 'ODE INTAKE', 4]);

                $previewUser = ['id' => $userId, 'full_name' => 'Preview Zonal Admin', 'zone_id' => $zoneId];
            }

            $_SESSION['user_id'] = $previewUser['id'];
            $_SESSION['role'] = 'zonal_admin';
            $_SESSION['full_name'] = $previewUser['full_name'];
            $_SESSION['zone_id'] = $previewUser['zone_id'];

            $db->commit();
            header('Location: zone-dashboard.php');
            exit;

        } elseif ($role === 'super_admin') {
            // Find or create preview super admin
            $stmt = $db->query("SELECT id, full_name FROM users WHERE role = 'super_admin' LIMIT 1");
            $previewUser = $stmt->fetch();

            if (!$previewUser) {
                // Create user
                $passwordHash = password_hash('password', PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES ('Preview Super Admin', 'preview_admin@foursquare.org', '123', ?, 'super_admin', 'active')");
                $stmt->execute([$passwordHash]);
                $userId = $db->lastInsertId();
                $previewUser = ['id' => $userId, 'full_name' => 'Preview Super Admin'];
            }

            $_SESSION['user_id'] = $previewUser['id'];
            $_SESSION['role'] = 'super_admin';
            $_SESSION['full_name'] = $previewUser['full_name'];

            $db->commit();
            header('Location: admin-dashboard.php');
            exit;
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error = 'Error setting up preview session: ' . $e->getMessage();
    }
}

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['access_user'] ?? $_POST['email'] ?? '');
    $password = $_POST['access_pass'] ?? $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email and password are required.';
    } else {
        try {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['status'] !== 'active') {
                    $error = 'Your account is pending or suspended. Please contact the administrator.';
                } else {
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];

                    // Look up entity ID
                    if ($user['role'] === 'church_admin') {
                        $stmt = $db->prepare("SELECT id FROM churches WHERE created_by = ?");
                        $stmt->execute([$user['id']]);
                        $_SESSION['church_id'] = $stmt->fetchColumn() ?: null;
                    } elseif ($user['role'] === 'zonal_admin') {
                        $stmt = $db->prepare("SELECT id FROM zones WHERE created_by = ?");
                        $stmt->execute([$user['id']]);
                        $_SESSION['zone_id'] = $stmt->fetchColumn() ?: null;
                    }

                    // Redirect
                    $map = [
                        'church_admin' => 'church-dashboard.php',
                        'zonal_admin'  => 'zone-dashboard.php',
                        'super_admin'  => 'admin-dashboard.php',
                    ];
                    header('Location: ' . ($map[$user['role']] ?? 'index.php'));
                    exit;
                }
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Foursquare Portal Access</title>
<meta name="description" content="Secure portal access.">
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
  .auth-card {
    position: relative; z-index: 2;
    width: 100%; max-width: 420px;
    background: #ffffff; border-radius: 20px;
    padding: 40px 40px 36px;
    box-shadow: 0 0 0 1px rgba(255,255,255,0.08), 0 32px 80px rgba(0,0,0,0.45), 0 4px 20px rgba(0,0,0,0.25);
    animation: cardIn 0.45s cubic-bezier(.22,1,.36,1) both;
  }
  @keyframes cardIn {
    from { opacity: 0; transform: translateY(28px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
  }
  .card-logo { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; padding-bottom: 22px; border-bottom: 1px solid #F4F4F5; }
  .card-logo img { width: 42px; height: 42px; border-radius: 10px; object-fit: cover; border: 1.5px solid #E4E4E7; }
  .card-logo-text .name { font-family: 'Outfit', sans-serif; font-size: 14px; font-weight: 800; color: #1A1040; display: block; line-height: 1.3; }
  .card-logo-text .sub { font-size: 10.5px; color: #A1A1AA; font-weight: 500; text-transform: uppercase; letter-spacing: .06em; }
  .auth-form-head { margin-bottom: 24px; }
  .auth-form-head h1 { font-family: 'Outfit', sans-serif; font-size: 23px; font-weight: 800; color: #1A1040; margin-bottom: 5px; letter-spacing: -0.02em; }
  .auth-form-head p { font-size: 13px; color: #71717A; }
  .field-group { margin-bottom: 16px; }
  .field-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
  .field-label { font-size: 12px; font-weight: 600; color: #3F3F46; }
  .field-link { font-size: 12px; color: #A1A1AA; cursor: pointer; transition: color 0.15s; text-decoration: none; background: none; border: none; font-family: inherit; }
  .field-link:hover { color: #E31E24; }
  .field-input {
    width: 100%; padding: 11px 14px;
    border: 1.5px solid #E4E4E7; border-radius: 9px;
    font-size: 13.5px; color: #0D0D12; background: #FAFAFA;
    transition: border-color 0.15s, box-shadow 0.15s; outline: none; font-family: inherit;
  }
  .field-input:focus { border-color: #E31E24; box-shadow: 0 0 0 3px rgba(227,30,36,0.10); background: #fff; }
  .field-input::placeholder { color: #C4C4C8; }
  .submit-btn {
    width: 100%; padding: 13px; margin-top: 8px;
    background: linear-gradient(135deg, #E31E24 0%, #B81018 100%);
    color: #fff; border: none; border-radius: 9px;
    font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit;
    letter-spacing: 0.01em; box-shadow: 0 4px 16px rgba(227,30,36,0.38);
    transition: box-shadow 0.15s, transform 0.1s, opacity 0.15s;
  }
  .submit-btn:hover { box-shadow: 0 6px 22px rgba(227,30,36,0.48); opacity: 0.93; }
  .submit-btn:active { transform: scale(0.99); }
  .error-bar {
    background: #FEF2F2; border: 1px solid #FECACA; color: #DC2626;
    border-radius: 9px; padding: 10px 14px; font-size: 13px; font-weight: 500;
    margin-bottom: 18px; display: flex; align-items: center; gap: 8px;
  }
  .form-divider { display: flex; align-items: center; gap: 12px; margin: 22px 0 16px; }
  .form-divider hr { flex: 1; border: none; border-top: 1px solid #F0F0F0; }
  .form-divider span { font-size: 11px; color: #C4C4C8; white-space: nowrap; }
  .register-link-block { text-align: center; font-size: 13px; color: #71717A; }
  .register-link-block a { font-weight: 700; color: #1A1040; text-decoration: underline; text-decoration-color: #D4D4D8; text-underline-offset: 3px; transition: text-decoration-color 0.15s; }
  .register-link-block a:hover { text-decoration-color: #1A1040; }
  @media (max-width: 480px) {
    .auth-topbar { padding: 14px 18px; }
    .auth-topbar-brand span { display: none; }
    .auth-card { padding: 32px 22px 28px; }
  }
</style>
</head>
<body>

<!-- Top Nav -->
<nav class="auth-topbar">
  <a href="index.php" class="auth-back-link">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Back to home
  </a>
</nav>

<!-- Login Card -->
<div class="auth-card">

  <div class="card-logo">
    <img src="assets/logo.jpg" alt="Foursquare Reports Logo">
    <div class="card-logo-text">
      <span class="name">Foursquare Reports</span>
      <span class="sub">Reporting Portal</span>
    </div>
  </div>

  <div class="auth-form-head">
    <h1>Portal Access</h1>
    <p>Secure authentication required</p>
  </div>

  <?php if ($error): ?>
  <div class="error-bar" role="alert">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?= h($error) ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="login.php" novalidate>
    <div class="field-group">
      <div class="field-row">
        <label class="field-label" for="usr_id_val">Access ID</label>
      </div>
      <input type="text" id="usr_id_val" name="access_user" class="field-input" required
             placeholder="Enter registered ID"
             value="<?= h($_POST['access_user'] ?? $_POST['email'] ?? '') ?>">
    </div>

    <div class="field-group">
      <div class="field-row">
        <label class="field-label" for="usr_pw_val">Passcode</label>
        <button type="button" class="field-link"
                onclick="alert('Please contact your administrator to reset your credentials.')">
          Forgot credentials?
        </button>
      </div>
      <input type="password" id="usr_pw_val" name="access_pass" class="field-input"
             required placeholder="••••••••">
    </div>

    <button type="submit" class="submit-btn" id="login-submit">Proceed</button>
  </form>

  <div class="form-divider">
    <hr><span>New here?</span><hr>
  </div>

  <div class="register-link-block">
    Don't have an account? <a href="index.php#paths">Register here</a>
  </div>

</div>

</body>
</html>

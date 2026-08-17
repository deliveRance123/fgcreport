<?php
/**
 * setup.php — Create first Super Admin account.
 * Works only if there are no super_admin accounts in the `users` table.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();

$db = db();

// Check if a super admin already exists
$stmt = $db->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'");
$superAdminExists = (int)$stmt->fetchColumn() > 0;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($superAdminExists) {
        $error = 'Super Admin account already exists. For security, this page is locked.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($fullName) || empty($email) || empty($password)) {
            $error = 'Full Name, Email, and Password are required.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, status) VALUES (?, ?, ?, ?, 'super_admin', 'active')");
                $stmt->execute([$fullName, $email, $phone, $passwordHash]);
                $success = 'Super Admin account created successfully! You can now log in.';
                $superAdminExists = true; // Lock the form now
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Super Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Create Super Admin Account</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= h($error) ?></div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?= h($success) ?></div>
                        <?php endif; ?>

                        <?php if ($superAdminExists && !$success): ?>
                            <div class="alert alert-warning">
                                A Super Admin account has already been created. This installer is locked.
                            </div>
                        <?php endif; ?>

                        <?php if (!$superAdminExists): ?>
                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" name="phone" class="form-control">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Password</label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm Password</label>
                                    <input type="password" name="confirm_password" class="form-control" required>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Create Account</button>
                            </form>
                        <?php else: ?>
                            <div class="text-center mt-3">
                                <a href="/fgc_report_web/login.php" class="btn btn-success">Go to Login</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

<?php
/**
 * migrate.php — Safe one-time migration script.
 * Run via browser: http://localhost/fgc_report_web/migrate.php
 * Adds any missing tables to an existing foursquare_reports database.
 * Safe to run multiple times (uses CREATE TABLE IF NOT EXISTS + INSERT IGNORE).
 */

if (file_exists(__DIR__ . '/config/db.php')) {
    require_once __DIR__ . '/config/db.php';
    $host    = DB_HOST;
    $user    = DB_USER;
    $pass    = DB_PASS;
    $dbName  = DB_NAME;
    $charset = DB_CHARSET;
} else {
    $host    = 'localhost';
    $user    = 'root';
    $pass    = '';
    $dbName  = 'foursquare_reports';
    $charset = 'utf8mb4';
}

$errors   = [];
$messages = [];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbName;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $messages[] = "✔ Connected to database <code>$dbName</code>.";

    // ─── TABLE: site_settings ─────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `site_settings` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
        `setting_value` TEXT NOT NULL DEFAULT '',
        `updated_by`    INT UNSIGNED NULL DEFAULT NULL,
        `updated_at`    TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✔ Table <code>site_settings</code> ready.";

    // ─── SEED: default site settings ──────────────────────────────────────
    $defaults = [
        ['site_name',          'Foursquare Reports'],
        ['site_tagline',       'Church & Zonal Reporting System'],
        ['hero_title',         'Monthly reports, finally in order.'],
        ['hero_lead',          'Replace the paper financial and spiritual report sheets with one system that calculates dues, tracks attendance, and keeps every month on file — for local churches and zonal offices alike.'],
        ['strip_item_1',       'Chartered & unchartered churches supported'],
        ['strip_item_2',       'Works for any zone, any number of churches'],
        ['strip_item_3',       'Dues calculated automatically, set centrally'],
        ['strip_item_4',       'Full report history, always on file'],
        ['paths_title',        'Two kinds of reporting, one system.'],
        ['paths_subtitle',     'Your church submits its own monthly report. Your zone compares reports across every church under it. Register for the one that applies to you.'],
        ['footer_org_name',    'Foursquare Gospel Church, Isara Zone'],
        ['contact_email',      'info@foursquarechurch.org'],
        ['contact_phone',      ''],
        ['how_title',          'From paper form to filed report.'],
    ];

    $ins = $pdo->prepare("INSERT IGNORE INTO `site_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
    foreach ($defaults as [$key, $val]) {
        $ins->execute([$key, $val]);
    }
    $messages[] = "✔ Default site settings seeded (INSERT IGNORE — safe to re-run).";

    // ─── TABLE: hero_videos ────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `hero_videos` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `video_path`    VARCHAR(255) NOT NULL,
        `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
        `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✔ Table <code>hero_videos</code> ready.";

    // ─── TABLE: hero_showcase_videos ─────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `hero_showcase_videos` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `video_path`    VARCHAR(255) NOT NULL,
        `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
        `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✔ Table <code>hero_showcase_videos</code> ready.";

    // ─── Add profile_photo column to users if not exists ──────────────────
    $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'profile_photo'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `profile_photo` VARCHAR(300) NULL DEFAULT NULL AFTER `phone`");
        $messages[] = "✔ Column <code>profile_photo</code> added to <code>users</code>.";
    } else {
        $messages[] = "✔ Column <code>profile_photo</code> already exists in <code>users</code>.";
    }

    // ─── Add bio column to users if not exists ────────────────────────────
    $cols2 = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'bio'")->fetchAll();
    if (empty($cols2)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `bio` TEXT NULL DEFAULT NULL AFTER `profile_photo`");
        $messages[] = "✔ Column <code>bio</code> added to <code>users</code>.";
    } else {
        $messages[] = "✔ Column <code>bio</code> already exists in <code>users</code>.";
    }

    $messages[] = "<strong style='color:green'>✔ Migration complete! All tables and columns are up to date.</strong>";
    $messages[] = "<strong style='color:#c00'>⚠ You may delete migrate.php after running it.</strong>";

} catch (PDOException $e) {
    $errors[] = "DB Error: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>FGC Report — Migration</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container py-4">
    <h2 class="mb-3">Foursquare Report System — Database Migration</h2>
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if ($messages): ?>
        <ul class="list-group mb-3">
            <?php foreach ($messages as $m): ?>
                <li class="list-group-item"><?= $m ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <div class="mt-3 d-flex gap-2">
        <a href="/fgc_report_web/admin-dashboard.php" class="btn btn-primary">→ Go to Admin Dashboard</a>
        <a href="/fgc_report_web/index.php" class="btn btn-secondary">← Back to Homepage</a>
    </div>
</body>
</html>

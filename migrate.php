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
        ['payment_enabled',    '0'],
        ['payment_public_key', ''],
        ['payment_secret_key', ''],
        ['monthly_sub_amount', '5000'],
        ['report_unlock_fee',  '2000'],
        ['free_trial_months',  '3'],
    ];

    $ins = $pdo->prepare("INSERT IGNORE INTO `site_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
    foreach ($defaults as [$key, $val]) {
        $ins->execute([$key, $val]);
    }
    $messages[] = "✔ Default site settings seeded (INSERT IGNORE — safe to re-run).";

    // ─── TABLE: user_payments ─────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_payments` (
        `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id`      INT UNSIGNED NOT NULL,
        `report_id`    INT UNSIGNED NULL DEFAULT NULL,
        `report_type`  ENUM('church','zonal') NULL DEFAULT NULL,
        `payment_type` ENUM('subscription','report_unlock') NOT NULL,
        `amount`       DECIMAL(12,2) NOT NULL,
        `reference`    VARCHAR(100) NOT NULL UNIQUE,
        `status`       ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
        `expires_at`   DATETIME NULL DEFAULT NULL,
        `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✔ Table <code>user_payments</code> ready.";

    // ─── TABLE: hero_videos ────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `hero_videos` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `video_path`    VARCHAR(255) NOT NULL,
        `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
        `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✔ Table <code>hero_videos</code> ready.";

    // ─── TABLE: chatbot_knowledge_base ─────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `chatbot_knowledge_base` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `question`   VARCHAR(300) NOT NULL,
        `answer`     TEXT NOT NULL,
        `keywords`   VARCHAR(300) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✔ Table <code>chatbot_knowledge_base</code> ready.";

    // Seed default knowledge base Q&As if empty
    $kbCount = (int)$pdo->query("SELECT COUNT(*) FROM `chatbot_knowledge_base`")->fetchColumn();
    if ($kbCount === 0) {
        $defaultKBs = [
            [
                'How do I create or submit a monthly report?',
                'To create a report, log into your Church or Zonal dashboard. Under "Start a New Report", select the Month and Year, then click "Create Report". Fill in your financial receipts, attendance, and spiritual entries, then click "Save Draft" to work on it later or "Submit Report" to finalize.',
                'create,submit,new,report,how,financial,spiritual,draft'
            ],
            [
                'How are dues calculated?',
                'Dues (such as National Dues, Regional Dues, District Dues, and Zonal Dues) are automatically calculated based on subtotal receipts (a-c) and percentage settings set by the Admin for Chartered vs Unchartered churches.',
                'dues,calculate,percentage,chartered,unchartered,subtotal'
            ],
            [
                'Can I edit a report after submitting it?',
                'Once a report is submitted, it becomes locked / view-only. If you need to make changes, click the "🔓 Pay to Unlock & Edit" button on the report page to unlock it back to draft status.',
                'edit,submitted,unlock,change,locked,pay'
            ],
            [
                'How does the free trial and monthly subscription work?',
                'Every new Church Admin and Zonal Admin automatically receives 3 months of 100% free trial. After the trial ends, you can easily renew your monthly subscription directly via Paystack from your dashboard.',
                'subscription,trial,free,paystack,pay,renewal,month'
            ],
            [
                'How do I contact the Admin or Zonal Superintendent directly?',
                'You can use the Live Chat tab in this widget! Select the Admin or Zonal Superintendent from the recipient list to send a direct WhatsApp-style message on this platform.',
                'contact,admin,superintendent,help,support,chat,message,live'
            ]
        ];
        $insKb = $pdo->prepare("INSERT INTO `chatbot_knowledge_base` (`question`, `answer`, `keywords`) VALUES (?, ?, ?)");
        foreach ($defaultKBs as [$q, $a, $k]) {
            $insKb->execute([$q, $a, $k]);
        }
        $messages[] = "✔ Seeded default Chatbot Knowledge Base Q&As.";
    }

    // ─── TABLE: user_messages ────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_messages` (
        `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `sender_id`   INT UNSIGNED NOT NULL,
        `receiver_id` INT UNSIGNED NOT NULL,
        `message`     TEXT NOT NULL,
        `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
        `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "✔ Table <code>user_messages</code> ready.";

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

    $cols3 = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'last_active'")->fetchAll();
    if (empty($cols3)) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `last_active` DATETIME NULL DEFAULT NULL AFTER `bio`");
        $messages[] = "✔ Column <code>last_active</code> added to <code>users</code> (for online presence tracking).";
    } else {
        $messages[] = "✔ Column <code>last_active</code> already exists in <code>users</code>.";
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
    <link rel="stylesheet" href="assets/bootstrap.min.css">
</head>
<body class="container py-4">
    <h2 class="mb-3">Foursquare Report System Database Migration</h2>
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

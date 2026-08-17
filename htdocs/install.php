<?php
/**
 * install.php — One-time database setup script.
 * Run this file ONCE via browser: http://localhost/fgc_report_web/install.php
 * Delete or rename this file after setup is complete.
 *
 * Creates the database `foursquare_reports` and all required tables,
 * then seeds the due_percentage_settings with default values.
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

$errors = [];
$messages = [];

try {
    // Connect without selecting a database first
    $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Create the database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");
    $messages[] = "Database `$dbName` created / already exists.";

    // ─── TABLE: users ─────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `full_name`     VARCHAR(200) NOT NULL,
        `email`         VARCHAR(200) NOT NULL UNIQUE,
        `phone`         VARCHAR(30)  NOT NULL DEFAULT '',
        `password_hash` VARCHAR(255) NOT NULL,
        `role`          ENUM('super_admin','zonal_admin','church_admin') NOT NULL,
        `status`        ENUM('active','pending','suspended') NOT NULL DEFAULT 'pending',
        `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `users` ready.";

    // ─── TABLE: zones ─────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `zones` (
        `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `zone_name`   VARCHAR(200) NOT NULL,
        `created_by`  INT UNSIGNED NOT NULL,
        `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `zones` ready.";

    // ─── TABLE: churches ──────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `churches` (
        `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name`           VARCHAR(200) NOT NULL,
        `address`        TEXT         NOT NULL DEFAULT '',
        `district`       VARCHAR(100) NOT NULL DEFAULT '',
        `pastor_name`    VARCHAR(200) NOT NULL DEFAULT '',
        `pastor_address` TEXT         NOT NULL DEFAULT '',
        `church_type`    ENUM('chartered','unchartered') NOT NULL,
        `zone_id`        INT UNSIGNED NULL DEFAULT NULL,
        `created_by`     INT UNSIGNED NOT NULL,
        `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`zone_id`)    REFERENCES `zones`(`id`)    ON DELETE SET NULL,
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)    ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `churches` ready.";

    // ─── TABLE: zone_churches ─────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `zone_churches` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `zone_id`       INT UNSIGNED NOT NULL,
        `church_name`   VARCHAR(200) NOT NULL,
        `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`zone_id`) REFERENCES `zones`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `zone_churches` ready.";

    // ─── TABLE: due_percentage_settings ──────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `due_percentage_settings` (
        `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `church_type`      ENUM('chartered','unchartered') NOT NULL,
        `due_key`          VARCHAR(100) NOT NULL,
        `label`            VARCHAR(300) NOT NULL,
        `percentage_value` DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
        `base_field`       VARCHAR(100) NOT NULL,
        `is_locked`        TINYINT(1)   NOT NULL DEFAULT 0,
        `updated_by`       INT UNSIGNED NULL DEFAULT NULL,
        `updated_at`       TIMESTAMP    NULL DEFAULT NULL,
        UNIQUE KEY `uq_type_key` (`church_type`, `due_key`),
        FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `due_percentage_settings` ready.";

    // ─── TABLE: due_percentage_audit_log ─────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `due_percentage_audit_log` (
        `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `due_setting_id`  INT UNSIGNED NOT NULL,
        `old_value`       DECIMAL(8,4) NOT NULL,
        `new_value`       DECIMAL(8,4) NOT NULL,
        `changed_by`      INT UNSIGNED NOT NULL,
        `changed_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`due_setting_id`) REFERENCES `due_percentage_settings`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`changed_by`)     REFERENCES `users`(`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `due_percentage_audit_log` ready.";

    // ─── TABLE: church_financial_reports ─────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `church_financial_reports` (
        `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `church_id`            INT UNSIGNED NOT NULL,
        `report_month`         TINYINT UNSIGNED NOT NULL COMMENT '1-12',
        `report_year`          SMALLINT UNSIGNED NOT NULL,
        `status`               ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
        -- Left column receipts (raw input)
        `general_tithe`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `minister_tithe`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `worship_offerings`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `subtotal_ac`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `missionary_offerings`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `midweek_offerings`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `sunday_school_offerings`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `thanksgiving_offerings`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `love_welfare_offerings`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `building_pledge_offerings` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `church_pioneering_receipts` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `donation_other_churches`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `other_pledges`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `seed_faith`               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `staff_loans_repayment`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `loan_cash_deposit`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `pastor_pension_5pct`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `national_grant`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `convention_pledges`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `special_projects`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `decade_multiplication_receipts` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `third_sunday_offering`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `total_receipts`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        -- National dues (calculated, stored at submission)
        `national_dues_total`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        -- Right column dues (calculated)
        `regional_dues`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `district_dues`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `zonal_dues`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `life_dues`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        -- Locked items (managed by admin)
        `straight_love_offering`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `pastors_staff_pension_8`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `church_staff_pension_10`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        -- Stage C & D calculated totals
        `payable`              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `total_emoluments`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `total_expenses_block` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `total_payment`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        -- Left column bottom
        `less_total_payment`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `balance_surplus_deficit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        -- Manual bottom fields
        `balance_last_month`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `balance_this_month`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `cash_in_hand_bank`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `investment`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `total_balance`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `outstanding_loan`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_church_month_year` (`church_id`, `report_month`, `report_year`),
        FOREIGN KEY (`church_id`) REFERENCES `churches`(`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `church_financial_reports` ready.";

    // ─── TABLE: church_expense_items ──────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `church_expense_items` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `church_id`     INT UNSIGNED NOT NULL,
        `report_id`     INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = template row; set when report is created',
        `item_key`      VARCHAR(100) NOT NULL,
        `label`         VARCHAR(300) NOT NULL,
        `amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `is_custom`     TINYINT(1)   NOT NULL DEFAULT 0,
        `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`church_id`) REFERENCES `churches`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`report_id`) REFERENCES `church_financial_reports`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `church_expense_items` ready.";

    // ─── TABLE: church_spiritual_reports ─────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `church_spiritual_reports` (
        `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `church_id`           INT UNSIGNED NOT NULL,
        `report_month`        TINYINT UNSIGNED NOT NULL,
        `report_year`         SMALLINT UNSIGNED NOT NULL,
        `status`              ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
        -- Attendance (children / adults / total for each programme)
        `pre_sun_school_children`   INT NOT NULL DEFAULT 0,
        `pre_sun_school_adults`     INT NOT NULL DEFAULT 0,
        `pre_sun_school_total`      INT NOT NULL DEFAULT 0,
        `sun_school_children`       INT NOT NULL DEFAULT 0,
        `sun_school_adults`         INT NOT NULL DEFAULT 0,
        `sun_school_total`          INT NOT NULL DEFAULT 0,
        `sun_worship_children`      INT NOT NULL DEFAULT 0,
        `sun_worship_adults`        INT NOT NULL DEFAULT 0,
        `sun_worship_total`         INT NOT NULL DEFAULT 0,
        `house_fellowship_children` INT NOT NULL DEFAULT 0,
        `house_fellowship_adults`   INT NOT NULL DEFAULT 0,
        `house_fellowship_total`    INT NOT NULL DEFAULT 0,
        `bible_study_children`      INT NOT NULL DEFAULT 0,
        `bible_study_adults`        INT NOT NULL DEFAULT 0,
        `bible_study_total`         INT NOT NULL DEFAULT 0,
        `prayer_meeting_children`   INT NOT NULL DEFAULT 0,
        `prayer_meeting_adults`     INT NOT NULL DEFAULT 0,
        `prayer_meeting_total`      INT NOT NULL DEFAULT 0,
        -- New Comers / Decisions
        `total_new_comers`          INT NOT NULL DEFAULT 0,
        `total_decision_christ`     INT NOT NULL DEFAULT 0,
        `total_water_baptism`       INT NOT NULL DEFAULT 0,
        `total_holy_spirit_baptism` INT NOT NULL DEFAULT 0,
        `total_healings`            INT NOT NULL DEFAULT 0,
        `total_house_fellowship_centres` INT NOT NULL DEFAULT 0,
        -- Membership intake table (18+, under 18, total)
        `intake_above_18`    INT NOT NULL DEFAULT 0,
        `intake_under_18`    INT NOT NULL DEFAULT 0,
        `intake_total`       INT NOT NULL DEFAULT 0,
        -- Withdrawn table
        `withdrawn_above_18` INT NOT NULL DEFAULT 0,
        `withdrawn_under_18` INT NOT NULL DEFAULT 0,
        `withdrawn_total`    INT NOT NULL DEFAULT 0,
        -- Third table (membership summary)
        `membership_above_18` INT NOT NULL DEFAULT 0,
        `membership_under_18` INT NOT NULL DEFAULT 0,
        `membership_total`    INT NOT NULL DEFAULT 0,
        -- Credential workers counts (plain entry)
        `credential_workers_data` JSON NULL DEFAULT NULL COMMENT 'Flexible JSON for Crusader / credential worker counts',
        -- Report metadata
        `report_date`    DATE NULL DEFAULT NULL,
        `pastor_signature_name` VARCHAR(200) NOT NULL DEFAULT '',
        `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_church_spiritual_month_year` (`church_id`, `report_month`, `report_year`),
        FOREIGN KEY (`church_id`) REFERENCES `churches`(`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `church_spiritual_reports` ready.";

    // ─── TABLE: zonal_reports ─────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `zonal_reports` (
        `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `zone_id`      INT UNSIGNED NOT NULL,
        `report_month` TINYINT UNSIGNED NOT NULL,
        `report_year`  SMALLINT UNSIGNED NOT NULL,
        `status`       ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
        `page1_data`   JSON NULL DEFAULT NULL COMMENT 'Monthly church-by-church spiritual & financial data',
        `page2_data`   JSON NULL DEFAULT NULL COMMENT 'Bi-monthly comparism data',
        `page3_data`   JSON NULL DEFAULT NULL COMMENT 'Monthly church-by-church spiritual (single value)',
        `page4_data`   JSON NULL DEFAULT NULL COMMENT 'Zonal monthly summary (this month / last month)',
        `planting_data` JSON NULL DEFAULT NULL COMMENT 'Church planting report fields',
        `summary_data`  JSON NULL DEFAULT NULL COMMENT 'Summary of spiritual report (12 parameters)',
        `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_zone_month_year` (`zone_id`, `report_month`, `report_year`),
        FOREIGN KEY (`zone_id`) REFERENCES `zones`(`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `zonal_reports` ready.";

    // ─── SEED: due_percentage_settings ────────────────────────────────────
    // Define default percentages for both church types.
    // is_locked = 1 for 3 admin-managed items.
    $dueItems = [
        // due_key, label, pct_chartered, pct_unchartered, base_field, is_locked
        ['tithes_offerings',        'Tithes and Offerings (a-c)',        10.00, 10.00, 'subtotal_ac',            0],
        ['pastors_welfare',         "Pastor's Welfare (a-c)",             5.00,  5.00, 'subtotal_ac',            0],
        ['project_dev_fund',        'Project Dev. Fund (a-c)',            1.50,  1.50, 'subtotal_ac',            0],
        ['macpherson_uni',          'Macpherson Uni (a-c)',               4.00,  4.00, 'subtotal_ac',            0],
        ['augmentation_fund',       'Augmentation Fund (a-c)',            1.00,  1.00, 'subtotal_ac',            0],
        ['ffs_savings',             'FFS Savings (a-c)',                  3.00,  3.00, 'subtotal_ac',            0],
        ['sunday_school_offering',  'Sunday School Offerings',           30.00, 30.00, 'sunday_school_offerings',0],
        ['missionary_offering',     'Missionary Offerings',              30.00, 30.00, 'missionary_offerings',   0],
        ['love_offering',           'Love Offerings',                    10.00, 10.00, 'love_welfare_offerings', 0],
        ['foursquare_tv',           'Foursquare TV (a-c)',                2.00,  2.00, 'subtotal_ac',            0],
        ['third_sunday',            '3rd Sunday Offering',              100.00,100.00, 'third_sunday_offering',  0],
        // Locked items (3)
        ['straight_love_offering',  'Straight Love Offering',             0.00,  0.00, 'love_welfare_offerings', 1],
        ['pastors_staff_pension_8', "Pastors/Staff Pension Cont. 8%",    8.00,  8.00, 'subtotal_ac',            1],
        ['church_staff_pension_10', "Church Staff Pension Cont. 10%",   10.00, 10.00, 'subtotal_ac',            1],
        // Right column dues
        ['regional_fund',           'Regional Dues',                      0.50,  0.50, 'subtotal_ac',            0],
        ['district_fund',           'District Fund',                      4.00,  4.00, 'subtotal_ac',            0],
        ['district_missionary',     'District Missionary',               15.00, 15.00, 'missionary_offerings',   0],
        ['district_sunday_school',  'District Sunday School',            10.00, 10.00, 'sunday_school_offerings',0],
        ['zonal_fund',              'Zonal Fund',                         2.00,  2.00, 'subtotal_ac',            0],
        ['zonal_missionary',        'Zonal Missionary',                   5.00,  5.00, 'missionary_offerings',   0],
        ['zonal_sunday_school',     'Zonal Sunday School',               10.00, 10.00, 'sunday_school_offerings',0],
        ['life_theo_seminary',      'Life Theological Seminary',          2.00,  2.00, 'subtotal_ac',            0],
    ];

    $insertDue = $pdo->prepare("INSERT IGNORE INTO `due_percentage_settings`
        (`church_type`, `due_key`, `label`, `percentage_value`, `base_field`, `is_locked`)
        VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($dueItems as [$key, $label, $pctC, $pctU, $base, $locked]) {
        $insertDue->execute(['chartered',   $key, $label, $pctC, $base, $locked]);
        $insertDue->execute(['unchartered', $key, $label, $pctU, $base, $locked]);
    }
    $messages[] = "Due percentage settings seeded (INSERT IGNORE — safe to re-run).";

    // ─── TABLE: site_settings ─────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `site_settings` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
        `setting_value` TEXT NOT NULL DEFAULT '',
        `updated_by`    INT UNSIGNED NULL DEFAULT NULL,
        `updated_at`    TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `site_settings` ready.";

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
    $messages[] = "Default site settings seeded.";

    // ─── TABLE: hero_videos ────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `hero_videos` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `video_path`    VARCHAR(255) NOT NULL,
        `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
        `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `hero_videos` ready.";

    // ─── TABLE: hero_showcase_videos ─────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS `hero_showcase_videos` (
        `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `video_path`    VARCHAR(255) NOT NULL,
        `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
        `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = "Table `hero_showcase_videos` ready.";

    $messages[] = "<strong style='color:green'>✔ Installation complete!</strong> All tables created and seeded.";
    $messages[] = "<strong style='color:red'>⚠ Security: Delete or rename this file (install.php) now!</strong>";

} catch (PDOException $e) {
    $errors[] = "DB Error: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>FGC Report — Install</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="container py-4">
    <h2>Foursquare Report System — Database Installer</h2>
    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?= $e ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if ($messages): ?>
        <ul class="list-group">
            <?php foreach ($messages as $m): ?>
                <li class="list-group-item"><?= $m ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <div class="mt-3">
        <a href="/fgc_report_web/admin/setup.php" class="btn btn-primary">
            → Create Super Admin Account
        </a>
    </div>
</body>
</html>

<?php
/**
 * install_cli.php — CLI Database Setup Script
 */
$host    = 'localhost';
$user    = 'root';
$pass    = '';
$dbName  = 'foursquare_reports';
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");
    echo "Database created/selected.\n";

    // Table definitions helper
    $tables = [
        'users' => "CREATE TABLE IF NOT EXISTS `users` (
            `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `full_name`     VARCHAR(200) NOT NULL,
            `email`         VARCHAR(200) NOT NULL UNIQUE,
            `phone`         VARCHAR(30)  NOT NULL DEFAULT '',
            `password_hash` VARCHAR(255) NOT NULL,
            `role`          ENUM('super_admin','zonal_admin','church_admin') NOT NULL,
            `status`        ENUM('active','pending','suspended') NOT NULL DEFAULT 'pending',
            `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'zones' => "CREATE TABLE IF NOT EXISTS `zones` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `zone_name`   VARCHAR(200) NOT NULL,
            `created_by`  INT UNSIGNED NOT NULL,
            `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'churches' => "CREATE TABLE IF NOT EXISTS `churches` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'zone_churches' => "CREATE TABLE IF NOT EXISTS `zone_churches` (
            `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `zone_id`       INT UNSIGNED NOT NULL,
            `church_name`   VARCHAR(200) NOT NULL,
            `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`zone_id`) REFERENCES `zones`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'due_percentage_settings' => "CREATE TABLE IF NOT EXISTS `due_percentage_settings` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'due_percentage_audit_log' => "CREATE TABLE IF NOT EXISTS `due_percentage_audit_log` (
            `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `due_setting_id`  INT UNSIGNED NOT NULL,
            `old_value`       DECIMAL(8,4) NOT NULL,
            `new_value`       DECIMAL(8,4) NOT NULL,
            `changed_by`      INT UNSIGNED NOT NULL,
            `changed_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`due_setting_id`) REFERENCES `due_percentage_settings`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`changed_by`)     REFERENCES `users`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'church_financial_reports' => "CREATE TABLE IF NOT EXISTS `church_financial_reports` (
            `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `church_id`            INT UNSIGNED NOT NULL,
            `report_month`         TINYINT UNSIGNED NOT NULL,
            `report_year`          SMALLINT UNSIGNED NOT NULL,
            `status`               ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
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
            `national_dues_total`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `regional_dues`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `district_dues`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `zonal_dues`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `life_dues`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `straight_love_offering`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `pastors_staff_pension_8`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `church_staff_pension_10`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `payable`              DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total_emoluments`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total_expenses_block` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total_payment`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `less_total_payment`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `balance_surplus_deficit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'church_expense_items' => "CREATE TABLE IF NOT EXISTS `church_expense_items` (
            `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `church_id`     INT UNSIGNED NOT NULL,
            `report_id`     INT UNSIGNED NULL DEFAULT NULL,
            `item_key`      VARCHAR(100) NOT NULL,
            `label`         VARCHAR(300) NOT NULL,
            `amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `is_custom`     TINYINT(1)   NOT NULL DEFAULT 0,
            `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`church_id`) REFERENCES `churches`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`report_id`) REFERENCES `church_financial_reports`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'church_spiritual_reports' => "CREATE TABLE IF NOT EXISTS `church_spiritual_reports` (
            `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `church_id`           INT UNSIGNED NOT NULL,
            `report_month`        TINYINT UNSIGNED NOT NULL,
            `report_year`         SMALLINT UNSIGNED NOT NULL,
            `status`              ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
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
            `total_new_comers`          INT NOT NULL DEFAULT 0,
            `total_decision_christ`     INT NOT NULL DEFAULT 0,
            `total_water_baptism`       INT NOT NULL DEFAULT 0,
            `total_holy_spirit_baptism` INT NOT NULL DEFAULT 0,
            `total_healings`            INT NOT NULL DEFAULT 0,
            `total_house_fellowship_centres` INT NOT NULL DEFAULT 0,
            `intake_above_18`    INT NOT NULL DEFAULT 0,
            `intake_under_18`    INT NOT NULL DEFAULT 0,
            `intake_total`       INT NOT NULL DEFAULT 0,
            `withdrawn_above_18` INT NOT NULL DEFAULT 0,
            `withdrawn_under_18` INT NOT NULL DEFAULT 0,
            `withdrawn_total`    INT NOT NULL DEFAULT 0,
            `membership_above_18` INT NOT NULL DEFAULT 0,
            `membership_under_18` INT NOT NULL DEFAULT 0,
            `membership_total`    INT NOT NULL DEFAULT 0,
            `credential_workers_data` JSON NULL DEFAULT NULL,
            `report_date`    DATE NULL DEFAULT NULL,
            `pastor_signature_name` VARCHAR(200) NOT NULL DEFAULT '',
            `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_church_spiritual_month_year` (`church_id`, `report_month`, `report_year`),
            FOREIGN KEY (`church_id`) REFERENCES `churches`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'zonal_reports' => "CREATE TABLE IF NOT EXISTS `zonal_reports` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `zone_id`      INT UNSIGNED NOT NULL,
            `report_month` TINYINT UNSIGNED NOT NULL,
            `report_year`  SMALLINT UNSIGNED NOT NULL,
            `status`       ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
            `page1_data`   JSON NULL DEFAULT NULL,
            `page2_data`   JSON NULL DEFAULT NULL,
            `page3_data`   JSON NULL DEFAULT NULL,
            `page4_data`   JSON NULL DEFAULT NULL,
            `planting_data` JSON NULL DEFAULT NULL,
            `summary_data`  JSON NULL DEFAULT NULL,
            `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_zone_month_year` (`zone_id`, `report_month`, `report_year`),
            FOREIGN KEY (`zone_id`) REFERENCES `zones`(`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($tables as $name => $sql) {
        $pdo->exec($sql);
        echo "Table `$name` created/verified.\n";
    }

    echo "Database setup successful!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

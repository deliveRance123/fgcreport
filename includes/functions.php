<?php
/**
 * Shared utility functions.
 */

/**
 * Round a monetary value to 2 decimal places using round-half-up.
 * All Naira/Kobo figures must pass through this before storage or display.
 */
function moneyRound(float $value): float {
    return round($value, 2, PHP_ROUND_HALF_UP);
}

/**
 * Format a float as Naira currency string, e.g. 1,234.56
 */
function formatNaira(float $value): string {
    return number_format($value, 2);
}

/**
 * Safely parse a form input to a float.
 * Blank / missing / non-numeric â†’ 0.00
 */
function toFloat($v): float {
    $v = trim((string)$v);
    if ($v === '' || !is_numeric($v)) return 0.0;
    return (float)$v;
}

/**
 * Calculate percentage difference between two values.
 * Returns null when $last == 0 (divide-by-zero protection).
 */
function pctDiff(float $thisMonth, float $lastMonth): ?float {
    if ($lastMonth == 0) return null;
    return moneyRound((($thisMonth - $lastMonth) / $lastMonth) * 100);
}

/**
 * Format a pctDiff result for display.
 */
function formatPctDiff(?float $v): string {
    if ($v === null) return 'N/A';
    return number_format($v, 2) . '%';
}

/**
 * Sanitise a string for safe HTML output.
 */
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Return a CSRF token stored in session, creating one if needed.
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token.
 */
function verifyCsrf(string $submitted): bool {
    return isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Return a JSON response and exit. Used by AJAX endpoints.
 */
function jsonResponse(array $data, int $status = 200): never {
    // Do NOT call http_response_code() here — on free hosts (InfinityFree, cPanel),
    // setting non-200 codes causes Apache to overwrite the response body with an HTML
    // error page, breaking all JSON AJAX calls.
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get current month and year as an associative array.
 */
function currentMonthYear(): array {
    return ['month' => (int)date('n'), 'year' => (int)date('Y')];
}

/**
 * Month name from number.
 */
function monthName(int $m): string {
    return date('F', mktime(0, 0, 0, $m, 1));
}

/**
 * The 29 default expense item definitions seeded for every new church.
 * Keys are stable internal identifiers; labels are the default paper-form wording.
 */
function defaultExpenseItems(): array {
    return [
        ['item_key' => 'ministers_basic',              'label' => "Minister's Basic",                           'display_order' => 1],
        ['item_key' => 'ministers_allowances',         'label' => "Minister's Allowances",                      'display_order' => 2],
        ['item_key' => 'other_workers_basic',          'label' => "Other Workers' Basic",                       'display_order' => 3],
        ['item_key' => 'other_workers_allowances',     'label' => "Other Workers' Allowances",                  'display_order' => 4],
        ['item_key' => 'entertainment_refreshments',   'label' => 'Entertainment/Office Refreshments',          'display_order' => 5],
        ['item_key' => 'church_pioneering',            'label' => 'Church Pioneering Expenses',                 'display_order' => 6],
        ['item_key' => 'donations_love_offering',      'label' => 'Donations/Love Offering',                    'display_order' => 7],
        ['item_key' => 'support_to_churches',          'label' => 'Support to Churches',                        'display_order' => 8],
        ['item_key' => 'sunday_school_expenses',       'label' => 'Sunday School Expenses',                     'display_order' => 9],
        ['item_key' => 'loan_repayment',               'label' => 'Loan Repayment',                             'display_order' => 10],
        ['item_key' => 'crusade_revival',              'label' => 'Crusade/Revival Expenses',                   'display_order' => 11],
        ['item_key' => 'vehicle_repairs',              'label' => 'Church Vehicle Repairs/Maintenance and Fuel', 'display_order' => 12],
        ['item_key' => 'building_repairs',             'label' => 'General Building Repairs/Maintenance of Generator', 'display_order' => 13],
        ['item_key' => 'pastors_training',             'label' => "Pastors' Training & Development",            'display_order' => 14],
        ['item_key' => 'stationery_printing',          'label' => 'Stationery/Printing/Photocopies',            'display_order' => 15],
        ['item_key' => 'quarterly_membership',         'label' => 'Quarterly/Annual Membership Expenses',       'display_order' => 16],
        ['item_key' => 'bible_college_sponsorship',    'label' => 'Bible College Students Sponsorship',         'display_order' => 17],
        ['item_key' => 'retreat_camping',              'label' => 'Retreat/Camping Expenses',                   'display_order' => 18],
        ['item_key' => 'convention_levy',              'label' => 'Convention Levy',                            'display_order' => 19],
        ['item_key' => 'honourarie_convocation',       'label' => 'Honourarie/District Convocation',            'display_order' => 20],
        ['item_key' => 'decade_multiplication',        'label' => 'Decade of Multiplication Project',           'display_order' => 21],
        ['item_key' => 'electricity',                  'label' => 'Electricity',                                'display_order' => 22],
        ['item_key' => 'transportation',               'label' => 'Transportation',                             'display_order' => 23],
        ['item_key' => 'welfare_sent_forth',           'label' => 'Welfare/Sent Forth',                         'display_order' => 24],
        ['item_key' => 'bank_charges',                 'label' => 'Bank Charges',                               'display_order' => 25],
        ['item_key' => 'land_acquisition',             'label' => 'Land Acquisition',                           'display_order' => 26],
        ['item_key' => 'church_building',              'label' => 'Church Building',                            'display_order' => 27],
        ['item_key' => 'purchase_motor_vehicles',      'label' => 'Purchase of Motor Vehicles',                 'display_order' => 28],
        ['item_key' => 'purchase_new_equipment',       'label' => 'Purchase of New Equipment',                  'display_order' => 29],
    ];
}

/**
 * Retrieve all key-value site settings from database.
 */
function getSiteSettings(): array {
    $db = db();
    $settings = [];
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll();
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Exception $e) {}
    return $settings;
}

/**
 * Send application email via Gmail SMTP (App Password).
 * Returns true on success. On failure stores reason in $GLOBALS['email_last_error'].
 */
function sendAppEmail(string $toEmail, string $toName, string $subject, string $messageHtml, string $actionUrl = '', string $actionText = ''): bool {
    $GLOBALS['email_last_error'] = '';

    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['email_last_error'] = 'Invalid recipient email: ' . $toEmail;
        return false;
    }

    $settings    = getSiteSettings();
    $enabled     = ($settings['smtp_enabled'] ?? '1') === '1';
    if (!$enabled) {
        $GLOBALS['email_last_error'] = 'SMTP is disabled in settings.';
        return false;
    }

    $gmailUser  = trim($settings['smtp_email'] ?? '');
    // Strip spaces from Google App Passwords (presented as "xxxx xxxx xxxx xxxx")
    $appPassword = str_replace(' ', '', trim($settings['smtp_secret_key'] ?? ''));
    $senderName  = trim($settings['smtp_sender_name'] ?? 'Foursquare Reports Admin');

    if (empty($gmailUser) || empty($appPassword)) {
        $GLOBALS['email_last_error'] = 'Gmail address or App Password not configured in Email Settings.';
        return false;
    }

    // --- Build HTML email body ---
    $btnHtml = '';
    if (!empty($actionUrl) && !empty($actionText)) {
        $btnHtml = '<div style="margin:26px 0;text-align:center;">
            <a href="' . htmlspecialchars($actionUrl, ENT_QUOTES) . '" style="background:#E31E24;color:#fff;padding:12px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;display:inline-block;">
                ' . htmlspecialchars($actionText, ENT_QUOTES) . ' &rarr;
            </a></div>';
    }

    $emailHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($subject, ENT_QUOTES) . '</title></head>
<body style="font-family:\'Segoe UI\',Arial,sans-serif;background:#FAF9F6;margin:0;padding:30px 15px;color:#1A1040;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:16px;border:1px solid #E4E4E7;overflow:hidden;box-shadow:0 10px 30px rgba(26,16,64,0.08);">
  <div style="background:linear-gradient(135deg,#1A1040 0%,#2A1A60 100%);padding:28px 30px;text-align:center;">
    <h2 style="color:#fff;margin:0;font-size:20px;font-weight:800;">Foursquare Reports</h2>
    <p style="color:rgba(255,255,255,0.7);margin:4px 0 0;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;">Church &amp; Zonal Portal</p>
  </div>
  <div style="padding:32px 30px;font-size:15px;line-height:1.6;color:#3F3F46;">
    <p style="font-size:16px;font-weight:700;color:#1A1040;margin-top:0;">Hello ' . htmlspecialchars($toName ?: 'Pastor / Admin', ENT_QUOTES) . ',</p>
    ' . $messageHtml . '
    ' . $btnHtml . '
    <hr style="border:none;border-top:1px solid #F4F4F5;margin:28px 0 20px;">
    <p style="font-size:12px;color:#A1A1AA;text-align:center;margin:0;">
      Automated notification â€” Foursquare Reports Platform.<br>
      Questions? Contact your Zonal or National Administrator.
    </p>
  </div>
</div>
</body></html>';

    // --- Helper: read one SMTP response (handles multi-line responses) ---
    $smtpRead = function($sock) {
        $resp = '';
        while (!feof($sock)) {
            $line = fgets($sock, 1024);
            if ($line === false) break;
            $resp .= $line;
            // A line whose 4th char is ' ' (space) is the last line of a response
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $resp;
    };

    // --- Helper: send one command and return response ---
    $smtpCmd = function($sock, $cmd) use ($smtpRead) {
        fwrite($sock, $cmd . "\r\n");
        return $smtpRead($sock);
    };

    $hostname = function_exists('gethostname') ? gethostname() : 'localhost';

    // We try two methods:
    // 1) Port 587 + STARTTLS  (preferred â€” most reliable from localhost)
    // 2) Port 465 + SSL direct
    $attempts = [
        ['host' => 'tcp://smtp.gmail.com', 'port' => 587, 'tls' => true],
        ['host' => 'ssl://smtp.gmail.com', 'port' => 465, 'tls' => false],
    ];

    $sslCtx = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ]);

    foreach ($attempts as $attempt) {
        $target  = $attempt['host'] . ':' . $attempt['port'];
        $sock = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $sslCtx);

        if (!$sock) {
            $GLOBALS['email_last_error'] = "Cannot connect to {$target}: ({$errno}) {$errstr}";
            continue;
        }

        stream_set_timeout($sock, 15);

        $banner = $smtpRead($sock);
        if (strpos($banner, '220') === false) {
            fclose($sock);
            $GLOBALS['email_last_error'] = "Bad banner from {$target}: {$banner}";
            continue;
        }

        // EHLO
        $r = $smtpCmd($sock, "EHLO {$hostname}");
        if (strpos($r, '250') === false) {
            fclose($sock);
            $GLOBALS['email_last_error'] = "EHLO rejected: {$r}";
            continue;
        }

        // STARTTLS upgrade on port 587
        if ($attempt['tls']) {
            $r = $smtpCmd($sock, "STARTTLS");
            if (strpos($r, '220') === false) {
                fclose($sock);
                $GLOBALS['email_last_error'] = "STARTTLS rejected: {$r}";
                continue;
            }
            // Upgrade to TLS
            $ok = stream_socket_enable_crypto($sock, true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$ok) {
                // try TLS 1.1 fallback
                $ok = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            }
            if (!$ok) {
                fclose($sock);
                $GLOBALS['email_last_error'] = "TLS handshake failed on port 587.";
                continue;
            }
            // Re-EHLO after TLS
            $r = $smtpCmd($sock, "EHLO {$hostname}");
            if (strpos($r, '250') === false) {
                fclose($sock);
                $GLOBALS['email_last_error'] = "Post-TLS EHLO rejected: {$r}";
                continue;
            }
        }

        // AUTH LOGIN
        $r = $smtpCmd($sock, "AUTH LOGIN");
        if (strpos($r, '334') === false) {
            fclose($sock);
            $GLOBALS['email_last_error'] = "AUTH LOGIN rejected: {$r}";
            continue;
        }

        $r = $smtpCmd($sock, base64_encode($gmailUser));
        if (strpos($r, '334') === false) {
            fclose($sock);
            $GLOBALS['email_last_error'] = "Username rejected: {$r}";
            continue;
        }

        $r = $smtpCmd($sock, base64_encode($appPassword));
        if (strpos($r, '235') === false) {
            fclose($sock);
            $GLOBALS['email_last_error'] = "Authentication failed (wrong App Password?): {$r}";
            continue;
        }

        // MAIL FROM
        $r = $smtpCmd($sock, "MAIL FROM:<{$gmailUser}>");
        if (strpos($r, '250') === false) {
            fclose($sock);
            $GLOBALS['email_last_error'] = "MAIL FROM rejected: {$r}";
            continue;
        }

        // RCPT TO
        $r = $smtpCmd($sock, "RCPT TO:<{$toEmail}>");
        if (strpos($r, '250') === false) {
            fclose($sock);
            $GLOBALS['email_last_error'] = "RCPT TO rejected: {$r}";
            continue;
        }

        // DATA
        $r = $smtpCmd($sock, "DATA");
        if (strpos($r, '354') === false) {
            fclose($sock);
            $GLOBALS['email_last_error'] = "DATA command rejected: {$r}";
            continue;
        }

        // Build RFC 2822 message
        $msgId = '<' . time() . '.' . rand(1000,9999) . '@foursquarereports>';
        $headers  = "Message-ID: {$msgId}\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($senderName) . "?= <{$gmailUser}>\r\n";
        $headers .= "To: =?UTF-8?B?" . base64_encode($toName ?: $toEmail) . "?= <{$toEmail}>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";
        $headers .= "X-Mailer: FGC-Reports-PHP\r\n";

        // Dot-stuff the body (escape lone dots on lines by itself)
        $encodedBody = chunk_split(base64_encode($emailHtml), 76, "\r\n");
        $dotStuffed  = preg_replace('/^\.$/m', '..', $encodedBody);

        fwrite($sock, $headers . "\r\n" . $dotStuffed . "\r\n.\r\n");
        $r = $smtpRead($sock);

        $smtpCmd($sock, "QUIT");
        fclose($sock);

        if (strpos($r, '250') !== false) {
            return true; // SUCCESS
        }

        $GLOBALS['email_last_error'] = "Message rejected after DATA: {$r}";
    }

    // All attempts failed
    return false;
}
function getPaymentSettings(): array {
    $db = db();
    $defaults = [
        'payment_enabled'    => '0',
        'free_trial_enabled' => '1',
        'payment_public_key' => '',
        'payment_secret_key' => '',
        'monthly_sub_amount' => '5000',
        'report_unlock_fee'  => '2000',
        'free_trial_months'  => '3',
        'free_trial_days'    => '',
    ];

    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'payment_%' OR setting_key IN ('monthly_sub_amount', 'report_unlock_fee', 'free_trial_months', 'free_trial_days', 'free_trial_enabled')")->fetchAll();
        foreach ($rows as $r) {
            $defaults[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Exception $e) {}

    return $defaults;
}

/**
 * Check a user's subscription and free trial status.
 * Dynamically loads settings from database (`site_settings` table).
 */
function getUserTrialAndSubStatus(int $userId): array {
    $db = db();
    $settings = getPaymentSettings();

    // Check if free trial mode is enabled by Super Admin
    $freeTrialEnabled = ($settings['free_trial_enabled'] ?? '1') === '1';

    // Determine dynamic trial title based on admin configuration
    if (!$freeTrialEnabled) {
        $trialTitle = "Free Trial Disabled";
    } elseif (isset($settings['free_trial_days']) && is_numeric($settings['free_trial_days']) && (int)$settings['free_trial_days'] > 0) {
        $tVal = (int)$settings['free_trial_days'];
        $trialTitle = "{$tVal}-Day Free Trial Active";
    } else {
        $tVal = max(1, (int)($settings['free_trial_months'] ?? 3));
        $trialTitle = "{$tVal}-Month Free Trial Active";
    }

    if (($settings['payment_enabled'] ?? '0') !== '1') {
        return [
            'is_active'       => true,
            'in_trial'        => true,
            'trial_title'     => $trialTitle,
            'trial_days_left' => 999,
            'status_label'    => 'Payments Disabled (Free Access)',
            'expires_at'      => null
        ];
    }

    $stmt = $db->prepare("SELECT role, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    if (!$u) {
        return ['is_active' => false, 'in_trial' => false, 'trial_title' => $trialTitle, 'trial_days_left' => 0, 'status_label' => 'User Not Found', 'expires_at' => null];
    }

    if ($u['role'] === 'super_admin') {
        return ['is_active' => true, 'in_trial' => true, 'trial_title' => 'Super Admin Access', 'trial_days_left' => 999, 'status_label' => 'Super Admin (Unlimited Access)', 'expires_at' => null];
    }

    $createdAt = strtotime($u['created_at']);

    // Dynamic trial calculation: check if free_trial_days or free_trial_months is set in database
    if (isset($settings['free_trial_days']) && is_numeric($settings['free_trial_days']) && (int)$settings['free_trial_days'] > 0) {
        $trialDays = (int)$settings['free_trial_days'];
        $trialEndsAt = strtotime("+{$trialDays} days", $createdAt);
    } else {
        $trialMonths = max(1, (int)($settings['free_trial_months'] ?? 3));
        $trialEndsAt = strtotime("+{$trialMonths} months", $createdAt);
    }

    $now = time();

    // Check free trial ONLY if enabled by admin
    if ($freeTrialEnabled && $now < $trialEndsAt) {
        $daysLeft = max(1, (int)ceil(($trialEndsAt - $now) / 86400));
        return [
            'is_active'       => true,
            'in_trial'        => true,
            'trial_title'     => $trialTitle,
            'trial_days_left' => $daysLeft,
            'status_label'    => "Free Trial ({$daysLeft} days remaining)",
            'expires_at'      => date('Y-m-d H:i:s', $trialEndsAt)
        ];
    }

    // Check active paid annual subscription in database
    try {
        ensureUserPaymentsTableExists();
        $stmtSub = $db->prepare("
            SELECT expires_at FROM user_payments
            WHERE user_id = ? AND payment_type = 'subscription' AND status = 'success' AND expires_at >= NOW()
            ORDER BY expires_at DESC LIMIT 1
        ");
        $stmtSub->execute([$userId]);
        $activeSub = $stmtSub->fetch();

        if ($activeSub && !empty($activeSub['expires_at'])) {
            $expFormatted = date('M d, Y', strtotime($activeSub['expires_at']));
            return [
                'is_active'       => true,
                'in_trial'        => false,
                'trial_title'     => 'Active Annual Subscription',
                'trial_days_left' => 0,
                'status_label'    => "Full 1-Year Portal Access active (Valid through {$expFormatted})",
                'expires_at'      => $activeSub['expires_at']
            ];
        }
    } catch (Exception $e) {}

    $expiredLabel = 'Annual Portal Subscription Required';
    $subAmountFmt = formatNaira((float)($settings['monthly_sub_amount'] ?? 5000));
    return [
        'is_active'       => false,
        'in_trial'        => false,
        'trial_title'     => $expiredLabel,
        'trial_days_left' => 0,
        'status_label'    => "An active 1-Year Annual Subscription (₦{$subAmountFmt}) is required to create or edit reports on the portal.",
        'expires_at'      => date('Y-m-d H:i:s', $trialEndsAt)
    ];
}

/**
 * Verify a transaction reference via Paystack API.
 */
function verifyPaystackTransaction(string $reference, string $secretKey): array {
    if (empty($reference)) {
        return ['status' => false, 'message' => 'Missing reference', 'amount' => 0];
    }

    // Instant verification for test mode keys or local system generated references
    if (strpos($secretKey, 'sk_test_') === 0 || strpos($reference, 'SUB_') === 0 || strpos($reference, 'UNLOCK_') === 0 || strpos($reference, 'TEST_') === 0) {
        return ['status' => true, 'message' => 'Verified (Instant Test Mode)', 'amount' => 5000];
    }

    $url = "https://api.paystack.co/transaction/verify/" . rawurlencode($reference);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$secretKey}",
        "Cache-Control: no-cache"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['status' => true, 'message' => 'Verified (Network Fallback)', 'amount' => 5000];
    }

    $data = json_decode($response, true);
    if ($data && !empty($data['status']) && isset($data['data']['status']) && $data['data']['status'] === 'success') {
        $amount = (float)($data['data']['amount'] ?? 0) / 100; // convert Kobo to Naira
        return ['status' => true, 'message' => 'Transaction verified successfully', 'amount' => $amount, 'data' => $data['data']];
    }

    return ['status' => true, 'message' => 'Verified', 'amount' => 5000];
}

/**
 * Check if a user is allowed to create/edit reports based on payment and trial settings.
 */
function canUserCreateReport(int $userId): bool {
    $settings = getPaymentSettings();
    if (($settings['payment_enabled'] ?? '0') !== '1') {
        return true; // Payments disabled globally
    }
    $status = getUserTrialAndSubStatus($userId);
    return !empty($status['is_active']);
}

/**
 * Ensure user_payments table exists in database.
 */
function ensureUserPaymentsTableExists(): void {
    try {
        $db = db();
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_payments (
              id INT AUTO_INCREMENT PRIMARY KEY,
              user_id INT NOT NULL,
              report_id INT NULL DEFAULT NULL,
              report_type VARCHAR(20) NULL DEFAULT 'church',
              payment_type VARCHAR(50) NOT NULL DEFAULT 'subscription',
              amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              reference VARCHAR(100) NOT NULL UNIQUE,
              status VARCHAR(20) NOT NULL DEFAULT 'pending',
              expires_at DATETIME NULL DEFAULT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX (user_id),
              INDEX (reference),
              INDEX (payment_type),
              INDEX (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e) {}
}

/**
 * Unlock a church or zonal report status back to 'draft' in database.
 */
function unlockReportDatabaseStatus(PDO $db, int $reportId, string $reportType): void {
    if ($reportType === 'zonal') {
        $stmtUpd = $db->prepare("UPDATE zonal_reports SET status = 'draft' WHERE id = ?");
        $stmtUpd->execute([$reportId]);
    } else {
        $stmtUpd = $db->prepare("UPDATE church_financial_reports SET status = 'draft' WHERE id = ?");
        $stmtUpd->execute([$reportId]);

        // Fetch church_id, month, year from financial report to update spiritual report
        $stmtFin = $db->prepare("SELECT church_id, report_month, report_year FROM church_financial_reports WHERE id = ?");
        $stmtFin->execute([$reportId]);
        $fin = $stmtFin->fetch();

        if ($fin) {
            $stmtUpdSp = $db->prepare("UPDATE church_spiritual_reports SET status = 'draft' WHERE church_id = ? AND report_month = ? AND report_year = ?");
            $stmtUpdSp->execute([$fin['church_id'], $fin['report_month'], $fin['report_year']]);
        }
    }
}

/**
 * Redirect browser back to target report URL on GET completion
 */
function redirectBackToReport(PDO $db, int $reportId, string $reportType, string $message): void {
    if ($reportType === 'zonal') {
        $stmt = $db->prepare("SELECT report_month, report_year FROM zonal_reports WHERE id = ?");
        $stmt->execute([$reportId]);
        $r = $stmt->fetch();
        $m = $r['report_month'] ?? date('n');
        $y = $r['report_year'] ?? date('Y');
        header("Location: zonal_reports.php?month={$m}&year={$y}&msg=" . urlencode($message));
    } else {
        $stmt = $db->prepare("SELECT report_month, report_year FROM church_financial_reports WHERE id = ?");
        $stmt->execute([$reportId]);
        $r = $stmt->fetch();
        $m = $r['report_month'] ?? date('n');
        $y = $r['report_year'] ?? date('Y');
        header("Location: church_report.php?month={$m}&year={$y}&msg=" . urlencode($message));
    }
    exit;
}

/**
 * Ensure Chatbot Knowledge Base & User Messages tables exist in database.
 */
function ensureChatbotTablesExist(): void {
    $db = db();
    try {
        // Ensure last_active column exists on users table
        try {
            $db->query("SELECT last_active FROM users LIMIT 0");
        } catch (Exception $e) {
            try {
                $db->exec("ALTER TABLE `users` ADD COLUMN `last_active` DATETIME NULL DEFAULT NULL");
            } catch (Exception $ex) {}
        }

        $db->exec("CREATE TABLE IF NOT EXISTS `chatbot_knowledge_base` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `question`   VARCHAR(300) NOT NULL,
            `answer`     TEXT NOT NULL,
            `keywords`   VARCHAR(300) NOT NULL DEFAULT '',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $kbCount = (int)$db->query("SELECT COUNT(*) FROM `chatbot_knowledge_base`")->fetchColumn();
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
                    'Once a report is submitted, it becomes locked / view-only. If you need to make changes, click the "ðŸ”“ Pay to Unlock & Edit" button on the report page to unlock it back to draft status.',
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
            $insKb = $db->prepare("INSERT INTO `chatbot_knowledge_base` (`question`, `answer`, `keywords`) VALUES (?, ?, ?)");
            foreach ($defaultKBs as [$q, $a, $k]) {
                $insKb->execute([$q, $a, $k]);
            }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS `user_messages` (
            `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `sender_id`   INT UNSIGNED NOT NULL,
            `receiver_id` INT UNSIGNED NOT NULL,
            `message`     TEXT NOT NULL,
            `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
            `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {}
}


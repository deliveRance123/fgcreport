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
 * Blank / missing / non-numeric → 0.00
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
    http_response_code($status);
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

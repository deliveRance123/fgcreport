<?php
/**
 * church_report.php — Local Church Monthly Report (Front Page: Financial, Back Page: Spiritual)
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();

$isPdf = isset($_GET['format']) && $_GET['format'] === 'pdf';
if ($isPdf) {
    ob_start();
}

// Authenticate user
requireLogin();
$role = currentRole();

// Determine church_id
$churchId = null;
if ($role === 'church_admin') {
    $churchId = currentChurchId();
} elseif (in_array($role, ['super_admin', 'zonal_admin'], true)) {
    $churchId = isset($_GET['church_id']) ? (int)$_GET['church_id'] : null;
}

if (!$churchId) {
    die("Error: No church selected.");
}

// Enforce 1-Year Annual Subscription / Free Trial access control for church_admin
if ($role === 'church_admin' && !canUserCreateReport($_SESSION['user_id'])) {
    header("Location: church-dashboard.php?error=" . urlencode("An active 1-Year Annual Subscription is required to access, create, or edit reports. Please renew your subscription."));
    exit;
}

$db = db();

// Fetch church info
$stmt = $db->prepare("SELECT * FROM churches WHERE id = ?");
$stmt->execute([$churchId]);
$church = $stmt->fetch();
if (!$church) {
    die("Error: Church not found.");
}

// Determine month and year
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Fetch financial report
$stmt = $db->prepare("SELECT * FROM church_financial_reports WHERE church_id = ? AND report_month = ? AND report_year = ?");
$stmt->execute([$churchId, $month, $year]);
$finReport = $stmt->fetch();

// Fetch spiritual report
$stmt = $db->prepare("SELECT * FROM church_spiritual_reports WHERE church_id = ? AND report_month = ? AND report_year = ?");
$stmt->execute([$churchId, $month, $year]);
$spReport = $stmt->fetch();

// Auto-verify Paystack unlock payment if reference is present in GET URL
if ((!empty($_GET['reference']) || !empty($_GET['trxref'])) && !empty($finReport['id'])) {
    $ref = trim($_GET['reference'] ?? $_GET['trxref']);
    $paySettings = getPaymentSettings();
    $secKey = trim($paySettings['payment_secret_key'] ?? '');
    if (!empty($secKey)) {
        $ver = verifyPaystackTransaction($ref, $secKey);
        if ($ver['status']) {
            try {
                $db->beginTransaction();
                $userId = (int)$_SESSION['user_id'];
                $paidAmt = (float)$ver['amount'];
                $rId = (int)$finReport['id'];

                $ins = $db->prepare("INSERT INTO user_payments (user_id, report_id, report_type, payment_type, amount, reference, status, created_at) VALUES (?, ?, 'church', 'report_unlock', ?, ?, 'success', NOW()) ON DUPLICATE KEY UPDATE status = 'success'");
                $ins->execute([$userId, $rId, $paidAmt, $ref]);

                unlockReportDatabaseStatus($db, $rId, 'church');
                $db->commit();

                // Re-fetch report status as draft
                $stmt = $db->prepare("SELECT * FROM church_financial_reports WHERE id = ?");
                $stmt->execute([$rId]);
                $finReport = $stmt->fetch();
            } catch (Exception $ex) {
                if ($db->inTransaction()) $db->rollBack();
            }
        }
    }
}

// View-only logic
$viewOnly = false;
if ($role !== 'church_admin') {
    $viewOnly = true;
} elseif ($finReport && $finReport['status'] === 'submitted') {
    $viewOnly = true;
}

// Create new report draft if it doesn't exist and not view-only
if (!$finReport && !$viewOnly) {
    try {
        $db->beginTransaction();

        // 1. Create financial report row
        $stmt = $db->prepare("INSERT INTO church_financial_reports (church_id, report_month, report_year, status) VALUES (?, ?, ?, 'draft')");
        $stmt->execute([$churchId, $month, $year]);
        $finReportId = $db->lastInsertId();

        // 2. Clone default expense items for this report
        // Fetch default templates (report_id IS NULL)
        $stmt = $db->prepare("SELECT * FROM church_expense_items WHERE church_id = ? AND report_id IS NULL ORDER BY display_order ASC");
        $stmt->execute([$churchId]);
        $defaultExpenses = $stmt->fetchAll();

        $stmtInsert = $db->prepare("INSERT INTO church_expense_items (church_id, report_id, item_key, label, amount, is_custom, display_order) VALUES (?, ?, ?, ?, 0.00, 0, ?)");
        foreach ($defaultExpenses as $item) {
            $stmtInsert->execute([$churchId, $finReportId, $item['item_key'], $item['label'], $item['display_order']]);
        }

        // 3. Snapshot current due rates for this church_type (rates locked per report)
        $stmtSnap = $db->prepare("SELECT due_key, percentage_value, is_locked, base_field FROM due_percentage_settings WHERE church_type = ?");
        $stmtSnap->execute([$church['church_type']]);
        $snapRaw = $stmtSnap->fetchAll(PDO::FETCH_ASSOC);
        $snapData = [];
        foreach ($snapRaw as $sr) {
            $snapData[$sr['due_key']] = [
                'percentage_value' => (float)$sr['percentage_value'],
                'is_locked'        => (int)$sr['is_locked'],
                'base_field'       => $sr['base_field'],
            ];
        }
        $stmtSnap2 = $db->prepare("UPDATE church_financial_reports SET due_rates_snapshot = ? WHERE id = ?");
        $stmtSnap2->execute([json_encode($snapData), $finReportId]);

        // 4. Create spiritual report row
        $stmt = $db->prepare("INSERT INTO church_spiritual_reports (church_id, report_month, report_year, status) VALUES (?, ?, ?, 'draft')");
        $stmt->execute([$churchId, $month, $year]);

        $db->commit();

        // Send Congratulations email for report creation
        if (isset($_SESSION['user_id'])) {
            $uStmt = $db->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $uStmt->execute([$_SESSION['user_id']]);
            $userAcc = $uStmt->fetch();
            if ($userAcc && !empty($userAcc['email'])) {
                $cName = $church['name'] ?? 'Local Church';
                $mName = monthName($month) . ' ' . $year;
                $msg = "Congratulations! You have successfully initialized a new monthly report for <strong>" . h($cName) . "</strong> (" . h($mName) . "). You can now complete your financial and spiritual report data.";
                sendAppEmail($userAcc['email'], $userAcc['full_name'], "🎉 Report Initialized — " . $cName . " (" . $mName . ")", $msg, "church_report.php?month={$month}&year={$year}", "Open Monthly Report");
            }
        }

        // Reload report data
        $stmt = $db->prepare("SELECT * FROM church_financial_reports WHERE id = ?");
        $stmt->execute([$finReportId]);
        $finReport = $stmt->fetch();

        $stmt = $db->prepare("SELECT * FROM church_spiritual_reports WHERE church_id = ? AND report_month = ? AND report_year = ?");
        $stmt->execute([$churchId, $month, $year]);
        $spReport = $stmt->fetch();

    } catch (Exception $e) {
        $db->rollBack();
        die("Error creating report draft: " . $e->getMessage());
    }
}

// If it still doesn't exist (e.g. view-only for non-existent report), show empty mock or error
if (!$finReport) {
    die("No report exists for this church for the selected month/year.");
}

$reportId = $finReport['id'];

// Send Congratulations email if report printing/exporting is triggered
if ((isset($_GET['notify_print']) || isset($_GET['pdf'])) && isset($_SESSION['user_id'])) {
    $sessionPrintKey = "printed_report_{$churchId}_{$month}_{$year}";
    if (empty($_SESSION[$sessionPrintKey])) {
        $_SESSION[$sessionPrintKey] = true;
        $uStmt = $db->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $uStmt->execute([$_SESSION['user_id']]);
        $userAcc = $uStmt->fetch();
        if ($userAcc && !empty($userAcc['email'])) {
            $cName = $church['name'] ?? 'Local Church';
            $mName = monthName($month) . ' ' . $year;
            $msg = "Congratulations! Your monthly report for <strong>" . h($cName) . "</strong> (" . h($mName) . ") has been successfully printed / exported as PDF from your portal.";
            sendAppEmail($userAcc['email'], $userAcc['full_name'], "🎉 Report Printed / Exported — " . $cName . " (" . $mName . ")", $msg, "church_report.php?month={$month}&year={$year}", "View Report Portal");
        }
    }
    if (isset($_GET['notify_print'])) {
        exit;
    }
}

// Handle POST: Saving/Submitting or adding custom item
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$viewOnly) {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete_draft') {
        try {
            $db->prepare("DELETE FROM church_expense_items WHERE report_id = ?")->execute([$reportId]);
            $db->prepare("DELETE FROM church_spiritual_reports WHERE church_id = ? AND report_month = ? AND report_year = ?")->execute([$churchId, $month, $year]);
            $db->prepare("DELETE FROM church_financial_reports WHERE id = ?")->execute([$reportId]);
            header("Location: church-dashboard.php?msg=" . urlencode("Draft report deleted successfully."));
            exit;
        } catch (Exception $e) {
            $errorMsg = 'Error deleting draft report: ' . $e->getMessage();
        }
    } else {
        // Save the full report data (for save, submit, add_custom_item, rename_item)
        try {
            $db->beginTransaction();

            // Load dues rates: use snapshot ONLY if the report is already submitted. Otherwise load live settings.
            $ratesSource = [];
            if ($finReport['status'] === 'submitted' && !empty($finReport['due_rates_snapshot'])) {
                $snap = json_decode($finReport['due_rates_snapshot'], true) ?? [];
                foreach ($snap as $dkey => $dval) {
                    $ratesSource[] = [
                        'due_key'          => $dkey,
                        'percentage_value' => $dval['percentage_value'],
                        'is_locked'        => $dval['is_locked'],
                        'base_field'       => $dval['base_field'],
                    ];
                }
            } else {
                $stmtSettings = $db->prepare("SELECT due_key, percentage_value, is_locked, base_field FROM due_percentage_settings WHERE church_type = ?");
                $stmtSettings->execute([$church['church_type']]);
                $ratesSource = $stmtSettings->fetchAll();
            }
            $dbRates = []; $dbLocks = []; $dbBases = [];
            foreach ($ratesSource as $s) {
                $dbRates[$s['due_key']] = (float)$s['percentage_value'];
                if ((int)$s['is_locked'] === 1) { $dbLocks[$s['due_key']] = true; }
                $dbBases[$s['due_key']] = $s['base_field'];
            }

            // Gather raw inputs
            $general_tithe = moneyRound(toFloat($_POST['general_tithe_naira'] ?? 0) + (toFloat($_POST['general_tithe_kobo'] ?? 0) / 100));
            $minister_tithe = moneyRound(toFloat($_POST['minister_tithe_naira'] ?? 0) + (toFloat($_POST['minister_tithe_kobo'] ?? 0) / 100));
            $worship_offerings = moneyRound(toFloat($_POST['worship_offerings_naira'] ?? 0) + (toFloat($_POST['worship_offerings_kobo'] ?? 0) / 100));
            
            // Sub-Total (a-c)
            $subtotal_ac = moneyRound($general_tithe + $minister_tithe + $worship_offerings);

            $missionary_offerings = moneyRound(toFloat($_POST['missionary_offerings_naira'] ?? 0) + (toFloat($_POST['missionary_offerings_kobo'] ?? 0) / 100));
            $midweek_offerings = moneyRound(toFloat($_POST['midweek_offerings_naira'] ?? 0) + (toFloat($_POST['midweek_offerings_kobo'] ?? 0) / 100));
            $sunday_school_offerings = moneyRound(toFloat($_POST['sunday_school_offerings_naira'] ?? 0) + (toFloat($_POST['sunday_school_offerings_kobo'] ?? 0) / 100));
            $thanksgiving_offerings = moneyRound(toFloat($_POST['thanksgiving_offerings_naira'] ?? 0) + (toFloat($_POST['thanksgiving_offerings_kobo'] ?? 0) / 100));
            $love_welfare_offerings = moneyRound(toFloat($_POST['love_welfare_offerings_naira'] ?? 0) + (toFloat($_POST['love_welfare_offerings_kobo'] ?? 0) / 100));
            $building_pledge_offerings = moneyRound(toFloat($_POST['building_pledge_offerings_naira'] ?? 0) + (toFloat($_POST['building_pledge_offerings_kobo'] ?? 0) / 100));
            $church_pioneering_receipts = moneyRound(toFloat($_POST['church_pioneering_receipts_naira'] ?? 0) + (toFloat($_POST['church_pioneering_receipts_kobo'] ?? 0) / 100));
            $donation_other_churches = moneyRound(toFloat($_POST['donation_other_churches_naira'] ?? 0) + (toFloat($_POST['donation_other_churches_kobo'] ?? 0) / 100));
            $other_pledges = moneyRound(toFloat($_POST['other_pledges_naira'] ?? 0) + (toFloat($_POST['other_pledges_kobo'] ?? 0) / 100));
            $seed_faith = moneyRound(toFloat($_POST['seed_faith_naira'] ?? 0) + (toFloat($_POST['seed_faith_kobo'] ?? 0) / 100));
            $staff_loans_repayment = moneyRound(toFloat($_POST['staff_loans_repayment_naira'] ?? 0) + (toFloat($_POST['staff_loans_repayment_kobo'] ?? 0) / 100));
            $loan_cash_deposit = moneyRound(toFloat($_POST['loan_cash_deposit_naira'] ?? 0) + (toFloat($_POST['loan_cash_deposit_kobo'] ?? 0) / 100));
            $pastor_pension_5pct = moneyRound(toFloat($_POST['pastor_pension_5pct_naira'] ?? 0) + (toFloat($_POST['pastor_pension_5pct_kobo'] ?? 0) / 100));
            $national_grant = moneyRound(toFloat($_POST['national_grant_naira'] ?? 0) + (toFloat($_POST['national_grant_kobo'] ?? 0) / 100));
            $convention_pledges = moneyRound(toFloat($_POST['convention_pledges_naira'] ?? 0) + (toFloat($_POST['convention_pledges_kobo'] ?? 0) / 100));
            $special_projects = moneyRound(toFloat($_POST['special_projects_naira'] ?? 0) + (toFloat($_POST['special_projects_kobo'] ?? 0) / 100));
            $decade_multiplication_receipts = moneyRound(toFloat($_POST['decade_multiplication_receipts_naira'] ?? 0) + (toFloat($_POST['decade_multiplication_receipts_kobo'] ?? 0) / 100));
            $third_sunday_offering = moneyRound(toFloat($_POST['third_sunday_offering_naira'] ?? 0) + (toFloat($_POST['third_sunday_offering_kobo'] ?? 0) / 100));

            // Total receipts
            $total_receipts = moneyRound($subtotal_ac + $missionary_offerings + $midweek_offerings + $sunday_school_offerings +
                                         $thanksgiving_offerings + $love_welfare_offerings + $building_pledge_offerings +
                                         $church_pioneering_receipts + $donation_other_churches + $other_pledges +
                                         $seed_faith + $staff_loans_repayment + $loan_cash_deposit + $pastor_pension_5pct +
                                         $national_grant + $convention_pledges + $special_projects +
                                         $decade_multiplication_receipts + $third_sunday_offering);

            // Helper to calculate dues (returns 0.0 if locked by admin)
            $calcDue = function($dueKey) use ($dbRates, $dbLocks, $dbBases, $subtotal_ac, $sunday_school_offerings, $missionary_offerings, $love_welfare_offerings, $third_sunday_offering) {
                if (isset($dbLocks[$dueKey])) return 0.0;

                $baseField = $dbBases[$dueKey] ?? 'subtotal_ac';
                $baseVal = match($baseField) {
                    'sunday_school_offerings' => $sunday_school_offerings,
                    'missionary_offerings'    => $missionary_offerings,
                    'love_welfare_offerings'  => $love_welfare_offerings,
                    'third_sunday_offering'   => $third_sunday_offering,
                    default                   => $subtotal_ac
                };
                if ($baseVal <= 0.0001) return 0.0;
                $rate = $dbRates[$dueKey] ?? 0.0;
                return moneyRound($baseVal * ($rate / 100));
            };

            // National dues total
            $due_tithes_offerings = $calcDue('tithes_offerings');
            $due_pastors_welfare  = $calcDue('pastors_welfare');
            $due_project_dev      = $calcDue('project_dev_fund');
            $due_macpherson       = $calcDue('macpherson_uni');
            $due_augmentation     = $calcDue('augmentation_fund');
            $due_ffs_savings       = $calcDue('ffs_savings');
            $due_sunday_school    = $calcDue('sunday_school_offering');
            $due_missionary       = $calcDue('missionary_offering');
            $due_love_offering     = $calcDue('love_offering');
            $due_foursquare_tv     = $calcDue('foursquare_tv');
            $due_third_sunday      = $calcDue('third_sunday');

            $national_dues_total = moneyRound($due_tithes_offerings + $due_pastors_welfare + $due_project_dev +
                                              $due_macpherson + $due_augmentation + $due_ffs_savings +
                                              $due_sunday_school + $due_missionary + $due_love_offering +
                                              $due_foursquare_tv + $due_third_sunday);

            // Right column dues
            $regional_dues = $calcDue('regional_fund');
            
            $due_district_fund = $calcDue('district_fund');
            $straight_love_offering = $calcDue('straight_love_offering');
            $pastors_staff_pension_8 = $calcDue('pastors_staff_pension_8');
            $church_staff_pension_10 = $calcDue('church_staff_pension_10');
            $due_dist_missionary = $calcDue('district_missionary');
            $due_dist_sunday_school = $calcDue('district_sunday_school');
            
            $district_dues = moneyRound($due_district_fund + $straight_love_offering + $pastors_staff_pension_8 + $church_staff_pension_10 + $due_dist_missionary + $due_dist_sunday_school);

            $due_zonal_fund = $calcDue('zonal_fund');
            $due_zonal_missionary = $calcDue('zonal_missionary');
            $due_zonal_sunday_school = $calcDue('zonal_sunday_school');
            
            $zonal_dues = moneyRound($due_zonal_fund + $due_zonal_missionary + $due_zonal_sunday_school);

            $life_dues = $calcDue('life_theo_seminary');

            $payable = moneyRound($national_dues_total + $regional_dues + $district_dues + $zonal_dues + $life_dues);

            // Fetch item keys for this report
            $stmt = $db->prepare("SELECT id, item_key FROM church_expense_items WHERE report_id = ?");
            $stmt->execute([$reportId]);
            $dbExpenses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // maps item_key => id
            
            $getExpenseAmt = function($key) use ($dbExpenses) {
                $itemId = $dbExpenses[$key] ?? 0;
                if (!$itemId) return 0.0;
                $nVal = toFloat($_POST['expense_amount_naira'][$itemId] ?? 0);
                $kVal = toFloat($_POST['expense_amount_kobo'][$itemId] ?? 0);
                return moneyRound($nVal + ($kVal / 100));
            };

            // Calculate Emoluments
            $ministersBasic = $getExpenseAmt('ministers_basic');
            $ministersAllowances = $getExpenseAmt('ministers_allowances');
            $ministersSubtotal = moneyRound($ministersBasic + $ministersAllowances);

            $otherWorkersBasic = $getExpenseAmt('other_workers_basic');
            $otherWorkersAllowances = $getExpenseAmt('other_workers_allowances');
            $otherWorkersSubtotal = moneyRound($otherWorkersBasic + $otherWorkersAllowances);

            $total_emoluments = moneyRound($ministersSubtotal + $otherWorkersSubtotal);

            // Calculate Fixed Assets
            $landAcquisition = $getExpenseAmt('land_acquisition');
            $churchBuilding = $getExpenseAmt('church_building');
            $purchaseMotorVehicles = $getExpenseAmt('purchase_motor_vehicles');
            $purchaseNewEquipment = $getExpenseAmt('purchase_new_equipment');
            $fixed_assets_subtotal = moneyRound($landAcquisition + $churchBuilding + $purchaseMotorVehicles + $purchaseNewEquipment);

            // Calculate General Expenses
            $generalExpensesTotal = 0.0;
            // Fetch all expense items for loop
            $stmt = $db->prepare("SELECT * FROM church_expense_items WHERE report_id = ? ORDER BY display_order ASC");
            $stmt->execute([$reportId]);
            $expenseItems = $stmt->fetchAll();

            foreach ($expenseItems as $item) {
                $key = $item['item_key'];
                if (!in_array($key, ['ministers_basic', 'ministers_allowances', 'other_workers_basic', 'other_workers_allowances', 'land_acquisition', 'church_building', 'purchase_motor_vehicles', 'purchase_new_equipment'], true)) {
                    $nVal = toFloat($_POST['expense_amount_naira'][$item['id']] ?? 0);
                    $kVal = toFloat($_POST['expense_amount_kobo'][$item['id']] ?? 0);
                    $generalExpensesTotal += moneyRound($nVal + ($kVal / 100));
                }
            }
            $general_expenses = moneyRound($generalExpensesTotal);

            // Total Payment
            $total_payment = moneyRound($payable + $total_emoluments + $general_expenses + $fixed_assets_subtotal);
            $less_total_payment = $total_payment;

            $balance_surplus_deficit = moneyRound($total_receipts - $total_payment);
            $balance_last_month = moneyRound(toFloat($_POST['balance_last_month_naira'] ?? 0) + (toFloat($_POST['balance_last_month_kobo'] ?? 0) / 100));
            $balance_this_month = moneyRound($balance_surplus_deficit + $balance_last_month);

            $cash_in_hand_bank = moneyRound(toFloat($_POST['cash_in_hand_bank_naira'] ?? 0) + (toFloat($_POST['cash_in_hand_bank_kobo'] ?? 0) / 100));
            $investment = moneyRound(toFloat($_POST['investment_naira'] ?? 0) + (toFloat($_POST['investment_kobo'] ?? 0) / 100));
            $total_balance = moneyRound($cash_in_hand_bank + $investment);

            $outstanding_loan = moneyRound(toFloat($_POST['outstanding_loan_naira'] ?? 0) + (toFloat($_POST['outstanding_loan_kobo'] ?? 0) / 100));

            // Status & update array
            $status = ($action === 'submit') ? 'submitted' : 'draft';

            $updateData = [
                'general_tithe' => $general_tithe,
                'minister_tithe' => $minister_tithe,
                'worship_offerings' => $worship_offerings,
                'subtotal_ac' => $subtotal_ac,
                'missionary_offerings' => $missionary_offerings,
                'midweek_offerings' => $midweek_offerings,
                'sunday_school_offerings' => $sunday_school_offerings,
                'thanksgiving_offerings' => $thanksgiving_offerings,
                'love_welfare_offerings' => $love_welfare_offerings,
                'building_pledge_offerings' => $building_pledge_offerings,
                'church_pioneering_receipts' => $church_pioneering_receipts,
                'donation_other_churches' => $donation_other_churches,
                'other_pledges' => $other_pledges,
                'seed_faith' => $seed_faith,
                'staff_loans_repayment' => $staff_loans_repayment,
                'loan_cash_deposit' => $loan_cash_deposit,
                'pastor_pension_5pct' => $pastor_pension_5pct,
                'national_grant' => $national_grant,
                'convention_pledges' => $convention_pledges,
                'special_projects' => $special_projects,
                'decade_multiplication_receipts' => $decade_multiplication_receipts,
                'third_sunday_offering' => $third_sunday_offering,
                'total_receipts' => $total_receipts,
                'national_dues_total' => $national_dues_total,
                'regional_dues' => $regional_dues,
                'district_dues' => $district_dues,
                'zonal_dues' => $zonal_dues,
                'life_dues' => $life_dues,
                'straight_love_offering' => $straight_love_offering,
                'pastors_staff_pension_8' => $pastors_staff_pension_8,
                'church_staff_pension_10' => $church_staff_pension_10,
                'payable' => $payable,
                'total_emoluments' => $total_emoluments,
                'total_expenses_block' => $general_expenses,
                'total_payment' => $total_payment,
                'less_total_payment' => $less_total_payment,
                'balance_surplus_deficit' => $balance_surplus_deficit,
                'balance_last_month' => $balance_last_month,
                'balance_this_month' => $balance_this_month,
                'cash_in_hand_bank' => $cash_in_hand_bank,
                'investment' => $investment,
                'total_balance' => $total_balance,
                'outstanding_loan' => $outstanding_loan,
                'special_projects_details' => $_POST['special_projects_details'] ?? '',
                'status' => $status,
                'report_id' => $reportId
            ];

            $sqlFin = "UPDATE church_financial_reports SET 
                general_tithe = :general_tithe,
                minister_tithe = :minister_tithe,
                worship_offerings = :worship_offerings,
                subtotal_ac = :subtotal_ac,
                missionary_offerings = :missionary_offerings,
                midweek_offerings = :midweek_offerings,
                sunday_school_offerings = :sunday_school_offerings,
                thanksgiving_offerings = :thanksgiving_offerings,
                love_welfare_offerings = :love_welfare_offerings,
                building_pledge_offerings = :building_pledge_offerings,
                church_pioneering_receipts = :church_pioneering_receipts,
                donation_other_churches = :donation_other_churches,
                other_pledges = :other_pledges,
                seed_faith = :seed_faith,
                staff_loans_repayment = :staff_loans_repayment,
                loan_cash_deposit = :loan_cash_deposit,
                pastor_pension_5pct = :pastor_pension_5pct,
                national_grant = :national_grant,
                convention_pledges = :convention_pledges,
                special_projects = :special_projects,
                decade_multiplication_receipts = :decade_multiplication_receipts,
                third_sunday_offering = :third_sunday_offering,
                total_receipts = :total_receipts,
                national_dues_total = :national_dues_total,
                regional_dues = :regional_dues,
                district_dues = :district_dues,
                zonal_dues = :zonal_dues,
                life_dues = :life_dues,
                straight_love_offering = :straight_love_offering,
                pastors_staff_pension_8 = :pastors_staff_pension_8,
                church_staff_pension_10 = :church_staff_pension_10,
                payable = :payable,
                total_emoluments = :total_emoluments,
                total_expenses_block = :total_expenses_block,
                total_payment = :total_payment,
                less_total_payment = :less_total_payment,
                balance_surplus_deficit = :balance_surplus_deficit,
                balance_last_month = :balance_last_month,
                balance_this_month = :balance_this_month,
                cash_in_hand_bank = :cash_in_hand_bank,
                investment = :investment,
                total_balance = :total_balance,
                outstanding_loan = :outstanding_loan,
                special_projects_details = :special_projects_details,
                status = :status
                WHERE id = :report_id";

            $stmt = $db->prepare($sqlFin);
            $stmt->execute($updateData);

            // If they are submitting the report, we capture the live rates as the final snapshot
            if ($status === 'submitted') {
                $stmtSnap = $db->prepare("SELECT due_key, percentage_value, is_locked, base_field FROM due_percentage_settings WHERE church_type = ?");
                $stmtSnap->execute([$church['church_type']]);
                $snapRaw = $stmtSnap->fetchAll(PDO::FETCH_ASSOC);
                $snapData = [];
                foreach ($snapRaw as $sr) {
                    $snapData[$sr['due_key']] = [
                        'percentage_value' => (float)$sr['percentage_value'],
                        'is_locked'        => (int)$sr['is_locked'],
                        'base_field'       => $sr['base_field'],
                    ];
                }
                $stmtSnapUpdate = $db->prepare("UPDATE church_financial_reports SET due_rates_snapshot = ? WHERE id = ?");
                $stmtSnapUpdate->execute([json_encode($snapData), $reportId]);
            }

            // 2. Save all expense amounts
            if (isset($_POST['expense_amount_naira']) && is_array($_POST['expense_amount_naira'])) {
                $stmtUpdateExpense = $db->prepare("UPDATE church_expense_items SET amount = ? WHERE id = ? AND report_id = ?");
                foreach ($_POST['expense_amount_naira'] as $itemId => $nVal) {
                    $kVal = $_POST['expense_amount_kobo'][$itemId] ?? 0;
                    $combinedAmt = moneyRound(toFloat($nVal) + (toFloat($kVal) / 100));
                    $stmtUpdateExpense->execute([$combinedAmt, $itemId, $reportId]);
                }
            }

            // 3. Save Spiritual Page
            $attendanceRowPrefixes = ['pre_sun_school', 'sun_school', 'sun_worship', 'house_fellowship', 'bible_study', 'prayer_meeting'];
            $spData = [];
            $spSqlParts = [];

            // Add simple attendance totals
            foreach ($attendanceRowPrefixes as $prefix) {
                $c = (int)($_POST["{$prefix}_children"] ?? 0);
                $a = (int)($_POST["{$prefix}_adults"] ?? 0);
                $t = $c + $a;

                $spData["{$prefix}_children"] = $c;
                $spData["{$prefix}_adults"] = $a;
                $spData["{$prefix}_total"] = $t;

                $spSqlParts[] = "`{$prefix}_children` = :{$prefix}_children";
                $spSqlParts[] = "`{$prefix}_adults` = :{$prefix}_adults";
                $spSqlParts[] = "`{$prefix}_total` = :{$prefix}_total";
            }

            // Add metadata
            $singleFields = [
                'total_new_comers', 'total_decision_christ', 'total_water_baptism',
                'total_holy_spirit_baptism', 'total_healings', 'total_house_fellowship_centres'
            ];
            foreach ($singleFields as $sf) {
                $val = (int)($_POST[$sf] ?? 0);
                $spData[$sf] = $val;
                $spSqlParts[] = "`{$sf}` = :{$sf}";
            }


            // Map standard membership fields into db columns directly
            $spData['intake_above_18'] = (int)($_POST['new_above_18'] ?? 0);
            $spData['intake_under_18'] = (int)($_POST['new_under_18'] ?? 0);
            $spData['intake_total'] = $spData['intake_above_18'] + $spData['intake_under_18'];
            $spSqlParts[] = "`intake_above_18` = :intake_above_18";
            $spSqlParts[] = "`intake_under_18` = :intake_under_18";
            $spSqlParts[] = "`intake_total` = :intake_total";

            $spData['withdrawn_above_18'] = (int)($_POST['withdrawn_total_above_18'] ?? 0);
            $spData['withdrawn_under_18'] = (int)($_POST['withdrawn_total_under_18'] ?? 0);
            $spData['withdrawn_total'] = $spData['withdrawn_above_18'] + $spData['withdrawn_under_18'];
            $spSqlParts[] = "`withdrawn_above_18` = :withdrawn_above_18";
            $spSqlParts[] = "`withdrawn_under_18` = :withdrawn_under_18";
            $spSqlParts[] = "`withdrawn_total` = :withdrawn_total";

            $spData['membership_above_18'] = (int)($_POST['after_withdrawal_above_18'] ?? 0);
            $spData['membership_under_18'] = (int)($_POST['after_withdrawal_under_18'] ?? 0);
            $spData['membership_total'] = $spData['membership_above_18'] + $spData['membership_under_18'];
            $spSqlParts[] = "`membership_above_18` = :membership_above_18";
            $spSqlParts[] = "`membership_under_18` = :membership_under_18";
            $spSqlParts[] = "`membership_total` = :membership_total";

            // Store detailed breakdown in credential_workers_data JSON
            $jsonPayload = [
                'new_comers' => (int)($_POST['total_new_comers'] ?? 0),
                'decisions' => (int)($_POST['total_decision_christ'] ?? 0),
                'water_bapt' => (int)($_POST['total_water_baptism'] ?? 0),
                'spirit_bapt' => (int)($_POST['total_holy_spirit_baptism'] ?? 0),
                'healings' => (int)($_POST['total_healings'] ?? 0),
                'house_fellowships' => (int)($_POST['total_house_fellowship_centres'] ?? 0),
                
                'crusaders' => [
                    'candlelighters' => (int)($_POST['crusader_candlelighters'] ?? 0),
                    'cupbearers' => (int)($_POST['crusader_cupbearers'] ?? 0),
                    'cadets' => (int)($_POST['crusader_cadets'] ?? 0),
                    'jr_teens' => (int)($_POST['crusader_jr_teens'] ?? 0),
                    'sr_teens' => (int)($_POST['crusader_sr_teens'] ?? 0),
                    'youth' => (int)($_POST['crusader_youth'] ?? 0),
                    'challengers' => (int)($_POST['crusader_challengers'] ?? 0),
                    'defenders' => (int)($_POST['crusader_defenders'] ?? 0),
                    'citizens' => (int)($_POST['crusader_citizens'] ?? 0),
                ],
                'credential_workers' => [
                    'ordained' => (int)($_POST['cw_ordained'] ?? 0),
                    'licensed' => (int)($_POST['cw_licensed'] ?? 0),
                    'exhorters' => (int)($_POST['cw_exhorters'] ?? 0),
                    'elders' => (int)($_POST['cw_elders'] ?? 0),
                    'deacons' => (int)($_POST['cw_deacons'] ?? 0),
                    'deaconesses' => (int)($_POST['cw_deaconesses'] ?? 0),
                ],
                // Repeatable detail rows
                'membership_details' => [
                    'prev_month' => ['18' => (int)($_POST['prev_above_18'] ?? 0), 'u18' => (int)($_POST['prev_under_18'] ?? 0)],
                    'new_members' => ['18' => (int)($_POST['new_above_18'] ?? 0), 'u18' => (int)($_POST['new_under_18'] ?? 0)],
                    'withdrawn_reasons' => [
                        'transfer' => ['18' => (int)($_POST['withdrawn_transfer_above_18'] ?? 0), 'u18' => (int)($_POST['withdrawn_transfer_under_18'] ?? 0)],
                        'resignation' => ['18' => (int)($_POST['withdrawn_resignation_above_18'] ?? 0), 'u18' => (int)($_POST['withdrawn_resignation_under_18'] ?? 0)],
                        'dismissal' => ['18' => (int)($_POST['withdrawn_dismissal_above_18'] ?? 0), 'u18' => (int)($_POST['withdrawn_dismissal_under_18'] ?? 0)],
                        'death' => ['18' => (int)($_POST['withdrawn_death_above_18'] ?? 0), 'u18' => (int)($_POST['withdrawn_death_under_18'] ?? 0)],
                    ]
                ]
            ];

            $spData['credential_workers_data'] = json_encode($jsonPayload);
            $spSqlParts[] = "`credential_workers_data` = :credential_workers_data";

            $spData['report_date'] = !empty($_POST['report_date']) ? $_POST['report_date'] : null;
            $spSqlParts[] = "`report_date` = :report_date";

            $spData['pastor_signature_name'] = $_POST['pastor_signature_name'] ?? '';
            $spSqlParts[] = "`pastor_signature_name` = :pastor_signature_name";

            $spData['treasurer_signature_name'] = $_POST['treasurer_signature_name'] ?? '';
            $spSqlParts[] = "`treasurer_signature_name` = :treasurer_signature_name";

            $spData['secretary_signature_name'] = $_POST['secretary_signature_name'] ?? '';
            $spSqlParts[] = "`secretary_signature_name` = :secretary_signature_name";

            $spData['status'] = $status;
            $spSqlParts[] = "`status` = :status";

            $spData['church_id'] = $churchId;
            $spData['month'] = $month;
            $spData['year'] = $year;

            $sqlSp = "UPDATE church_spiritual_reports SET " . implode(", ", $spSqlParts) . " WHERE church_id = :church_id AND report_month = :month AND report_year = :year";
            $stmt = $db->prepare($sqlSp);
            $stmt->execute($spData);

            if ($action === 'add_custom_item') {
                $customLabel = trim($_POST['custom_label'] ?? '');
                if (!empty($customLabel)) {
                    $order = (int)$db->query("SELECT MAX(display_order) FROM church_expense_items WHERE report_id = $reportId")->fetchColumn() + 1;
                    $itemKey = 'custom_' . bin2hex(random_bytes(4));
                    $stmtCustom = $db->prepare("INSERT INTO church_expense_items (church_id, report_id, item_key, label, amount, is_custom, display_order) VALUES (?, ?, ?, ?, 0.00, 1, ?)");
                    $stmtCustom->execute([$churchId, $reportId, $itemKey, $customLabel, $order]);
                    $successMsg = 'Report saved and custom expense item added!';
                }
            } elseif ($action === 'rename_item') {
                $itemId = (int)($_POST['rename_id'] ?? 0);
                $newLabel = trim($_POST['rename_label'] ?? '');
                if ($itemId > 0 && !empty($newLabel)) {
                    $stmtRename = $db->prepare("UPDATE church_expense_items SET label = ? WHERE id = ? AND church_id = ? AND (report_id = ? OR report_id IS NULL)");
                    $stmtRename->execute([$newLabel, $itemId, $churchId, $reportId]);
                    $successMsg = 'Report saved and expense item renamed successfully!';
                }
            }

            $db->commit();
            if (empty($successMsg)) {
                $successMsg = ($action === 'submit') ? 'Report submitted successfully! It is now locked.' : 'Report draft saved successfully!';
            }
            
            // Send email notification on submission
            if ($action === 'submit' && isset($_SESSION['user_id'])) {
                $uStmt = $db->prepare("SELECT email, full_name FROM users WHERE id = ?");
                $uStmt->execute([$_SESSION['user_id']]);
                $userAcc = $uStmt->fetch();
                if ($userAcc && !empty($userAcc['email'])) {
                    $cName = $church['name'] ?? 'Local Church';
                    $mName = monthName($month) . ' ' . $year;
                    $msg = "Congratulations! Your monthly report for <strong>" . h($cName) . "</strong> (" . h($mName) . ") has been officially <strong>submitted</strong> and locked for review. Thank you for your faithful reporting!";
                    sendAppEmail($userAcc['email'], $userAcc['full_name'], "🎉 Report Submitted — " . $cName . " (" . $mName . ")", $msg, "church_report.php?month={$month}&year={$year}", "View Submitted Report");
                }
            }
            
            // Reload updated values
            $stmt = $db->prepare("SELECT * FROM church_financial_reports WHERE id = ?");
            $stmt->execute([$reportId]);
            $finReport = $stmt->fetch();

            $stmt = $db->prepare("SELECT * FROM church_spiritual_reports WHERE church_id = ? AND report_month = ? AND report_year = ?");
            $stmt->execute([$churchId, $month, $year]);
            $spReport = $stmt->fetch();

            if ($action === 'submit') {
                $viewOnly = true; // Lock view immediately
            }
        } catch (Exception $e) {
            $db->rollBack();
            $errorMsg = 'Error saving report: ' . $e->getMessage();
        }
    }
}

// Fetch expense items for this report
$stmt = $db->prepare("SELECT * FROM church_expense_items WHERE report_id = ? ORDER BY display_order ASC");
$stmt->execute([$reportId]);
$expenseItems = $stmt->fetchAll();

$skipExpenseKeys = ['ministers_basic','ministers_allowances','other_workers_basic','other_workers_allowances','land_acquisition','church_building','purchase_motor_vehicles','purchase_new_equipment'];
$numGeneralExpenses = 0;
foreach ($expenseItems as $ei) {
    if (!in_array($ei['item_key'], $skipExpenseKeys, true)) $numGeneralExpenses++;
}
$printRowH  = round(max(8.0,  13.0 - max(0, $numGeneralExpenses - 8) * 0.45), 2);
$printCellP = round(max(0.3,  1.5  - max(0, $numGeneralExpenses - 8) * 0.10), 2);
$printFontS = round(max(7.5,  9.5  - max(0, $numGeneralExpenses - 8) * 0.20), 2);

// Map items for easy layout lookup
$expensesMap = [];
foreach ($expenseItems as $item) {
    $expensesMap[$item['item_key']] = $item;
}

// Fetch active due percentage settings — use snapshot ONLY if the report is already submitted (to freeze submitted reports)
if ($finReport['status'] === 'submitted' && !empty($finReport['due_rates_snapshot'])) {
    $snap = json_decode($finReport['due_rates_snapshot'], true) ?? [];
    $settingsList = [];
    foreach ($snap as $dkey => $dval) {
        $settingsList[] = [
            'due_key'          => $dkey,
            'percentage_value' => $dval['percentage_value'],
            'is_locked'        => $dval['is_locked'],
            'base_field'       => $dval['base_field'],
        ];
    }
} else {
    // Fallback for old reports created before snapshot feature
    $stmt = $db->prepare("SELECT due_key, percentage_value, is_locked, base_field FROM due_percentage_settings WHERE church_type = ?");
    $stmt->execute([$church['church_type']]);
    $settingsList = $stmt->fetchAll();
}

$jsRates = [];
$lockedKeys = [];
$baseFields = [];
foreach ($settingsList as $s) {
    $jsRates[$s['due_key']] = (float)$s['percentage_value'];
    if ((int)$s['is_locked'] === 1) {
        $lockedKeys[$s['due_key']] = true;
    }
    $baseFields[$s['due_key']] = $s['base_field'];
}

// Helpers to output Naira/Kobo inputs
function renderInput($fieldName, $value, $viewOnly, $class = 'cell', $isLocked = false) {
    global $isPdf;

    // Locked dues are forced to 0 — no value shown, no lock indicator visible
    if ($isLocked) {
        $value = 0.0;
    }

    $value = round((float)$value, 2);
    $naira = floor(abs($value));
    $kobo = round((abs($value) - $naira) * 100);
    $sign = $value < 0 ? '-' : '';
    
    $nairaVal = $value != 0 ? $sign . $naira : '';
    $koboVal = $value != 0 ? str_pad($kobo, 2, '0', STR_PAD_LEFT) : '';

    if ($isPdf) {
        echo '<td style="text-align:right; font-size:12px; font-family:\'Times New Roman\';">' . h($nairaVal) . '</td>';
        echo '<td style="text-align:center; font-size:12px; font-family:\'Times New Roman\';">' . h($koboVal) . '</td>';
    } else {
        // Locked inputs are disabled (read-only) but look identical to other computed cells — no badge, no special class
        $disabledAttr = ($viewOnly || $isLocked) ? 'disabled' : '';
        echo '<td><input type="text" class="' . $class . '" name="' . $fieldName . '_naira" value="' . h($nairaVal) . '" ' . $disabledAttr . '></td>';
        echo '<td><input type="text" class="' . $class . '" name="' . $fieldName . '_kobo" value="' . h($koboVal) . '" ' . $disabledAttr . '></td>';
    }
}

function renderExpenseRow($item, $viewOnly) {
    global $isPdf;
    if (!$item) return;
    $labelHtml = h($item['label']);
    if (!$viewOnly && !$isPdf) {
        $labelHtml = '<span class="editable-label text-decoration-underline" onclick="openRenameModal(' . $item['id'] . ', \'' . h($item['label']) . '\')" style="cursor:pointer;" title="Click to rename">' . h($item['label']) . '</span>';
    }
    echo '<tr data-expense-item="true" data-item-key="' . h($item['item_key']) . '">';
    echo '<td>' . $labelHtml . '</td>';
    
    $nVal = $item['amount'] != 0 ? floor($item['amount']) : '';
    $kVal = $item['amount'] != 0 ? str_pad(round(($item['amount'] - floor($item['amount'])) * 100), 2, '0', STR_PAD_LEFT) : '';
    
    if ($isPdf) {
        echo '<td style="text-align:right; font-size:12px; font-family:\'Times New Roman\';">' . h($nVal) . '</td>';
        echo '<td style="text-align:center; font-size:12px; font-family:\'Times New Roman\';">' . h($kVal) . '</td>';
    } else {
        $disabled = $viewOnly ? 'disabled' : '';
        echo '<td><input type="text" class="cell" name="expense_amount_naira[' . $item['id'] . ']" value="' . h($nVal) . '" ' . $disabled . '></td>';
        echo '<td><input type="text" class="cell" name="expense_amount_kobo[' . $item['id'] . ']" value="' . h($kVal) . '" ' . $disabled . '></td>';
    }
    echo '</tr>';
}

function renderSimpleInput($name, $value, $viewOnly, $class = '', $style = '', $placeholder = '', $type = 'text') {
    global $isPdf;
    if ($isPdf) {
        $styleAttr = $style ? ' style="' . $style . ' font-size:12px; font-family:\'Times New Roman\'; border:none; background:transparent;"' : ' style="font-size:12px; font-family:\'Times New Roman\'; border:none; background:transparent;"';
        echo '<span' . $styleAttr . '>' . h($value) . '</span>';
    } else {
        $disabledAttr = $viewOnly ? 'disabled' : '';
        $classAttr = $class ? ' class="' . $class . '"' : '';
        $styleAttr = $style ? ' style="' . $style . '"' : '';
        $placeholderAttr = $placeholder ? ' placeholder="' . h($placeholder) . '"' : '';
        echo '<input type="' . $type . '"' . $classAttr . ' name="' . $name . '" value="' . h($value) . '" ' . $disabledAttr . $styleAttr . $placeholderAttr . '>';
    }
}

// Decode spiritual JSON details
$spJson = $spReport && !empty($spReport['credential_workers_data']) ? json_decode($spReport['credential_workers_data'], true) : [];

// ─── PHP-side computed spiritual/membership totals (for PDF render, JS does not run) ───
$prevAbove18  = (int)($spJson['membership_details']['prev_month']['18']  ?? 0);
$prevUnder18  = (int)($spJson['membership_details']['prev_month']['u18'] ?? 0);
$prevTotal    = $prevAbove18 + $prevUnder18;
$newAbove18   = (int)($spJson['membership_details']['new_members']['18']  ?? 0);
$newUnder18   = (int)($spJson['membership_details']['new_members']['u18'] ?? 0);
$newTotal     = $newAbove18 + $newUnder18;
$beforeWithdrawAbove18 = $prevAbove18 + $newAbove18;
$beforeWithdrawUnder18 = $prevUnder18 + $newUnder18;
$beforeWithdrawTotal   = $beforeWithdrawAbove18 + $beforeWithdrawUnder18;
$wReasons  = $spJson['membership_details']['withdrawn_reasons'] ?? [];
$wTA18 = (int)($wReasons['transfer']['18']   ?? 0); $wTU18 = (int)($wReasons['transfer']['u18']   ?? 0);
$wRA18 = (int)($wReasons['resignation']['18']?? 0); $wRU18 = (int)($wReasons['resignation']['u18']?? 0);
$wDA18 = (int)($wReasons['dismissal']['18']  ?? 0); $wDU18 = (int)($wReasons['dismissal']['u18']  ?? 0);
$wDeA18= (int)($wReasons['death']['18']      ?? 0); $wDeU18= (int)($wReasons['death']['u18']      ?? 0);
$wTotalAbove18 = $wTA18 + $wRA18 + $wDA18 + $wDeA18;
$wTotalUnder18 = $wTU18 + $wRU18 + $wDU18 + $wDeU18;
$wTotalTotal   = $wTotalAbove18 + $wTotalUnder18;
$afterWithdrawAbove18 = $beforeWithdrawAbove18 - $wTotalAbove18;
$afterWithdrawUnder18 = $beforeWithdrawUnder18 - $wTotalUnder18;
$afterWithdrawTotal   = $afterWithdrawAbove18  + $afterWithdrawUnder18;

// Recalculate computed values for displaying on page load
$general_tithe = (float)($finReport['general_tithe'] ?? 0.0);
$minister_tithe = (float)($finReport['minister_tithe'] ?? 0.0);
$worship_offerings = (float)($finReport['worship_offerings'] ?? 0.0);
$subtotal_ac = moneyRound($general_tithe + $minister_tithe + $worship_offerings);

$missionary_offerings = (float)($finReport['missionary_offerings'] ?? 0.0);
$midweek_offerings = (float)($finReport['midweek_offerings'] ?? 0.0);
$sunday_school_offerings = (float)($finReport['sunday_school_offerings'] ?? 0.0);
$thanksgiving_offerings = (float)($finReport['thanksgiving_offerings'] ?? 0.0);
$love_welfare_offerings = (float)($finReport['love_welfare_offerings'] ?? 0.0);
$building_pledge_offerings = (float)($finReport['building_pledge_offerings'] ?? 0.0);
$church_pioneering_receipts = (float)($finReport['church_pioneering_receipts'] ?? 0.0);
$donation_other_churches = (float)($finReport['donation_other_churches'] ?? 0.0);
$other_pledges = (float)($finReport['other_pledges'] ?? 0.0);
$seed_faith = (float)($finReport['seed_faith'] ?? 0.0);
$staff_loans_repayment = (float)($finReport['staff_loans_repayment'] ?? 0.0);
$loan_cash_deposit = (float)($finReport['loan_cash_deposit'] ?? 0.0);
$pastor_pension_5pct = (float)($finReport['pastor_pension_5pct'] ?? 0.0);
$national_grant = (float)($finReport['national_grant'] ?? 0.0);
$convention_pledges = (float)($finReport['convention_pledges'] ?? 0.0);
$special_projects = (float)($finReport['special_projects'] ?? 0.0);
$decade_multiplication_receipts = (float)($finReport['decade_multiplication_receipts'] ?? 0.0);
$third_sunday_offering = (float)($finReport['third_sunday_offering'] ?? 0.0);

$total_receipts = moneyRound($subtotal_ac + $missionary_offerings + $midweek_offerings + $sunday_school_offerings +
                             $thanksgiving_offerings + $love_welfare_offerings + $building_pledge_offerings +
                             $church_pioneering_receipts + $donation_other_churches + $other_pledges +
                             $seed_faith + $staff_loans_repayment + $loan_cash_deposit + $pastor_pension_5pct +
                             $national_grant + $convention_pledges + $special_projects +
                             $decade_multiplication_receipts + $third_sunday_offering);

// Helper to calculate due for rendering.
// Locked dues always return 0.0 for unsaved/draft reports (submitted reports use snapshot, unaffected).
$calcDueRender = function($dueKey) use ($jsRates, $lockedKeys, $baseFields, $subtotal_ac, $sunday_school_offerings, $missionary_offerings, $love_welfare_offerings, $third_sunday_offering) {
    // If admin has locked this due, it contributes nothing to the report
    if (isset($lockedKeys[$dueKey])) return 0.0;

    $baseField = $baseFields[$dueKey] ?? 'subtotal_ac';
    $baseVal = match($baseField) {
        'sunday_school_offerings' => $sunday_school_offerings,
        'missionary_offerings'    => $missionary_offerings,
        'love_welfare_offerings'  => $love_welfare_offerings,
        'third_sunday_offering'   => $third_sunday_offering,
        default                   => $subtotal_ac
    };
    if ($baseVal <= 0.0001) return 0.0;
    $rate = $jsRates[$dueKey] ?? 0.0;
    return moneyRound($baseVal * ($rate / 100));
};

$due_tithes_offerings = $calcDueRender('tithes_offerings');
$due_pastors_welfare  = $calcDueRender('pastors_welfare');
$due_project_dev      = $calcDueRender('project_dev_fund');
$due_macpherson       = $calcDueRender('macpherson_uni');
$due_augmentation     = $calcDueRender('augmentation_fund');
$due_ffs_savings       = $calcDueRender('ffs_savings');
$due_sunday_school    = $calcDueRender('sunday_school_offering');
$due_missionary       = $calcDueRender('missionary_offering');
$due_love_offering     = $calcDueRender('love_offering');
$due_foursquare_tv     = $calcDueRender('foursquare_tv');
$due_third_sunday      = $calcDueRender('third_sunday');

$sumDues = function($arr) {
    $sum = 0.0;
    foreach ($arr as $v) {
        if ($v !== null) $sum += $v;
    }
    return moneyRound($sum);
};

$national_dues_total = $sumDues([
    $due_tithes_offerings, $due_pastors_welfare, $due_project_dev,
    $due_macpherson, $due_augmentation, $due_ffs_savings,
    $due_sunday_school, $due_missionary, $due_love_offering,
    $due_foursquare_tv, $due_third_sunday
]);

// Right column dues
$due_regional_fund = $calcDueRender('regional_fund');

$due_district_fund = $calcDueRender('district_fund');
$straight_love_offering = $calcDueRender('straight_love_offering');
$pastors_staff_pension_8 = $calcDueRender('pastors_staff_pension_8');
$church_staff_pension_10 = $calcDueRender('church_staff_pension_10');
$due_dist_missionary = $calcDueRender('district_missionary');
$due_dist_sunday_school = $calcDueRender('district_sunday_school');

$district_dues = $sumDues([
    $due_district_fund, $straight_love_offering, $pastors_staff_pension_8,
    $church_staff_pension_10, $due_dist_missionary, $due_dist_sunday_school
]);

$due_zonal_fund = $calcDueRender('zonal_fund');
$due_zonal_missionary = $calcDueRender('zonal_missionary');
$due_zonal_sunday_school = $calcDueRender('zonal_sunday_school');

$zonal_dues = $sumDues([
    $due_zonal_fund, $due_zonal_missionary, $due_zonal_sunday_school
]);

$due_life_theo_seminary = $calcDueRender('life_theo_seminary');

$regional_dues = $sumDues([$due_regional_fund]);
$life_dues = $sumDues([$due_life_theo_seminary]);

$payable = moneyRound($national_dues_total + $regional_dues + $district_dues + $zonal_dues + $life_dues);

// Emoluments
$getExpenseAmtLoad = function($key) use ($expensesMap) {
    $item = $expensesMap[$key] ?? null;
    return $item ? (float)$item['amount'] : 0.0;
};
$ministersBasic = $getExpenseAmtLoad('ministers_basic');
$ministersAllowances = $getExpenseAmtLoad('ministers_allowances');
$ministersSubtotal = moneyRound($ministersBasic + $ministersAllowances);

$otherWorkersBasic = $getExpenseAmtLoad('other_workers_basic');
$otherWorkersAllowances = $getExpenseAmtLoad('other_workers_allowances');
$otherWorkersSubtotal = moneyRound($otherWorkersBasic + $otherWorkersAllowances);

$total_emoluments = moneyRound($ministersSubtotal + $otherWorkersSubtotal);

// Fixed Assets
$landAcquisition = $getExpenseAmtLoad('land_acquisition');
$churchBuilding = $getExpenseAmtLoad('church_building');
$purchaseMotorVehicles = $getExpenseAmtLoad('purchase_motor_vehicles');
$purchaseNewEquipment = $getExpenseAmtLoad('purchase_new_equipment');
$fixed_assets_subtotal = moneyRound($landAcquisition + $churchBuilding + $purchaseMotorVehicles + $purchaseNewEquipment);

// General Expenses
$generalExpensesTotalLoad = 0.0;
foreach ($expenseItems as $item) {
    $key = $item['item_key'];
    if (!in_array($key, ['ministers_basic', 'ministers_allowances', 'other_workers_basic', 'other_workers_allowances', 'land_acquisition', 'church_building', 'purchase_motor_vehicles', 'purchase_new_equipment'], true)) {
        $generalExpensesTotalLoad += (float)$item['amount'];
    }
}
$general_expenses = moneyRound($generalExpensesTotalLoad);

// Total Payment
$total_payment = moneyRound($payable + $total_emoluments + $general_expenses + $fixed_assets_subtotal);
$less_total_payment = $total_payment;

$balance_surplus_deficit = moneyRound($total_receipts - $total_payment);
$balance_last_month = (float)($finReport['balance_last_month'] ?? 0.0);
$balance_this_month = moneyRound($balance_surplus_deficit + $balance_last_month);

$cash_in_hand_bank = (float)($finReport['cash_in_hand_bank'] ?? 0.0);
$investment = (float)($finReport['investment'] ?? 0.0);
$total_balance = moneyRound($cash_in_hand_bank + $investment);
$outstanding_loan = (float)($finReport['outstanding_loan'] ?? 0.0);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title><?= h($church['name']) ?> - Church Report <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></title>
  <link rel="icon" type="image/jpeg" href="assets/logo.jpg">
  <link rel="stylesheet" href="assets/bootstrap.min.css">
  <style>
    @page {
      size: A4 portrait;
      margin: 6mm 8mm;
    }

    /* Ensure disabled inputs do not fade out and remain black */
    input:disabled,
    input[disabled] {
      color: #000 !important;
      -webkit-text-fill-color: #000 !important;
      opacity: 1 !important;
      background: transparent !important;
    }

    <?php if ($isPdf): ?>
      /* ===== PDF MODE: mirror @media print exactly (dompdf ignores @media print) ===== */
      body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
      .no-print,
      .action-bar,
      .back-btn-container { display: none !important; }

      /* Each .page div = one A4 portrait page (dompdf page-break) */
      .page {
        box-shadow: none !important;
        margin: 0 !important;
        width: 194mm !important;
        height: 277mm !important;
        min-height: 0 !important;
        padding: 3mm 5mm !important;
        overflow: hidden !important;
        box-sizing: border-box !important;
        page-break-after: always !important;
        page-break-inside: avoid !important;
      }
      .page:last-child { page-break-after: avoid !important; }

      /* PAGE 1: Financial Report — fill A4 portrait */
      table.report-table { font-size: <?= $printFontS ?>px !important; }
      table.report-table td,
      table.report-table th {
        padding: <?= $printCellP ?>px 2px !important;
        height: <?= $printRowH ?>px !important;
      }
      table.report-table input,
      table.report-table select,
      input.cell,
      .cell {
        font-size: <?= $printFontS ?>px !important;
        height: <?= $printRowH ?>px !important;
        line-height: <?= $printRowH ?>px !important;
        padding: 0 1px !important;
      }
      .title-block { margin-bottom: 3px !important; }
      .title-block h1 { font-size: 12px !important; }
      .subtitle-row { font-size: 10px !important; margin-bottom: 3px !important; }
      .meta-line { font-size: 10px !important; margin-bottom: 3px !important; gap: 4px !important; }
      .meta-group { gap: 2px !important; }
      .ledger { font-size: 8.5px !important; margin-top: 2px !important; }
      .bottom-note { font-size: 7.5px !important; padding: 1px 3px !important; margin-top: 2px !important; }
      .col-naira { width: 46px !important; }
      .col-kobo  { width: 28px !important; }

      /* PAGE 2: Spiritual Report — tight to fit */
      .page2-title { font-size: 10px !important; margin-bottom: 2px !important; }
      .stat-grid-top { font-size: 9px !important; margin-bottom: 2px !important; }
      .section-title { font-size: 10px !important; margin: 2px 0 2px 0 !important; }
      table.small-table { font-size: 8px !important; }
      table.small-table td, table.small-table th { height: 9px !important; padding: 0.2px 2px !important; }
      table.membership-table { margin-bottom: 4px !important; }
      table.membership-table td, table.membership-table th { height: 9px !important; padding: 0.2px 2px !important; }
      table.membership-table td.label-cell { font-size: 8px !important; }
      table.membership-table th.label-header { font-size: 8.5px !important; }
      table.membership-table th.input-header { font-size: 8px !important; }
      .signature-block { margin-top: 4px !important; font-size: 8.5px !important; }
      .sig-col { padding: 0 4px !important; }
      .sig-line { height: 14px !important; margin-bottom: 2px !important; }

      /* Make PDF text cells readable */
      td span, td { font-family: "DejaVu Sans", sans-serif !important; }
    <?php endif; ?>

    * {
      box-sizing: border-box;
    }

    body {
      font-family: "Times New Roman", Times, serif;
      background: #e5e5e5;
      margin: 0;
      padding: 20px 0;
      color: #111;
    }

    html, body {
      max-width: 100%;
      overflow-x: hidden;
    }

    .report-scroll-wrapper {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      padding: 0 4px;
      box-sizing: border-box;
    }

    .page {
      width: 210mm;
      height: 297mm;
      background: #fff;
      margin: 0 auto 24px auto;
      padding: 3mm 6mm;
      box-shadow: 0 0 12px rgba(0, 0, 0, 0.3);
      position: relative;
      box-sizing: border-box;
      overflow: visible;
    }

    input[type="text"],
    input[type="number"] {
      font-family: "Times New Roman", Times, serif;
      border: none;
      border-bottom: 1px dotted #888;
      background: transparent;
      font-size: 10px;
      width: 100%;
      padding: 0 2px;
    }

    input[type="text"]:focus,
    input[type="number"]:focus {
      outline: 1px solid #2563eb;
      background: #eef4ff;
    }

    input.cell {
      border: none;
      background: transparent;
      text-align: right;
      font-size: 10px;
      width: 100%;
      height: 13px;
      line-height: 13px;
      padding: 0 2px;
    }

    input.cell:focus {
      outline: 1px solid #2563eb;
      background: #eef4ff;
    }

    input.center {
      text-align: center;
    }

    input.left {
      text-align: left;
    }

    .title-block {
      text-align: center;
      margin-bottom: 2px;
    }

    .title-block h1 {
      font-size: 12.5px;
      margin: 0 0 1px 0;
      letter-spacing: 0.1px;
    }

    .subtitle-row {
      display: flex;
      justify-content: space-between;
      border-bottom: 1px solid #000;
      padding-bottom: 1px;
      margin-bottom: 2px;
    }

    .subtitle-row .left-label {
      font-style: italic;
      text-decoration: underline;
      font-size: 11px;
    }

    .subtitle-row .right-label {
      font-size: 11px;
    }

    .meta-line {
      display: flex;
      flex-wrap: nowrap;
      align-items: baseline;
      gap: 6px;
      font-size: 10.5px;
      margin-bottom: 2px;
      width: 100%;
    }

    .meta-group {
      display: flex;
      align-items: baseline;
      gap: 4px;
      min-width: 0;
    }

    .meta-group label {
      white-space: nowrap;
      font-weight: normal;
    }

    .meta-group input.meta-input {
      border: none;
      border-bottom: 1px solid #444;
      background: transparent;
      font-family: "Times New Roman", Times, serif;
      font-size: 10.5px;
      padding: 0 2px;
      flex: 1;
      min-width: 0;
    }

    .mg-district { flex: 1.5; }
    .mg-month { flex: 1; }
    .mg-year { flex: 0.8; }
    .mg-pastorname { flex: 2.5; }
    .mg-church { flex: 1.2; }
    .mg-churchaddr { flex: 2.5; }
    .mg-pastoraddr { flex: 2.5; }

    .ledger {
      display: flex;
      border: 2px solid #000;
      margin-top: 2px;
    }

    .ledger-col {
      flex: 1;
      border-right: 2px solid #000;
    }

    .ledger-col:last-child {
      border-right: none;
    }

    table.report-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 10px;
    }

    table.report-table td,
    table.report-table th {
      border: 1px solid #000;
      padding: 1px 2px;
      vertical-align: middle;
      height: 13px;
      line-height: 1.1;
    }

    table.report-table th {
      text-align: center;
      font-weight: bold;
    }

    .col-label { width: auto; }
    .col-naira { width: 58px; text-align: center; }
    .col-kobo  { width: 28px; text-align: center; }

    .row-header td {
      font-weight: bold;
      text-decoration: underline;
      background: #fafafa;
    }

    .indent {
      padding-left: 10px !important;
    }

    .small-note {
      font-size: 9.5px;
      font-style: italic;
    }

    .bottom-note {
      background: #000;
      color: #fff;
      font-size: 8px;
      padding: 2px 4px;
      margin-top: 2px;
      line-height: 1.3;
      text-align: center;
    }

    /* Page 2 Spiritual */
    .page2-title {
      text-align: center;
      font-size: 13px;
      font-weight: bold;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }

    .stat-grid-top {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 3px 16px;
      font-size: 11.5px;
      margin-bottom: 4px;
    }

    .stat-line {
      display: flex;
      align-items: baseline;
      gap: 6px;
    }

    .stat-line label {
      white-space: nowrap;
    }

    .stat-line input {
      flex: 1;
      border: none;
      border-bottom: 1px dotted #888;
      background: transparent;
      font-family: "Times New Roman", Times, serif;
      font-size: 13px;
    }

    .section-title {
      text-align: center;
      font-weight: bold;
      font-size: 12px;
      margin: 4px 0 3px 0;
      text-decoration: underline;
    }

    .two-col-section {
      display: flex;
      gap: 8px;
      align-items: flex-start;
    }

    .two-col-section>div {
      flex: 1;
    }

    table.small-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 10px;
    }

    table.small-table td,
    table.small-table th {
      border: 1px solid #000;
      padding: 1px 3px;
      height: 13px;
    }

    table.small-table th {
      text-align: center;
      font-weight: bold;
    }

    .credential-box {
      border: 1px dashed #000;
      padding: 3px 6px;
      font-size: 11px;
      margin-top: 4px;
    }

    .credential-box .cbtitle {
      text-align: center;
      font-weight: bold;
      margin-bottom: 4px;
      text-decoration: underline;
    }

    .credential-row {
      display: flex;
      align-items: baseline;
      gap: 4px;
      margin-bottom: 2px;
    }

    .credential-row label {
      min-width: 70px;
    }

    .credential-row input {
      flex: 1;
      border: none;
      border-bottom: 1px dotted #888;
      background: transparent;
      font-family: "Times New Roman", Times, serif;
    }

    .credential-row.multi input {
      flex: 0 1 40px;
      margin-right: 6px;
    }

    .credential-row.multi label.small {
      min-width: auto;
      margin-right: 2px;
    }

    table.membership-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 4px;
    }

    table.membership-table th,
    table.membership-table td {
      padding: 1px 3px;
      height: 14px;
      vertical-align: middle;
    }

    table.membership-table td.label-cell {
      border: none;
      border-bottom: 1px dotted #999;
      text-align: left;
      font-family: "Times New Roman", Times, serif;
      font-size: 12.5px;
    }

    table.membership-table td.label-cell.no-line {
      border-bottom: none;
    }

    table.membership-table th.label-header {
      border: none;
      text-align: left;
      font-family: "Times New Roman", Times, serif;
      font-size: 13.5px;
      font-weight: bold;
      text-decoration: underline;
    }

    table.membership-table th.input-header {
      border: 1px solid #000;
      text-align: center;
      font-weight: bold;
      font-size: 11.5px;
      width: 140px;
    }

    table.membership-table td.input-cell {
      border: 1px solid #000;
      text-align: center;
      padding: 0;
    }

    table.membership-table td.input-cell input {
      border: none;
      background: transparent;
      text-align: center;
      font-family: "Times New Roman", Times, serif;
      font-size: 12.5px;
      width: 100%;
      height: 100%;
      padding: 2px;
    }

    table.membership-table td.input-cell input:focus {
      outline: 1px solid #2563eb;
      background: #eef4ff;
    }

    .signature-block {
      margin-top: 8px;
      display: flex;
      justify-content: space-between;
      font-size: 11px;
    }

    .sig-col {
      flex: 1;
      text-align: center;
      padding: 0 4px;
    }

    .sig-line {
      border-bottom: 1px solid #000;
      height: 18px;
      margin-bottom: 2px;
    }

    .sig-name-line {
      display: flex;
      align-items: baseline;
      gap: 4px;
      margin-top: 6px;
      justify-content: center;
    }

    .sig-name-line input {
      border: none;
      border-bottom: 1px dotted #888;
      background: transparent;
      font-family: "Times New Roman", Times, serif;
      width: 70%;
      text-align: center;
    }

    .note-box {
      font-size: 9px;
      margin-top: 4px;
      border-top: 1px solid #000;
      padding-top: 2px;
      line-height: 1.3;
      text-align: center;
    }

    .back-btn {
      display: inline-block;
      margin: 0 12px 12px 0;
      padding: 8px 16px;
      background: #1C0F4A;
      color: #fff;
      text-decoration: none;
      border-radius: 6px;
      font-family: system-ui, -apple-system, sans-serif;
      font-size: 13px;
      font-weight: 600;
      text-align: center;
      transition: background 0.2s;
    }

    .back-btn:hover {
      background: #EF231C;
    }

    .back-btn-container {
      width: 210mm;
      margin: 0 auto;
      text-align: left;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    @media print {
      @page { size: A4 portrait; margin: 6mm 8mm; }

      /* Force browser to print ALL background colors exactly as on screen */
      *, *::before, *::after {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
      }

      .back-btn-container {
        display: none !important;
      }
      body {
        background: #fff;
        padding: 0;
        margin: 0;
      }
      /* Each page = exactly one A4 portrait sheet */
      .page {
        box-shadow: none !important;
        margin: 0 !important;
        width: 194mm !important;
        height: 285mm !important;
        min-height: 0 !important;
        padding: 2mm 3mm !important;
        overflow: hidden !important;
        box-sizing: border-box !important;
        page-break-after: always !important;
        page-break-inside: avoid !important;
        break-after: page !important;
        break-inside: avoid !important;
      }
      .page:last-child {
        page-break-after: avoid !important;
        break-after: avoid !important;
      }

      /* Strip input box decoration but keep border-bottom field lines */
      input, select, textarea {
        background: transparent !important;
        box-shadow: none !important;
        outline: none !important;
        color: #000 !important;
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        appearance: none !important;
        /* Remove box borders — but NOT border-bottom, so field underlines survive */
        border-top: none !important;
        border-left: none !important;
        border-right: none !important;
      }
      /* Table cell inputs sit inside bordered table cells — no underline needed */
      input.cell,
      table.report-table input,
      table.small-table input,
      table.membership-table input {
        border: none !important;
      }
      /* Explicitly preserve field underlines for free-standing inputs */
      .stat-line input        { border-bottom: 1px dotted #888 !important; }
      .credential-row input   { border-bottom: 1px dotted #888 !important; }
      .sig-name-line input    { border-bottom: 1px dotted #888 !important; }
      input.meta-input        { border-bottom: 1px solid #444 !important; }
      input:disabled, input[disabled], select:disabled {
        color: #000 !important;
        -webkit-text-fill-color: #000 !important;
        opacity: 1 !important;
      }
      
      /* PAGE 1: Financial Report Spacing — match screen 13px height */
      table.report-table {
        font-size: 10px !important;
      }
      table.report-table td,
      table.report-table th {
        padding: 1px 2px !important;
        height: 13px !important;
      }
      /* Preserve row-header shaded background exactly as on screen */
      .row-header td {
        background: #fafafa !important;
        font-weight: bold !important;
        text-decoration: underline !important;
      }
      table.report-table input,
      table.report-table select,
      input.cell,
      .cell {
        font-size: 10px !important;
        height: 13px !important;
        line-height: 13px !important;
        padding: 0 2px !important;
      }
      .title-block { margin-bottom: 3px !important; }
      .title-block h1 { font-size: 12px !important; }
      .subtitle-row { font-size: 10px !important; margin-bottom: 3px !important; }
      .meta-line { font-size: 10px !important; margin-bottom: 3px !important; gap: 4px !important; }
      .meta-group { gap: 2px !important; }
      .ledger { font-size: 8.5px !important; margin-top: 2px !important; }
      /* Preserve the black footer bar with white text exactly as on screen */
      .bottom-note {
        background: #000 !important;
        color: #fff !important;
        font-size: 7.5px !important;
        padding: 1px 3px !important;
        margin-top: 2px !important;
      }
      .col-naira { width: 46px !important; }
      .col-kobo  { width: 28px !important; }

      /* PAGE 2: Spiritual Report Spacing */
      .page2-title { font-size: 10px !important; margin-bottom: 2px !important; }
      .stat-grid-top { font-size: 9px !important; margin-bottom: 2px !important; }
      .section-title { font-size: 10px !important; margin: 2px 0 2px 0 !important; }
      table.small-table { font-size: 8px !important; }
      table.small-table td, table.small-table th { height: 9px !important; padding: 0.2px 2px !important; }
      table.membership-table { margin-bottom: 4px !important; }
      table.membership-table td, table.membership-table th { height: 9px !important; padding: 0.2px 2px !important; }
      table.membership-table td.label-cell { font-size: 8px !important; }
      table.membership-table th.label-header { font-size: 8.5px !important; }
      table.membership-table th.input-header { font-size: 8px !important; }
      .signature-block { margin-top: 4px !important; font-size: 8.5px !important; }
      .sig-col { padding: 0 4px !important; }
      .sig-line { height: 14px !important; margin-bottom: 2px !important; }
      .no-print { display: none !important; }
    }
  </style>
</head>

<body>
  <div class="back-btn-container">
    <div>
        <?php
        $dashUrl = 'login.php';
        if ($role === 'church_admin') $dashUrl = 'church-dashboard.php';
        elseif ($role === 'zonal_admin') $dashUrl = 'zone-dashboard.php';
        elseif ($role === 'super_admin') $dashUrl = 'admin-dashboard.php';
        ?>
        <a href="<?= $dashUrl ?>" class="back-btn">← Back to Dashboard</a>
    </div>
    
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <?php if (!$viewOnly): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('actionField').value='save'; document.getElementById('reportForm').submit();">Save Draft</button>
        <button type="button" class="btn btn-sm btn-danger" onclick="if(confirm('Are you sure you want to submit? This report will be locked and cannot be edited.')) { document.getElementById('actionField').value='submit'; document.getElementById('reportForm').submit(); }">Submit Report</button>
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="if(confirm('Are you sure you want to delete this draft report? All draft data will be lost.')) { document.getElementById('actionField').value='delete_draft'; document.getElementById('reportForm').submit(); }">🗑 Delete Draft</button>
        <?php else: ?>
        <span class="badge bg-secondary p-2">Locked / View-Only</span>
        <?php
        $paySettings = getPaymentSettings();
        if (($paySettings['payment_enabled'] ?? '0') === '1' && !empty($paySettings['payment_public_key'])):
        ?>
        <button type="button" class="btn btn-sm btn-warning fw-bold text-dark d-inline-flex align-items-center gap-1" onclick="payToUnlockReport()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Pay ₦<?= formatNaira($paySettings['report_unlock_fee']) ?> to Unlock &amp; Edit
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-dark" onclick="printReport()">🖨️ Print / Save as PDF</button>
    </div>
  </div>

  <?php if ($successMsg): ?>
      <div class="container my-2 no-print" style="max-width: 210mm;"><div class="alert alert-success p-2 small"><?= h($successMsg) ?></div></div>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
      <div class="container my-2 no-print" style="max-width: 210mm;"><div class="alert alert-danger p-2 small"><?= h($errorMsg) ?></div></div>
  <?php endif; ?>

  <form method="POST" action="" id="reportForm">
    <input type="hidden" name="action" id="actionField" value="save">
    <div class="report-scroll-wrapper">

    <!-- ============ PAGE 1 ============ -->
    <div class="page">
      <div class="title-block">
        <h1>MONTHLY REPORT OF THE FOURSQUARE GOSPEL CHURCH IN NIGERIA</h1>
      </div>
      <div class="subtitle-row">
        <div class="left-label"><?= strtoupper($church['church_type']) ?> CHURCHES</div>
        <div class="right-label">FINANCIAL REPORT</div>
      </div>

      <div class="meta-line">
        <div class="meta-group mg-district">
          <label>DISTRICT</label>
          <input class="meta-input" type="text" disabled value="<?= h($church['district']) ?>">
        </div>
        <div class="meta-group mg-month">
          <label>MONTH</label>
          <input class="meta-input" type="text" disabled value="<?= monthName($month) ?>">
        </div>
        <div class="meta-group mg-year">
          <label>YEAR</label>
          <input class="meta-input" type="text" disabled value="<?= $year ?>">
        </div>
        <div class="meta-group mg-pastorname">
          <label>PASTOR'S NAME</label>
          <input class="meta-input" type="text" disabled value="<?= h($church['pastor_name']) ?>">
        </div>
      </div>
      <div class="meta-line">
        <div class="meta-group mg-church">
          <label>CHURCH</label>
          <input class="meta-input" type="text" disabled value="<?= h($church['name']) ?>">
        </div>
        <div class="meta-group mg-churchaddr">
          <label>CHURCH ADDRESS</label>
          <input class="meta-input" type="text" disabled value="<?= h($church['address']) ?>">
        </div>
        <div class="meta-group mg-pastoraddr">
          <label>PASTOR'S ADDRESS</label>
          <input class="meta-input" type="text" disabled value="<?= h($church['pastor_address']) ?>">
        </div>
      </div>

      <div class="ledger">
        <!-- LEFT COLUMN -->
        <div class="ledger-col">
          <table class="report-table">
            <tr>
              <th class="col-label">PAYMENTS</th>
              <th class="col-naira">N</th>
              <th class="col-kobo">K</th>
            </tr>
            <tr class="row-header">
              <td colspan="3">TITHES</td>
            </tr>
            <tr>
              <td class="indent">A&nbsp;&nbsp;General Tithe</td>
              <?php renderInput('general_tithe', $finReport['general_tithe'], $viewOnly); ?>
            </tr>
            <tr>
              <td class="indent">B&nbsp;&nbsp;Minister's Tithe</td>
              <?php renderInput('minister_tithe', $finReport['minister_tithe'], $viewOnly); ?>
            </tr>
            <tr>
              <td class="indent">C&nbsp;&nbsp;Worship Offerings</td>
              <?php renderInput('worship_offerings', $finReport['worship_offerings'], $viewOnly); ?>
            </tr>
            <tr>
              <td></td>
              <td></td>
              <td></td>
            </tr>
            <tr>
              <td><strong>SUB - TOTAL (a - c)</strong></td>
              <?php renderInput('subtotal_ac', $finReport['subtotal_ac'], true); ?>
            </tr>
            <tr>
              <td>Missionary Offerings</td>
              <?php renderInput('missionary_offerings', $finReport['missionary_offerings'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Midweek Offerings</td>
              <?php renderInput('midweek_offerings', $finReport['midweek_offerings'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Sunday School Offerings</td>
              <?php renderInput('sunday_school_offerings', $finReport['sunday_school_offerings'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Thanksgiving Offerings</td>
              <?php renderInput('thanksgiving_offerings', $finReport['thanksgiving_offerings'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Love/Welfare Offerings</td>
              <?php renderInput('love_welfare_offerings', $finReport['love_welfare_offerings'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Building Pledge Offerings</td>
              <?php renderInput('building_pledge_offerings', $finReport['building_pledge_offerings'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Church Pioneering</td>
              <?php renderInput('church_pioneering_receipts', $finReport['church_pioneering_receipts'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Donation from Other Churches</td>
              <?php renderInput('donation_other_churches', $finReport['donation_other_churches'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Other Pledges</td>
              <?php renderInput('other_pledges', $finReport['other_pledges'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Seed Faith</td>
              <?php renderInput('seed_faith', $finReport['seed_faith'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Staff Loans Repayment</td>
              <?php renderInput('staff_loans_repayment', $finReport['staff_loans_repayment'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Loan/Cash Deposit from Church Arms</td>
              <?php renderInput('loan_cash_deposit', $finReport['loan_cash_deposit'], $viewOnly); ?>
            </tr>
            <tr>
              <td>5% Pastor's Personal Pension</td>
              <?php renderInput('pastor_pension_5pct', $finReport['pastor_pension_5pct'], $viewOnly); ?>
            </tr>
            <tr>
              <td>National Grant/Augumentation</td>
              <?php renderInput('national_grant', $finReport['national_grant'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Convention Pledges</td>
              <?php renderInput('convention_pledges', $finReport['convention_pledges'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Special Projects 
                <?php renderSimpleInput('special_projects_details', $finReport['special_projects_details'] ?? '', $viewOnly, 'cell left', 'width:60%;display:inline;border-bottom:1px solid #444;', 'Detail'); ?>
              </td>
              <?php renderInput('special_projects', $finReport['special_projects'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Decade of Multiplication Projects</td>
              <?php renderInput('decade_multiplication_receipts', $finReport['decade_multiplication_receipts'], $viewOnly); ?>
            </tr>
            <tr>
              <td>3rd Sunday Offering</td>
              <?php renderInput('third_sunday_offering', $finReport['third_sunday_offering'], $viewOnly); ?>
            </tr>
            <tr>
              <td>TOTAL RECEIPTS</td>
              <?php renderInput('total_receipts', $total_receipts, true); ?>
            </tr>

            <tr>
              <td colspan="1"></td>
              <th class="col-naira">N</th>
              <th class="col-kobo">K</th>
            </tr>
            <tr>
              <td><strong>TOTAL RECEIPTS</strong></td>
              <?php renderInput('total_receipts_dup', $total_receipts, true); ?>
            </tr>
            <tr>
              <td>LESS TOTAL PAYMENT</td>
              <?php renderInput('less_total_payment', $total_payment, true); ?>
            </tr>
            <tr>
              <td>Balance Surplus/Deficit</td>
              <?php renderInput('balance_surplus_deficit', $balance_surplus_deficit, true); ?>
            </tr>
            <tr>
              <td>Balance from Last Month</td>
              <?php renderInput('balance_last_month', $finReport['balance_last_month'], $viewOnly); ?>
            </tr>
            <tr>
              <td>Balance for this Month</td>
              <?php renderInput('balance_this_month', $balance_this_month, true); ?>
            </tr>
            <tr class="row-header">
              <td colspan="3">FINANCIAL INFORMATION</td>
            </tr>
            <tr>
              <td>CASH IN HAND &amp; AT BANK</td>
              <?php renderInput('cash_in_hand_bank', $finReport['cash_in_hand_bank'], $viewOnly); ?>
            </tr>
            <tr>
              <td>INVESTMENT</td>
              <?php renderInput('investment', $finReport['investment'], $viewOnly); ?>
            </tr>
            <tr>
              <td>TOTAL BALANCE</td>
              <?php renderInput('total_balance', $total_balance, true); ?>
            </tr>
            <tr>
              <td>OUTSTANDING LOAN</td>
              <?php renderInput('outstanding_loan', $finReport['outstanding_loan'], $viewOnly); ?>
            </tr>
            <tr class="row-header">
              <td colspan="3">DETAILS OF NATIONAL DUES</td>
            </tr>
            <?php // Dues calculations are performed at the top ?>
            <tr>
              <td>
                TITHES AND OFFERINGS (a - c) <?= floatval($jsRates['tithes_offerings'] ?? 10) ?>%
                
              </td>
              <?php renderInput('due_tithes_offerings', $due_tithes_offerings, $viewOnly, 'cell', isset($lockedKeys['tithes_offerings'])); ?>
            </tr>
            <tr>
              <td>
                PASTOR'S WELFARE (a - c) &nbsp;<?= floatval($jsRates['pastors_welfare'] ?? 5) ?>%
                
              </td>
              <?php renderInput('due_pastors_welfare', $due_pastors_welfare, $viewOnly, 'cell', isset($lockedKeys['pastors_welfare'])); ?>
            </tr>
            <tr>
              <td>
                PROJECT DEV. FUND (a - c) <?= floatval($jsRates['project_dev_fund'] ?? 1.5) ?>%
                
              </td>
              <?php renderInput('due_project_dev', $due_project_dev, $viewOnly, 'cell', isset($lockedKeys['project_dev_fund'])); ?>
            </tr>
            <tr>
              <td>
                MACPHERSON UNI (a - c) <?= floatval($jsRates['macpherson_uni'] ?? 4) ?>%
                
              </td>
              <?php renderInput('due_macpherson', $due_macpherson, $viewOnly, 'cell', isset($lockedKeys['macpherson_uni'])); ?>
            </tr>
            <tr>
              <td>
                AUGMENTATION FUND (a - c) <?= floatval($jsRates['augmentation_fund'] ?? 1) ?>%
                
              </td>
              <?php renderInput('due_augmentation', $due_augmentation, $viewOnly, 'cell', isset($lockedKeys['augmentation_fund'])); ?>
            </tr>
            <tr>
              <td>
                FFS SAVINS (a - c) <?= floatval($jsRates['ffs_savings'] ?? 3) ?>%
                
              </td>
              <?php renderInput('due_ffs_savings', $due_ffs_savings, $viewOnly, 'cell', isset($lockedKeys['ffs_savings'])); ?>
            </tr>
            <tr>
              <td>
                SUNDAY SCHOOL OFFERINGS <?= floatval($jsRates['sunday_school_offering'] ?? 30) ?>%
                
              </td>
              <?php renderInput('due_sunday_school', $due_sunday_school, $viewOnly, 'cell', isset($lockedKeys['sunday_school_offering'])); ?>
            </tr>
            <tr>
              <td>
                MISSIONARY OFFERINGS <?= floatval($jsRates['missionary_offering'] ?? 30) ?>%
                
              </td>
              <?php renderInput('due_missionary', $due_missionary, $viewOnly, 'cell', isset($lockedKeys['missionary_offering'])); ?>
            </tr>
            <tr>
              <td>
                LOVE OFFERINGS <?= floatval($jsRates['love_offering'] ?? 10) ?>%
                
              </td>
              <?php renderInput('due_love_offering', $due_love_offering, $viewOnly, 'cell', isset($lockedKeys['love_offering'])); ?>
            </tr>
            <tr>
              <td>
                FOURSQUARE TV (a - c) <?= floatval($jsRates['foursquare_tv'] ?? 2) ?>%
                
              </td>
              <?php renderInput('due_foursquare_tv', $due_foursquare_tv, $viewOnly, 'cell', isset($lockedKeys['foursquare_tv'])); ?>
            </tr>
            <tr>
              <td>
                3RD SUNDAY OFFERING <?= floatval($jsRates['third_sunday'] ?? 100) ?>%
                
              </td>
              <?php renderInput('due_third_sunday', $due_third_sunday, $viewOnly, 'cell', isset($lockedKeys['third_sunday'])); ?>
            </tr>
            <tr class="row-header">
              <td><strong>TOTAL</strong></td>
              <?php renderInput('national_dues_total', $national_dues_total, true); ?>
            </tr>
          </table>
        </div>

        <!-- RIGHT COLUMN -->
        <div class="ledger-col">
          <table class="report-table">
            <tr>
              <th class="col-label">PAYMENTS</th>
              <th class="col-naira">N</th>
              <th class="col-kobo">K</th>
            </tr>
            <tr class="row-header">
              <td colspan="3" style="text-decoration: none !important;">STAFF SALARIES: Number on Pay Roll</td>
            </tr>
            
            <?php renderExpenseRow($expensesMap['ministers_basic'] ?? null, $viewOnly); ?>
            <?php renderExpenseRow($expensesMap['ministers_allowances'] ?? null, $viewOnly); ?>
            
            <tr>
              <td class="indent">Sub Total (Ministers)</td>
              <?php renderInput('ministers_subtotal', $ministersSubtotal, true); ?>
            </tr>
            
            <?php renderExpenseRow($expensesMap['other_workers_basic'] ?? null, $viewOnly); ?>
            <?php renderExpenseRow($expensesMap['other_workers_allowances'] ?? null, $viewOnly); ?>
            
            <tr>
              <td class="indent">Sub Total (Other Workers)</td>
              <?php renderInput('other_workers_subtotal', $otherWorkersSubtotal, true); ?>
            </tr>
            <tr>
              <td><strong>Total Emoluments</strong></td>
              <?php renderInput('total_emoluments', $total_emoluments, true); ?>
            </tr>

            <!-- General expenses items (rendered dynamically) -->
            <?php
            foreach ($expenseItems as $item) {
                // Skip salaries and fixed assets
                $sKeys = ['ministers_basic', 'ministers_allowances', 'other_workers_basic', 'other_workers_allowances', 'land_acquisition', 'church_building', 'purchase_motor_vehicles', 'purchase_new_equipment'];
                if (!in_array($item['item_key'], $sKeys, true)) {
                    renderExpenseRow($item, $viewOnly);
                }
            }
            ?>
            
            <?php if (!$viewOnly): ?>
            <tr class="no-print">
                <td colspan="3" class="text-center bg-light">
                    <button type="button" class="btn btn-xs btn-outline-primary" style="font-size:10px; padding:2px 6px;" onclick="openAddCustomModal()">+ Add Line Item</button>
                </td>
            </tr>
            <?php endif; ?>

            <tr class="row-header">
              <td colspan="3">FIXED ASSETS:</td>
            </tr>
            
            <?php renderExpenseRow($expensesMap['land_acquisition'] ?? null, $viewOnly); ?>
            <?php renderExpenseRow($expensesMap['church_building'] ?? null, $viewOnly); ?>
            <?php renderExpenseRow($expensesMap['purchase_motor_vehicles'] ?? null, $viewOnly); ?>
            <?php renderExpenseRow($expensesMap['purchase_new_equipment'] ?? null, $viewOnly); ?>
            
            <tr>
              <td><strong>SUB TOTAL (Fixed Assets)</strong></td>
              <?php renderInput('fixed_assets_subtotal', $fixed_assets_subtotal, true); ?>
            </tr>
            
            <tr class="row-header">
              <td colspan="3">DUESSUMMARY</td>
            </tr>
            <tr>
              <td>National Dues</td>
              <?php renderInput('top_national_dues', $national_dues_total, true); ?>
            </tr>
            <tr>
              <td>Regional Dues</td>
              <?php renderInput('top_regional_dues', $regional_dues, true); ?>
            </tr>
            <tr>
              <td>District Dues</td>
              <?php renderInput('top_district_dues', $district_dues, true); ?>
            </tr>
            <tr>
              <td>Zonal Dues</td>
              <?php renderInput('top_zonal_dues', $zonal_dues, true); ?>
            </tr>
            <tr>
              <td>Life Dues</td>
              <?php renderInput('top_life_dues', $life_dues, true); ?>
            </tr>
            <tr class="row-header">
              <td><strong>TOTAL PAYMENTS</strong></td>
              <?php renderInput('total_payment', $total_payment, true); ?>
            </tr>

            <tr class="row-header">
              <td colspan="3">PAYABLESSUMMARY</td>
            </tr>
            <tr>
              <td><strong>PAYABLES</strong></td>
              <?php renderInput('payable_disp', $payable, true); ?>
            </tr>
             <tr>
             <tr>
              <td>
                Regional Dues &nbsp;(a-c) &nbsp;<?= floatval($jsRates['regional_fund'] ?? 0.5) ?>%
                
              </td>
              <?php renderInput('due_regional_fund', $due_regional_fund, $viewOnly, 'cell', isset($lockedKeys['regional_fund'])); ?>
            </tr>
            <tr>
              <td>
                DISTRICT DUES District Fund (a - c) <?= floatval($jsRates['district_fund'] ?? 4) ?>%
                
              </td>
              <?php renderInput('due_district_fund', $due_district_fund, $viewOnly, 'cell', isset($lockedKeys['district_fund'])); ?>
            </tr>
            <tr>
              <td>
                District Love Offering
                
              </td>
              <?php renderInput('due_straight_love_offering', $straight_love_offering, $viewOnly, 'cell', isset($lockedKeys['straight_love_offering'])); ?>
            </tr>
            <tr>
              <td>
                Pastors/Staff Pension Cont. <?= floatval($jsRates['pastors_staff_pension_8'] ?? 8) ?>%
                
              </td>
              <?php renderInput('due_staff_pension_8', $pastors_staff_pension_8, $viewOnly, 'cell', isset($lockedKeys['pastors_staff_pension_8'])); ?>
            </tr>
            <tr>
              <td>
                Church Staff Pension Cont (As Above) <?= floatval($jsRates['church_staff_pension_10'] ?? 10) ?>%
                
              </td>
              <?php renderInput('due_church_pension_10', $church_staff_pension_10, $viewOnly, 'cell', isset($lockedKeys['church_staff_pension_10'])); ?>
            </tr>
            <tr>
              <td>
                Missionary Offering &nbsp;<?= floatval($jsRates['district_missionary'] ?? 15) ?>%
                
              </td>
              <?php renderInput('due_dist_missionary', $due_dist_missionary, $viewOnly, 'cell', isset($lockedKeys['district_missionary'])); ?>
            </tr>
            <tr>
              <td>
                Sunday School Offering &nbsp;<?= floatval($jsRates['district_sunday_school'] ?? 10) ?>%
                
              </td>
              <?php renderInput('due_dist_sunday_school', $due_dist_sunday_school, $viewOnly, 'cell', isset($lockedKeys['district_sunday_school'])); ?>
            </tr>
            <tr>
              <td>
                ZONAL DUES Zonal Fund (a - c) <?= floatval($jsRates['zonal_fund'] ?? 2) ?>%
                
              </td>
              <?php renderInput('due_zonal_fund', $due_zonal_fund, $viewOnly, 'cell', isset($lockedKeys['zonal_fund'])); ?>
            </tr>
            <tr>
              <td>
                Missionary Offering &nbsp;<?= floatval($jsRates['zonal_missionary'] ?? 5) ?>%
                
              </td>
              <?php renderInput('due_zonal_missionary', $due_zonal_missionary, $viewOnly, 'cell', isset($lockedKeys['zonal_missionary'])); ?>
            </tr>
            <tr>
              <td>
                Sunday School Offering &nbsp;<?= floatval($jsRates['zonal_sunday_school'] ?? 10) ?>%
                
              </td>
              <?php renderInput('due_zonal_sunday_school', $due_zonal_sunday_school, $viewOnly, 'cell', isset($lockedKeys['zonal_sunday_school'])); ?>
            </tr>
            <tr>
              <td>
                LIFE THEO SEMINARY LIFE (a - c) <?= floatval($jsRates['life_theo_seminary'] ?? 2) ?>%
                
              </td>
              <?php renderInput('due_life_theo_seminary', $due_life_theo_seminary, $viewOnly, 'cell', isset($lockedKeys['life_theo_seminary'])); ?>
            </tr>
          </table>
        </div>
      </div>

      <div class="bottom-note">
        NOTE: CALCULATION OF DUES IS BASED ON THE ADDITION OF TITHES AND WORSHIP OFFERINGS (A - C)<br>
        CALCULATION OF PENSION = BASIC SALARY + HOUSING + TRANSPORT ALLOWANCES
      </div>
    </div>

    <!-- ============ PAGE 2 ============ -->
    <div class="page">
      <div class="page2-title">SPIRITUAL REPORT</div>

      <div class="stat-grid-top">
        <div class="stat-line">
          <label>1&nbsp;&nbsp;Total New Comers Total</label>
          <?php renderSimpleInput('total_new_comers', $spJson['new_comers'] ?? '', $viewOnly); ?>
        </div>
        <div class="stat-line">
          <label>Holy Spirit Baptism</label>
          <?php renderSimpleInput('total_holy_spirit_baptism', $spJson['spirit_bapt'] ?? '', $viewOnly); ?>
        </div>
        <div class="stat-line">
          <label>Total Decision for Christ</label>
          <?php renderSimpleInput('total_decision_christ', $spJson['decisions'] ?? '', $viewOnly); ?>
        </div>
        <div class="stat-line">
          <label>Total Healings</label>
          <?php renderSimpleInput('total_healings', $spJson['healings'] ?? '', $viewOnly); ?>
        </div>
        <div class="stat-line">
          <label>Total Water Baptism</label>
          <?php renderSimpleInput('total_water_baptism', $spJson['water_bapt'] ?? '', $viewOnly); ?>
        </div>
        <div class="stat-line">
          <label>Total No of House Fellowship Centres</label>
          <?php renderSimpleInput('total_house_fellowship_centres', $spJson['house_fellowships'] ?? '', $viewOnly); ?>
        </div>
      </div>

      <div class="section-title">ATTENDANCE</div>

      <div class="two-col-section">
        <div>
          <div class="section-title" style="text-align:left;">2&nbsp;&nbsp;CRUSADER PROGRAMME</div>
          <table class="small-table">
            <tr>
              <th>Section</th>
              <th>Age Range</th>
              <th>Total No</th>
            </tr>
            <tr>
              <td>Children</td>
              <td></td>
              <td></td>
            </tr>
            <tr>
              <td class="indent">- Candlelighters</td>
              <td>4 - 5</td>
              <td><?php renderSimpleInput('crusader_candlelighters', $spJson['crusaders']['candlelighters'] ?? '', $viewOnly, 'cell'); ?></td>
            </tr>
            <tr>
              <td class="indent">- Cup Bearers</td>
              <td>6 - 8</td>
              <td><?php renderSimpleInput('crusader_cupbearers', $spJson['crusaders']['cupbearers'] ?? '', $viewOnly, 'cell'); ?></td>
            </tr>
            <tr>
              <td class="indent">- Cadets</td>
              <td>9 - 12</td>
              <td><?php renderSimpleInput('crusader_cadets', $spJson['crusaders']['cadets'] ?? '', $viewOnly, 'cell'); ?></td>
            </tr>
            <tr>
              <td>Teens</td>
              <td></td>
              <td></td>
            </tr>
            <tr>
              <td class="indent">- Junior Teens</td>
              <td>13 - 15</td>
              <td><?php renderSimpleInput('crusader_jr_teens', $spJson['crusaders']['jr_teens'] ?? '', $viewOnly, 'cell'); ?></td>
            </tr>
            <tr>
              <td class="indent">- Senior Teens</td>
              <td>16 - 19</td>
              <td><?php renderSimpleInput('crusader_sr_teens', $spJson['crusaders']['sr_teens'] ?? '', $viewOnly, 'cell'); ?></td>
            </tr>
            <tr>
              <td>Youth</td>
              <td></td>
              <td><?php renderSimpleInput('crusader_youth', $spJson['crusaders']['youth'] ?? '', $viewOnly, 'cell'); ?></td>
            </tr>
            <tr>
              <td>Adult (CFM/FWI)</td>
              <td></td>
              <td></td>
            </tr>
            <tr>
              <td class="indent">- Challengers</td>
              <td>30 - 49</td>
              <td><?php renderSimpleInput('crusader_challengers', $spJson['crusaders']['challengers'] ?? '', $viewOnly, 'cell'); ?></td>
            </tr>
            <tr>
              <td class="indent">- Defenders</td>
              <td>50 - 69</td>
              <td><?php renderSimpleInput('crusader_defenders', $spJson['crusaders']['defenders'] ?? '', $viewOnly, 'cell'); ?></td>
            </tr>
            <tr>
              <td class="indent">- Senior Citizens</td>
              <td>70 &amp; Above</td>
              <td><?php renderSimpleInput('crusader_citizens', $spJson['crusaders']['citizens'] ?? '', $viewOnly, 'cell'); ?></td>
            </tr>
          </table>
        </div>

        <div>
          <div class="section-title" style="text-align:left;">CHURCH PROGRAMME AVERAGE ATTENDANCE</div>
          <table class="small-table">
            <tr>
              <th>Section</th>
              <th>Children</th>
              <th>Adults</th>
              <th>Total</th>
            </tr>
            <?php
            $rows = [
                'pre_sun_school' => 'Average Pre Sun. Sch. Prayer',
                'sun_school' => 'Average Sun School',
                'sun_worship' => 'Average Sun. Worship',
                'house_fellowship' => 'Average House Fellowship',
                'bible_study' => 'Average Bible Study',
                'prayer_meeting' => 'Average Prayer Meeting'
            ];
            foreach ($rows as $prefix => $label):
                $cVal = $spReport["{$prefix}_children"] ?? '';
                $aVal = $spReport["{$prefix}_adults"] ?? '';
                $tVal = $spReport["{$prefix}_total"] ?? '';
                $dis = $viewOnly ? 'disabled' : '';
            ?>
            <tr>
              <td><?= $label ?></td>
              <td><?php renderSimpleInput("{$prefix}_children", $cVal != 0 ? $cVal : '', $viewOnly, 'cell'); ?></td>
              <td><?php renderSimpleInput("{$prefix}_adults", $aVal != 0 ? $aVal : '', $viewOnly, 'cell'); ?></td>
              <td><?php renderSimpleInput("{$prefix}_total", (((int)$cVal + (int)$aVal) != 0 ? ((int)$cVal + (int)$aVal) : ($tVal != 0 ? $tVal : '')), true, 'cell'); ?></td>
            </tr>
            <?php endforeach; ?>
          </table>

          <div class="credential-box">
            <div class="cbtitle">CREDENTIAL WORKERS</div>
            <div class="credential-row">
              <label>Ordained</label>
              <?php renderSimpleInput('cw_ordained', $spJson['credential_workers']['ordained'] ?? '', $viewOnly); ?>
            </div>
            <div class="credential-row">
              <label>Licenced</label>
              <?php renderSimpleInput('cw_licensed', $spJson['credential_workers']['licensed'] ?? '', $viewOnly); ?>
            </div>
            <div class="credential-row">
              <label>Exhorters</label>
              <?php renderSimpleInput('cw_exhorters', $spJson['credential_workers']['exhorters'] ?? '', $viewOnly); ?>
            </div>
            <div class="credential-row multi">
              <label class="small">Elders</label>
              <?php renderSimpleInput('cw_elders', $spJson['credential_workers']['elders'] ?? '', $viewOnly); ?>
              <label class="small">Deacons</label>
              <?php renderSimpleInput('cw_deacons', $spJson['credential_workers']['deacons'] ?? '', $viewOnly); ?>
              <label class="small">Deaconesses</label>
              <?php renderSimpleInput('cw_deaconesses', $spJson['credential_workers']['deaconesses'] ?? '', $viewOnly); ?>
            </div>
          </div>
        </div>
      </div>

      <div class="section-title" style="text-align:left; margin-top:16px;">3&nbsp;&nbsp;CHURCH INMEMBERSHIP</div>
      <div style="margin-top: 10px;">
        <!-- Table 1: INTAKES -->
        <table class="membership-table">
          <thead>
            <tr>
              <th class="label-header">INTAKES</th>
              <th class="input-header">18 Years and above</th>
              <th class="input-header">Under 18 Years</th>
              <th class="input-header">TOTAL</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="label-cell">Members up to previous month</td>
              <td class="input-cell"><?php renderSimpleInput('prev_above_18', $spJson['membership_details']['prev_month']['18'] ?? '', $viewOnly, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('prev_under_18', $spJson['membership_details']['prev_month']['u18'] ?? '', $viewOnly, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('prev_total', $prevTotal != 0 ? $prevTotal : '', true, 'cell'); ?></td>
            </tr>
            <tr>
              <td class="label-cell">New Memberes this month</td>
              <td class="input-cell"><?php renderSimpleInput('new_above_18', $spJson['membership_details']['new_members']['18'] ?? '', $viewOnly, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('new_under_18', $spJson['membership_details']['new_members']['u18'] ?? '', $viewOnly, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('new_total', $newTotal != 0 ? $newTotal : '', true, 'cell'); ?></td>
            </tr>
            <tr style="font-weight: bold;">
              <td class="label-cell">TOTAL MEMBERS before WITHDRAWAL</td>
              <td class="input-cell"><?php renderSimpleInput('before_withdrawal_above_18', $beforeWithdrawAbove18 != 0 ? $beforeWithdrawAbove18 : '', true, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('before_withdrawal_under_18', $beforeWithdrawUnder18 != 0 ? $beforeWithdrawUnder18 : '', true, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('before_withdrawal_total', $beforeWithdrawTotal != 0 ? $beforeWithdrawTotal : '', true, 'cell'); ?></td>
            </tr>
          </tbody>
        </table>

        <!-- Table 2: WITHDRAWN -->
        <table class="membership-table">
          <thead>
            <tr>
              <th class="label-header">WITHDRAWN</th>
              <th class="input-header">18 Years and above</th>
              <th class="input-header">Under 18 Years</th>
              <th class="input-header">TOTAL</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="label-cell no-line" style="font-style: italic; font-size: 11.5px; padding-left: 0;">No of Members withdrawn for the following reasons</td>
              <td style="border: none;"></td>
              <td style="border: none;"></td>
              <td style="border: none;"></td>
            </tr>
            <?php
            $reasons = [
                'transfer' => '1. Transfer',
                'resignation' => '2. Resignation',
                'dismissal' => '   Dismissal',
                'death' => '   Death'
            ];
            foreach ($reasons as $key => $lbl):
                $dis = $viewOnly ? 'disabled' : '';
            ?>
            <tr>
              <td class="label-cell" style="padding-left: 15px;"><?= $lbl ?></td>
              <td class="input-cell"><?php renderSimpleInput("withdrawn_{$key}_above_18", $spJson['membership_details']['withdrawn_reasons'][$key]['18'] ?? '', $viewOnly, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput("withdrawn_{$key}_under_18", $spJson['membership_details']['withdrawn_reasons'][$key]['u18'] ?? '', $viewOnly, 'cell'); ?></td>
              <td class="input-cell"><?php 
                $wThisAbove = (int)($wReasons[$key]['18'] ?? 0);
                $wThisUnder = (int)($wReasons[$key]['u18'] ?? 0);
                $wThisTotal = $wThisAbove + $wThisUnder;
                renderSimpleInput("withdrawn_{$key}_total", $wThisTotal != 0 ? $wThisTotal : '', true, 'cell'); 
              ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="font-weight: bold;">
              <td class="label-cell">TOTAL WITHDRAWALS THIS MONTH</td>
              <td class="input-cell"><?php renderSimpleInput('withdrawn_total_above_18', $wTotalAbove18 != 0 ? $wTotalAbove18 : '', true, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('withdrawn_total_under_18', $wTotalUnder18 != 0 ? $wTotalUnder18 : '', true, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('withdrawn_total_total', $wTotalTotal != 0 ? $wTotalTotal : '', true, 'cell'); ?></td>
            </tr>
          </tbody>
        </table>

        <!-- Table 3: MEMBERSHIP SUMMARY -->
        <table class="membership-table">
          <thead>
            <tr>
              <th class="label-header">MEMBERSHIP SUMMARY</th>
              <th class="input-header">18 Years and above</th>
              <th class="input-header">Under 18 Years</th>
              <th class="input-header">TOTAL</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td class="label-cell">Total Members Before Withdrawal</td>
              <td class="input-cell"><?php renderSimpleInput('summary_before_withdrawal_above_18', $beforeWithdrawAbove18 != 0 ? $beforeWithdrawAbove18 : '', true, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('summary_before_withdrawal_under_18', $beforeWithdrawUnder18 != 0 ? $beforeWithdrawUnder18 : '', true, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('summary_before_withdrawal_total', $beforeWithdrawTotal != 0 ? $beforeWithdrawTotal : '', true, 'cell'); ?></td>
            </tr>
            <tr>
              <td class="label-cell">Total Withdrawals This Month</td>
              <td class="input-cell"><?php renderSimpleInput('summary_withdrawn_above_18', $wTotalAbove18 != 0 ? $wTotalAbove18 : '', true, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('summary_withdrawn_under_18', $wTotalUnder18 != 0 ? $wTotalUnder18 : '', true, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('summary_withdrawn_total', $wTotalTotal != 0 ? $wTotalTotal : '', true, 'cell'); ?></td>
            </tr>
            <tr style="font-weight: bold;">
              <td class="label-cell">Total Members After Withdrawal</td>
              <td class="input-cell"><?php renderSimpleInput('after_withdrawal_above_18', $afterWithdrawAbove18 != 0 ? $afterWithdrawAbove18 : '', true, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('after_withdrawal_under_18', $afterWithdrawUnder18 != 0 ? $afterWithdrawUnder18 : '', true, 'cell'); ?></td>
              <td class="input-cell"><?php renderSimpleInput('after_withdrawal_total', $afterWithdrawTotal != 0 ? $afterWithdrawTotal : '', true, 'cell'); ?></td>
            </tr>
          </tbody>
        </table>

        <div class="stat-line" style="margin-top: 10px; width: 250px;">
          <label>DATE</label>
          <?php renderSimpleInput('report_date', $spReport['report_date'] ?? '', $viewOnly, '', '', '', 'date'); ?>
        </div>
      </div>

      <div class="signature-block">
        <div class="sig-col">
          <div class="sig-line"></div>
          TREASURER'S SIGNATURE
          <div class="sig-name-line"><label>NAME</label><?php renderSimpleInput('treasurer_signature_name', $spReport['treasurer_signature_name'] ?? '', $viewOnly); ?></div>
        </div>
        <div class="sig-col">
          <div class="sig-line"></div>
          PASTOR'S SIGNATURE
          <div class="sig-name-line"><label>NAME</label><?php renderSimpleInput('pastor_signature_name', $spReport['pastor_signature_name'] ?? '', $viewOnly); ?></div>
        </div>
        <div class="sig-col">
          <div class="sig-line"></div>
          SECRETARY'S SIGNATURE
          <div class="sig-name-line"><label>NAME</label><?php renderSimpleInput('secretary_signature_name', $spReport['secretary_signature_name'] ?? '', $viewOnly); ?></div>
        </div>
      </div>

      <div class="note-box">
        <strong>NOTE</strong><br>
        This report must be completed on the last Sunday of the month and taken to the Zonal council Meeting for onward
        transmission to the District.<br>
        The Final report must get to the National Office on or before the 14th of the new month. It should be sent by
        courier Service to natseedv@yahoo.com
      </div>
    </div>
  </div>
</form>

  <!-- Rename Modal -->
  <div id="renameModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; padding:20px; border-radius:8px; width:340px; box-shadow:0 4px 15px rgba(0,0,0,0.2);">
      <h5 class="fw-bold mb-3">Rename Expense Category</h5>
      <input type="hidden" id="renameItemId">
      <div class="mb-3">
          <label class="form-label small fw-semibold">New Label Description</label>
          <input type="text" id="renameLabelInput" class="form-control form-control-sm" required>
      </div>
      <div class="d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-secondary btn-sm" onclick="closeRenameModal()">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="submitRenameItem()">Save Label</button>
      </div>
    </div>
  </div>

  <!-- Add Custom Expense Modal -->
  <div id="addCustomModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; padding:20px; border-radius:8px; width:340px; box-shadow:0 4px 15px rgba(0,0,0,0.2);">
      <h5 class="fw-bold mb-3">Add Custom Expense</h5>
      <div class="mb-3">
          <label class="form-label small fw-semibold">Expense Name / Label</label>
          <input type="text" id="customLabelInput" class="form-control form-control-sm" required placeholder="e.g. Youth Day Expenses">
      </div>
      <div class="d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-secondary btn-sm" onclick="closeAddCustomModal()">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="submitCustomItem()">Add Item</button>
      </div>
    </div>
  </div>

  <script>
    // Set configuration for JS calculator
    window.FGC_RATES = <?= json_encode($jsRates) ?>;
    window.FGC_LOCKED_KEYS = <?= json_encode(array_keys($lockedKeys)) ?>;
    window.FGC_BASE_FIELDS = <?= json_encode($baseFields) ?>;
    window.FGC_VIEW_ONLY = <?= $viewOnly ? 'true' : 'false' ?>;

    // Modal Control
    function openRenameModal(itemId, label) {
        document.getElementById('renameItemId').value = itemId;
        document.getElementById('renameLabelInput').value = label;
        document.getElementById('renameModal').style.display = 'flex';
    }

    function closeRenameModal() {
        document.getElementById('renameModal').style.display = 'none';
    }

    function openAddCustomModal() {
        document.getElementById('addCustomModal').style.display = 'flex';
    }

    function closeAddCustomModal() {
        document.getElementById('addCustomModal').style.display = 'none';
    }

    function submitCustomItem() {
        const input = document.getElementById('customLabelInput');
        const label = input ? input.value.trim() : '';
        if (!label) {
            alert('Please enter an expense name.');
            return;
        }
        const mainForm = document.getElementById('reportForm');
        let actionInput = mainForm.querySelector('input[name="action"]');
        if (!actionInput) {
            actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            mainForm.appendChild(actionInput);
        }
        actionInput.value = 'add_custom_item';

        let labelInput = mainForm.querySelector('input[name="custom_label"]');
        if (!labelInput) {
            labelInput = document.createElement('input');
            labelInput.type = 'hidden';
            labelInput.name = 'custom_label';
            mainForm.appendChild(labelInput);
        }
        labelInput.value = label;
        mainForm.submit();
    }

    function submitRenameItem() {
        const itemId = document.getElementById('renameItemId').value;
        const input = document.getElementById('renameLabelInput');
        const label = input ? input.value.trim() : '';
        if (!label || !itemId) {
            alert('Please enter a new label.');
            return;
        }
        const mainForm = document.getElementById('reportForm');
        let actionInput = mainForm.querySelector('input[name="action"]');
        if (!actionInput) {
            actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            mainForm.appendChild(actionInput);
        }
        actionInput.value = 'rename_item';

        let idInput = mainForm.querySelector('input[name="rename_id"]');
        if (!idInput) {
            idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'rename_id';
            mainForm.appendChild(idInput);
        }
        idInput.value = itemId;

        let labelInput = mainForm.querySelector('input[name="rename_label"]');
        if (!labelInput) {
            labelInput = document.createElement('input');
            labelInput.type = 'hidden';
            labelInput.name = 'rename_label';
            mainForm.appendChild(labelInput);
        }
        labelInput.value = label;
        mainForm.submit();
    }

    function printReport() {
        fetch('church_report.php?notify_print=1&month=<?= $month ?>&year=<?= $year ?>').catch(()=>{});
        window.print();
    }
  </script>
  <?php require_once __DIR__ . '/includes/payment_modal.php'; ?>
  <script>
  function payToUnlockReport() {
      openFgcCheckoutModal('report_unlock', 'Unlock Submitted Report for Editing', <?= (float)($paySettings['report_unlock_fee'] ?? 2000) ?>, <?= $reportId ?>, 'church');
  }
  </script>
  <script src="assets/js/church_calc.js"></script>

</body>
</html>
<?php
if ($isPdf) {
    // Increase limits for PDF generation
    set_time_limit(300);
    ini_set('memory_limit', '512M');

    $html = ob_get_clean();

    // Strip all <script> tags — JS is useless in PDF and slows dompdf
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

    require_once __DIR__ . '/vendor/autoload.php';
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);   // disable remote — prevents hang on CDN
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isFontSubsettingEnabled', true);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $churchName  = preg_replace('/[^a-z0-9_\-]/i', '_', $church['name'] ?? 'church');
    $pdfFilename = $churchName . '_Financial_Report_' . monthName($month) . '_' . $year . '.pdf';
    $dompdf->stream($pdfFilename, ['Attachment' => true]);
    exit;
}
?>
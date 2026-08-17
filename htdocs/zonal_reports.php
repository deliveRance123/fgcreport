<?php
/**
 * zonal_reports.php — Zonal Report (Pages 1-4)
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();

$isPdf = isset($_GET['format']) && $_GET['format'] === 'pdf';
if ($isPdf) {
    ob_start();
}

requireLogin();

$role = currentRole();
$zoneId = null;

if ($role === 'zonal_admin') {
    $zoneId = currentZoneId();
} elseif ($role === 'super_admin') {
    $zoneId = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : null;
}

if (!$zoneId) {
    die("Error: No zone selected.");
}

$db = db();

// Fetch zone info
$stmt = $db->prepare("SELECT * FROM zones WHERE id = ?");
$stmt->execute([$zoneId]);
$zone = $stmt->fetch();
if (!$zone) {
    die("Error: Zone not found.");
}

// Fetch churches under this zone
$stmt = $db->prepare("SELECT church_name FROM zone_churches WHERE zone_id = ? ORDER BY display_order ASC");
$stmt->execute([$zoneId]);
$zoneChurches = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($zoneChurches)) {
    die("Error: No churches are registered under this zone. Please add churches in the dashboard first.");
}

// Dynamic print/PDF scaling based on church count
$numChurches = count($zoneChurches);
$scaleSteps  = max(0, $numChurches - 6);
$pZonalFontS = round(max(5.5,  10.5 - $scaleSteps * 0.45), 2);
$pZonalPad   = round(max(0.5,  2.0  - $scaleSteps * 0.20), 2);
$pZonalH     = round(max(8.0,  14.0 - $scaleSteps * 0.50), 2);

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Fetch zonal report
$stmt = $db->prepare("SELECT * FROM zonal_reports WHERE zone_id = ? AND report_month = ? AND report_year = ?");
$stmt->execute([$zoneId, $month, $year]);
$zReport = $stmt->fetch();

$viewOnly = false;
if ($role !== 'zonal_admin') {
    $viewOnly = true;
} elseif ($zReport && $zReport['status'] === 'submitted') {
    $viewOnly = true;
}

// Create new report draft if it doesn't exist and not view-only
if (!$zReport && !$viewOnly) {
    try {
        $stmt = $db->prepare("INSERT INTO zonal_reports (zone_id, report_month, report_year, status) VALUES (?, ?, ?, 'draft')");
        $stmt->execute([$zoneId, $month, $year]);
        
        $stmt = $db->prepare("SELECT * FROM zonal_reports WHERE zone_id = ? AND report_month = ? AND report_year = ?");
        $stmt->execute([$zoneId, $month, $year]);
        $zReport = $stmt->fetch();
    } catch (Exception $e) {
        die("Error creating zonal report draft: " . $e->getMessage());
    }
}

// If it still doesn't exist (e.g. view-only for non-existent report), show empty mock or error
if (!$zReport) {
    die("No report exists for this zone for the selected month/year.");
}

$reportId = $zReport['id'];
$successMsg = '';
$errorMsg = '';

// Handle POST: Saving/Submitting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$viewOnly) {
    $action = $_POST['action'] ?? 'save';

    try {
        $db->beginTransaction();

        // 1. Gather Page 1 inputs (row-by-row for each church)
        $p1Data = [];
        foreach ($zoneChurches as $cName) {
            $key = md5($cName);
            $p1Data[$key] = [
                'church_name' => $cName,
                'sp_tm'  => toFloat($_POST["p1_sp_tm_{$key}"] ?? 0),
                'sp_lm'  => toFloat($_POST["p1_sp_lm_{$key}"] ?? 0),
                'sp_ago' => toFloat($_POST["p1_sp_ago_{$key}"] ?? 0),
                'fin_tm'  => toFloat($_POST["p1_fin_tm_{$key}"] ?? 0),
                'fin_lm'  => toFloat($_POST["p1_fin_lm_{$key}"] ?? 0),
                'fin_ago' => toFloat($_POST["p1_fin_ago_{$key}"] ?? 0),
                'ft'  => (int)($_POST["p1_ft_{$key}"] ?? 0),
                'pt'  => (int)($_POST["p1_pt_{$key}"] ?? 0),
                'dc'  => (int)($_POST["p1_dc_{$key}"] ?? 0),
                'dcn' => (int)($_POST["p1_dcn_{$key}"] ?? 0),
                'eld' => (int)($_POST["p1_eld_{$key}"] ?? 0),
            ];
        }

        // 2. Gather Page 2 inputs (12 params x dynamic churches)
        $p2Data = [];
        for ($p = 1; $p <= 12; $p++) {
            $p2Data[$p] = [];
            foreach ($zoneChurches as $cName) {
                $key = md5($cName);
                $p2Data[$p][$key] = [
                    'church_name' => $cName,
                    'tm' => (int)($_POST["p2_tm_{$p}_{$key}"] ?? 0),
                    'lm' => (int)($_POST["p2_lm_{$p}_{$key}"] ?? 0),
                ];
            }
        }

        // 3. Gather Page 3 inputs (12 params x dynamic churches)
        $p3Data = [];
        for ($p = 1; $p <= 12; $p++) {
            $p3Data[$p] = [];
            foreach ($zoneChurches as $cName) {
                $key = md5($cName);
                $p3Data[$p][$key] = [
                    'church_name' => $cName,
                    'val' => (int)($_POST["p3_val_{$p}_{$key}"] ?? 0),
                ];
            }
        }

        // 4. Gather Page 4 inputs (12 params zonal summaries)
        $p4Data = [];
        for ($p = 1; $p <= 12; $p++) {
            $p4Data[$p] = [
                'tm' => (int)($_POST["p4_tm_{$p}"] ?? 0),
                'lm' => (int)($_POST["p4_lm_{$p}"] ?? 0),
            ];
        }

        // 5. Gather Section B Summary of Spiritual Report
        $summaryData = [];
        for ($p = 1; $p <= 12; $p++) {
            $summaryData[$p] = [
                'tm' => (int)($_POST["p1_sum_tm_{$p}"] ?? 0),
                'lm' => (int)($_POST["p1_sum_lm_{$p}"] ?? 0),
            ];
        }

        // 6. Gather Section C Church Planting Report
        $plantingData = [
            'name' => $_POST['planting_name'] ?? '',
            'address' => $_POST['planting_address'] ?? '',
            'coordinator' => $_POST['planting_coordinator'] ?? '',
            'planting_date' => $_POST['planting_date'] ?? '',
            'attendance' => $_POST['planting_attendance'] ?? '',
            'mother_church' => $_POST['planting_mother_church'] ?? '',
            'pastor_name' => $_POST['planting_pastor_name'] ?? '',
            'phone' => $_POST['planting_phone'] ?? '',
        ];

        $status = ($action === 'submit') ? 'submitted' : 'draft';

        $stmt = $db->prepare("
            UPDATE zonal_reports 
            SET page1_data = ?, page2_data = ?, page3_data = ?, page4_data = ?, planting_data = ?, summary_data = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            json_encode($p1Data),
            json_encode($p2Data),
            json_encode($p3Data),
            json_encode($p4Data),
            json_encode($plantingData),
            json_encode($summaryData),
            $status,
            $reportId
        ]);

        $db->commit();
        $successMsg = ($action === 'submit') ? 'Zonal report submitted successfully! It is now locked.' : 'Zonal report draft saved successfully!';

        // Reload report data
        $stmt = $db->prepare("SELECT * FROM zonal_reports WHERE id = ?");
        $stmt->execute([$reportId]);
        $zReport = $stmt->fetch();

        if ($action === 'submit') {
            $viewOnly = true;
        }

    } catch (Exception $e) {
        $db->rollBack();
        $errorMsg = 'Error saving report: ' . $e->getMessage();
    }
}

// Decode zonal JSON details
$p1Saved = !empty($zReport['page1_data']) ? json_decode($zReport['page1_data'], true) : [];
$p2Saved = !empty($zReport['page2_data']) ? json_decode($zReport['page2_data'], true) : [];
$p3Saved = !empty($zReport['page3_data']) ? json_decode($zReport['page3_data'], true) : [];
$p4Saved = !empty($zReport['page4_data']) ? json_decode($zReport['page4_data'], true) : [];
$summarySaved = !empty($zReport['summary_data']) ? json_decode($zReport['summary_data'], true) : [];
$plantingSaved = !empty($zReport['planting_data']) ? json_decode($zReport['planting_data'], true) : [];

// Auto Pre-fill Helper from online sub-reports (only for draft reports)
// This pre-fills values from submitted reports for matching church names!
if (empty($p1Saved) && !$viewOnly) {
    // If no saved data, let's pre-populate it live!
    foreach ($zoneChurches as $cName) {
        $key = md5($cName);
        
        // Find matching local church
        $stmt = $db->prepare("SELECT id, name FROM churches WHERE name LIKE ? LIMIT 1");
        $stmt->execute(["%{$cName}%"]);
        $lChurch = $stmt->fetch();

        $spTm = 0; $spLm = 0; $spAgo = 0;
        $finTm = 0; $finLm = 0; $finAgo = 0;
        $ft = 0; $pt = 0; $dc = 0; $dcn = 0; $eld = 0;

        if ($lChurch) {
            // Fetch submitted report for this month
            $stmt = $db->prepare("SELECT * FROM church_financial_reports WHERE church_id = ? AND report_month = ? AND report_year = ? AND status='submitted'");
            $stmt->execute([$lChurch['id'], $month, $year]);
            $rTm = $stmt->fetch();

            // Fetch last month report
            $prevM = $month == 1 ? 12 : $month - 1;
            $prevY = $month == 1 ? $year - 1 : $year;
            $stmt = $db->prepare("SELECT * FROM church_financial_reports WHERE church_id = ? AND report_month = ? AND report_year = ? AND status='submitted'");
            $stmt->execute([$lChurch['id'], $prevM, $prevY]);
            $rLm = $stmt->fetch();

            // Fetch a year ago report
            $stmt = $db->prepare("SELECT * FROM church_financial_reports WHERE church_id = ? AND report_month = ? AND report_year = ? AND status='submitted'");
            $stmt->execute([$lChurch['id'], $month, $year - 1]);
            $rAgo = $stmt->fetch();

            if ($rTm) {
                $finTm = $rTm['total_receipts'];
                // Fetch spiritual details
                $stmt = $db->prepare("SELECT * FROM church_spiritual_reports WHERE church_id = ? AND report_month = ? AND report_year = ?");
                $stmt->execute([$lChurch['id'], $month, $year]);
                $spTmRep = $stmt->fetch();
                if ($spTmRep) {
                    $spTm = $spTmRep['sun_worship_total'];
                    // Get credential workers counts
                    $spDetail = json_decode($spTmRep['credential_workers_data'] ?? '{}', true);
                    $ft = (int)($spDetail['credential_workers']['ordained'] ?? 0);
                    $pt = (int)($spDetail['credential_workers']['licensed'] ?? 0);
                    $dc = (int)($spDetail['credential_workers']['deacons'] ?? 0);
                    $dcn = (int)($spDetail['credential_workers']['deaconesses'] ?? 0);
                    $eld = (int)($spDetail['credential_workers']['elders'] ?? 0);
                }
            }
            if ($rLm) {
                $finLm = $rLm['total_receipts'];
                $stmt = $db->prepare("SELECT sun_worship_total FROM church_spiritual_reports WHERE church_id = ? AND report_month = ? AND report_year = ?");
                $stmt->execute([$lChurch['id'], $prevM, $prevY]);
                $spLm = (int)$stmt->fetchColumn();
            }
            if ($rAgo) {
                $finAgo = $rAgo['total_receipts'];
                $stmt = $db->prepare("SELECT sun_worship_total FROM church_spiritual_reports WHERE church_id = ? AND report_month = ? AND report_year = ?");
                $stmt->execute([$lChurch['id'], $month, $year - 1]);
                $spAgo = (int)$stmt->fetchColumn();
            }
        }

        $p1Saved[$key] = [
            'church_name' => $cName,
            'sp_tm'  => $spTm, 'sp_lm'  => $spLm, 'sp_ago' => $spAgo,
            'fin_tm' => $finTm, 'fin_lm' => $finLm, 'fin_ago' => $finAgo,
            'ft'  => $ft, 'pt'  => $pt, 'dc'  => $dc, 'dcn' => $dcn, 'eld' => $eld,
        ];
    }
}

// ─── SERVER-SIDE ZONAL PRE-CALCULATIONS ──────────────────────────────────
$spTmSum = 0; $spLmSum = 0; $spAgoSum = 0;
$finTmSum = 0; $finLmSum = 0; $finAgoSum = 0;
$ftSum = 0; $ptSum = 0; $dcSum = 0; $dcnSum = 0; $eldSum = 0;

$p1RowData = [];
foreach ($zoneChurches as $cName) {
    $key = md5($cName);
    $row = $p1Saved[$key] ?? [
        'sp_tm' => 0, 'sp_lm' => 0, 'sp_ago' => 0,
        'fin_tm' => 0, 'fin_lm' => 0, 'fin_ago' => 0,
        'ft' => 0, 'pt' => 0, 'dc' => 0, 'dcn' => 0, 'eld' => 0
    ];
    $spTmSum += (float)($row['sp_tm'] ?? 0);
    $spLmSum += (float)($row['sp_lm'] ?? 0);
    $spAgoSum += (float)($row['sp_ago'] ?? 0);
    $finTmSum += (float)($row['fin_tm'] ?? 0);
    $finLmSum += (float)($row['fin_lm'] ?? 0);
    $finAgoSum += (float)($row['fin_ago'] ?? 0);
    $ftSum += (int)($row['ft'] ?? 0);
    $ptSum += (int)($row['pt'] ?? 0);
    $dcSum += (int)($row['dc'] ?? 0);
    $dcnSum += (int)($row['dcn'] ?? 0);
    $eldSum += (int)($row['eld'] ?? 0);

    $spDiff = $row['sp_lm'] != 0 ? moneyRound((($row['sp_tm'] - $row['sp_lm']) / $row['sp_lm']) * 100) : null;
    $finDiff = $row['fin_lm'] != 0 ? moneyRound((($row['fin_tm'] - $row['fin_lm']) / $row['fin_lm']) * 100) : null;

    $p1RowData[$key] = [
        'sp_diff' => $spDiff !== null ? number_format($spDiff, 2) . '%' : '—',
        'fin_diff' => $finDiff !== null ? number_format($finDiff, 2) . '%' : '—'
    ];
}

$spTotalDiff = $spLmSum != 0 ? moneyRound((($spTmSum - $spLmSum) / $spLmSum) * 100) : null;
$finTotalDiff = $finLmSum != 0 ? moneyRound((($finTmSum - $finLmSum) / $finLmSum) * 100) : null;

$p1Totals = [
    'sp_tm' => $spTmSum, 'sp_lm' => $spLmSum, 'sp_ago' => $spAgoSum,
    'fin_tm' => $finTmSum, 'fin_lm' => $finLmSum, 'fin_ago' => $finAgoSum,
    'ft' => $ftSum, 'pt' => $ptSum, 'dc' => $dcSum, 'dcn' => $dcnSum, 'eld' => $eldSum,
    'sp_diff' => $spTotalDiff !== null ? number_format($spTotalDiff, 2) . '%' : '—',
    'fin_diff' => $finTotalDiff !== null ? number_format($finTotalDiff, 2) . '%' : '—'
];

$p2Totals = [];
for ($p = 1; $p <= 12; $p++) {
    $tmSum = 0;
    $lmSum = 0;
    foreach ($zoneChurches as $cName) {
        $key = md5($cName);
        $tmSum += (int)($p2Saved[$p][$key]['tm'] ?? 0);
        $lmSum += (int)($p2Saved[$p][$key]['lm'] ?? 0);
    }
    $p2Totals[$p] = ['tm' => $tmSum, 'lm' => $lmSum];
}

$p3Totals = [];
for ($p = 1; $p <= 12; $p++) {
    $sum = 0;
    foreach ($zoneChurches as $cName) {
        $key = md5($cName);
        $sum += (int)($p3Saved[$p][$key]['val'] ?? 0);
    }
    $p3Totals[$p] = $sum;
}

$p4Calcs = [];
for ($p = 1; $p <= 12; $p++) {
    $tm = (int)($p4Saved[$p]['tm'] ?? 0);
    $lm = (int)($p4Saved[$p]['lm'] ?? 0);
    $diff = $tm - $lm;
    $pct = $lm != 0 ? moneyRound(($diff / $lm) * 100) : null;
    $p4Calcs[$p] = [
        'diff' => $diff,
        'pct' => $pct !== null ? number_format($pct, 2) . '%' : '—'
    ];
}

function renderZonalCell($name, $value, $viewOnly, $class = '', $disabled = false, $type = 'text') {
    global $isPdf;
    $renderedVal = ($value !== '' && $value !== null && $value !== 0 && $value !== 0.0 && $value !== '0' && $value !== '0.00') ? $value : '';
    if ($isPdf) {
        echo '<span style="font-size:10px; font-family:\'Times New Roman\';">' . h($renderedVal) . '</span>';
    } else {
        $disabledAttr = ($viewOnly || $disabled) ? 'disabled' : '';
        $classAttr = $class ? ' class="' . $class . '"' : '';
        $nameAttr = $name ? ' name="' . $name . '"' : '';
        echo '<input type="' . $type . '"' . $classAttr . $nameAttr . ' value="' . h($renderedVal) . '" ' . $disabledAttr . '>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($zone['zone_name']) ?> - Zonal Report <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></title>
<link rel="icon" type="image/jpeg" href="assets/logo.jpg">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
  @page { size: A4 landscape; margin: 6mm 8mm; }
  * { box-sizing: border-box; }

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
    body { background: #fff !important; padding: 0; margin: 0; }
    .no-print, .back-btn-container, .action-bar { display: none !important; }

    /* Each .page div = one A4 landscape page */
    .page {
      box-shadow: none !important;
      margin: 0 !important;
      width: 281mm !important;
      height: 194mm !important;
      min-height: 0 !important;
      padding: 3mm 4mm !important;
      box-sizing: border-box !important;
      page-break-after: always !important;
      overflow: hidden !important;
    }
    .page:last-child { page-break-after: avoid !important; }

    input, select, textarea {
      border: none !important; background: transparent !important;
      box-shadow: none !important; outline: none !important; color: #000 !important;
      -webkit-appearance: none !important; appearance: none !important;
    }
    input:disabled, input[disabled] { color: #000 !important; -webkit-text-fill-color: #000 !important; opacity: 1 !important; }

    /* Dynamic scaling based on number of churches */
    table.grid { font-size: <?= $pZonalFontS ?>px !important; width: 100% !important; }
    table.grid th, table.grid td { padding: <?= $pZonalPad ?>px 1px !important; height: <?= $pZonalH ?>px !important; }
    input.cell { font-size: <?= $pZonalFontS ?>px !important; height: <?= $pZonalH ?>px !important; }
    table.summary-table { font-size: <?= $pZonalFontS ?>px !important; }
    table.summary-table td, table.summary-table th { padding: <?= $pZonalPad ?>px 1px !important; height: <?= $pZonalH ?>px !important; }

    h1.doc-title { font-size: 13px !important; margin: 0 0 1px 0 !important; }
    h2.doc-subtitle { font-size: 10px !important; margin: 0 0 4px 0 !important; }
    .month-year-line { font-size: 11px !important; margin-bottom: 4px !important; }
    .box-title { font-size: 10px !important; padding: 2px 4px !important; margin-bottom: 2px !important; }
    .planting-form { font-size: 10px !important; padding: 4px 6px !important; }
    .planting-form .line { margin-bottom: 4px !important; }
    .section-flex { gap: 6px !important; margin-top: 4px !important; }
    .zonal-page1-bottom-flex { gap: 8px !important; }

    /* Make spans (PDF cell values) look like table cells */
    span { font-size: 8.5px !important; font-family: "DejaVu Sans", sans-serif !important; }
  <?php endif; ?>

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

  body {
    font-family: "Times New Roman", Times, serif;
    background: #e5e5e5;
    margin: 0;
    padding: 20px 0;
    color: #111;
  }

  .page {
    width: 297mm;
    height: 210mm;
    background: #fff;
    margin: 0 auto 24px auto;
    padding: 5mm 8mm;
    box-shadow: 0 0 12px rgba(0,0,0,0.3);
    overflow: hidden;
    position: relative;
    box-sizing: border-box;
  }

  h1.doc-title {
    text-align: center;
    font-size: 18px;
    margin: 0 0 2px 0;
    letter-spacing: 0.3px;
  }
  h2.doc-subtitle {
    text-align: center;
    font-size: 13px;
    font-weight: bold;
    margin: 0 0 4px 0;
  }
  .month-year-line {
    text-align: center;
    font-size: 12px;
    margin-bottom: 5px;
  }
  .month-year-line input {
    border: none;
    border-bottom: 1px solid #444;
    background: transparent;
    font-family: "Times New Roman", Times, serif;
    font-size: 13px;
    text-align: center;
  }
  .w-month { width: 90px; }
  .w-year { width: 60px; }

  table.grid {
    width: 100%;
    border-collapse: collapse;
    font-size: 10.5px;
  }
  table.grid th, table.grid td {
    border: 1px solid #000;
    padding: 1px 2px;
    text-align: center;
    height: 13px;
  }
  table.grid th { font-weight: bold; background: #fafafa; }
  table.grid td.param-label, table.grid th.param-label {
    text-align: left;
    white-space: nowrap;
  }
  table.grid td.sn { width: 22px; }

  input.cell {
    border: none;
    background: transparent;
    text-align: center;
    font-family: "Times New Roman", Times, serif;
    font-size: 10.5px;
    width: 100%;
    height: 100%;
    padding: 1px;
  }
  input.cell:focus {
    outline: 1px solid #2563eb;
    background: #eef4ff;
  }

  td.split-cell {
    padding: 0 !important;
  }
  td.split-cell .split-wrapper {
    display: flex;
    height: 100%;
    align-items: stretch;
  }
  td.split-cell .split-wrapper input {
    flex: 1;
    border: none;
    background: transparent;
    text-align: center;
    font-family: "Times New Roman", Times, serif;
    font-size: 10.5px;
    height: 100%;
    padding: 1px;
    width: 50%;
  }
  td.split-cell .split-wrapper input:focus {
    outline: 1px solid #2563eb;
    background: #eef4ff;
  }
  td.split-cell .split-wrapper input:first-child {
    border-right: 1px solid #000;
  }

  .section-flex {
    display: flex;
    gap: 10px;
    margin-top: 6px;
  }
  .section-flex > div { flex: 1; }

  .box-title {
    font-weight: bold;
    font-size: 13px;
    margin-bottom: 4px;
  }

  table.summary-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
  }
  table.summary-table th, table.summary-table td {
    border: 1px solid #000;
    padding: 1px 3px;
    height: 13px;
  }
  table.summary-table th { font-weight: bold; text-align: center; }
  table.summary-table td.param { text-align: left; }

  .planting-form {
    border: 1px solid #000;
    padding: 5px 8px;
    font-size: 11px;
  }
  .planting-form .line {
    display: flex;
    align-items: baseline;
    gap: 4px;
    margin-bottom: 4px;
  }
  .planting-form .line label { white-space: nowrap; min-width: 110px; }
  .planting-form .line input {
    flex: 1;
    border: none;
    border-bottom: 1px dotted #888;
    background: transparent;
    font-family: "Times New Roman", Times, serif;
  }

  .zonal-page1-bottom-flex {
    display: flex;
    gap: 20px;
    align-items: flex-start;
  }

  @media print {
    @page { size: A4 landscape; margin: 6mm 8mm; }

    /* Force browser to print ALL background colors exactly as on screen */
    *, *::before, *::after {
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
      color-adjust: exact !important;
    }

    body { background: #fff !important; padding: 0; margin: 0; }
    .no-print, .back-btn-container, .action-bar { display: none !important; }
    .page {
      box-shadow: none !important; margin: 0 !important;
      width: 281mm !important; height: 194mm !important;
      min-height: 0 !important;
      padding: 3mm 4mm !important; box-sizing: border-box !important;
      page-break-after: always !important; overflow: hidden !important;
      break-after: page !important; break-inside: avoid !important;
    }
    .page:last-child { page-break-after: avoid !important; break-after: avoid !important; }

    /* Strip input decoration but keep border-bottom field lines */
    input, select, textarea {
      background: transparent !important;
      box-shadow: none !important; outline: none !important; color: #000 !important;
      -webkit-appearance: none !important; -moz-appearance: none !important; appearance: none !important;
      /* Remove box borders only — preserve border-bottom so field lines survive */
      border-top: none !important;
      border-left: none !important;
      border-right: none !important;
    }
    /* Table cell inputs sit inside bordered cells — strip all borders */
    input.cell, table.grid input, table.summary-table input {
      border: none !important;
    }
    /* Preserve field underlines for free-standing inputs */
    .planting-form .line input  { border-bottom: 1px dotted #888 !important; }
    .month-year-line input      { border-bottom: 1px solid #444 !important; }

    input:disabled, input[disabled] { color: #000 !important; -webkit-text-fill-color: #000 !important; opacity: 1 !important; }

    /* Preserve grid header shaded background exactly as on screen */
    table.grid th { background: #fafafa !important; }
    table.grid { font-size: 10px !important; width: 100% !important; }
    table.grid th, table.grid td { padding: 1px 2px !important; height: 13px !important; }
    input.cell { font-size: 10px !important; height: 13px !important; line-height: 13px !important; }
    table.summary-table { font-size: 10px !important; }
    table.summary-table td, table.summary-table th { padding: 1px 2px !important; height: 13px !important; }
    table.summary-table th { background: #fafafa !important; }
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
    width: 100%;
    max-width: 297mm;
    margin: 0 auto;
    padding: 0 12px;
    box-sizing: border-box;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
  }
  @media (max-width: 767px) {
    .back-btn-container {
      flex-direction: column;
      align-items: stretch;
      text-align: center;
      padding: 0 16px;
    }
    .back-btn-container > div {
      display: flex;
      justify-content: center;
      flex-wrap: wrap;
      gap: 8px;
      width: 100%;
    }
    .back-btn {
      width: 100%;
      margin: 0 0 4px 0;
    }
  }
</style>
</head>
<body>
  <div class="back-btn-container">
    <div>
        <?php
        $dashUrl = 'login.php';
        if ($role === 'zonal_admin') $dashUrl = 'zone-dashboard.php';
        elseif ($role === 'super_admin') $dashUrl = 'admin-dashboard.php';
        ?>
        <a href="<?= $dashUrl ?>" class="back-btn">← Back to Dashboard</a>
    </div>
    
    <div style="display:flex;gap:8px;align-items:center;">
        <?php if (!$viewOnly): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('actionField').value='save'; document.getElementById('zonalForm').submit();">Save Draft</button>
        <button type="button" class="btn btn-sm btn-danger" onclick="if(confirm('Are you sure you want to submit? Zonal report will be locked.')) { document.getElementById('actionField').value='submit'; document.getElementById('zonalForm').submit(); }">Submit Zonal Report</button>
        <?php else: ?>
        <span class="badge bg-secondary p-2">Locked / View-Only</span>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-dark" onclick="printReport()">🖨️ Print / Save as PDF</button>
    </div>
  </div>

  <?php if ($successMsg): ?>
      <div class="container my-2" style="max-width: 297mm;"><div class="alert alert-success p-2 small"><?= h($successMsg) ?></div></div>
  <?php endif; ?>

  <form method="POST" action="" id="zonalForm">
    <input type="hidden" name="action" id="actionField" value="save">
    <div class="report-scroll-wrapper">

    <!-- ============ PAGE 1: MONTHLY CHURCH BY CHURCH SPIRITUAL AND FINANCIAL REPORT ============ -->
    <div class="page">
      <h1 class="doc-title">FOURSQUARE GOSPEL CHURCH, <?= h($zone['zone_name']) ?></h1>
      <h2 class="doc-subtitle">A MONTHLY CHURCH BY CHURCH SPIRITUAL AND FINANCIAL REPORT</h2>
      <div class="month-year-line">
        <input class="w-month" type="text" disabled value="<?= monthName($month) ?>"> 
        <input class="w-year" type="text" disabled value="<?= $year ?>">
      </div>

      <table class="grid" style="width: 100%; margin-top: 10px; margin-bottom: 16px;">
        <thead>
          <tr>
            <th colspan="6">SPIRITUAL</th>
            <th colspan="9">FINANCIAL</th>
          </tr>
          <tr>
            <th class="sn">S/N</th>
            <th class="param-label">CHURCH</th>
            <th>T/M</th>
            <th>L/M</th>
            <th>%Diff</th>
            <th>A yr ago</th>
            <th>T/M</th>
            <th>L/M</th>
            <th>%Diff</th>
            <th>A yr ago</th>
            <th>FT</th>
            <th>PT</th>
            <th>DC</th>
            <th>DCN</th>
            <th>ELD</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $sn = 1;
          foreach ($zoneChurches as $cName):
              $key = md5($cName);
              $row = $p1Saved[$key] ?? [
                  'sp_tm' => 0, 'sp_lm' => 0, 'sp_ago' => 0,
                  'fin_tm' => 0, 'fin_lm' => 0, 'fin_ago' => 0,
                  'ft' => 0, 'pt' => 0, 'dc' => 0, 'dcn' => 0, 'eld' => 0
              ];
              $dis = $viewOnly ? 'disabled' : '';
          ?>
          <tr data-p1-row="true" data-row-index="<?= $sn ?>">
            <td><?= $sn++ ?>.</td>
            <td class="param-label"><?= h($cName) ?></td>
            <td><?php renderZonalCell("p1_sp_tm_{$key}", $row['sp_tm'], $viewOnly, 'cell p1-sp-tm'); ?></td>
            <td><?php renderZonalCell("p1_sp_lm_{$key}", $row['sp_lm'], $viewOnly, 'cell p1-sp-lm'); ?></td>
            <td><?php renderZonalCell('', $p1RowData[$key]['sp_diff'], true, 'cell p1-sp-diff'); ?></td>
            <td><?php renderZonalCell("p1_sp_ago_{$key}", $row['sp_ago'], $viewOnly, 'cell p1-sp-ago'); ?></td>
            <td><?php renderZonalCell("p1_fin_tm_{$key}", $row['fin_tm'], $viewOnly, 'cell p1-fin-tm'); ?></td>
            <td><?php renderZonalCell("p1_fin_lm_{$key}", $row['fin_lm'], $viewOnly, 'cell p1-fin-lm'); ?></td>
            <td><?php renderZonalCell('', $p1RowData[$key]['fin_diff'], true, 'cell p1-fin-diff'); ?></td>
            <td><?php renderZonalCell("p1_fin_ago_{$key}", $row['fin_ago'], $viewOnly, 'cell p1-fin-ago'); ?></td>
            <td><?php renderZonalCell("p1_ft_{$key}", $row['ft'], $viewOnly, 'cell p1-ft'); ?></td>
            <td><?php renderZonalCell("p1_pt_{$key}", $row['pt'], $viewOnly, 'cell p1-pt'); ?></td>
            <td><?php renderZonalCell("p1_dc_{$key}", $row['dc'], $viewOnly, 'cell p1-dc'); ?></td>
            <td><?php renderZonalCell("p1_dcn_{$key}", $row['dcn'], $viewOnly, 'cell p1-dcn'); ?></td>
            <td><?php renderZonalCell("p1_eld_{$key}", $row['eld'], $viewOnly, 'cell p1-eld'); ?></td>
          </tr>
          <?php endforeach; ?>

          <!-- Total Row Page 1 -->
          <tr data-p1-total="true" style="font-weight: bold; background: #fafafa;">
            <td><?= $sn ?>.</td>
            <td class="param-label">TOTAL</td>
            <td><?php renderZonalCell('', $p1Totals['sp_tm'], true, 'cell p1-sp-tm-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['sp_lm'], true, 'cell p1-sp-lm-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['sp_diff'], true, 'cell p1-sp-diff-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['sp_ago'], true, 'cell p1-sp-ago-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['fin_tm'], true, 'cell p1-fin-tm-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['fin_lm'], true, 'cell p1-fin-lm-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['fin_diff'], true, 'cell p1-fin-diff-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['fin_ago'], true, 'cell p1-fin-ago-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['ft'], true, 'cell p1-ft-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['pt'], true, 'cell p1-pt-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['dc'], true, 'cell p1-dc-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['dcn'], true, 'cell p1-dcn-total'); ?></td>
            <td><?php renderZonalCell('', $p1Totals['eld'], true, 'cell p1-eld-total'); ?></td>
          </tr>
        </tbody>
      </table>

      <!-- Bottom flex container for B. SUMMARY and C. PLANTING side-by-side -->
      <div class="zonal-page1-bottom-flex">
        <!-- LEFT: B. SUMMARY OF SPIRITUAL REPORT -->
        <div style="flex: 1.15;">
          <div class="box-title">B. SUMMARY OF SPIRITUAL REPORT</div>
          <table class="summary-table">
            <tr><th style="width:26px;">S/N</th><th>PARAMETER</th><th>T/M</th><th>L/M</th><th>%DIFF</th></tr>
            <?php
            $params = [
                1 => 'Total new comers',
                2 => 'Total Decision for Christ',
                3 => 'Total Water Baptism',
                4 => 'Total Holy Ghost Baptism',
                5 => 'Total Divine Healing',
                6 => 'Average Sun. School Attendance',
                7 => 'Average Worship Service Attend.',
                8 => 'Average Bible Study Attend.',
                9 => 'Average Prayer Meeting Attend.',
                10 => 'Average Pre- sun. School Attend.',
                11 => 'Average House F/Ship Attend.',
                12 => 'Total New members'
            ];
            foreach ($params as $p => $pName):
                $pRow = $summarySaved[$p] ?? ['tm' => 0, 'lm' => 0];
                $dis = $viewOnly ? 'disabled' : '';
            ?>
            <tr>
              <td><?= $p ?>.</td>
              <td class="param"><?= $pName ?></td>
              <td><?php renderZonalCell("p1_sum_tm_{$p}", $pRow['tm'], $viewOnly, 'cell p1-sum-tm', false, 'text'); ?></td>
              <td><?php renderZonalCell("p1_sum_lm_{$p}", $pRow['lm'], $viewOnly, 'cell p1-sum-lm', false, 'text'); ?></td>
              <td>
                <?php
                $sDiff = $pRow['tm'] - $pRow['lm'];
                $sPct = $pRow['lm'] != 0 ? moneyRound(($sDiff / $pRow['lm']) * 100) : null;
                $sText = $sPct !== null ? number_format($sPct, 2) . '%' : '—';
                renderZonalCell("p1_sum_pct_{$p}", $sText, true, 'cell p1-sum-pct');
                ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>

        <!-- RIGHT: C. CHURCH PLANTING REPORT -->
        <div style="flex: 0.85;">
          <div class="box-title">C. CHURCH PLANTING REPORT</div>
          <div class="planting-form">
            <div class="line"><label>NAME OF CHURCH:</label><?php renderZonalCell('planting_name', $plantingSaved['name'] ?? '', $viewOnly); ?></div>
            <div class="line"><label>ADDRESS:</label><?php renderZonalCell('planting_address', $plantingSaved['address'] ?? '', $viewOnly); ?></div>
            <div class="line"><label>COORDINATOR:</label><?php renderZonalCell('planting_coordinator', $plantingSaved['coordinator'] ?? '', $viewOnly); ?></div>
            <div class="line"><label>DATE OF PLANTING:</label><?php renderZonalCell('planting_date', $plantingSaved['planting_date'] ?? '', $viewOnly); ?></div>
            <div class="line"><label>ATTENDANCE:</label><?php renderZonalCell('planting_attendance', $plantingSaved['attendance'] ?? '', $viewOnly); ?></div>
            <div class="line"><label>MOTHER CHURCH:</label><?php renderZonalCell('planting_mother_church', $plantingSaved['mother_church'] ?? '', $viewOnly); ?></div>
            <div class="line"><label>PASTOR'S NAME:</label><?php renderZonalCell('planting_pastor_name', $plantingSaved['pastor_name'] ?? '', $viewOnly); ?></div>
            <div class="line"><label>PHONE NO.:</label><?php renderZonalCell('planting_phone', $plantingSaved['phone'] ?? '', $viewOnly); ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ PAGE 2: BI-MONTHLY CHURCH BY CHURCH SPIRITUAL REPORTS COMPARISM ============ -->
    <div class="page">
      <h1 class="doc-title">FOURSQUARE GOSPEL CHURCH, <?= h($zone['zone_name']) ?></h1>
      <h2 class="doc-subtitle">BI-MONTHLY CHURCH BY CHURCH SPIRITUAL REPORTS COMPARISM FOR
        <input class="w-month" type="text" disabled value="<?= monthName($month) ?>"> 
        <input class="w-year" type="text" disabled value="<?= $year ?>">
      </h2>

      <table class="grid">
        <thead>
          <tr>
            <th class="sn">S/N</th>
            <th class="param-label">PARAMETERS</th>
            <?php foreach ($zoneChurches as $cName): ?>
              <th><?= h($cName) ?><br>TM&nbsp;|&nbsp;LM</th>
            <?php endforeach; ?>
            <th>TOTAL<br>TM&nbsp;|&nbsp;LM</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($params as $p => $pName): ?>
          <tr>
            <td><?= $p ?>.</td>
            <td class="param-label"><?= $pName ?></td>
            <?php foreach ($zoneChurches as $cName): 
                $key = md5($cName);
                $val = $p2Saved[$p][$key] ?? ['tm' => 0, 'lm' => 0];
                $dis = $viewOnly ? 'disabled' : '';
            ?>
              <td class="split-cell">
                <div class="split-wrapper">
                  <?php renderZonalCell("p2_tm_{$p}_{$key}", $val['tm'], $viewOnly, 'cell p2-tm'); ?>
                  <?php renderZonalCell("p2_lm_{$p}_{$key}", $val['lm'], $viewOnly, 'cell p2-lm'); ?>
                </div>
              </td>
            <?php endforeach; ?>
            <td class="split-cell">
              <div class="split-wrapper">
                <?php renderZonalCell("p2_total_tm_{$p}", $p2Totals[$p]['tm'], true, 'cell p2-total-tm'); ?>
                <?php renderZonalCell("p2_total_lm_{$p}", $p2Totals[$p]['lm'], true, 'cell p2-total-lm'); ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- ============ PAGE 3: MONTHLY CHURCH BY CHURCH SPIRITUAL REPORTS ============ -->
    <div class="page">
      <h1 class="doc-title">FOURSQUARE GOSPEL CHURCH, <?= h($zone['zone_name']) ?></h1>
      <h2 class="doc-subtitle">MONTHLY CHURCH BY CHURCH SPIRITUAL REPORTS FOR
        <input class="w-month" type="text" disabled value="<?= monthName($month) ?>"> 
        <input class="w-year" type="text" disabled value="<?= $year ?>">
      </h2>

      <table class="grid">
        <thead>
          <tr>
            <th class="sn">S/N</th>
            <th class="param-label">PARAMETERS</th>
            <?php foreach ($zoneChurches as $cName): ?>
              <th><?= h($cName) ?></th>
            <?php endforeach; ?>
            <th>TOTAL</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($params as $p => $pName): ?>
          <tr>
            <td><?= $p ?>.</td>
            <td class="param-label"><?= $pName ?></td>
            <?php foreach ($zoneChurches as $cName): 
                $key = md5($cName);
                $val = $p3Saved[$p][$key] ?? ['val' => 0];
                $dis = $viewOnly ? 'disabled' : '';
            ?>
              <td><?php renderZonalCell("p3_val_{$p}_{$key}", $val['val'], $viewOnly, 'cell p3-val'); ?></td>
            <?php endforeach; ?>
            <td><?php renderZonalCell("p3_total_{$p}", $p3Totals[$p], true, 'cell p3-total'); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- ============ PAGE 4: ZONAL MONTHLY SPIRITUAL REPORT SUMMARY ============ -->
    <div class="page">
      <h1 class="doc-title">FOURSQUARE GOSPEL CHURCH, <?= h($zone['zone_name']) ?></h1>
      <h2 class="doc-subtitle">ZONAL MONTHLY SPIRITUAL REPORT SUMMARY FOR
        <input class="w-month" type="text" disabled value="<?= monthName($month) ?>"> 
        <input class="w-year" type="text" disabled value="<?= $year ?>">
      </h2>

      <table class="grid" style="font-size:12px;">
        <thead>
          <tr>
            <th class="sn">S/N</th>
            <th class="param-label">PARAMETERS</th>
            <th>THIS MONTH</th>
            <th>LAST MONTHLY</th>
            <th>DIFF</th>
            <th>%DIFF</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($params as $p => $pName): 
              $val = $p4Saved[$p] ?? ['tm' => 0, 'lm' => 0];
              $dis = $viewOnly ? 'disabled' : '';
          ?>
          <tr>
            <td><?= $p ?>.</td>
            <td class="param-label"><?= $pName ?></td>
            <td><?php renderZonalCell("p4_tm_{$p}", $val['tm'], $viewOnly, 'cell p4-tm'); ?></td>
            <td><?php renderZonalCell("p4_lm_{$p}", $val['lm'], $viewOnly, 'cell p4-lm'); ?></td>
            <td><?php renderZonalCell("p4_diff_{$p}", $p4Calcs[$p]['diff'], true, 'cell p4-diff'); ?></td>
            <td><?php renderZonalCell("p4_pct_{$p}", $p4Calcs[$p]['pct'], true, 'cell p4-pct'); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

  <script src="assets/js/zonal_calc.js?v=<?= time() ?>"></script>
  <script>
    function printReport() {
      window.print();
    }
  </script>
</body>
</html>
<?php
if ($isPdf) {
    // Increase limits for large zonal report PDF generation
    set_time_limit(300);
    ini_set('memory_limit', '512M');

    $html = ob_get_clean();

    // Strip all <script> tags — JS is useless in PDF and slows dompdf
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

    // Replace CDN Bootstrap/FA links with nothing (dompdf can't fetch them anyway)
    // Keep only inline <style> blocks — already embedded

    require_once __DIR__ . '/vendor/autoload.php';
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);   // disable remote — prevents hang on CDN
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isFontSubsettingEnabled', true);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $zoneSafeName = preg_replace('/[^a-z0-9_\-]/i', '_', $zone['zone_name'] ?? 'zone');
    $pdfFilename = $zoneSafeName . '_Zonal_Report_' . monthName($month) . '_' . $year . '.pdf';
    $dompdf->stream($pdfFilename, ['Attachment' => true]);
    exit;
}
?>

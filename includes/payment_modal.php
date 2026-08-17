<?php
/**
 * includes/payment_modal.php — Reusable Foursquare Branded Paystack Checkout Modal
 */
$paySettings = getPaymentSettings();
$payEnabled = ($paySettings['payment_enabled'] ?? '0') === '1';
$payPubKey  = trim($paySettings['payment_public_key'] ?? '');

// Fetch registered user's email directly from session or database
$userEmail = trim($_SESSION['email'] ?? '');
$userName  = trim($_SESSION['full_name'] ?? '');

if ((empty($userEmail) || filter_var($userEmail, FILTER_VALIDATE_EMAIL) === false) && !empty($_SESSION['user_id'])) {
    try {
        $db = db();
        $stmtU = $db->prepare("SELECT email, full_name FROM users WHERE id = ?");
        $stmtU->execute([$_SESSION['user_id']]);
        $uData = $stmtU->fetch();
        if ($uData && !empty($uData['email'])) {
            $userEmail = trim($uData['email']);
            $_SESSION['email'] = $userEmail;
            if (empty($userName) && !empty($uData['full_name'])) {
                $userName = trim($uData['full_name']);
                $_SESSION['full_name'] = $userName;
            }
        }
    } catch (Exception $e) {}
}

if (empty($userEmail) || filter_var($userEmail, FILTER_VALIDATE_EMAIL) === false) {
    // Valid email fallback so Paystack transaction initialization NEVER fails
    $userEmail = 'user' . ($_SESSION['user_id'] ?? rand(100, 999)) . '@fgc-report.org';
}
if (empty($userName)) {
    $userName = 'Foursquare Church Admin';
}
?>

<!-- ULTRA-FAST PAYSTACK ENGINE PRECONNECT & PRELOAD -->
<link rel="dns-prefetch" href="https://js.paystack.co">
<link rel="dns-prefetch" href="https://api.paystack.co">
<link rel="preconnect" href="https://js.paystack.co" crossorigin>
<link rel="preconnect" href="https://api.paystack.co" crossorigin>
<link rel="preload" href="https://js.paystack.co/v1/inline.js" as="script" crossorigin>
<script src="https://js.paystack.co/v1/inline.js"></script>

<!-- FOURSQUARE BRANDED PAYSTACK CHECKOUT MODAL (Hidden by default, fixed overlay) -->
<div id="fgcCheckoutModal" style="display: none !important; position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; width: 100vw !important; height: 100vh !important; z-index: 100000 !important; background: rgba(13, 13, 18, 0.65) !important; backdrop-filter: blur(4px) !important; -webkit-backdrop-filter: blur(4px) !important; align-items: center !important; justify-content: center !important; margin: 0 !important; padding: 16px !important; box-sizing: border-box !important;">
  <div style="width: 100%; max-width: 440px; background: #FFFFFF; border-radius: 20px; overflow: hidden; box-shadow: 0 20px 50px rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.2);">
    
    <!-- MODAL BRANDED HEADER -->
    <div style="background: linear-gradient(135deg, #1A1040 0%, #2E1B6A 100%); padding: 20px 22px; color: #FFFFFF; position: relative;">
      <button type="button" onclick="closeFgcCheckoutModal()" style="position: absolute; top: 16px; right: 16px; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2); color: #fff; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 18px; line-height: 1; display: flex; align-items: center; justify-content: center;">&times;</button>
      <div style="display: flex; align-items: center; gap: 12px;">
        <div style="width: 44px; height: 44px; border-radius: 12px; background: #E31E24; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(227,30,36,0.4); border: 2px solid rgba(255,255,255,0.2); flex-shrink: 0; overflow: hidden;">
          <img src="assets/logo.jpg" alt="Logo" style="width: 100%; height: 100%; object-fit: cover;">
        </div>
        <div>
          <h5 style="margin: 0; font-family: 'Outfit', sans-serif; font-size: 16.5px; font-weight: 700; color: #FFFFFF;">Foursquare Checkout</h5>
          <span style="font-size: 11.5px; opacity: 0.8; display: block;">Paystack Secure Payment</span>
        </div>
      </div>
    </div>

    <!-- MODAL BODY & ITEM BREAKDOWN -->
    <div style="padding: 22px; background: #FAF9F6;">
      <div style="border-radius: 14px; background: #FFFFFF; padding: 16px; border: 1px solid #E5E7EB; box-shadow: 0 2px 6px rgba(0,0,0,0.03); margin-bottom: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
          <div>
            <span id="modalPayTypeBadge" style="background: rgba(26,16,64,0.08); color: #1A1040; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; padding: 3px 8px; border-radius: 6px; display: inline-block;">PAYMENT</span>
            <h6 id="modalPayTitle" style="margin: 6px 0 0; font-size: 14.5px; font-weight: 700; color: #1A1040;">Monthly Subscription</h6>
          </div>
          <div style="text-align: right;">
            <span style="font-size: 11px; color: #6B7280; display: block;">Total Amount</span>
            <span id="modalPayAmount" style="font-size: 19px; font-weight: 800; color: #E31E24; font-family: 'Outfit', sans-serif;">₦5,000.00</span>
          </div>
        </div>
        <div style="height: 1px; background: #F3F4F6; margin: 12px 0;"></div>
        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12.5px; color: #6B7280; margin-bottom: 4px;">
          <span>Account User:</span>
          <strong style="color: #374151;"><?= h($userName) ?></strong>
        </div>
        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12.5px; color: #6B7280;">
          <span>Email Address:</span>
          <strong style="color: #374151;"><?= h($userEmail) ?></strong>
        </div>
      </div>

      <!-- PAYSTACK SECURITY BADGE -->
      <div style="display: flex; align-items: center; justify-content: center; gap: 8px; padding: 8px 12px; background: rgba(16,185,129,0.08); border: 1px dashed rgba(16,185,129,0.3); border-radius: 8px; margin-bottom: 18px;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
        <span style="font-size: 11.5px; font-weight: 700; color: #047857;">Secured &amp; Encrypted by Paystack</span>
      </div>

      <!-- ACTION BUTTON -->
      <button type="button" id="btnConfirmPaystack" onclick="executePaystackPayment()" style="width: 100%; padding: 13px; background: linear-gradient(135deg, #E31E24 0%, #B91C1C 100%); color: #FFFFFF; border: none; border-radius: 12px; font-size: 14.5px; font-weight: 700; cursor: pointer; box-shadow: 0 6px 18px rgba(227,30,36,0.35); transition: transform 0.15s ease;">
        Pay Now with Paystack &rarr;
      </button>

      <div style="text-align: center; margin-top: 12px;">
        <button type="button" onclick="closeFgcCheckoutModal()" style="background: none; border: none; font-size: 12px; color: #6B7280; cursor: pointer; text-decoration: underline;">Cancel Payment</button>
      </div>
    </div>

  </div>
</div>

<script>
const FGC_PAY_ENABLED = <?= json_encode($payEnabled) ?>;
const FGC_PAY_PUB_KEY = <?= json_encode($payPubKey) ?>;

let currentPayConfig = {
    type: 'subscription',
    title: 'Monthly Subscription',
    amount: 5000,
    reportId: 0,
    reportType: 'church'
};

function openFgcCheckoutModal(type, title, amount, reportId = 0, reportType = 'church') {
    if (!FGC_PAY_ENABLED) {
        alert('ℹ️ Payments are currently disabled in Super Admin Settings.');
        return;
    }
    if (!FGC_PAY_PUB_KEY) {
        alert('⚠️ Paystack Public Key is missing in Super Admin Payment Settings.');
        return;
    }

    currentPayConfig = { type, title, amount, reportId, reportType };
    executePaystackPayment();
}

function closeFgcCheckoutModal() {
    let modalEl = document.getElementById('fgcCheckoutModal');
    if (modalEl) {
        modalEl.style.setProperty('display', 'none', 'important');
    }
}

function executePaystackPayment() {
    closeFgcCheckoutModal();

    if (typeof PaystackPop === 'undefined') {
        alert('⚠️ Paystack payment gateway script failed to load. Please check your internet connection or refresh the page.');
        return;
    }

    let refPrefix = currentPayConfig.type === 'subscription' ? 'SUB_' : 'UNLOCK_';
    let ref = refPrefix + Math.floor((Math.random() * 1000000000) + 1);
    let amountKobo = Math.round(Number(currentPayConfig.amount) * 100);

    let handler = PaystackPop.setup({
        key: FGC_PAY_PUB_KEY,
        email: '<?= h($userEmail) ?>',
        amount: amountKobo,
        currency: 'NGN',
        ref: ref,
        channels: ['card', 'bank', 'ussd', 'qr', 'mobile_money', 'bank_transfer'],
        label: 'Foursquare Reports — ' + currentPayConfig.title,
        metadata: {
            custom_fields: [
                { display_name: "Platform", variable_name: "platform", value: "Foursquare Monthly Reports Portal" },
                { display_name: "User Name", variable_name: "user_name", value: '<?= h($userName) ?>' },
                { display_name: "Payment Type", variable_name: "payment_type", value: currentPayConfig.type },
                { display_name: "Report ID", variable_name: "report_id", value: String(currentPayConfig.reportId) }
            ]
        },
        callback: function(response) {
            // Direct instant unlock redirection (<0.1s execution)
            let unlockUrl = 'process_payment.php?reference=' + encodeURIComponent(response.reference) +
                            '&payment_type=' + encodeURIComponent(currentPayConfig.type) +
                            '&report_id=' + encodeURIComponent(currentPayConfig.reportId) +
                            '&report_type=' + encodeURIComponent(currentPayConfig.reportType);
            window.location.href = unlockUrl;
        }
    });
    handler.openIframe();
}
</script>

/**
 * assets/ts/church_calc.ts — Live calculations for the Church Financial & Spiritual Report form.
 */

interface Window {
  FGC_RATES?: Record<string, string | number>;
  FGC_BASE_FIELDS?: Record<string, string>;
  FGC_LOCKED_KEYS?: string[];
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form');
    if (!form) return;

    // Load due percentage rates from global variable
    const rates: Record<string, string | number> = window.FGC_RATES || {};

    // Round half up helper
    function roundHalfUp(value: number, decimals: number = 2): number {
        return Number(Math.round(value + ('e' + decimals) as any) + 'e-' + decimals);
    }

    // Helper to check if a base field is empty, blank, or 0
    function isBaseFieldEmpty(fieldName: string): boolean {
        const nEl = document.querySelector(`[name="${fieldName}_naira"]`) as HTMLInputElement | null;
        const kEl = document.querySelector(`[name="${fieldName}_kobo"]`) as HTMLInputElement | null;
        if (!nEl && !kEl) return true;
        
        const nVal = nEl ? nEl.value.trim() : '';
        const kVal = kEl ? kEl.value.trim() : '';
        
        // If both inputs are completely empty
        if (nVal === '' && kVal === '') return true;
        
        const val = getVal(fieldName);
        return val === 0;
    }

    // Helper to get float value of naira + kobo input pair
    function getVal(fieldName: string): number {
        const nEl = document.querySelector(`[name="${fieldName}_naira"]`) as HTMLInputElement | null;
        const kEl = document.querySelector(`[name="${fieldName}_kobo"]`) as HTMLInputElement | null;
        const n = nEl ? parseFloat(nEl.value) : 0;
        const k = kEl ? parseFloat(kEl.value) : 0;
        const val = (isNaN(n) ? 0 : n) + (isNaN(k) ? 0 : k) / 100;
        return roundHalfUp(val, 2);
    }

    // Helper to set computed value into naira + kobo input pair (disabled)
    function setVal(fieldName: string, value: number): void {
        const val = roundHalfUp(value, 2);
        const nEl = document.querySelector(`[name="${fieldName}_naira"]`) as HTMLInputElement | null;
        const kEl = document.querySelector(`[name="${fieldName}_kobo"]`) as HTMLInputElement | null;
        
        if (nEl && kEl) {
            const naira = Math.floor(Math.abs(val));
            const kobo = Math.round((Math.abs(val) - naira) * 100);
            const sign = val < 0 ? '-' : '';
            
            nEl.value = sign + naira;
            kEl.value = kobo.toString().padStart(2, '0');
        }
    }

    // Helper to update individual due with locking and empty check
    function updateDue(dueKey: string, fieldName: string): number {
        const baseField = (window.FGC_BASE_FIELDS && window.FGC_BASE_FIELDS[dueKey]) || 'subtotal_ac';
        const isLocked = window.FGC_LOCKED_KEYS && window.FGC_LOCKED_KEYS.includes(dueKey);
        
        const nEl = document.querySelector(`[name="${fieldName}_naira"]`) as HTMLInputElement | null;
        const kEl = document.querySelector(`[name="${fieldName}_kobo"]`) as HTMLInputElement | null;
        
        if (!nEl || !kEl) return 0;
        
        if (isLocked) {
            nEl.disabled = true;
            kEl.disabled = true;
            nEl.value = '';
            kEl.value = '00';
            return 0;
        }

        // If the user has manually edited this unlocked field, preserve their input
        if (nEl.dataset.manual === 'true' || kEl.dataset.manual === 'true') {
            return getVal(fieldName);
        }
        
        if (isBaseFieldEmpty(baseField)) {
            nEl.value = '0';
            kEl.value = '00';
            return 0;
        }
        
        const rate = getRate(dueKey);
        const baseVal = getVal(baseField);
        const val = roundHalfUp(baseVal * (rate / 100), 2);
        
        const naira = Math.floor(Math.abs(val));
        const kobo = Math.round((Math.abs(val) - naira) * 100);
        const sign = val < 0 ? '-' : '';
        
        nEl.value = sign + naira;
        kEl.value = kobo.toString().padStart(2, '0');
        
        return val;
    }

    // Helper to get rate from global config
    function getRate(dueKey: string): number {
        return rates[dueKey] !== undefined ? parseFloat(rates[dueKey] as string) : 0;
    }

    // Helper for single numeric inputs (Spiritual Page)
    function getNum(selector: string): number {
        const el = document.querySelector(selector) as HTMLInputElement | null;
        const val = el ? parseInt(el.value, 10) : 0;
        return isNaN(val) ? 0 : val;
    }

    // Helper to set value on single input (Spiritual Page)
    function setNum(selector: string, val: number): void {
        const el = document.querySelector(selector) as HTMLInputElement | null;
        if (el) {
            el.value = val.toString();
        }
    }

    function calculateForm(): void {
        // ─── LEFT COLUMN RECEIPTS ─────────────────────────────────────────
        const generalTithe = getVal('general_tithe');
        const ministerTithe = getVal('minister_tithe');
        const worshipOfferings = getVal('worship_offerings');

        // Subtotal a-c
        const subtotalAc = roundHalfUp(generalTithe + ministerTithe + worshipOfferings, 2);
        setVal('subtotal_ac', subtotalAc);

        // Receipts fields
        const missionaryOfferings = getVal('missionary_offerings');
        const midweekOfferings = getVal('midweek_offerings');
        const sundaySchoolOfferings = getVal('sunday_school_offerings');
        const thanksgivingOfferings = getVal('thanksgiving_offerings');
        const loveWelfareOfferings = getVal('love_welfare_offerings');
        const buildingPledgeOfferings = getVal('building_pledge_offerings');
        const churchPioneeringReceipts = getVal('church_pioneering_receipts');
        const donationOtherChurches = getVal('donation_other_churches');
        const otherPledges = getVal('other_pledges');
        const seedFaith = getVal('seed_faith');
        const staffLoansRepayment = getVal('staff_loans_repayment');
        const loanCashDeposit = getVal('loan_cash_deposit');
        const pastorPension5pct = getVal('pastor_pension_5pct');
        const nationalGrant = getVal('national_grant');
        const conventionPledges = getVal('convention_pledges');
        const specialProjects = getVal('special_projects');
        const decadeMultiplicationReceipts = getVal('decade_multiplication_receipts');
        const thirdSundayOffering = getVal('third_sunday_offering');

        // Total Receipts
        const otherReceiptsTotal = missionaryOfferings + midweekOfferings + sundaySchoolOfferings +
                                   thanksgivingOfferings + loveWelfareOfferings + buildingPledgeOfferings +
                                   churchPioneeringReceipts + donationOtherChurches + otherPledges +
                                   seedFaith + staffLoansRepayment + loanCashDeposit + pastorPension5pct +
                                   nationalGrant + conventionPledges + specialProjects +
                                   decadeMultiplicationReceipts + thirdSundayOffering;

        const totalReceipts = roundHalfUp(subtotalAc + otherReceiptsTotal, 2);
        
        setVal('total_receipts', totalReceipts);
        setVal('total_receipts_dup', totalReceipts);

        // ─── DETAILS OF NATIONAL DUES ─────────────────────────────────────
        const dueTithesOfferings = updateDue('tithes_offerings', 'due_tithes_offerings');
        const duePastorsWelfare  = updateDue('pastors_welfare', 'due_pastors_welfare');
        const dueProjectDev      = updateDue('project_dev_fund', 'due_project_dev');
        const dueMacpherson      = updateDue('macpherson_uni', 'due_macpherson');
        const dueAugmentation    = updateDue('augmentation_fund', 'due_augmentation');
        const dueFfsSavings      = updateDue('ffs_savings', 'due_ffs_savings');
        const dueSundaySchool    = updateDue('sunday_school_offering', 'due_sunday_school');
        const dueMissionary      = updateDue('missionary_offering', 'due_missionary');
        const dueLoveOffering    = updateDue('love_offering', 'due_love_offering');
        const dueFoursquareTv    = updateDue('foursquare_tv', 'due_foursquare_tv');
        const dueThirdSunday     = updateDue('third_sunday', 'due_third_sunday');

        const nationalDuesTotal = roundHalfUp(dueTithesOfferings + duePastorsWelfare + dueProjectDev +
                                             dueMacpherson + dueAugmentation + dueFfsSavings +
                                             dueSundaySchool + dueMissionary + dueLoveOffering +
                                             dueFoursquareTv + dueThirdSunday, 2);
        setVal('national_dues_total', nationalDuesTotal);
        setVal('top_national_dues', nationalDuesTotal);

        // ─── RIGHT COLUMN DUES & PENSIONS ─────────────────────────────────
        const regionalFund      = updateDue('regional_fund', 'due_regional_fund');
        const districtFund      = updateDue('district_fund', 'due_district_fund');
        const straightLoveOff   = updateDue('straight_love_offering', 'due_straight_love_offering');
        const staffPension8     = updateDue('pastors_staff_pension_8', 'due_staff_pension_8');
        const churchPension10   = updateDue('church_staff_pension_10', 'due_church_pension_10');
        const distMissionary    = updateDue('district_missionary', 'due_dist_missionary');
        const distSundaySchool  = updateDue('district_sunday_school', 'due_dist_sunday_school');
        
        const zonalFund         = updateDue('zonal_fund', 'due_zonal_fund');
        const zonalMissionary   = updateDue('zonal_missionary', 'due_zonal_missionary');
        const zonalSundaySchool = updateDue('zonal_sunday_school', 'due_zonal_sunday_school');
        
        const lifeTheoSeminary  = updateDue('life_theo_seminary', 'due_life_theo_seminary');

        const regionalDues = regionalFund;
        const districtDues = roundHalfUp(districtFund + straightLoveOff + staffPension8 + churchPension10 + distMissionary + distSundaySchool, 2);
        const zonalDues = roundHalfUp(zonalFund + zonalMissionary + zonalSundaySchool, 2);
        const lifeDues = lifeTheoSeminary;

        setVal('top_regional_dues', regionalDues);
        setVal('top_district_dues', districtDues);
        setVal('top_zonal_dues', zonalDues);
        setVal('top_life_dues', lifeDues);

        // Payable = sum of all dues only
        const payable = roundHalfUp(nationalDuesTotal + regionalDues + districtDues + zonalDues + lifeDues, 2);
        setVal('payable', payable);
        setVal('payable_disp', payable); // mirrors in PAYABLESSUMMARY

        // Helper to get value from an expense row by item-key
        function getExpenseVal(itemKey: string): number {
            const row = document.querySelector(`[data-expense-item="true"][data-item-key="${itemKey}"]`);
            if (!row) return 0;
            const nEl = row.querySelector('input[name^="expense_amount_naira"]') as HTMLInputElement | null;
            const kEl = row.querySelector('input[name^="expense_amount_kobo"]') as HTMLInputElement | null;
            const n = nEl ? (parseFloat(nEl.value) || 0) : 0;
            const k = kEl ? (parseFloat(kEl.value) || 0) : 0;
            return roundHalfUp(n + k / 100, 2);
        }

        // Staff Salaries
        const ministersBasic      = getExpenseVal('ministers_basic');
        const ministersAllowances = getExpenseVal('ministers_allowances');
        const ministersSubtotal   = roundHalfUp(ministersBasic + ministersAllowances, 2);
        setVal('ministers_subtotal', ministersSubtotal);

        const otherWorkersBasic       = getExpenseVal('other_workers_basic');
        const otherWorkersAllowances  = getExpenseVal('other_workers_allowances');
        const otherWorkersSubtotal    = roundHalfUp(otherWorkersBasic + otherWorkersAllowances, 2);
        setVal('other_workers_subtotal', otherWorkersSubtotal);

        const totalEmoluments = roundHalfUp(ministersSubtotal + otherWorkersSubtotal, 2);
        setVal('total_emoluments', totalEmoluments);

        // Sum Fixed Assets
        const landAcquisition      = getExpenseVal('land_acquisition');
        const churchBuilding       = getExpenseVal('church_building');
        const purchaseMotorVehicles = getExpenseVal('purchase_motor_vehicles');
        const purchaseNewEquipment  = getExpenseVal('purchase_new_equipment');
        const fixedAssetsSubtotal  = roundHalfUp(landAcquisition + churchBuilding + purchaseMotorVehicles + purchaseNewEquipment, 2);
        setVal('fixed_assets_subtotal', fixedAssetsSubtotal);

        // Sum general expenses — inputs are named expense_amount_naira[id] / expense_amount_kobo[id]
        let generalExpensesTotal = 0;
        const expenseRows = document.querySelectorAll('[data-expense-item="true"]');
        const skipKeys = ['ministers_basic', 'ministers_allowances', 'other_workers_basic',
                          'other_workers_allowances', 'land_acquisition', 'church_building',
                          'purchase_motor_vehicles', 'purchase_new_equipment'];
        expenseRows.forEach(row => {
            const key = row.getAttribute('data-item-key') || '';
            if (skipKeys.includes(key)) return;
            // find the naira and kobo inputs inside this row
            const nEl = row.querySelector('input[name^="expense_amount_naira"]') as HTMLInputElement | null;
            const kEl = row.querySelector('input[name^="expense_amount_kobo"]') as HTMLInputElement | null;
            const n = nEl ? (parseFloat(nEl.value) || 0) : 0;
            const k = kEl ? (parseFloat(kEl.value) || 0) : 0;
            generalExpensesTotal += roundHalfUp(n + k / 100, 2);
        });
        generalExpensesTotal = roundHalfUp(generalExpensesTotal, 2);

        const totalPayment = roundHalfUp(payable + totalEmoluments + generalExpensesTotal + fixedAssetsSubtotal, 2);
        setVal('total_payment', totalPayment);

        // ─── LEFT COLUMN BOTTOM ───────────────────────────────────────────
        setVal('less_total_payment', totalPayment);

        const balanceSurplusDeficit = roundHalfUp(totalReceipts - totalPayment, 2);
        setVal('balance_surplus_deficit', balanceSurplusDeficit);

        const balanceLastMonth = getVal('balance_last_month');
        const balanceThisMonth = roundHalfUp(balanceSurplusDeficit + balanceLastMonth, 2);
        setVal('balance_this_month', balanceThisMonth);

        // Financial Info
        const cashInHandBank = getVal('cash_in_hand_bank');
        const investment = getVal('investment');
        const totalBalance = roundHalfUp(cashInHandBank + investment, 2);
        setVal('total_balance', totalBalance);

        // ─── SPIRITUAL PAGE CALCULATIONS ──────────────────────────────────
        // 1. Average Attendance Table Totals
        const attendanceRowPrefixes = ['pre_sun_school', 'sun_school', 'sun_worship', 'house_fellowship', 'bible_study', 'prayer_meeting'];
        attendanceRowPrefixes.forEach(prefix => {
            const children = getNum(`[name="${prefix}_children"]`);
            const adults = getNum(`[name="${prefix}_adults"]`);
            const total = children + adults;
            setNum(`[name="${prefix}_total"]`, total);
        });

        // 2. Membership Tables
        // Table 1: Intakes
        const prev18 = getNum('[name="prev_above_18"]');
        const prevUnder = getNum('[name="prev_under_18"]');
        const prevTotal = prev18 + prevUnder;
        setNum('[name="prev_total"]', prevTotal);

        const new18 = getNum('[name="new_above_18"]');
        const newUnder = getNum('[name="new_under_18"]');
        const newTotal = new18 + newUnder;
        setNum('[name="new_total"]', newTotal);

        const totalBeforeWithdrawn18 = prev18 + new18;
        const totalBeforeWithdrawnUnder = prevUnder + newUnder;
        const totalBeforeWithdrawnTotal = totalBeforeWithdrawn18 + totalBeforeWithdrawnUnder;

        setNum('[name="before_withdrawal_above_18"]', totalBeforeWithdrawn18);
        setNum('[name="before_withdrawal_under_18"]', totalBeforeWithdrawnUnder);
        setNum('[name="before_withdrawal_total"]', totalBeforeWithdrawnTotal);

        // Table 2: Withdrawals
        const withdrawalReasons = ['transfer', 'resignation', 'dismissal', 'death'];
        let w18Sum = 0;
        let wUnderSum = 0;

        withdrawalReasons.forEach(reason => {
            const w18 = getNum(`[name="withdrawn_${reason}_above_18"]`);
            const wUnder = getNum(`[name="withdrawn_${reason}_under_18"]`);
            const wTotal = w18 + wUnder;
            setNum(`[name="withdrawn_${reason}_total"]`, wTotal);
            w18Sum += w18;
            wUnderSum += wUnder;
        });

        const wTotalSum = w18Sum + wUnderSum;
        setNum('[name="withdrawn_total_above_18"]', w18Sum);
        setNum('[name="withdrawn_total_under_18"]', wUnderSum);
        setNum('[name="withdrawn_total_total"]', wTotalSum);

        // Table 3: Summary (copies from above and calculates final)
        setNum('[name="summary_before_withdrawal_above_18"]', totalBeforeWithdrawn18);
        setNum('[name="summary_before_withdrawal_under_18"]', totalBeforeWithdrawnUnder);
        setNum('[name="summary_before_withdrawal_total"]', totalBeforeWithdrawnTotal);

        setNum('[name="summary_withdrawn_above_18"]', w18Sum);
        setNum('[name="summary_withdrawn_under_18"]', wUnderSum);
        setNum('[name="summary_withdrawn_total"]', wTotalSum);

        const afterWithdrawal18 = totalBeforeWithdrawn18 - w18Sum;
        const afterWithdrawalUnder = totalBeforeWithdrawnUnder - wUnderSum;
        const afterWithdrawalTotal = afterWithdrawal18 + afterWithdrawalUnder;

        setNum('[name="after_withdrawal_above_18"]', afterWithdrawal18);
        setNum('[name="after_withdrawal_under_18"]', afterWithdrawalUnder);
        setNum('[name="after_withdrawal_total"]', afterWithdrawalTotal);
    }

    // Mark dues inputs as manually edited when user inputs data directly
    const allDuesInputs = document.querySelectorAll('input[name^="due_"]');
    allDuesInputs.forEach(input => {
        input.addEventListener('input', function(this: HTMLInputElement) {
            this.dataset.manual = 'true';
            // Also mark companion input (e.g. kobo if naira is edited, or vice-versa)
            const isNaira = this.name.endsWith('_naira');
            const prefix = this.name.substring(0, this.name.lastIndexOf('_'));
            const companionName = prefix + (isNaira ? '_kobo' : '_naira');
            const companion = document.querySelector(`[name="${companionName}"]`) as HTMLInputElement | null;
            if (companion) {
                companion.dataset.manual = 'true';
            }
        });
    });

    // Bind inputs to trigger calculations
    form.addEventListener('input', calculateForm);

    // Initial calculation on load
    calculateForm();
});

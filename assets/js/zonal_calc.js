"use strict";
/**
 * assets/ts/zonal_calc.ts — Live calculations for the Zonal Reports (Pages 1-4).
 */
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form');
    if (!form)
        return;
    function roundHalfUp(value, decimals = 2) {
        return Number(Math.round(value + ('e' + decimals)) + 'e-' + decimals);
    }
    function getVal(el) {
        if (!el)
            return 0;
        const v = el.value.replace('%', '').replace('—', '').trim();
        const val = parseFloat(v);
        return isNaN(val) ? 0 : val;
    }
    function setVal(el, val, isPct = false) {
        if (!el)
            return;
        if (isPct) {
            if (val === null || isNaN(val) || val === 0) {
                el.value = '—';
            }
            else {
                el.value = roundHalfUp(val, 2).toFixed(2) + '%';
            }
        }
        else {
            if (val === 0 || val === null || isNaN(val)) {
                el.value = '';
            }
            else {
                const rounded = roundHalfUp(val, 2);
                el.value = rounded % 1 === 0 ? rounded.toFixed(0) : rounded.toFixed(2);
            }
        }
    }
    function calculateZonal() {
        // ─── PAGE 1 CALCULATIONS ──────────────────────────────────────────
        const rows = document.querySelectorAll('tr[data-p1-row="true"]');
        let spTmSum = 0, spLmSum = 0, spAgoSum = 0;
        let finTmSum = 0, finLmSum = 0, finAgoSum = 0;
        let ftSum = 0, ptSum = 0, dcSum = 0, dcnSum = 0, eldSum = 0;
        rows.forEach(row => {
            const spTm = getVal(row.querySelector('.p1-sp-tm'));
            const spLm = getVal(row.querySelector('.p1-sp-lm'));
            const spAgo = getVal(row.querySelector('.p1-sp-ago'));
            const finTm = getVal(row.querySelector('.p1-fin-tm'));
            const finLm = getVal(row.querySelector('.p1-fin-lm'));
            const finAgo = getVal(row.querySelector('.p1-fin-ago'));
            const ft = getVal(row.querySelector('.p1-ft'));
            const pt = getVal(row.querySelector('.p1-pt'));
            const dc = getVal(row.querySelector('.p1-dc'));
            const dcn = getVal(row.querySelector('.p1-dcn'));
            const eld = getVal(row.querySelector('.p1-eld'));
            // Accumulate sums
            spTmSum += spTm;
            spLmSum += spLm;
            spAgoSum += spAgo;
            finTmSum += finTm;
            finLmSum += finLm;
            finAgoSum += finAgo;
            ftSum += ft;
            ptSum += pt;
            dcSum += dc;
            dcnSum += dcn;
            eldSum += eld;
            // Row-level %Diff
            const spDiff = spLm !== 0 ? ((spTm - spLm) / spLm) * 100 : null;
            const finDiff = finLm !== 0 ? ((finTm - finLm) / finLm) * 100 : null;
            setVal(row.querySelector('.p1-sp-diff'), spDiff, true);
            setVal(row.querySelector('.p1-fin-diff'), finDiff, true);
        });
        // Set Page 1 Totals
        const totalRow = document.querySelector('tr[data-p1-total="true"]');
        if (totalRow) {
            setVal(totalRow.querySelector('.p1-sp-tm-total'), spTmSum);
            setVal(totalRow.querySelector('.p1-sp-lm-total'), spLmSum);
            setVal(totalRow.querySelector('.p1-sp-ago-total'), spAgoSum);
            setVal(totalRow.querySelector('.p1-fin-tm-total'), finTmSum);
            setVal(totalRow.querySelector('.p1-fin-lm-total'), finLmSum);
            setVal(totalRow.querySelector('.p1-fin-ago-total'), finAgoSum);
            setVal(totalRow.querySelector('.p1-ft-total'), ftSum);
            setVal(totalRow.querySelector('.p1-pt-total'), ptSum);
            setVal(totalRow.querySelector('.p1-dc-total'), dcSum);
            setVal(totalRow.querySelector('.p1-dcn-total'), dcnSum);
            setVal(totalRow.querySelector('.p1-eld-total'), eldSum);
            const spTotalDiff = spLmSum !== 0 ? ((spTmSum - spLmSum) / spLmSum) * 100 : null;
            const finTotalDiff = finLmSum !== 0 ? ((finTmSum - finLmSum) / finLmSum) * 100 : null;
            setVal(totalRow.querySelector('.p1-sp-diff-total'), spTotalDiff, true);
            setVal(totalRow.querySelector('.p1-fin-diff-total'), finTotalDiff, true);
        }
        // ─── SECTION B SUMMARY OF SPIRITUAL REPORT ──────────────────────────
        for (let p = 1; p <= 12; p++) {
            const tmEl = document.querySelector(`[name="p1_sum_tm_${p}"]`);
            const lmEl = document.querySelector(`[name="p1_sum_lm_${p}"]`);
            const pctEl = document.querySelector(`[name="p1_sum_pct_${p}"]`);
            const tm = getVal(tmEl);
            const lm = getVal(lmEl);
            const diff = tm - lm;
            const pct = lm !== 0 ? (diff / lm) * 100 : null;
            setVal(pctEl, pct, true);
        }
        // ─── PAGE 2 CALCULATIONS ──────────────────────────────────────────
        for (let p = 1; p <= 12; p++) {
            const tmInputs = document.querySelectorAll(`input.p2-tm[name^="p2_tm_${p}_"]`);
            const lmInputs = document.querySelectorAll(`input.p2-lm[name^="p2_lm_${p}_"]`);
            let tmSum = 0;
            let lmSum = 0;
            tmInputs.forEach(el => tmSum += getVal(el));
            lmInputs.forEach(el => lmSum += getVal(el));
            setVal(document.querySelector(`[name="p2_total_tm_${p}"]`), tmSum);
            setVal(document.querySelector(`[name="p2_total_lm_${p}"]`), lmSum);
        }
        // ─── PAGE 3 CALCULATIONS ──────────────────────────────────────────
        for (let p = 1; p <= 12; p++) {
            const valInputs = document.querySelectorAll(`input.p3-val[name^="p3_val_${p}_"]`);
            let sum = 0;
            valInputs.forEach(el => sum += getVal(el));
            setVal(document.querySelector(`[name="p3_total_${p}"]`), sum);
        }
        // ─── PAGE 4 CALCULATIONS ──────────────────────────────────────────
        for (let p = 1; p <= 12; p++) {
            const tm = getVal(document.querySelector(`[name="p4_tm_${p}"]`));
            const lm = getVal(document.querySelector(`[name="p4_lm_${p}"]`));
            const diff = tm - lm;
            const pct = lm !== 0 ? (diff / lm) * 100 : null;
            setVal(document.querySelector(`[name="p4_diff_${p}"]`), diff);
            setVal(document.querySelector(`[name="p4_pct_${p}"]`), pct, true);
        }
    }
    form.addEventListener('input', calculateZonal);
    calculateZonal();
});

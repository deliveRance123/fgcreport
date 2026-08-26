/**
 * assets/ts/zonal_calc.ts — Live calculations for the Zonal Reports (Pages 1-4).
 */

document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form');
    if (!form) return;

    function roundHalfUp(value: number, decimals: number = 2): number {
        return Number(Math.round(value + ('e' + decimals) as any) + 'e-' + decimals);
    }

    function getVal(el: HTMLInputElement | null): number {
        if (!el) return 0;
        const v = el.value.replace('%', '').replace('—', '').trim();
        const val = parseFloat(v);
        return isNaN(val) ? 0 : val;
    }

    function setVal(el: HTMLInputElement | null, val: number | null, isPct: boolean = false): void {
        if (!el) return;
        if (isPct) {
            if (val === null || isNaN(val) || val === 0) {
                el.value = '—';
            } else {
                el.value = roundHalfUp(val, 2).toFixed(2) + '%';
            }
        } else {
            if (val === 0 || val === null || isNaN(val)) {
                el.value = '';
            } else {
                const rounded = roundHalfUp(val, 2);
                el.value = rounded % 1 === 0 ? rounded.toFixed(0) : rounded.toFixed(2);
            }
        }
    }

    function calculateZonal(): void {
        // ─── PAGE 1 CALCULATIONS ──────────────────────────────────────────
        const rows = document.querySelectorAll('tr[data-p1-row="true"]');
        let spTmSum = 0, spLmSum = 0, spAgoSum = 0;
        let finTmSum = 0, finLmSum = 0, finAgoSum = 0;
        let ftSum = 0, ptSum = 0, dcSum = 0, dcnSum = 0, eldSum = 0;

        rows.forEach(row => {
            const spTm = getVal(row.querySelector('.p1-sp-tm') as HTMLInputElement | null);
            const spLm = getVal(row.querySelector('.p1-sp-lm') as HTMLInputElement | null);
            const spAgo = getVal(row.querySelector('.p1-sp-ago') as HTMLInputElement | null);

            const finTm = getVal(row.querySelector('.p1-fin-tm') as HTMLInputElement | null);
            const finLm = getVal(row.querySelector('.p1-fin-lm') as HTMLInputElement | null);
            const finAgo = getVal(row.querySelector('.p1-fin-ago') as HTMLInputElement | null);

            const ft = getVal(row.querySelector('.p1-ft') as HTMLInputElement | null);
            const pt = getVal(row.querySelector('.p1-pt') as HTMLInputElement | null);
            const dc = getVal(row.querySelector('.p1-dc') as HTMLInputElement | null);
            const dcn = getVal(row.querySelector('.p1-dcn') as HTMLInputElement | null);
            const eld = getVal(row.querySelector('.p1-eld') as HTMLInputElement | null);

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

            setVal(row.querySelector('.p1-sp-diff') as HTMLInputElement | null, spDiff, true);
            setVal(row.querySelector('.p1-fin-diff') as HTMLInputElement | null, finDiff, true);
        });

        // Set Page 1 Totals
        const totalRow = document.querySelector('tr[data-p1-total="true"]');
        if (totalRow) {
            setVal(totalRow.querySelector('.p1-sp-tm-total') as HTMLInputElement | null, spTmSum);
            setVal(totalRow.querySelector('.p1-sp-lm-total') as HTMLInputElement | null, spLmSum);
            setVal(totalRow.querySelector('.p1-sp-ago-total') as HTMLInputElement | null, spAgoSum);

            setVal(totalRow.querySelector('.p1-fin-tm-total') as HTMLInputElement | null, finTmSum);
            setVal(totalRow.querySelector('.p1-fin-lm-total') as HTMLInputElement | null, finLmSum);
            setVal(totalRow.querySelector('.p1-fin-ago-total') as HTMLInputElement | null, finAgoSum);

            setVal(totalRow.querySelector('.p1-ft-total') as HTMLInputElement | null, ftSum);
            setVal(totalRow.querySelector('.p1-pt-total') as HTMLInputElement | null, ptSum);
            setVal(totalRow.querySelector('.p1-dc-total') as HTMLInputElement | null, dcSum);
            setVal(totalRow.querySelector('.p1-dcn-total') as HTMLInputElement | null, dcnSum);
            setVal(totalRow.querySelector('.p1-eld-total') as HTMLInputElement | null, eldSum);

            const spTotalDiff = spLmSum !== 0 ? ((spTmSum - spLmSum) / spLmSum) * 100 : null;
            const finTotalDiff = finLmSum !== 0 ? ((finTmSum - finLmSum) / finLmSum) * 100 : null;

            setVal(totalRow.querySelector('.p1-sp-diff-total') as HTMLInputElement | null, spTotalDiff, true);
            setVal(totalRow.querySelector('.p1-fin-diff-total') as HTMLInputElement | null, finTotalDiff, true);
        }

        // ─── SECTION B SUMMARY OF SPIRITUAL REPORT ──────────────────────────
        for (let p = 1; p <= 12; p++) {
            const tmEl = document.querySelector(`[name="p1_sum_tm_${p}"]`) as HTMLInputElement | null;
            const lmEl = document.querySelector(`[name="p1_sum_lm_${p}"]`) as HTMLInputElement | null;
            const pctEl = document.querySelector(`[name="p1_sum_pct_${p}"]`) as HTMLInputElement | null;
            
            const tm = getVal(tmEl);
            const lm = getVal(lmEl);
            
            const diff = tm - lm;
            const pct = lm !== 0 ? (diff / lm) * 100 : null;
            
            setVal(pctEl, pct, true);
        }

        // ─── PAGE 2 CALCULATIONS ──────────────────────────────────────────
        for (let p = 1; p <= 12; p++) {
            const tmInputs = document.querySelectorAll(`input.p2-tm[name^="p2_tm_${p}_"]`) as NodeListOf<HTMLInputElement>;
            const lmInputs = document.querySelectorAll(`input.p2-lm[name^="p2_lm_${p}_"]`) as NodeListOf<HTMLInputElement>;
            
            let tmSum = 0;
            let lmSum = 0;

            tmInputs.forEach(el => tmSum += getVal(el));
            lmInputs.forEach(el => lmSum += getVal(el));

            setVal(document.querySelector(`[name="p2_total_tm_${p}"]`) as HTMLInputElement | null, tmSum);
            setVal(document.querySelector(`[name="p2_total_lm_${p}"]`) as HTMLInputElement | null, lmSum);
        }

        // ─── PAGE 3 CALCULATIONS ──────────────────────────────────────────
        for (let p = 1; p <= 12; p++) {
            const valInputs = document.querySelectorAll(`input.p3-val[name^="p3_val_${p}_"]`) as NodeListOf<HTMLInputElement>;
            let sum = 0;
            valInputs.forEach(el => sum += getVal(el));
            setVal(document.querySelector(`[name="p3_total_${p}"]`) as HTMLInputElement | null, sum);
        }

        // ─── PAGE 4 CALCULATIONS ──────────────────────────────────────────
        for (let p = 1; p <= 12; p++) {
            const tm = getVal(document.querySelector(`[name="p4_tm_${p}"]`) as HTMLInputElement | null);
            const lm = getVal(document.querySelector(`[name="p4_lm_${p}"]`) as HTMLInputElement | null);
            
            const diff = tm - lm;
            const pct = lm !== 0 ? (diff / lm) * 100 : null;

            setVal(document.querySelector(`[name="p4_diff_${p}"]`) as HTMLInputElement | null, diff);
            setVal(document.querySelector(`[name="p4_pct_${p}"]`) as HTMLInputElement | null, pct, true);
        }
    }

    form.addEventListener('input', calculateZonal);
    calculateZonal();
});

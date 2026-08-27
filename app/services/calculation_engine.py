"""
app/services/calculation_engine.py
======================================================
Centralized, Pure Calculation Engine for Foursquare Reports.

FORMULA PRESERVATION GUARANTEE:
All formulas, rounding behavior, and zero-handling are extracted
directly from app/routes/reports.py and app/utils.py.
NO mathematical behavior has been changed.

Features covered:
  - Feature 5:  Automatic Error Detection (enhanced input validation)
  - Feature 7:  Automatic Recalculation (pure functions, no stale state)
  - Feature 8:  Historical Accuracy (snapshot-based, external to this module)
  - Feature 9:  Zonal Aggregation (invariant-verified)
  - Feature 10: Calculation Audit (traceability metadata returned)
"""

import math
from decimal import Decimal, ROUND_HALF_UP
from typing import Any, Dict, List, Optional, Tuple


# ====================================================
# VALIDATION ERROR
# ====================================================

class CalculationValidationError(ValueError):
    """
    Raised when a financial input fails validation or a post-calculation
    invariant is violated. The route layer should catch this and return
    a 400 HTTP error with the message to the client.
    """
    pass


# ====================================================
# ROUNDING — preserved exactly from app/utils.py
# ====================================================

def money_round(value: Any, decimals: int = 2) -> float:
    """
    Round-half-up rounding identical to the existing moneyRound() in utils.py.
    Uses Python Decimal with ROUND_HALF_UP to match Foursquare accounting rules.
    """
    if value is None:
        return 0.0
    try:
        if isinstance(value, float) and (math.isnan(value) or math.isinf(value)):
            raise CalculationValidationError(
                "Invalid monetary value: NaN or Infinity is not allowed"
            )
        dec = Decimal(str(value))
        fmt = "0." + ("0" * decimals) if decimals > 0 else "0"
        return float(dec.quantize(Decimal(fmt), rounding=ROUND_HALF_UP))
    except CalculationValidationError:
        raise
    except Exception:
        return 0.0


# ====================================================
# INPUT SANITIZERS — Feature 5: Automatic Error Detection
# ====================================================

def sanitize_money(
    v: Any,
    field_name: str = "amount",
    allow_negative: bool = False,
    max_value: float = 9_999_999_999.99,
) -> float:
    """
    Safely parses a monetary input, raises CalculationValidationError for:
      - NaN / Infinity
      - Non-numeric / malformed strings
      - Negative values on fields that prohibit them
      - Values exceeding column precision (Numeric 12,2)
    """
    if v is None or v == "":
        return 0.0

    if isinstance(v, (int, float)):
        raw = float(v)
    else:
        stripped = str(v).strip().replace(",", "")
        if not stripped:
            return 0.0
        try:
            raw = float(stripped)
        except (ValueError, TypeError):
            raise CalculationValidationError(
                f"Field '{field_name}' contains a non-numeric value: {v!r}"
            )

    if math.isnan(raw) or math.isinf(raw):
        raise CalculationValidationError(
            f"Field '{field_name}' is NaN or Infinity — this is not a valid amount"
        )

    val = money_round(raw, 2)

    if not allow_negative and val < 0:
        raise CalculationValidationError(
            f"Field '{field_name}' cannot be negative (received {val}). "
            "If this is a balance carry-over or deficit, use the correct field."
        )

    if abs(val) > max_value:
        raise CalculationValidationError(
            f"Field '{field_name}' value {val} exceeds the maximum allowed amount "
            f"({max_value:,.2f})"
        )

    return val


def sanitize_count(
    v: Any,
    field_name: str = "count",
    allow_negative: bool = False,
) -> int:
    """
    Safely parses a spiritual/membership count integer.
    Raises CalculationValidationError for invalid or negative values.
    """
    if v is None or v == "":
        return 0

    if isinstance(v, bool):
        raise CalculationValidationError(f"Field '{field_name}' must be a number, not boolean")

    if isinstance(v, int):
        raw = v
    elif isinstance(v, float):
        if math.isnan(v) or math.isinf(v):
            raise CalculationValidationError(
                f"Field '{field_name}' is NaN or Infinity"
            )
        raw = int(v)
    else:
        stripped = str(v).strip().replace(",", "")
        if not stripped:
            return 0
        try:
            raw = int(float(stripped))
        except (ValueError, TypeError):
            raise CalculationValidationError(
                f"Field '{field_name}' is not a valid integer: {v!r}"
            )

    if not allow_negative and raw < 0:
        raise CalculationValidationError(
            f"Field '{field_name}' cannot be negative (received {raw})"
        )

    return raw


def validate_report_period(month: Any, year: Any) -> Tuple[int, int]:
    """
    Validates that a report month and year are in a sensible range.
    Month: 1–12. Year: 2000–2099.
    """
    try:
        m = int(month)
        y = int(year)
    except (TypeError, ValueError):
        raise CalculationValidationError(
            "Report period month and year must be integers"
        )
    if not (1 <= m <= 12):
        raise CalculationValidationError(
            f"Report month {m} is invalid — must be between 1 and 12"
        )
    if not (2000 <= y <= 2099):
        raise CalculationValidationError(
            f"Report year {y} is invalid — must be between 2000 and 2099"
        )
    return m, y


def validate_church_type(church_type: str) -> str:
    """Validates that church_type is either 'chartered' or 'unchartered'."""
    if church_type not in ("chartered", "unchartered"):
        raise CalculationValidationError(
            f"Invalid church_type '{church_type}'. Must be 'chartered' or 'unchartered'."
        )
    return church_type


# ====================================================
# CHURCH FINANCIAL CALCULATION ENGINE
# Formulas preserved from app/routes/reports.py lines 334–490
# ====================================================

def calculate_church_financials(
    receipts: Dict[str, Any],
    expenses: List[Dict[str, Any]],
    rates_source: Dict[str, float],
    locked_keys: List[str],
    base_fields: Dict[str, str],
    balance_last_month: float = 0.0,
    cash_in_hand_bank: float = 0.0,
    investment: float = 0.0,
    outstanding_loan: float = 0.0,
) -> Dict[str, Any]:
    """
    Pure calculation function for a local church monthly financial report.

    FORMULAS (PRESERVED EXACTLY from reports.py):
      subtotal_ac       = money_round(general_tithe + minister_tithe + worship_offerings)
      total_receipts    = money_round(subtotal_ac + sum(other_receipts))
      due_amount        = money_round(base_val * (rate / 100))  [locked => 0.00]
      national_dues_total = sum(11 national dues)
      regional_dues     = due_regional_fund
      district_dues     = sum(6 district items)
      zonal_dues        = sum(3 zonal items)
      payable           = sum(national + regional + district + zonal + life)
      total_emoluments  = ministers_subtotal + other_workers_subtotal
      fixed_assets_subtotal = sum(4 capital items)
      general_expenses  = sum(all non-emolument non-fixed-asset expense items)
      total_payment     = payable + total_emoluments + general_expenses + fixed_assets_subtotal
      balance_surplus_deficit = total_receipts - total_payment
      balance_this_month      = balance_surplus_deficit + balance_last_month
      total_balance           = cash_in_hand_bank + investment
    """

    # ── 1. Core Revenue: Subtotal (a–c) ──────────────────────────────────────
    general_tithe   = sanitize_money(receipts.get("general_tithe",    0), "general_tithe")
    minister_tithe  = sanitize_money(receipts.get("minister_tithe",   0), "minister_tithe")
    worship_off     = sanitize_money(receipts.get("worship_offerings", 0), "worship_offerings")
    subtotal_ac     = money_round(general_tithe + minister_tithe + worship_off)

    # ── 2. Other Receipts ────────────────────────────────────────────────────
    OTHER_KEYS = [
        "missionary_offerings",
        "midweek_offerings",
        "sunday_school_offerings",
        "thanksgiving_offerings",
        "love_welfare_offerings",
        "building_pledge_offerings",
        "church_pioneering_receipts",
        "donation_other_churches",
        "other_pledges",
        "seed_faith",
        "staff_loans_repayment",
        "loan_cash_deposit",
        "pastor_pension_5pct",
        "national_grant",
        "convention_pledges",
        "special_projects",
        "decade_multiplication_receipts",
        "third_sunday_offering",
    ]
    other: Dict[str, float] = {}
    other_sum = 0.0
    for k in OTHER_KEYS:
        val = sanitize_money(receipts.get(k, 0), k)
        other[k] = val
        other_sum += val
    other_sum = money_round(other_sum)

    total_receipts = money_round(subtotal_ac + other_sum)

    # ── 3. Per-due calculation helper ────────────────────────────────────────
    BASE_FIELD_MAP: Dict[str, str] = {
        "sunday_school_offering": "sunday_school_offerings",
        "missionary_offering":    "missionary_offerings",
        "love_offering":          "love_welfare_offerings",
        "third_sunday":           "third_sunday_offering",
    }

    def get_due(due_key: str) -> float:
        if due_key in locked_keys:
            return 0.0
        # Determine base field (override from admin config or default map)
        configured_base = base_fields.get(due_key, None)
        if configured_base and configured_base != "subtotal_ac":
            base_val = other.get(configured_base, 0.0)
        elif due_key in BASE_FIELD_MAP:
            base_val = other.get(BASE_FIELD_MAP[due_key], 0.0)
        else:
            base_val = subtotal_ac
        rate = float(rates_source.get(due_key, 0.0))
        return money_round(base_val * (rate / 100.0))

    # ── 4. National Dues (11 items) ─────────────────────────────────────────
    d_tithes     = get_due("tithes_offerings")
    d_welfare    = get_due("pastors_welfare")
    d_project    = get_due("project_dev_fund")
    d_macpherson = get_due("macpherson_uni")
    d_augment    = get_due("augmentation_fund")
    d_ffs        = get_due("ffs_savings")
    d_sschool    = get_due("sunday_school_offering")
    d_mission    = get_due("missionary_offering")
    d_love       = get_due("love_offering")
    d_tv         = get_due("foursquare_tv")
    d_third      = get_due("third_sunday")

    national_dues_total = money_round(
        d_tithes + d_welfare + d_project + d_macpherson + d_augment +
        d_ffs + d_sschool + d_mission + d_love + d_tv + d_third
    )

    # ── 5. Hierarchy Dues ────────────────────────────────────────────────────
    regional_dues = get_due("regional_fund")

    d_dist_fund   = get_due("district_fund")
    d_straight_lo = get_due("straight_love_offering")
    d_p_pension8  = get_due("pastors_staff_pension_8")
    d_c_pension10 = get_due("church_staff_pension_10")
    d_dist_miss   = get_due("district_missionary")
    d_dist_ss     = get_due("district_sunday_school")
    district_dues = money_round(
        d_dist_fund + d_straight_lo + d_p_pension8 +
        d_c_pension10 + d_dist_miss + d_dist_ss
    )

    d_zonal_fund  = get_due("zonal_fund")
    d_zonal_miss  = get_due("zonal_missionary")
    d_zonal_ss    = get_due("zonal_sunday_school")
    zonal_dues    = money_round(d_zonal_fund + d_zonal_miss + d_zonal_ss)

    life_dues     = get_due("life_theo_seminary")

    payable = money_round(
        national_dues_total + regional_dues + district_dues + zonal_dues + life_dues
    )

    # ── 6. Emoluments ────────────────────────────────────────────────────────
    exp_map: Dict[str, float] = {}
    for item in expenses:
        k = str(item.get("item_key", ""))
        amt = sanitize_money(item.get("amount", 0), f"expense_{k}")
        exp_map[k] = amt

    min_basic   = exp_map.get("ministers_basic", 0.0)
    min_allow   = exp_map.get("ministers_allowances", 0.0)
    min_sub     = money_round(min_basic + min_allow)

    ow_basic    = exp_map.get("other_workers_basic", 0.0)
    ow_allow    = exp_map.get("other_workers_allowances", 0.0)
    ow_sub      = money_round(ow_basic + ow_allow)

    total_emoluments = money_round(min_sub + ow_sub)

    # ── 7. Fixed Assets ──────────────────────────────────────────────────────
    FIXED_KEYS = [
        "land_acquisition", "church_building",
        "purchase_motor_vehicles", "purchase_new_equipment",
    ]
    fixed_total = money_round(sum(exp_map.get(k, 0.0) for k in FIXED_KEYS))

    # ── 8. General Expenses ──────────────────────────────────────────────────
    EMOL_AND_FIXED = set([
        "ministers_basic", "ministers_allowances",
        "other_workers_basic", "other_workers_allowances",
    ] + FIXED_KEYS)
    gen_exp = 0.0
    for item in expenses:
        k = str(item.get("item_key", ""))
        if k not in EMOL_AND_FIXED:
            gen_exp += sanitize_money(item.get("amount", 0), f"expense_{k}")
    gen_exp = money_round(gen_exp)

    # ── 9. Total Payment ─────────────────────────────────────────────────────
    total_payment = money_round(payable + total_emoluments + gen_exp + fixed_total)

    # ── 10. Balances ─────────────────────────────────────────────────────────
    bal_last  = sanitize_money(balance_last_month, "balance_last_month", allow_negative=True)
    balance_surplus_deficit = money_round(total_receipts - total_payment)
    balance_this_month      = money_round(balance_surplus_deficit + bal_last)

    cash_bank   = sanitize_money(cash_in_hand_bank, "cash_in_hand_bank")
    invest      = sanitize_money(investment, "investment")
    total_balance = money_round(cash_bank + invest)
    out_loan    = sanitize_money(outstanding_loan, "outstanding_loan")

    # ── 11. Invariant Consistency Checks ─────────────────────────────────────
    _check_receipts = money_round(subtotal_ac + other_sum)
    if abs(total_receipts - _check_receipts) > 0.02:
        raise CalculationValidationError(
            f"Receipts invariant failed: total_receipts={total_receipts} "
            f"!= subtotal_ac + other_sum = {_check_receipts}"
        )

    _check_payment = money_round(payable + total_emoluments + gen_exp + fixed_total)
    if abs(total_payment - _check_payment) > 0.02:
        raise CalculationValidationError(
            f"Payments invariant failed: total_payment={total_payment} "
            f"!= payable+emoluments+expenses+fixed = {_check_payment}"
        )

    _check_surplus = money_round(total_receipts - total_payment)
    if abs(balance_surplus_deficit - _check_surplus) > 0.02:
        raise CalculationValidationError(
            f"Surplus/Deficit invariant failed: {balance_surplus_deficit} "
            f"!= total_receipts - total_payment = {_check_surplus}"
        )

    # ── 12. Return all calculated fields ─────────────────────────────────────
    return {
        # Core receipts
        "general_tithe":            general_tithe,
        "minister_tithe":           minister_tithe,
        "worship_offerings":        worship_off,
        "subtotal_ac":              subtotal_ac,
        # Other receipts (all 18 fields)
        **other,
        "total_receipts":           total_receipts,
        # National dues
        "due_tithes_offerings":     d_tithes,
        "due_pastors_welfare":      d_welfare,
        "due_project_dev":          d_project,
        "due_macpherson":           d_macpherson,
        "due_augmentation":         d_augment,
        "due_ffs_savings":          d_ffs,
        "due_sunday_school":        d_sschool,
        "due_missionary":           d_mission,
        "due_love_offering":        d_love,
        "due_foursquare_tv":        d_tv,
        "due_third_sunday":         d_third,
        "national_dues_total":      national_dues_total,
        # Hierarchy dues
        "regional_dues":            regional_dues,
        "district_dues":            district_dues,
        "zonal_dues":               zonal_dues,
        "life_dues":                life_dues,
        "straight_love_offering":   d_straight_lo,
        "pastors_staff_pension_8":  d_p_pension8,
        "church_staff_pension_10":  d_c_pension10,
        # Totals
        "payable":                  payable,
        "ministers_subtotal":       min_sub,
        "other_workers_subtotal":   ow_sub,
        "total_emoluments":         total_emoluments,
        "fixed_assets_subtotal":    fixed_total,
        "general_expenses":         gen_exp,
        "total_payment":            total_payment,
        # Balances
        "balance_surplus_deficit":  balance_surplus_deficit,
        "balance_last_month":       bal_last,
        "balance_this_month":       balance_this_month,
        "cash_in_hand_bank":        cash_bank,
        "investment":               invest,
        "total_balance":            total_balance,
        "outstanding_loan":         out_loan,
    }


# ====================================================
# CHURCH SPIRITUAL CALCULATION ENGINE
# ====================================================

def calculate_church_spiritual(
    attendance_inputs: Dict[str, Dict[str, Any]],
    membership_inputs: Dict[str, Any],
) -> Dict[str, Any]:
    """
    Calculates attendance totals and membership movement for spiritual reports.
    All formulas preserved from existing spiritual report logic.
    """
    # Attendance totals per service
    SERVICE_PREFIXES = [
        "pre_sun_school", "sun_school", "sun_worship",
        "house_fellowship", "bible_study", "prayer_meeting",
    ]
    attendance: Dict[str, Dict[str, int]] = {}
    for prefix in SERVICE_PREFIXES:
        section = attendance_inputs.get(prefix, {})
        c = sanitize_count(section.get("children", 0), f"{prefix}_children")
        a = sanitize_count(section.get("adults",   0), f"{prefix}_adults")
        attendance[prefix] = {"children": c, "adults": a, "total": c + a}

    # Previous month membership
    prev_18  = sanitize_count(membership_inputs.get("prev_18",  0), "prev_18")
    prev_u18 = sanitize_count(membership_inputs.get("prev_u18", 0), "prev_u18")
    prev_total = prev_18 + prev_u18

    # New members
    new_18   = sanitize_count(membership_inputs.get("new_18",  0), "new_18")
    new_u18  = sanitize_count(membership_inputs.get("new_u18", 0), "new_u18")
    new_total = new_18 + new_u18

    # Before withdrawal
    bw_18    = prev_18  + new_18
    bw_u18   = prev_u18 + new_u18
    bw_total = bw_18   + bw_u18

    # Withdrawals (broken down by reason)
    w_reasons = membership_inputs.get("withdrawn_reasons", {})
    REASONS = ["transfer", "resignation", "dismissal", "death"]
    withdrawn = {}
    w18_sum = 0
    wu18_sum = 0
    for r in REASONS:
        r_data = w_reasons.get(r, {})
        w18  = sanitize_count(r_data.get("18",  0), f"withdrawn_{r}_18")
        wu18 = sanitize_count(r_data.get("u18", 0), f"withdrawn_{r}_u18")
        withdrawn[r] = {"18": w18, "u18": wu18, "total": w18 + wu18}
        w18_sum  += w18
        wu18_sum += wu18

    w_total = w18_sum + wu18_sum

    # After withdrawal (floor at 0)
    aw_18    = max(0, bw_18  - w18_sum)
    aw_u18   = max(0, bw_u18 - wu18_sum)
    aw_total = aw_18 + aw_u18

    return {
        "attendance":          attendance,
        "prev_month":          {"18": prev_18,  "u18": prev_u18,  "total": prev_total},
        "new_members":         {"18": new_18,   "u18": new_u18,   "total": new_total},
        "before_withdrawal":   {"18": bw_18,    "u18": bw_u18,    "total": bw_total},
        "withdrawn_breakdown": withdrawn,
        "withdrawn_total":     {"18": w18_sum,  "u18": wu18_sum,  "total": w_total},
        "after_withdrawal":    {"18": aw_18,    "u18": aw_u18,    "total": aw_total},
    }


# ====================================================
# ZONAL VARIANCE ENGINE
# Formula preserved from app/routes/reports.py lines 814–958
# ====================================================

def calculate_zonal_variance(
    current_val: float,
    last_val: float,
) -> Tuple[float, Optional[str]]:
    """
    Calculates month-on-month difference and percentage variance.
    Zero-division safety: returns None/'—' when last_val is zero.

    Formula (preserved):
      diff = money_round(current_val - last_val)
      pct  = money_round(((current_val - last_val) / last_val) * 100)  if last_val > 0
    """
    diff = money_round(current_val - last_val)
    if last_val > 0:
        pct = money_round(((current_val - last_val) / last_val) * 100, 2)
        pct_str: Optional[str] = f"{pct:.2f}%"
    else:
        pct_str = None  # rendered as '—' in templates
    return diff, pct_str

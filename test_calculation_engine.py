"""
test_calculation_engine.py
============================
Baseline & regression tests for the Foursquare Reports Calculation Engine.

Tests: Feature 5 (Automatic Error Detection), Feature 7 (Automatic Recalculation),
       Feature 8 (Historical Safety), Feature 9 (Zonal Aggregation),
       Feature 10 (Audit Trail).

BEFORE/AFTER comparison: These tests establish the baseline expected values.
After any architecture change, these values MUST remain identical.
"""

import pytest
from app.services.calculation_engine import (
    money_round,
    sanitize_money,
    sanitize_count,
    validate_report_period,
    validate_church_type,
    calculate_church_financials,
    calculate_church_spiritual,
    calculate_zonal_variance,
    CalculationValidationError,
)


# ──────────────────────────────────────────────────────────────────────────────
# CHARTERED CHURCH TEST RATES (mirrors typical production DuePercentageSettings)
# ──────────────────────────────────────────────────────────────────────────────
CHARTERED_RATES = {
    "tithes_offerings":       10.0,
    "pastors_welfare":         2.5,
    "project_dev_fund":        2.5,
    "macpherson_uni":          2.5,
    "augmentation_fund":       2.5,
    "ffs_savings":             2.5,
    "sunday_school_offering": 20.0,
    "missionary_offering":    20.0,
    "love_offering":          50.0,
    "foursquare_tv":           2.5,
    "third_sunday":           30.0,
    "regional_fund":           5.0,
    "district_fund":           5.0,
    "straight_love_offering": 25.0,
    "pastors_staff_pension_8": 8.0,
    "church_staff_pension_10":10.0,
    "district_missionary":    20.0,
    "district_sunday_school": 20.0,
    "zonal_fund":              5.0,
    "zonal_missionary":       20.0,
    "zonal_sunday_school":    20.0,
    "life_theo_seminary":      2.5,
}

CHARTERED_BASE_FIELDS = {
    "sunday_school_offering": "sunday_school_offerings",
    "missionary_offering":    "missionary_offerings",
    "love_offering":          "love_welfare_offerings",
    "third_sunday":           "third_sunday_offering",
}

UNCHARTERED_LOCKED_KEYS = [
    "tithes_offerings", "project_dev_fund", "macpherson_uni",
    "augmentation_fund", "ffs_savings", "foursquare_tv",
    "regional_fund", "district_fund", "straight_love_offering",
    "pastors_staff_pension_8", "church_staff_pension_10",
    "district_missionary", "district_sunday_school",
    "zonal_fund", "zonal_missionary", "zonal_sunday_school",
    "life_theo_seminary",
]

SAMPLE_RECEIPTS = {
    "general_tithe":                 100_000.0,
    "minister_tithe":                 20_000.0,
    "worship_offerings":              30_000.0,
    "missionary_offerings":            5_000.0,
    "midweek_offerings":               3_000.0,
    "sunday_school_offerings":         2_000.0,
    "thanksgiving_offerings":          4_000.0,
    "love_welfare_offerings":          1_000.0,
    "building_pledge_offerings":       0.0,
    "church_pioneering_receipts":      0.0,
    "donation_other_churches":         0.0,
    "other_pledges":                   0.0,
    "seed_faith":                      0.0,
    "staff_loans_repayment":           0.0,
    "loan_cash_deposit":               0.0,
    "pastor_pension_5pct":             0.0,
    "national_grant":                  0.0,
    "convention_pledges":              0.0,
    "special_projects":                0.0,
    "decade_multiplication_receipts":  0.0,
    "third_sunday_offering":           2_000.0,
}

SAMPLE_EXPENSES = [
    {"item_key": "ministers_basic",       "amount": 50_000.0},
    {"item_key": "ministers_allowances",  "amount": 10_000.0},
    {"item_key": "other_workers_basic",   "amount": 20_000.0},
    {"item_key": "other_workers_allowances", "amount": 5_000.0},
    {"item_key": "land_acquisition",      "amount": 0.0},
    {"item_key": "church_building",       "amount": 0.0},
    {"item_key": "purchase_motor_vehicles","amount": 0.0},
    {"item_key": "purchase_new_equipment","amount": 0.0},
    {"item_key": "stationery",            "amount": 2_000.0},
    {"item_key": "electricity",           "amount": 3_000.0},
]


# ──────────────────────────────────────────────────────────────────────────────
# 1. money_round tests
# ──────────────────────────────────────────────────────────────────────────────

def test_money_round_basic():
    assert money_round(100.005) == 100.01   # half-up: 0.005 → round up
    assert money_round(100.004) == 100.00
    assert money_round(0) == 0.0
    assert money_round(None) == 0.0

def test_money_round_rejects_nan():
    with pytest.raises(CalculationValidationError):
        money_round(float("nan"))

def test_money_round_rejects_inf():
    with pytest.raises(CalculationValidationError):
        money_round(float("inf"))


# ──────────────────────────────────────────────────────────────────────────────
# 2. sanitize_money — Feature 5 validation
# ──────────────────────────────────────────────────────────────────────────────

def test_sanitize_money_normal():
    assert sanitize_money(50000, "field") == 50000.0

def test_sanitize_money_string_number():
    assert sanitize_money("50000", "field") == 50000.0

def test_sanitize_money_comma_string():
    assert sanitize_money("50,000.50", "field") == 50000.50

def test_sanitize_money_empty():
    assert sanitize_money("", "field") == 0.0
    assert sanitize_money(None, "field") == 0.0

def test_sanitize_money_nan_string():
    with pytest.raises(CalculationValidationError):
        sanitize_money("abc", "general_tithe")

def test_sanitize_money_negative_rejected():
    with pytest.raises(CalculationValidationError):
        sanitize_money(-100.0, "general_tithe", allow_negative=False)

def test_sanitize_money_negative_allowed():
    assert sanitize_money(-5000.0, "balance_last_month", allow_negative=True) == -5000.0

def test_sanitize_money_nan():
    with pytest.raises(CalculationValidationError):
        sanitize_money(float("nan"), "field")

def test_sanitize_money_inf():
    with pytest.raises(CalculationValidationError):
        sanitize_money(float("inf"), "field")

def test_sanitize_money_over_max():
    with pytest.raises(CalculationValidationError):
        sanitize_money(10_000_000_000.0, "field")


# ──────────────────────────────────────────────────────────────────────────────
# 3. validate_report_period
# ──────────────────────────────────────────────────────────────────────────────

def test_validate_report_period_valid():
    assert validate_report_period(1, 2025) == (1, 2025)
    assert validate_report_period(12, 2099) == (12, 2099)

def test_validate_report_period_bad_month():
    with pytest.raises(CalculationValidationError):
        validate_report_period(0, 2025)
    with pytest.raises(CalculationValidationError):
        validate_report_period(13, 2025)

def test_validate_report_period_bad_year():
    with pytest.raises(CalculationValidationError):
        validate_report_period(5, 1999)
    with pytest.raises(CalculationValidationError):
        validate_report_period(5, 2100)

def test_validate_report_period_non_int():
    with pytest.raises(CalculationValidationError):
        validate_report_period("abc", 2025)


# ──────────────────────────────────────────────────────────────────────────────
# 4. validate_church_type
# ──────────────────────────────────────────────────────────────────────────────

def test_validate_church_type_valid():
    assert validate_church_type("chartered") == "chartered"
    assert validate_church_type("unchartered") == "unchartered"

def test_validate_church_type_invalid():
    with pytest.raises(CalculationValidationError):
        validate_church_type("CHARTERED")
    with pytest.raises(CalculationValidationError):
        validate_church_type("")
    with pytest.raises(CalculationValidationError):
        validate_church_type("branch")


# ──────────────────────────────────────────────────────────────────────────────
# 5. CHARTERED CHURCH — Baseline Calculation Test
# ──────────────────────────────────────────────────────────────────────────────

def test_chartered_subtotal_ac():
    """subtotal_ac = general_tithe + minister_tithe + worship_offerings"""
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["subtotal_ac"] == money_round(100_000 + 20_000 + 30_000)   # 150,000.00

def test_chartered_total_receipts():
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    # subtotal_ac=150,000 + other=5000+3000+2000+4000+1000+2000=17,000 => 167,000
    assert r["total_receipts"] == 167_000.0

def test_chartered_national_tithes_due():
    """10% of subtotal_ac (150,000) = 15,000"""
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["due_tithes_offerings"] == money_round(150_000 * 0.10)   # 15,000.00

def test_chartered_sunday_school_due():
    """20% of sunday_school_offerings (2,000) = 400"""
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["due_sunday_school"] == money_round(2_000 * 0.20)   # 400.00

def test_chartered_love_offering_due():
    """50% of love_welfare_offerings (1,000) = 500"""
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["due_love_offering"] == money_round(1_000 * 0.50)   # 500.00

def test_chartered_third_sunday_due():
    """30% of third_sunday_offering (2,000) = 600"""
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["due_third_sunday"] == money_round(2_000 * 0.30)   # 600.00

def test_chartered_emoluments():
    """ministers_subtotal = 50000+10000=60000; other_workers=20000+5000=25000; total=85000"""
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["ministers_subtotal"] == 60_000.0
    assert r["other_workers_subtotal"] == 25_000.0
    assert r["total_emoluments"] == 85_000.0

def test_chartered_general_expenses():
    """stationery(2000) + electricity(3000) = 5000"""
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["general_expenses"] == 5_000.0

def test_chartered_balance_with_deficit_carry():
    """balance_last_month allowed negative"""
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
        balance_last_month=-10_000.0,
    )
    # balance_this_month = balance_surplus_deficit + (-10000)
    assert r["balance_last_month"] == -10_000.0
    assert abs(r["balance_this_month"] - (r["balance_surplus_deficit"] - 10_000.0)) < 0.02


# ──────────────────────────────────────────────────────────────────────────────
# 6. UNCHARTERED CHURCH — Locked Dues = 0
# ──────────────────────────────────────────────────────────────────────────────

def test_unchartered_locked_dues_zero():
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=UNCHARTERED_LOCKED_KEYS,
        base_fields=CHARTERED_BASE_FIELDS,
    )
    # All locked keys must produce 0.00 regardless of rate
    assert r["due_tithes_offerings"] == 0.0
    assert r["due_project_dev"] == 0.0
    assert r["due_augmentation"] == 0.0
    assert r["district_dues"] == 0.0
    assert r["zonal_dues"] == 0.0
    assert r["life_dues"] == 0.0

def test_unchartered_unlocked_welfare_still_calculates():
    """pastors_welfare is NOT locked in unchartered → should still calculate"""
    unlocked_partial = [k for k in UNCHARTERED_LOCKED_KEYS if k != "pastors_welfare"]
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=unlocked_partial,
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["due_pastors_welfare"] == money_round(150_000 * 0.025)


# ──────────────────────────────────────────────────────────────────────────────
# 7. Zero Values — no NaN or division error
# ──────────────────────────────────────────────────────────────────────────────

def test_all_zero_receipts():
    zero_receipts = {k: 0.0 for k in SAMPLE_RECEIPTS}
    r = calculate_church_financials(
        receipts=zero_receipts,
        expenses=[],
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["subtotal_ac"] == 0.0
    assert r["total_receipts"] == 0.0
    assert r["national_dues_total"] == 0.0
    assert r["payable"] == 0.0
    assert r["total_payment"] == 0.0
    assert r["balance_surplus_deficit"] == 0.0


# ──────────────────────────────────────────────────────────────────────────────
# 8. Empty Optional Fields
# ──────────────────────────────────────────────────────────────────────────────

def test_missing_optional_fields_default_to_zero():
    minimal_receipts = {
        "general_tithe": 50_000,
        "minister_tithe": 0,
        "worship_offerings": 0,
    }
    r = calculate_church_financials(
        receipts=minimal_receipts,
        expenses=[],
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["subtotal_ac"] == 50_000.0
    assert r["missionary_offerings"] == 0.0   # defaults correctly
    assert r["total_receipts"] == 50_000.0


# ──────────────────────────────────────────────────────────────────────────────
# 9. Decimal Precision — ROUND HALF UP
# ──────────────────────────────────────────────────────────────────────────────

def test_round_half_up_behavior():
    """100,000 * 10.5% = 10,500.00 exactly"""
    receipts = {**{k: 0.0 for k in SAMPLE_RECEIPTS}, "general_tithe": 100_000.0}
    rates = {**CHARTERED_RATES, "tithes_offerings": 10.5}
    r = calculate_church_financials(
        receipts=receipts,
        expenses=[],
        rates_source=rates,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["due_tithes_offerings"] == money_round(100_000 * 0.105)

def test_decimal_kobo_value():
    """Naira 99,999 + 50 kobo = 99,999.50"""
    val = money_round(99_999 + 50 / 100)
    assert val == 99_999.50


# ──────────────────────────────────────────────────────────────────────────────
# 10. Large Values — within Numeric(12,2) column
# ──────────────────────────────────────────────────────────────────────────────

def test_large_value_calculation():
    large_receipts = {**{k: 0.0 for k in SAMPLE_RECEIPTS}, "general_tithe": 9_000_000.0}
    r = calculate_church_financials(
        receipts=large_receipts,
        expenses=[],
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
    )
    assert r["total_receipts"] == 9_000_000.0
    assert r["due_tithes_offerings"] == money_round(9_000_000 * 0.10)

def test_value_exceeds_column_precision():
    with pytest.raises(CalculationValidationError):
        sanitize_money(10_000_000_000.01, "general_tithe")


# ──────────────────────────────────────────────────────────────────────────────
# 11. Recalculation — Changed Root Input (Feature 7)
# ──────────────────────────────────────────────────────────────────────────────

def test_recalculation_when_tithe_changes():
    """Changing general_tithe from 100,000 to 150,000 must cascade to all dependents."""
    receipts_before = {**SAMPLE_RECEIPTS, "general_tithe": 100_000.0}
    receipts_after  = {**SAMPLE_RECEIPTS, "general_tithe": 150_000.0}

    r_before = calculate_church_financials(
        receipts=receipts_before, expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES, locked_keys=[], base_fields=CHARTERED_BASE_FIELDS
    )
    r_after = calculate_church_financials(
        receipts=receipts_after, expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES, locked_keys=[], base_fields=CHARTERED_BASE_FIELDS
    )

    # subtotal_ac must increase by exactly 50,000
    assert r_after["subtotal_ac"] == r_before["subtotal_ac"] + 50_000.0

    # total_receipts must also increase by 50,000
    assert r_after["total_receipts"] == r_before["total_receipts"] + 50_000.0

    # Tithes due (10%) must increase by 5,000
    assert abs(r_after["due_tithes_offerings"] - r_before["due_tithes_offerings"] - 5_000.0) < 0.02

    # balance_surplus_deficit changes because higher tithe → higher dues → more total_payment
    # surplus_deficit may decrease (become more negative) when dues outweigh the receipt gain
    assert r_after["total_receipts"] > r_before["total_receipts"]
    assert r_after["total_payment"] > r_before["total_payment"]
    # The change in total_receipts should equal 50,000
    assert abs((r_after["total_receipts"] - r_before["total_receipts"]) - 50_000.0) < 0.02



# ──────────────────────────────────────────────────────────────────────────────
# 12. Zonal Variance — Feature 9
# ──────────────────────────────────────────────────────────────────────────────

def test_zonal_variance_normal():
    diff, pct = calculate_zonal_variance(110.0, 100.0)
    assert diff == 10.0
    assert pct == "10.00%"

def test_zonal_variance_decline():
    diff, pct = calculate_zonal_variance(90.0, 100.0)
    assert diff == -10.0
    assert pct == "-10.00%"

def test_zonal_variance_zero_last():
    """Zero-division protection: last_val=0 returns None for percentage"""
    diff, pct = calculate_zonal_variance(50.0, 0.0)
    assert diff == 50.0
    assert pct is None

def test_zonal_variance_both_zero():
    diff, pct = calculate_zonal_variance(0.0, 0.0)
    assert diff == 0.0
    assert pct is None


# ──────────────────────────────────────────────────────────────────────────────
# 13. Calculation Invariants — cannot produce inconsistent totals
# ──────────────────────────────────────────────────────────────────────────────

def test_calculation_invariants_hold():
    r = calculate_church_financials(
        receipts=SAMPLE_RECEIPTS,
        expenses=SAMPLE_EXPENSES,
        rates_source=CHARTERED_RATES,
        locked_keys=[],
        base_fields=CHARTERED_BASE_FIELDS,
        balance_last_month=5_000.0,
    )
    # total_receipts invariant
    assert abs(r["total_receipts"] - (r["subtotal_ac"] + sum([
        r[k] for k in [
            "missionary_offerings", "midweek_offerings", "sunday_school_offerings",
            "thanksgiving_offerings", "love_welfare_offerings", "building_pledge_offerings",
            "church_pioneering_receipts", "donation_other_churches", "other_pledges",
            "seed_faith", "staff_loans_repayment", "loan_cash_deposit",
            "pastor_pension_5pct", "national_grant", "convention_pledges",
            "special_projects", "decade_multiplication_receipts", "third_sunday_offering",
        ]
    ]))) < 0.05

    # balance invariant
    expected_surplus = money_round(r["total_receipts"] - r["total_payment"])
    assert abs(r["balance_surplus_deficit"] - expected_surplus) < 0.02

    expected_this_month = money_round(r["balance_surplus_deficit"] + r["balance_last_month"])
    assert abs(r["balance_this_month"] - expected_this_month) < 0.02

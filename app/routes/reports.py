import json
from datetime import datetime
from urllib.parse import quote
from fastapi import APIRouter, Request, Depends, Form, HTTPException, Response
from fastapi.responses import RedirectResponse, HTMLResponse
from sqlalchemy.orm import Session
from app.database import get_db, SessionLocal
from app.models import (
    User, Church, Zone, ZoneChurch, DuePercentageSettings, 
    ChurchFinancialReport, ChurchSpiritualReport, ZonalReport,
    ChurchExpenseItem, UserPayment
)
from app.auth import (
    is_logged_in, current_role, current_user_id, current_church_id, current_zone_id, ensure_role_session
)
from app.utils import (
    moneyRound, toFloat, toInt, pctDiff, monthName, 
    canUserCreateReport, sendAppEmail, render_pdf
)
from app.services.calculation_engine import CalculationValidationError, sanitize_money, validate_report_period, validate_church_type


router = APIRouter()

from app.main import templates

@router.get("/church-report", response_class=HTMLResponse)
def get_church_report(
    request: Request,
    month: str = None,
    year: str = None,
    church_id: str = None,
    format: str = None,
    db: Session = Depends(get_db)
):
    uid = ensure_role_session(request, "church_admin", db)
    role = current_role(request)

    # Determine church_id
    cid = None
    if role == "church_admin":
        cid = current_church_id(request, db)
    elif role in ["super_admin", "zonal_admin"]:
        cid = int(church_id) if (church_id and str(church_id).isdigit()) else current_church_id(request, db)
        
    church = db.query(Church).filter(Church.id == cid).first() if cid else None
    if not church:
        church = db.query(Church).filter(Church.created_by == uid).first()
        if not church:
            church = db.query(Church).first()
        if not church:
            church = Church(
                name="Foursquare Local Church",
                district="Lagos District",
                address="",
                pastor_name="Lead Pastor",
                pastor_address="",
                church_type="unchartered",
                created_by=uid
            )
            db.add(church)
            db.commit()
            db.refresh(church)
        cid = church.id
        request.session["church_id"] = cid

    # Access control
    if role == "church_admin" and not canUserCreateReport(db, uid):
        return RedirectResponse(url="/church-dashboard?error=" + quote("An active 1-Year Annual Subscription is required to access, create, or edit reports."), status_code=303)

    # Determine month and year
    import datetime as dt_mod
    now = dt_mod.datetime.now()
    r_month = int(month) if (month and str(month).isdigit()) else now.month
    r_year = int(year) if (year and str(year).isdigit()) else now.year

    # Fetch existing reports
    fin_report = db.query(ChurchFinancialReport).filter_by(
        church_id=cid, report_month=r_month, report_year=r_year
    ).first()
    
    sp_report = db.query(ChurchSpiritualReport).filter_by(
        church_id=cid, report_month=r_month, report_year=r_year
    ).first()

    # View-only logic
    view_only = False
    if role != "church_admin":
        view_only = True
    elif fin_report and fin_report.status == "submitted":
        view_only = True

    # 1. Create reports if they don't exist and not view_only
    if not fin_report and not view_only:
        try:
            
            # Create financial report row
            fin_report = ChurchFinancialReport(
                church_id=cid,
                report_month=r_month,
                report_year=r_year,
                status="draft"
            )
            db.add(fin_report)
            db.commit()
            db.refresh(fin_report)

            # Clone default expense items (report_id = NULL)
            default_expenses = db.query(ChurchExpenseItem).filter_by(
                church_id=cid, report_id=None
            ).order_by(ChurchExpenseItem.display_order.asc()).all()

            for item in default_expenses:
                db.add(ChurchExpenseItem(
                    church_id=cid,
                    report_id=fin_report.id,
                    item_key=item.item_key,
                    label=item.label,
                    amount=0.00,
                    is_custom=item.is_custom,
                    display_order=item.display_order
                ))

            # Snapshot due rates
            snap_raw = db.query(DuePercentageSettings).filter_by(church_type=church.church_type).all()
            snap_data = {}
            for sr in snap_raw:
                snap_data[sr.due_key] = {
                    "percentage_value": float(sr.percentage_value),
                    "is_locked": int(sr.is_locked),
                    "base_field": sr.base_field
                }
            fin_report.due_rates_snapshot = snap_data

            # Create spiritual report row
            sp_report = ChurchSpiritualReport(
                church_id=cid,
                report_month=r_month,
                report_year=r_year,
                status="draft"
            )
            db.add(sp_report)
            db.commit()

            # Email notification for report creation
            user = db.query(User).filter(User.id == uid).first()
            if user and user.email:
                cname = church.name
                mname = f"{monthName(r_month)} {r_year}"
                msg = f"Congratulations! You have successfully initialized a new monthly report for <strong>{cname}</strong> ({mname})."
                sendAppEmail(db, user.email, user.full_name, f"🎉 Report Initialized — {cname} ({mname})", msg, f"church-report?month={r_month}&year={r_year}", "Open Monthly Report")

            db.commit()
        except Exception as e:
            db.rollback()
            raise HTTPException(status_code=500, detail=f"Error creating report draft: {str(e)}")

    if not fin_report:
        raise HTTPException(status_code=404, detail="No report exists for this church for the selected month/year.")

    # Fetch expense items for report
    expense_items = db.query(ChurchExpenseItem).filter_by(
        report_id=fin_report.id
    ).order_by(ChurchExpenseItem.display_order.asc()).all()

    # Fallback safety: If report has no expense items cloned yet, clone them automatically
    if not expense_items and cid:
        default_expenses = db.query(ChurchExpenseItem).filter_by(
            church_id=cid, report_id=None
        ).order_by(ChurchExpenseItem.display_order.asc()).all()

        if not default_expenses:
            from app.utils import defaultExpenseItems
            for def_item in defaultExpenseItems():
                db.add(ChurchExpenseItem(
                    church_id=cid,
                    report_id=None,
                    item_key=def_item['item_key'],
                    label=def_item['label'],
                    amount=0.00,
                    is_custom=False,
                    display_order=def_item['display_order']
                ))
            db.commit()
            default_expenses = db.query(ChurchExpenseItem).filter_by(
                church_id=cid, report_id=None
            ).order_by(ChurchExpenseItem.display_order.asc()).all()

        for item in default_expenses:
            db.add(ChurchExpenseItem(
                church_id=cid,
                report_id=fin_report.id,
                item_key=item.item_key,
                label=item.label,
                amount=0.00,
                is_custom=item.is_custom,
                display_order=item.display_order
            ))
        db.commit()
        expense_items = db.query(ChurchExpenseItem).filter_by(
            report_id=fin_report.id
        ).order_by(ChurchExpenseItem.display_order.asc()).all()


    # Load rates:
    # - Submitted reports load from immutable due_rates_snapshot
    # - New and draft (unsaved) reports load directly from DuePercentageSettings so Admin rate/lock edits take immediate effect
    rates_source = {}
    locked_keys = []
    base_fields = {}
    
    if fin_report.status == "submitted" and fin_report.due_rates_snapshot:
        snap = fin_report.due_rates_snapshot
        for dkey, dval in snap.items():
            rates_source[dkey] = dval["percentage_value"]
            if dval["is_locked"]:
                locked_keys.append(dkey)
            base_fields[dkey] = dval["base_field"]
    else:
        db_dues = db.query(DuePercentageSettings).filter_by(church_type=church.church_type).all()
        for d in db_dues:
            rates_source[d.due_key] = float(d.percentage_value)
            if d.is_locked:
                locked_keys.append(d.due_key)
            base_fields[d.due_key] = d.base_field


    # Trigger export notifier emails if format=pdf is requested
    is_pdf = (format == "pdf")
    if is_pdf and uid:
        session_key = f"printed_report_{cid}_{r_month}_{r_year}"
        if not request.session.get(session_key):
            request.session[session_key] = True
            user = db.query(User).filter(User.id == uid).first()
            if user and user.email:
                cname = church.name
                mname = f"{monthName(r_month)} {r_year}"
                msg = f"Congratulations! Your monthly report for <strong>{cname}</strong> ({mname}) has been successfully printed / exported as PDF from your portal."
                sendAppEmail(db, user.email, user.full_name, f"🎉 Report Printed / Exported — {cname} ({mname})", msg, f"church-report?month={r_month}&year={r_year}", "View Report Portal")

    # Parse spiritual breakdown JSON with full default structure
    sp_json = {}
    if sp_report and sp_report.credential_workers_data:
        try:
            sp_json = json.loads(sp_report.credential_workers_data) if isinstance(sp_report.credential_workers_data, str) else sp_report.credential_workers_data
        except Exception:
            sp_json = {}
    if not isinstance(sp_json, dict):
        sp_json = {}
    
    if "crusaders" not in sp_json or not isinstance(sp_json["crusaders"], dict):
        sp_json["crusaders"] = {}
    if "credential_workers" not in sp_json or not isinstance(sp_json["credential_workers"], dict):
        sp_json["credential_workers"] = {}
    if "church_prog" not in sp_json or not isinstance(sp_json["church_prog"], dict):
        sp_json["church_prog"] = {}
    if "membership_details" not in sp_json or not isinstance(sp_json["membership_details"], dict):
        sp_json["membership_details"] = {}
    
    mem = sp_json["membership_details"]
    if "prev_month" not in mem or not isinstance(mem["prev_month"], dict):
        mem["prev_month"] = {"18": 0, "u18": 0}
    if "new_members" not in mem or not isinstance(mem["new_members"], dict):
        mem["new_members"] = {"18": 0, "u18": 0}
    if "withdrawn_reasons" not in mem or not isinstance(mem["withdrawn_reasons"], dict):
        mem["withdrawn_reasons"] = {}
    
    w_reasons = mem["withdrawn_reasons"]
    for r_key in ["transfer", "resignation", "dismissal", "death"]:
        if r_key not in w_reasons or not isinstance(w_reasons[r_key], dict):
            w_reasons[r_key] = {"18": 0, "u18": 0}

    # Render context
    render_ctx = {
        "request": request,
        "church": church,
        "month": r_month,
        "year": r_year,
        "finReport": fin_report,
        "spReport": sp_report,
        "spJson": sp_json,
        "expenseItems": expense_items,
        "viewOnly": view_only,
        "rates": rates_source,
        "lockedKeys": locked_keys,
        "baseFields": base_fields,
        "successMsg": request.query_params.get("msg", ""),
        "errorMsg": request.query_params.get("error", ""),
        "skipExpenseKeys": ['ministers_basic','ministers_allowances','other_workers_basic','other_workers_allowances','land_acquisition','church_building','purchase_motor_vehicles','purchase_new_equipment']
    }

    if is_pdf:
        # Generate PDF output using xhtml2pdf
        html_out = templates.get_template("church_report.html").render({**render_ctx, "is_pdf": True})
        pdf_bytes = render_pdf(html_out)
        if pdf_bytes:
            filename = f"Church_Report_{church.name.replace(' ', '_')}_{r_month}_{r_year}.pdf"
            return Response(
                content=pdf_bytes,
                media_type="application/pdf",
                headers={"Content-Disposition": f"inline; filename={filename}"}
            )
        else:
            raise HTTPException(status_code=500, detail="PDF generation failed.")

    return templates.TemplateResponse(request, "church_report.html", render_ctx)


@router.post("/church-report")
async def post_church_report(
    request: Request,
    db: Session = Depends(get_db)
):
    if not is_logged_in(request):
        return RedirectResponse(url="/login", status_code=303)

    form_data = await request.form()
    action = form_data.get("action", "save")
    custom_label = form_data.get("custom_label")
    rename_id = form_data.get("rename_id")
    rename_label = form_data.get("rename_label")
    month = form_data.get("month")
    year = form_data.get("year")
    church_id = form_data.get("church_id")

    role = current_role(request)
    uid = current_user_id(request)
    cid = current_church_id(request, db) if role == "church_admin" else (int(church_id) if (church_id and str(church_id).isdigit()) else (int(request.query_params.get("church_id")) if request.query_params.get("church_id") else None))

    import datetime as dt_mod
    now = dt_mod.datetime.now()
    r_month = int(month) if (month and str(month).isdigit()) else (int(request.query_params.get("month")) if request.query_params.get("month") else now.month)
    r_year = int(year) if (year and str(year).isdigit()) else (int(request.query_params.get("year")) if request.query_params.get("year") else now.year)

    if not cid:
        raise HTTPException(status_code=400, detail="Error: No church selected.")

    # Access control
    if role == "church_admin" and not canUserCreateReport(db, uid):
        return RedirectResponse(url="/church-dashboard?error=" + quote("An active 1-Year Annual Subscription is required."), status_code=303)

    church = db.query(Church).filter(Church.id == cid).first()
    fin_report = db.query(ChurchFinancialReport).filter_by(church_id=cid, report_month=r_month, report_year=r_year).first()
    
    if not fin_report or fin_report.status == "submitted":
        return RedirectResponse(url=f"/church-report?month={r_month}&year={r_year}&church_id={cid}&error=" + quote("Action not permitted on locked report."), status_code=303)

    report_id = fin_report.id

    if action == "delete_draft":
        try:
            db.begin_nested()
            db.query(ChurchExpenseItem).filter(ChurchExpenseItem.report_id == report_id).delete()
            db.query(ChurchSpiritualReport).filter_by(church_id=cid, report_month=month, report_year=year).delete()
            db.query(ChurchFinancialReport).filter_by(id=report_id).delete()
            db.commit()
            return RedirectResponse(url="/church-dashboard?msg=" + quote("Draft report deleted successfully."), status_code=303)
        except Exception as e:
            db.rollback()
            return RedirectResponse(url=f"/church-report?month={month}&year={year}&church_id={cid}&error=" + quote(f"Error deleting draft: {str(e)}"), status_code=303)

    # Process Form Saving
    form_data = await request.form()

    try:
        db.begin_nested()

        # Load percentage settings rates:
        # - Submitted reports validate against their original snapshot
        # - Draft/unsaved reports use live DuePercentageSettings
        rates_source = {}
        base_fields = {}
        locked_keys = []
        
        if fin_report.status == "submitted" and fin_report.due_rates_snapshot:
            snap = fin_report.due_rates_snapshot
            for dkey, dval in snap.items():
                rates_source[dkey] = float(dval["percentage_value"])
                if dval["is_locked"]:
                    locked_keys.append(dkey)
                base_fields[dkey] = dval["base_field"]
        else:
            db_dues = db.query(DuePercentageSettings).filter_by(church_type=church.church_type).all()
            for d in db_dues:
                rates_source[d.due_key] = float(d.percentage_value)
                if d.is_locked:
                    locked_keys.append(d.due_key)
                base_fields[d.due_key] = d.base_field


        # ── Feature 5: Pre-Calculation Input Validation ──────────────────────────
        #    Runs BEFORE any formula calculation. Any CalculationValidationError
        #    is caught by the outer except block and returned as a redirect error.
        validate_report_period(r_month, r_year)
        if church:
            validate_church_type(church.church_type)

        # Validate primary revenue fields (negative receipts are not valid)
        _val_receipt_fields = [
            "general_tithe_naira", "general_tithe_kobo",
            "minister_tithe_naira", "minister_tithe_kobo",
            "worship_offerings_naira", "worship_offerings_kobo",
            "missionary_offerings_naira", "midweek_offerings_naira",
            "sunday_school_offerings_naira", "thanksgiving_offerings_naira",
            "love_welfare_offerings_naira", "building_pledge_offerings_naira",
            "church_pioneering_receipts_naira", "donation_other_churches_naira",
            "other_pledges_naira", "seed_faith_naira", "staff_loans_repayment_naira",
            "loan_cash_deposit_naira", "pastor_pension_5pct_naira",
            "national_grant_naira", "convention_pledges_naira",
            "special_projects_naira", "decade_multiplication_receipts_naira",
            "third_sunday_offering_naira",
            "cash_in_hand_bank_naira", "investment_naira", "outstanding_loan_naira",
        ]
        for _f in _val_receipt_fields:
            sanitize_money(form_data.get(_f, 0), _f, allow_negative=False)

        # balance_last_month IS allowed to be negative (deficit carry-forward)
        sanitize_money(form_data.get("balance_last_month_naira", 0), "balance_last_month_naira", allow_negative=True)

        # Extract numerical inputs safely

        general_tithe = moneyRound(toFloat(form_data.get("general_tithe_naira", 0)) + (toFloat(form_data.get("general_tithe_kobo", 0)) / 100))
        minister_tithe = moneyRound(toFloat(form_data.get("minister_tithe_naira", 0)) + (toFloat(form_data.get("minister_tithe_kobo", 0)) / 100))
        worship_offerings = moneyRound(toFloat(form_data.get("worship_offerings_naira", 0)) + (toFloat(form_data.get("worship_offerings_kobo", 0)) / 100))
        
        subtotal_ac = moneyRound(general_tithe + minister_tithe + worship_offerings)

        # Other inputs
        missionary_offerings = moneyRound(toFloat(form_data.get("missionary_offerings_naira", 0)) + (toFloat(form_data.get("missionary_offerings_kobo", 0)) / 100))
        midweek_offerings = moneyRound(toFloat(form_data.get("midweek_offerings_naira", 0)) + (toFloat(form_data.get("midweek_offerings_kobo", 0)) / 100))
        sunday_school_offerings = moneyRound(toFloat(form_data.get("sunday_school_offerings_naira", 0)) + (toFloat(form_data.get("sunday_school_offerings_kobo", 0)) / 100))
        thanksgiving_offerings = moneyRound(toFloat(form_data.get("thanksgiving_offerings_naira", 0)) + (toFloat(form_data.get("thanksgiving_offerings_kobo", 0)) / 100))
        love_welfare_offerings = moneyRound(toFloat(form_data.get("love_welfare_offerings_naira", 0)) + (toFloat(form_data.get("love_welfare_offerings_kobo", 0)) / 100))
        building_pledge_offerings = moneyRound(toFloat(form_data.get("building_pledge_offerings_naira", 0)) + (toFloat(form_data.get("building_pledge_offerings_kobo", 0)) / 100))
        church_pioneering_receipts = moneyRound(toFloat(form_data.get("church_pioneering_receipts_naira", 0)) + (toFloat(form_data.get("church_pioneering_receipts_kobo", 0)) / 100))
        donation_other_churches = moneyRound(toFloat(form_data.get("donation_other_churches_naira", 0)) + (toFloat(form_data.get("donation_other_churches_kobo", 0)) / 100))
        other_pledges = moneyRound(toFloat(form_data.get("other_pledges_naira", 0)) + (toFloat(form_data.get("other_pledges_kobo", 0)) / 100))
        seed_faith = moneyRound(toFloat(form_data.get("seed_faith_naira", 0)) + (toFloat(form_data.get("seed_faith_kobo", 0)) / 100))
        staff_loans_repayment = moneyRound(toFloat(form_data.get("staff_loans_repayment_naira", 0)) + (toFloat(form_data.get("staff_loans_repayment_kobo", 0)) / 100))
        loan_cash_deposit = moneyRound(toFloat(form_data.get("loan_cash_deposit_naira", 0)) + (toFloat(form_data.get("loan_cash_deposit_kobo", 0)) / 100))
        pastor_pension_5pct = moneyRound(toFloat(form_data.get("pastor_pension_5pct_naira", 0)) + (toFloat(form_data.get("pastor_pension_5pct_kobo", 0)) / 100))
        national_grant = moneyRound(toFloat(form_data.get("national_grant_naira", 0)) + (toFloat(form_data.get("national_grant_kobo", 0)) / 100))
        convention_pledges = moneyRound(toFloat(form_data.get("convention_pledges_naira", 0)) + (toFloat(form_data.get("convention_pledges_kobo", 0)) / 100))
        special_projects = moneyRound(toFloat(form_data.get("special_projects_naira", 0)) + (toFloat(form_data.get("special_projects_kobo", 0)) / 100))
        decade_multiplication_receipts = moneyRound(toFloat(form_data.get("decade_multiplication_receipts_naira", 0)) + (toFloat(form_data.get("decade_multiplication_receipts_kobo", 0)) / 100))
        third_sunday_offering = moneyRound(toFloat(form_data.get("third_sunday_offering_naira", 0)) + (toFloat(form_data.get("third_sunday_offering_kobo", 0)) / 100))

        # Sum total receipts
        total_receipts = moneyRound(
            subtotal_ac + missionary_offerings + midweek_offerings + sunday_school_offerings +
            thanksgiving_offerings + love_welfare_offerings + building_pledge_offerings +
            church_pioneering_receipts + donation_other_churches + other_pledges +
            seed_faith + staff_loans_repayment + loan_cash_deposit + pastor_pension_5pct +
            national_grant + convention_pledges + special_projects +
            decade_multiplication_receipts + third_sunday_offering
        )

        def calc_due_amt(due_key: str) -> float:
            if due_key in locked_keys:
                return 0.0
            bfield = base_fields.get(due_key, "subtotal_ac")
            base_val = subtotal_ac
            if bfield == "sunday_school_offerings":
                base_val = sunday_school_offerings
            elif bfield == "missionary_offerings":
                base_val = missionary_offerings
            elif bfield == "love_welfare_offerings":
                base_val = love_welfare_offerings
            elif bfield == "third_sunday_offering":
                base_val = third_sunday_offering
            
            rate = rates_source.get(due_key, 0.0)
            return moneyRound(base_val * (rate / 100))

        # Dues calculations
        due_tithes_offerings = calc_due_amt('tithes_offerings')
        due_pastors_welfare  = calc_due_amt('pastors_welfare')
        due_project_dev      = calc_due_amt('project_dev_fund')
        due_macpherson       = calc_due_amt('macpherson_uni')
        due_augmentation     = calc_due_amt('augmentation_fund')
        due_ffs_savings      = calc_due_amt('ffs_savings')
        due_sunday_school    = calc_due_amt('sunday_school_offering')
        due_missionary       = calc_due_amt('missionary_offering')
        due_love_offering    = calc_due_amt('love_offering')
        due_foursquare_tv    = calc_due_amt('foursquare_tv')
        due_third_sunday     = calc_due_amt('third_sunday')

        national_dues_total = moneyRound(
            due_tithes_offerings + due_pastors_welfare + due_project_dev +
            due_macpherson + due_augmentation + due_ffs_savings +
            due_sunday_school + due_missionary + due_love_offering +
            due_foursquare_tv + due_third_sunday
        )

        # Right column
        regional_dues = calc_due_amt('regional_fund')
        
        due_district_fund = calc_due_amt('district_fund')
        straight_love_offering = calc_due_amt('straight_love_offering')
        pastors_staff_pension_8 = calc_due_amt('pastors_staff_pension_8')
        church_staff_pension_10 = calc_due_amt('church_staff_pension_10')
        due_dist_missionary = calc_due_amt('district_missionary')
        due_dist_sunday_school = calc_due_amt('district_sunday_school')
        
        district_dues = moneyRound(
            due_district_fund + straight_love_offering + pastors_staff_pension_8 + 
            church_staff_pension_10 + due_dist_missionary + due_dist_sunday_school
        )

        due_zonal_fund = calc_due_amt('zonal_fund')
        due_zonal_missionary = calc_due_amt('zonal_missionary')
        due_zonal_sunday_school = calc_due_amt('zonal_sunday_school')
        
        zonal_dues = moneyRound(due_zonal_fund + due_zonal_missionary + due_zonal_sunday_school)

        life_dues = calc_due_amt('life_theo_seminary')

        payable = moneyRound(national_dues_total + regional_dues + district_dues + zonal_dues + life_dues)

        # Fetch expense items for update
        db_expenses = db.query(ChurchExpenseItem).filter_by(report_id=report_id).all()
        db_expense_ids = {e.id: e for e in db_expenses}
        db_expense_keys = {e.item_key: e for e in db_expenses}

        def get_expense_amt(key: str) -> float:
            item = db_expense_keys.get(key)
            if not item:
                return 0.0
            n_raw = form_data.get(f"expense_amount[{item.id}]_naira") or form_data.get(f"expense_amount_naira[{item.id}]", 0)
            k_raw = form_data.get(f"expense_amount[{item.id}]_kobo") or form_data.get(f"expense_amount_kobo[{item.id}]", 0)
            n_val = toFloat(n_raw)
            k_val = toFloat(k_raw)
            return moneyRound(n_val + k_val / 100)

        # Salaries
        ministersBasic = get_expense_amt('ministers_basic')
        ministersAllowances = get_expense_amt('ministers_allowances')
        ministersSubtotal = moneyRound(ministersBasic + ministersAllowances)

        otherWorkersBasic = get_expense_amt('other_workers_basic')
        otherWorkersAllowances = get_expense_amt('other_workers_allowances')
        otherWorkersSubtotal = moneyRound(otherWorkersBasic + otherWorkersAllowances)

        total_emoluments = moneyRound(ministersSubtotal + otherWorkersSubtotal)

        # Fixed assets
        landAcquisition = get_expense_amt('land_acquisition')
        churchBuilding = get_expense_amt('church_building')
        purchaseMotorVehicles = get_expense_amt('purchase_motor_vehicles')
        purchaseNewEquipment = get_expense_amt('purchase_new_equipment')
        fixed_assets_subtotal = moneyRound(landAcquisition + churchBuilding + purchaseMotorVehicles + purchaseNewEquipment)

        # Update expense items database rows
        general_expenses = 0.0
        skip_keys = ['ministers_basic', 'ministers_allowances', 'other_workers_basic', 'other_workers_allowances', 'land_acquisition', 'church_building', 'purchase_motor_vehicles', 'purchase_new_equipment']
        for item in db_expenses:
            n_raw = form_data.get(f"expense_amount[{item.id}]_naira") or form_data.get(f"expense_amount_naira[{item.id}]", 0)
            k_raw = form_data.get(f"expense_amount[{item.id}]_kobo") or form_data.get(f"expense_amount_kobo[{item.id}]", 0)
            n_val = toFloat(n_raw)
            k_val = toFloat(k_raw)
            amt = moneyRound(n_val + k_val / 100)
            item.amount = amt
            
            if item.item_key not in skip_keys:
                general_expenses += amt
        general_expenses = moneyRound(general_expenses)


        # Total payments
        total_payment = moneyRound(payable + total_emoluments + general_expenses + fixed_assets_subtotal)
        less_total_payment = total_payment

        balance_surplus_deficit = moneyRound(total_receipts - total_payment)
        balance_last_month = moneyRound(toFloat(form_data.get("balance_last_month_naira", 0)) + (toFloat(form_data.get("balance_last_month_kobo", 0)) / 100))
        balance_this_month = moneyRound(balance_surplus_deficit + balance_last_month)

        cash_in_hand_bank = moneyRound(toFloat(form_data.get("cash_in_hand_bank_naira", 0)) + (toFloat(form_data.get("cash_in_hand_bank_kobo", 0)) / 100))
        investment = moneyRound(toFloat(form_data.get("investment_naira", 0)) + (toFloat(form_data.get("investment_kobo", 0)) / 100))
        total_balance = moneyRound(cash_in_hand_bank + investment)

        outstanding_loan = moneyRound(toFloat(form_data.get("outstanding_loan_naira", 0)) + (toFloat(form_data.get("outstanding_loan_kobo", 0)) / 100))

        # ── Feature 5 + 3: Post-Calculation Invariant Verification ───────────────
        #    Verifies all calculated totals are consistent with their source formulas.
        #    If any invariant fails the report is NOT saved — no false "success" response.
        _other_receipts_sum = moneyRound(
            missionary_offerings + midweek_offerings + sunday_school_offerings +
            thanksgiving_offerings + love_welfare_offerings + building_pledge_offerings +
            church_pioneering_receipts + donation_other_churches + other_pledges +
            seed_faith + staff_loans_repayment + loan_cash_deposit + pastor_pension_5pct +
            national_grant + convention_pledges + special_projects +
            decade_multiplication_receipts + third_sunday_offering
        )
        _expected_total_receipts = moneyRound(subtotal_ac + _other_receipts_sum)
        if abs(total_receipts - _expected_total_receipts) > 0.02:
            raise CalculationValidationError(
                f"Receipts invariant failed: total_receipts={total_receipts:.2f} "
                f"!= subtotal_ac({subtotal_ac:.2f}) + other_receipts({_other_receipts_sum:.2f})"
            )

        _expected_payable = moneyRound(national_dues_total + regional_dues + district_dues + zonal_dues + life_dues)
        if abs(payable - _expected_payable) > 0.02:
            raise CalculationValidationError(
                f"Payable invariant failed: payable={payable:.2f} "
                f"!= national+regional+district+zonal+life = {_expected_payable:.2f}"
            )

        _expected_total_payment = moneyRound(payable + total_emoluments + general_expenses + fixed_assets_subtotal)
        if abs(total_payment - _expected_total_payment) > 0.02:
            raise CalculationValidationError(
                f"Total payment invariant failed: {total_payment:.2f} "
                f"!= payable+emoluments+expenses+fixed = {_expected_total_payment:.2f}"
            )

        _expected_surplus = moneyRound(total_receipts - total_payment)
        if abs(balance_surplus_deficit - _expected_surplus) > 0.02:
            raise CalculationValidationError(
                f"Balance surplus/deficit invariant failed: {balance_surplus_deficit:.2f} "
                f"!= total_receipts - total_payment = {_expected_surplus:.2f}"
            )

        _expected_balance_this_month = moneyRound(balance_surplus_deficit + balance_last_month)
        if abs(balance_this_month - _expected_balance_this_month) > 0.02:
            raise CalculationValidationError(
                f"Balance this month invariant failed: {balance_this_month:.2f} "
                f"!= surplus_deficit + balance_last_month = {_expected_balance_this_month:.2f}"
            )

        # Status
        status = "submitted" if action == "submit" else "draft"


        # Update Financial report
        fin_report.general_tithe = general_tithe
        fin_report.minister_tithe = minister_tithe
        fin_report.worship_offerings = worship_offerings
        fin_report.subtotal_ac = subtotal_ac
        fin_report.missionary_offerings = missionary_offerings
        fin_report.midweek_offerings = midweek_offerings
        fin_report.sunday_school_offerings = sunday_school_offerings
        fin_report.thanksgiving_offerings = thanksgiving_offerings
        fin_report.love_welfare_offerings = love_welfare_offerings
        fin_report.building_pledge_offerings = building_pledge_offerings
        fin_report.church_pioneering_receipts = church_pioneering_receipts
        fin_report.donation_other_churches = donation_other_churches
        fin_report.other_pledges = other_pledges
        fin_report.seed_faith = seed_faith
        fin_report.staff_loans_repayment = staff_loans_repayment
        fin_report.loan_cash_deposit = loan_cash_deposit
        fin_report.pastor_pension_5pct = pastor_pension_5pct
        fin_report.national_grant = national_grant
        fin_report.convention_pledges = convention_pledges
        fin_report.special_projects = special_projects
        fin_report.decade_multiplication_receipts = decade_multiplication_receipts
        fin_report.third_sunday_offering = third_sunday_offering
        fin_report.total_receipts = total_receipts
        fin_report.national_dues_total = national_dues_total
        fin_report.regional_dues = regional_dues
        fin_report.district_dues = district_dues
        fin_report.zonal_dues = zonal_dues
        fin_report.life_dues = life_dues
        fin_report.straight_love_offering = straight_love_offering
        fin_report.pastors_staff_pension_8 = pastors_staff_pension_8
        fin_report.church_staff_pension_10 = church_staff_pension_10
        fin_report.payable = payable
        fin_report.total_emoluments = total_emoluments
        fin_report.total_expenses_block = general_expenses
        fin_report.total_payment = total_payment
        fin_report.less_total_payment = less_total_payment
        fin_report.balance_surplus_deficit = balance_surplus_deficit
        fin_report.balance_last_month = balance_last_month
        fin_report.balance_this_month = balance_this_month
        fin_report.cash_in_hand_bank = cash_in_hand_bank
        fin_report.investment = investment
        fin_report.total_balance = total_balance
        fin_report.outstanding_loan = outstanding_loan
        fin_report.special_projects_details = form_data.get("special_projects_details", "")
        fin_report.status = status

        if action == "submit":
            snap_raw = db.query(DuePercentageSettings).filter_by(church_type=church.church_type).all()
            snap_data = {}
            for sr in snap_raw:
                snap_data[sr.due_key] = {
                    "percentage_value": float(sr.percentage_value),
                    "is_locked": int(sr.is_locked),
                    "base_field": sr.base_field
                }
            fin_report.due_rates_snapshot = snap_data

        # Update Spiritual Report

        sp_report = db.query(ChurchSpiritualReport).filter_by(church_id=cid, report_month=r_month, report_year=r_year).first()
        if not sp_report:
            sp_report = ChurchSpiritualReport(
                church_id=cid,
                report_month=r_month,
                report_year=r_year,
                status=status
            )
            db.add(sp_report)

        sp_report.status = status
        sp_report.pastor_signature_name = form_data.get("pastor_signature_name", "")
        sp_report.treasurer_signature_name = form_data.get("treasurer_signature_name", "")
        sp_report.secretary_signature_name = form_data.get("secretary_signature_name", "")
        
        # Save date safely
        r_date = form_data.get("report_date")
        if r_date:
            try:
                sp_report.report_date = datetime.strptime(r_date, "%Y-%m-%d").date()
            except Exception:
                pass
        
        # Collect attendance fields safely
        for prefix in ['pre_sun_school', 'sun_school', 'sun_worship', 'house_fellowship', 'bible_study', 'prayer_meeting']:
            setattr(sp_report, f"{prefix}_children", toInt(form_data.get(f"{prefix}_children")))
            setattr(sp_report, f"{prefix}_adults", toInt(form_data.get(f"{prefix}_adults")))
            setattr(sp_report, f"{prefix}_total", toInt(form_data.get(f"{prefix}_total")))

        # Other fields
        sp_report.total_new_comers = toInt(form_data.get("total_new_comers"))
        sp_report.total_decision_christ = toInt(form_data.get("total_decision_christ"))
        sp_report.total_water_baptism = toInt(form_data.get("total_water_baptism"))
        sp_report.total_holy_spirit_baptism = toInt(form_data.get("total_holy_spirit_baptism"))
        sp_report.total_healings = toInt(form_data.get("total_healings"))
        sp_report.total_house_fellowship_centres = toInt(form_data.get("total_house_fellowship_centres"))
        
        sp_report.intake_above_18 = toInt(form_data.get("intake_above_18"))
        sp_report.intake_under_18 = toInt(form_data.get("intake_under_18"))
        sp_report.intake_total = toInt(form_data.get("intake_total"))

        sp_report.withdrawn_above_18 = toInt(form_data.get("withdrawn_total_above_18"))
        sp_report.withdrawn_under_18 = toInt(form_data.get("withdrawn_total_under_18"))
        sp_report.withdrawn_total = toInt(form_data.get("withdrawn_total_total"))

        sp_report.membership_above_18 = toInt(form_data.get("after_withdrawal_above_18"))
        sp_report.membership_under_18 = toInt(form_data.get("after_withdrawal_under_18"))
        sp_report.membership_total = toInt(form_data.get("after_withdrawal_total"))

        # Store payload JSON
        json_payload = {
            'new_comers': toInt(form_data.get('total_new_comers')),
            'decisions': toInt(form_data.get('total_decision_christ')),
            'water_bapt': toInt(form_data.get('total_water_baptism')),
            'spirit_bapt': toInt(form_data.get('total_holy_spirit_baptism')),
            'healings': toInt(form_data.get('total_healings')),
            'house_fellowships': toInt(form_data.get('total_house_fellowship_centres')),
            'crusaders': {
                'candlelighters': toInt(form_data.get('crusader_candlelighters')),
                'cupbearers': toInt(form_data.get('crusader_cupbearers')),
                'cadets': toInt(form_data.get('crusader_cadets')),
                'jr_teens': toInt(form_data.get('crusader_jr_teens')),
                'sr_teens': toInt(form_data.get('crusader_sr_teens')),
                'youth': toInt(form_data.get('crusader_youth')),
                'challengers': toInt(form_data.get('crusader_challengers')),
                'defenders': toInt(form_data.get('crusader_defenders')),
                'citizens': toInt(form_data.get('crusader_citizens')),
            },
            'credential_workers': {
                'ordained': toInt(form_data.get('cw_ordained')),
                'licensed': toInt(form_data.get('cw_licensed')),
                'exhorters': toInt(form_data.get('cw_exhorters')),
                'elders': toInt(form_data.get('cw_elders')),
                'deacons': toInt(form_data.get('cw_deacons')),
                'deaconesses': toInt(form_data.get('cw_deaconesses')),
            },
            'membership_details': {
                'prev_month': {'18': toInt(form_data.get('prev_above_18')), 'u18': toInt(form_data.get('prev_under_18'))},
                'new_members': {'18': toInt(form_data.get('new_above_18')), 'u18': toInt(form_data.get('new_under_18'))},
                'withdrawn_reasons': {
                    'transfer': {'18': toInt(form_data.get('withdrawn_transfer_above_18')), 'u18': toInt(form_data.get('withdrawn_transfer_under_18'))},
                    'resignation': {'18': toInt(form_data.get('withdrawn_resignation_above_18')), 'u18': toInt(form_data.get('withdrawn_resignation_under_18'))},
                    'dismissal': {'18': toInt(form_data.get('withdrawn_dismissal_above_18')), 'u18': toInt(form_data.get('withdrawn_dismissal_under_18'))},
                    'death': {'18': toInt(form_data.get('withdrawn_death_above_18')), 'u18': toInt(form_data.get('withdrawn_death_under_18'))},
                }
            }
        }
        sp_report.credential_workers_data = json_payload

        # Handle custom expense actions
        successMsg = ""
        if action == "add_custom_item":
            c_label = form_data.get("custom_label", "").strip()
            if c_label:
                import random
                # get max order
                from sqlalchemy import func
                max_order = db.query(func.max(ChurchExpenseItem.display_order)).filter_by(report_id=report_id).scalar() or 0
                item_key = f"custom_{random.randint(10000000, 99999999)}"
                db.add(ChurchExpenseItem(
                    church_id=cid,
                    report_id=report_id,
                    item_key=item_key,
                    label=c_label,
                    amount=0.00,
                    is_custom=True,
                    display_order=max_order + 1
                ))
                successMsg = "Report saved and custom expense item added!"

        elif action == "rename_item":
            if rename_id and rename_label:
                item = db.query(ChurchExpenseItem).filter_by(id=rename_id, church_id=cid).first()
                if item and (item.report_id == report_id or item.report_id is None):
                    item.label = rename_label.strip()
                    successMsg = "Report saved and expense item renamed successfully!"

        db.commit()

        # Send email on submit
        if action == "submit" and uid:
            user = db.query(User).filter(User.id == uid).first()
            if user and user.email:
                cname = church.name
                mname = f"{monthName(r_month)} {r_year}"
                msg = f"Congratulations! Your monthly report for <strong>{cname}</strong> ({mname}) has been officially <strong>submitted</strong> and locked for review."
                sendAppEmail(db, user.email, user.full_name, f"🎉 Report Submitted — {cname} ({mname})", msg, f"church-report?month={r_month}&year={r_year}", "View Submitted Report")

        if not successMsg:
            successMsg = "Report submitted successfully! It is now locked." if action == "submit" else "Report draft saved successfully!"

        return RedirectResponse(url=f"/church-report?month={r_month}&year={r_year}&church_id={cid}&msg=" + quote(successMsg), status_code=303)

    except CalculationValidationError as ve:
        db.rollback()
        return RedirectResponse(url=f"/church-report?month={r_month}&year={r_year}&church_id={cid}&error=" + quote(f"Validation Error: {str(ve)}"), status_code=303)

    except Exception as e:
        db.rollback()
        return RedirectResponse(url=f"/church-report?month={r_month}&year={r_year}&church_id={cid}&error=" + quote(f"Error saving report: {str(e)}"), status_code=303)



@router.get("/zonal-reports", response_class=HTMLResponse)
def get_zonal_reports(
    request: Request,
    month: str = None,
    year: str = None,
    zone_id: str = None,
    format: str = None,
    db: Session = Depends(get_db)
):
    uid = ensure_role_session(request, "zonal_admin", db)
    role = current_role(request)
    zid = current_zone_id(request, db) if role == "zonal_admin" else (int(zone_id) if (zone_id and str(zone_id).isdigit()) else current_zone_id(request, db))

    if not zid:
        first_zone = db.query(Zone).first()
        if not first_zone:
            first_zone = Zone(zone_name="Central Zone", created_by=uid)
            db.add(first_zone)
            db.commit()
            db.refresh(first_zone)
        zid = first_zone.id
        request.session["zone_id"] = zid

    # Fetch zone
    zone = db.query(Zone).filter(Zone.id == zid).first()
    if not zone:
        zone = Zone(zone_name="Central Zone", created_by=uid)
        db.add(zone)
        db.commit()
        db.refresh(zone)
        zid = zone.id
        request.session["zone_id"] = zid

    # Fetch churches under zone - auto-seed default churches if missing
    zone_churches = db.query(ZoneChurch).filter_by(zone_id=zid).order_by(ZoneChurch.display_order.asc()).all()
    if not zone_churches:
        churches_to_add = [
            ("ZONAL HQTS", 1),
            ("BRANCH 1", 2),
            ("BRANCH 2", 3),
            ("BRANCH 3", 4)
        ]
        for name, order in churches_to_add:
            db.add(ZoneChurch(zone_id=zid, church_name=name, display_order=order))
        db.commit()
        zone_churches = db.query(ZoneChurch).filter_by(zone_id=zid).order_by(ZoneChurch.display_order.asc()).all()

    import datetime as dt_mod
    now = dt_mod.datetime.now()
    r_month = int(month) if (month and str(month).isdigit()) else now.month
    r_year = int(year) if (year and str(year).isdigit()) else now.year

    # Fetch zonal report
    z_report = db.query(ZonalReport).filter_by(zone_id=zid, report_month=r_month, report_year=r_year).first()

    view_only = False
    if role not in ["zonal_admin", "super_admin"]:
        view_only = True
    elif z_report and z_report.status == "submitted":
        view_only = True

    # 1. Create draft if not exists and not view_only
    if not z_report and not view_only:
        try:
            z_report = ZonalReport(
                zone_id=zid,
                report_month=r_month,
                report_year=r_year,
                status="draft"
            )
            db.add(z_report)
            db.commit()
            db.refresh(z_report)

            # Send email
            user = db.query(User).filter(User.id == uid).first()
            if user and user.email:
                zname = zone.zone_name
                mname = f"{monthName(r_month)} {r_year}"
                msg = f"Congratulations! You have initialized a new zonal monthly report for <strong>{zname} Zone</strong> ({mname})."
                sendAppEmail(db, user.email, user.full_name, f"🎉 Zonal Report Initialized — {zname} ({mname})", msg, f"zonal-reports?month={r_month}&year={r_year}", "Open Zonal Report")

            db.commit()
        except Exception as e:
            db.rollback()
            raise HTTPException(status_code=500, detail=f"Error creating zonal report: {str(e)}")

    if not z_report:
        raise HTTPException(status_code=404, detail="No report exists for this zone for the selected month/year.")

    is_pdf = (format == "pdf")
    if is_pdf and uid:
        session_key = f"printed_zonal_report_{zid}_{r_month}_{r_year}"
        if not request.session.get(session_key):
            request.session[session_key] = True
            user = db.query(User).filter(User.id == uid).first()
            if user and user.email:
                zname = zone.zone_name
                mname = f"{monthName(r_month)} {r_year}"
                msg = f"Congratulations! Your zonal monthly report for <strong>{zname} Zone</strong> ({mname}) has been successfully printed / exported."
                sendAppEmail(db, user.email, user.full_name, f"🎉 Zonal Report Printed / Exported — {zname} ({mname})", msg, f"zonal-reports?month={r_month}&year={r_year}", "View Zonal Portal")

    # Scale print sizes dynamically based on church count
    num_churches = len(zone_churches)
    scale_steps = max(0, num_churches - 6)
    font_size = max(5.5, 10.5 - scale_steps * 0.45)
    padding = max(0.5, 2.0 - scale_steps * 0.20)
    line_h = max(8.0, 14.0 - scale_steps * 0.50)

    # Decode zonal JSON details
    p1_saved = z_report.page1_data if isinstance(z_report.page1_data, dict) else (json.loads(z_report.page1_data) if z_report.page1_data else {})
    p2_saved = z_report.page2_data if isinstance(z_report.page2_data, dict) else (json.loads(z_report.page2_data) if z_report.page2_data else {})
    p3_saved = z_report.page3_data if isinstance(z_report.page3_data, dict) else (json.loads(z_report.page3_data) if z_report.page3_data else {})
    p4_saved = z_report.page4_data if isinstance(z_report.page4_data, dict) else (json.loads(z_report.page4_data) if z_report.page4_data else {})
    summary_saved = z_report.summary_data if isinstance(z_report.summary_data, dict) else (json.loads(z_report.summary_data) if z_report.summary_data else {})
    planting_saved = z_report.planting_data if isinstance(z_report.planting_data, dict) else (json.loads(z_report.planting_data) if z_report.planting_data else {})

    if not isinstance(p1_saved, dict): p1_saved = {}
    if not isinstance(p2_saved, dict): p2_saved = {}
    if not isinstance(p3_saved, dict): p3_saved = {}
    if not isinstance(p4_saved, dict): p4_saved = {}
    if not isinstance(summary_saved, dict): summary_saved = {}
    if not isinstance(planting_saved, dict): planting_saved = {}

    for p in range(1, 13):
        p_str = str(p)
        if p_str not in p2_saved or not isinstance(p2_saved[p_str], dict): p2_saved[p_str] = {}
        if p_str not in p3_saved or not isinstance(p3_saved[p_str], dict): p3_saved[p_str] = {}
        if p_str not in p4_saved or not isinstance(p4_saved[p_str], dict): p4_saved[p_str] = {'tm': 0, 'lm': 0}
        if p_str not in summary_saved or not isinstance(summary_saved[p_str], dict): summary_saved[p_str] = {'tm': 0, 'lm': 0}

    import hashlib

    # Auto Pre-fill Helper from online sub-reports (only for new draft reports)
    if not p1_saved and not view_only:
        p1_saved = {}
        for zc in zone_churches:
            cName = zc.church_name
            key = hashlib.md5(cName.encode('utf-8')).hexdigest()
            
            # Find matching local church
            l_church = db.query(Church).filter(Church.name.ilike(f"%{cName}%")).first()
            sp_tm, sp_lm, sp_ago = 0, 0, 0
            fin_tm, fin_lm, fin_ago = 0, 0, 0
            ft, pt, dc, dcn, eld = 0, 0, 0, 0, 0

            if l_church:
                # Submitted this month
                r_tm = db.query(ChurchFinancialReport).filter_by(church_id=l_church.id, report_month=r_month, report_year=r_year, status='submitted').first()
                prev_m = 12 if r_month == 1 else r_month - 1
                prev_y = r_year - 1 if r_month == 1 else r_year
                r_lm = db.query(ChurchFinancialReport).filter_by(church_id=l_church.id, report_month=prev_m, report_year=prev_y, status='submitted').first()
                r_ago = db.query(ChurchFinancialReport).filter_by(church_id=l_church.id, report_month=r_month, report_year=r_year - 1, status='submitted').first()

                if r_tm:
                    fin_tm = float(r_tm.total_receipts or 0)
                    sp_tm_rep = db.query(ChurchSpiritualReport).filter_by(church_id=l_church.id, report_month=r_month, report_year=r_year).first()
                    if sp_tm_rep:
                        sp_tm = float(sp_tm_rep.sun_worship_total or 0)
                        sp_detail = json.loads(sp_tm_rep.credential_workers_data) if isinstance(sp_tm_rep.credential_workers_data, str) else (sp_tm_rep.credential_workers_data or {})
                        cw = sp_detail.get('credential_workers', {}) if isinstance(sp_detail, dict) else {}
                        ft = int(cw.get('ordained', 0) or 0)
                        pt = int(cw.get('licensed', 0) or 0)
                        dc = int(cw.get('deacons', 0) or 0)
                        dcn = int(cw.get('deaconesses', 0) or 0)
                        eld = int(cw.get('elders', 0) or 0)
                if r_lm:
                    fin_lm = float(r_lm.total_receipts or 0)
                    sp_lm_rep = db.query(ChurchSpiritualReport).filter_by(church_id=l_church.id, report_month=prev_m, report_year=prev_y).first()
                    if sp_lm_rep:
                        sp_lm = float(sp_lm_rep.sun_worship_total or 0)
                if r_ago:
                    fin_ago = float(r_ago.total_receipts or 0)
                    sp_ago_rep = db.query(ChurchSpiritualReport).filter_by(church_id=l_church.id, report_month=r_month, report_year=r_year - 1).first()
                    if sp_ago_rep:
                        sp_ago = float(sp_ago_rep.sun_worship_total or 0)

            p1_saved[key] = {
                'church_name': cName,
                'sp_tm': sp_tm, 'sp_lm': sp_lm, 'sp_ago': sp_ago,
                'fin_tm': fin_tm, 'fin_lm': fin_lm, 'fin_ago': fin_ago,
                'ft': ft, 'pt': pt, 'dc': dc, 'dcn': dcn, 'eld': eld,
            }

    # Calculations
    sp_tm_sum, sp_lm_sum, sp_ago_sum = 0, 0, 0
    fin_tm_sum, fin_lm_sum, fin_ago_sum = 0, 0, 0
    ft_sum, pt_sum, dc_sum, dcn_sum, eld_sum = 0, 0, 0, 0, 0

    p1_row_data = {}
    for zc in zone_churches:
        cName = zc.church_name
        key = hashlib.md5(cName.encode('utf-8')).hexdigest()
        row = p1_saved.get(key, {
            'sp_tm': 0, 'sp_lm': 0, 'sp_ago': 0,
            'fin_tm': 0, 'fin_lm': 0, 'fin_ago': 0,
            'ft': 0, 'pt': 0, 'dc': 0, 'dcn': 0, 'eld': 0
        })
        sp_tm_sum += float(row.get('sp_tm', 0) or 0)
        sp_lm_sum += float(row.get('sp_lm', 0) or 0)
        sp_ago_sum += float(row.get('sp_ago', 0) or 0)
        fin_tm_sum += float(row.get('fin_tm', 0) or 0)
        fin_lm_sum += float(row.get('fin_lm', 0) or 0)
        fin_ago_sum += float(row.get('fin_ago', 0) or 0)
        ft_sum += int(row.get('ft', 0) or 0)
        pt_sum += int(row.get('pt', 0) or 0)
        dc_sum += int(row.get('dc', 0) or 0)
        dcn_sum += int(row.get('dcn', 0) or 0)
        eld_sum += int(row.get('eld', 0) or 0)

        sp_lm_val = float(row.get('sp_lm', 0) or 0)
        sp_tm_val = float(row.get('sp_tm', 0) or 0)
        fin_lm_val = float(row.get('fin_lm', 0) or 0)
        fin_tm_val = float(row.get('fin_tm', 0) or 0)

        sp_diff = round(((sp_tm_val - sp_lm_val) / sp_lm_val) * 100, 2) if sp_lm_val != 0 else None
        fin_diff = round(((fin_tm_val - fin_lm_val) / fin_lm_val) * 100, 2) if fin_lm_val != 0 else None

        p1_row_data[key] = {
            'sp_diff': f"{sp_diff:.2f}%" if sp_diff is not None else '—',
            'fin_diff': f"{fin_diff:.2f}%" if fin_diff is not None else '—'
        }

    sp_total_diff = round(((sp_tm_sum - sp_lm_sum) / sp_lm_sum) * 100, 2) if sp_lm_sum != 0 else None
    fin_total_diff = round(((fin_tm_sum - fin_lm_sum) / fin_lm_sum) * 100, 2) if fin_lm_sum != 0 else None

    p1_totals = {
        'sp_tm': sp_tm_sum, 'sp_lm': sp_lm_sum, 'sp_ago': sp_ago_sum,
        'fin_tm': fin_tm_sum, 'fin_lm': fin_lm_sum, 'fin_ago': fin_ago_sum,
        'ft': ft_sum, 'pt': pt_sum, 'dc': dc_sum, 'dcn': dcn_sum, 'eld': eld_sum,
        'sp_diff': f"{sp_total_diff:.2f}%" if sp_total_diff is not None else '—',
        'fin_diff': f"{fin_total_diff:.2f}%" if fin_total_diff is not None else '—'
    }

    p2_totals = {}
    for p in range(1, 13):
        tm_sum, lm_sum = 0, 0
        for zc in zone_churches:
            key = hashlib.md5(zc.church_name.encode('utf-8')).hexdigest()
            p2_row = p2_saved.get(str(p), {}).get(key, {}) if isinstance(p2_saved.get(str(p)), dict) else {}
            tm_sum += int(p2_row.get('tm', 0) or 0)
            lm_sum += int(p2_row.get('lm', 0) or 0)
        p2_totals[str(p)] = {'tm': tm_sum, 'lm': lm_sum}

    p3_totals = {}
    for p in range(1, 13):
        sum_val = 0
        for zc in zone_churches:
            key = hashlib.md5(zc.church_name.encode('utf-8')).hexdigest()
            p3_row = p3_saved.get(str(p), {}).get(key, {}) if isinstance(p3_saved.get(str(p)), dict) else {}
            sum_val += int(p3_row.get('val', 0) or 0)
        p3_totals[str(p)] = sum_val

    p4_calcs = {}
    for p in range(1, 13):
        p4_row = p4_saved.get(str(p), {}) if isinstance(p4_saved, dict) else {}
        tm = int(p4_row.get('tm', 0) or 0)
        lm = int(p4_row.get('lm', 0) or 0)
        diff = tm - lm
        pct = round((diff / lm) * 100, 2) if lm != 0 else None
        p4_calcs[str(p)] = {
            'diff': diff,
            'pct': f"{pct:.2f}%" if pct is not None else '—'
        }

    sum_params = [
        (1, 'Total new comers'),
        (2, 'Total Decision for Christ'),
        (3, 'Total Water Baptism'),
        (4, 'Total Holy Ghost Baptism'),
        (5, 'Total Divine Healing'),
        (6, 'Average Sun. School Attendance'),
        (7, 'Average Worship Service Attend.'),
        (8, 'Average Bible Study Attend.'),
        (9, 'Average Prayer Meeting Attend.'),
        (10, 'Average Pre- sun. School Attend.'),
        (11, 'Average House F/Ship Attend.'),
        (12, 'Total New members')
    ]

    render_ctx = {
        "request": request,
        "zone": zone,
        "zoneChurches": [zc.church_name for zc in zone_churches],
        "month": r_month,
        "year": r_year,
        "zReport": z_report,
        "viewOnly": view_only,
        "role": role,
        "font_size": font_size,
        "padding": padding,
        "line_h": line_h,
        "pZonalFontS": font_size,
        "pZonalPad": padding,
        "pZonalH": line_h,
        "p1Saved": p1_saved,
        "p2Saved": p2_saved,
        "p3Saved": p3_saved,
        "p4Saved": p4_saved,
        "summarySaved": summary_saved,
        "plantingSaved": planting_saved,
        "p1RowData": p1_row_data,
        "p1Totals": p1_totals,
        "p2Totals": p2_totals,
        "p3Totals": p3_totals,
        "p4Calcs": p4_calcs,
        "sumParams": sum_params,
        "successMsg": request.query_params.get("msg", ""),
        "errorMsg": request.query_params.get("error", "")
    }

    if is_pdf:
        html_out = templates.get_template("zonal_reports.html").render({**render_ctx, "is_pdf": True})
        pdf_bytes = render_pdf(html_out)
        if pdf_bytes:
            filename = f"Zonal_Report_{zone.zone_name.replace(' ', '_')}_{r_month}_{r_year}.pdf"
            return Response(
                content=pdf_bytes,
                media_type="application/pdf",
                headers={"Content-Disposition": f"inline; filename={filename}"}
            )
        else:
            raise HTTPException(status_code=500, detail="PDF generation failed.")

    return templates.TemplateResponse(request, "zonal_reports.html", render_ctx)


@router.post("/zonal-reports")
async def post_zonal_reports(
    request: Request,
    db: Session = Depends(get_db)
):
    if not is_logged_in(request):
        return RedirectResponse(url="/login", status_code=303)

    form_data = await request.form()
    action = form_data.get("action", "save")
    month = form_data.get("month")
    year = form_data.get("year")
    zone_id = form_data.get("zone_id")

    role = current_role(request)
    uid = current_user_id(request)
    zid = current_zone_id(request, db) if role == "zonal_admin" else (int(zone_id) if (zone_id and str(zone_id).isdigit()) else (int(request.query_params.get("zone_id")) if request.query_params.get("zone_id") else None))

    import datetime as dt_mod
    now = dt_mod.datetime.now()
    r_month = int(month) if (month and str(month).isdigit()) else (int(request.query_params.get("month")) if request.query_params.get("month") else now.month)
    r_year = int(year) if (year and str(year).isdigit()) else (int(request.query_params.get("year")) if request.query_params.get("year") else now.year)

    if not zid:
        raise HTTPException(status_code=400, detail="Error: No zone selected.")

    # Access control
    if role == "zonal_admin" and not canUserCreateReport(db, uid):
        return RedirectResponse(url="/zone-dashboard?error=" + quote("An active 1-Year Annual Subscription is required."), status_code=303)

    z_report = db.query(ZonalReport).filter_by(zone_id=zid, report_month=r_month, report_year=r_year).first()
    if not z_report or z_report.status == "submitted":
        return RedirectResponse(url=f"/zonal-reports?month={r_month}&year={r_year}&zone_id={zid}&error=" + quote("Zonal report is locked."), status_code=303)

    zone_churches = db.query(ZoneChurch).filter_by(zone_id=zid).all()
    form_data = await request.form()

    try:
        db.begin_nested()

        # 1. Page 1 Inputs
        p1_data = {}
        import hashlib
        for zc in zone_churches:
            key = hashlib.md5(zc.church_name.encode("utf-8")).hexdigest()
            p1_data[key] = {
                "church_name": zc.church_name,
                "sp_tm": toFloat(form_data.get(f"p1_sp_tm_{key}", 0)),
                "sp_lm": toFloat(form_data.get(f"p1_sp_lm_{key}", 0)),
                "sp_ago": toFloat(form_data.get(f"p1_sp_ago_{key}", 0)),
                "fin_tm": toFloat(form_data.get(f"p1_fin_tm_{key}", 0)),
                "fin_lm": toFloat(form_data.get(f"p1_fin_lm_{key}", 0)),
                "fin_ago": toFloat(form_data.get(f"p1_fin_ago_{key}", 0)),
                "ft": toInt(form_data.get(f"p1_ft_{key}")),
                "pt": toInt(form_data.get(f"p1_pt_{key}")),
                "dc": toInt(form_data.get(f"p1_dc_{key}")),
                "dcn": toInt(form_data.get(f"p1_dcn_{key}")),
                "eld": toInt(form_data.get(f"p1_eld_{key}")),
            }

        # 2. Page 2 Comparism (12 params x dynamic churches)
        p2_data = {}
        for p in range(1, 13):
            p2_data[str(p)] = {}
            for zc in zone_churches:
                key = hashlib.md5(zc.church_name.encode("utf-8")).hexdigest()
                p2_data[str(p)][key] = {
                    "church_name": zc.church_name,
                    "tm": toFloat(form_data.get(f"p2_tm_{p}_{key}", 0)),
                    "lm": toFloat(form_data.get(f"p2_lm_{p}_{key}", 0))
                }

        # 3. Page 3 Inputs (12 params x dynamic churches)
        p3_data = {}
        for p in range(1, 13):
            p3_data[str(p)] = {}
            for zc in zone_churches:
                key = hashlib.md5(zc.church_name.encode("utf-8")).hexdigest()
                p3_data[str(p)][key] = {
                    "church_name": zc.church_name,
                    "val": toFloat(form_data.get(f"p3_val_{p}_{key}", 0))
                }

        # 4. Page 4 Zonal Monthly Summary (12 params)
        p4_data = {}
        for p in range(1, 13):
            p4_data[str(p)] = {
                "tm": toFloat(form_data.get(f"p4_tm_{p}", 0)),
                "lm": toFloat(form_data.get(f"p4_lm_{p}", 0))
            }

        # 5. Zonal Church Planting
        # 5. Section C Church Planting Report
        planting_data = {
            "name": form_data.get("planting_name", ""),
            "address": form_data.get("planting_address", ""),
            "coordinator": form_data.get("planting_coordinator", ""),
            "planting_date": form_data.get("planting_date", ""),
            "attendance": form_data.get("planting_attendance", ""),
            "mother_church": form_data.get("planting_mother_church", ""),
            "pastor_name": form_data.get("planting_pastor_name", ""),
            "phone": form_data.get("planting_phone", "")
        }

        # 6. Page 1 Summary Section B (12 parameters)
        summary_data = {}
        for p in range(1, 13):
            summary_data[str(p)] = {
                "tm": toFloat(form_data.get(f"p1_sum_tm_{p}", 0)),
                "lm": toFloat(form_data.get(f"p1_sum_lm_{p}", 0))
            }

        status = "submitted" if action == "submit" else "draft"

        # Update zonal report row
        z_report.page1_data = p1_data
        z_report.page2_data = p2_data
        z_report.page3_data = p3_data
        z_report.page4_data = p4_data
        z_report.planting_data = planting_data
        z_report.summary_data = summary_data
        z_report.status = status

        db.commit()

        # Send email on submit
        if action == "submit" and uid:
            user = db.query(User).filter(User.id == uid).first()
            if user and user.email:
                zname = db.query(Zone).filter_by(id=zid).first().zone_name
                mname = f"{monthName(r_month)} {r_year}"
                msg = f"Congratulations! Your zonal monthly report for <strong>{zname} Zone</strong> ({mname}) has been successfully <strong>submitted</strong> and locked for review."
                sendAppEmail(db, user.email, user.full_name, f"🎉 Zonal Report Submitted — {zname} ({mname})", msg, f"zonal-reports?month={r_month}&year={r_year}", "View Zonal Report")

        successMsg = "Zonal report submitted successfully! It is now locked." if action == "submit" else "Zonal report draft saved successfully!"
        return RedirectResponse(url=f"/zonal-reports?month={r_month}&year={r_year}&zone_id={zid}&msg=" + quote(successMsg), status_code=303)

    except Exception as e:
        db.rollback()
        return RedirectResponse(url=f"/zonal-reports?month={r_month}&year={r_year}&zone_id={zid}&error=" + quote(f"Error saving zonal report: {str(e)}"), status_code=303)

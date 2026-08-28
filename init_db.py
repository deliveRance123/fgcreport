import sys
from decimal import Decimal
from sqlalchemy.orm import Session
from app.database import engine, Base, SessionLocal
from app.models import DuePercentageSettings, SiteSetting, ChatbotKnowledgeBase

# Default due percentage settings
DUE_ITEMS = [
    # due_key, label, pct_chartered, pct_unchartered, base_field, is_locked
    ('tithes_offerings',        'Tithes and Offerings (a-c)',        10.00, 10.00, 'subtotal_ac',            False),
    ('pastors_welfare',         "Pastor's Welfare (a-c)",             5.00,  5.00, 'subtotal_ac',            False),
    ('project_dev_fund',        'Project Dev. Fund (a-c)',            1.50,  1.50, 'subtotal_ac',            False),
    ('macpherson_uni',          'Macpherson Uni (a-c)',               4.00,  4.00, 'subtotal_ac',            False),
    ('augmentation_fund',       'Augmentation Fund (a-c)',            1.00,  1.00, 'subtotal_ac',            False),
    ('ffs_savings',             'FFS Savings (a-c)',                  3.00,  3.00, 'subtotal_ac',            False),
    ('sunday_school_offering',  'Sunday School Offerings',           30.00, 30.00, 'sunday_school_offerings', False),
    ('missionary_offering',     'Missionary Offerings',              30.00, 30.00, 'missionary_offerings',   False),
    ('love_offering',           'Love Offerings',                    10.00, 10.00, 'love_welfare_offerings', False),
    ('foursquare_tv',           'Foursquare TV (a-c)',                2.00,  2.00, 'subtotal_ac',            False),
    ('third_sunday',            '3rd Sunday Offering',              100.00, 100.00, 'third_sunday_offering',  False),
    # Locked items
    ('straight_love_offering',  'Straight Love Offering',             0.00,  0.00, 'love_welfare_offerings',  True),
    ('pastors_staff_pension_8', "Pastors/Staff Pension Cont. 8%",    8.00,  8.00, 'subtotal_ac',             True),
    ('church_staff_pension_10', "Church Staff Pension Cont. 10%",   10.00, 10.00, 'subtotal_ac',             True),
    # Right column dues
    ('regional_fund',           'Regional Dues',                      0.50,  0.50, 'subtotal_ac',            False),
    ('district_fund',           'District Fund',                      4.00,  4.00, 'subtotal_ac',            False),
    ('district_missionary',     'District Missionary',               15.00, 15.00, 'missionary_offerings',   False),
    ('district_sunday_school',  'District Sunday School',            10.00, 10.00, 'sunday_school_offerings', False),
    ('zonal_fund',              'Zonal Fund',                         2.00,  2.00, 'subtotal_ac',            False),
    ('zonal_missionary',        'Zonal Missionary',                   5.00,  5.00, 'missionary_offerings',   False),
    ('zonal_sunday_school',     'Zonal Sunday School',               10.00, 10.00, 'sunday_school_offerings', False),
    ('life_theo_seminary',      'Life Theological Seminary',          2.00,  2.00, 'subtotal_ac',            False),
]

# Default site settings
SITE_SETTINGS_DEFAULTS = {
    'site_name':          'Foursquare Reports',
    'site_tagline':       'Church & Zonal Reporting System',
    'hero_title':         'Monthly reports, finally in order.',
    'hero_lead':          'Replace the paper financial and spiritual report sheets with one system that calculates dues, tracks attendance, and keeps every month on file — for local churches and zonal offices alike.',
    'strip_item_1':       'Chartered & unchartered churches supported',
    'strip_item_2':       'Works for any zone, any number of churches',
    'strip_item_3':       'Dues calculated automatically, set centrally',
    'strip_item_4':       'Full report history, always on file',
    'paths_title':        'Two kinds of reporting, one system.',
    'paths_subtitle':     'Your church submits its own monthly report. Your zone compares reports across every church under it. Register for the one that applies to you.',
    'footer_org_name':    'Foursquare Report Developed by Realdeli-Tech Solutions',
    'contact_email':      'info@foursquarechurch.org',
    'contact_phone':      '',
    'how_title':          'From paper form to filed report.',
    'payment_enabled':    '0',
    'payment_public_key': '',
    'payment_secret_key': '',
    'monthly_sub_amount': '5000',
    'report_unlock_fee':  '2000',
    'free_trial_months':  '3',
}

# Default chatbot knowledge base Q&As
CHATBOT_KB_DEFAULTS = [
    (
        'How do I create or submit a monthly report?',
        'To create a report, log into your Church or Zonal dashboard. Under "Start a New Report", select the Month and Year, then click "Create Report". Fill in your financial receipts, attendance, and spiritual entries, then click "Save Draft" to work on it later or "Submit Report" to finalize.',
        'create,submit,new,report,how,financial,spiritual,draft'
    ),
    (
        'How are dues calculated?',
        'Dues (such as National Dues, Regional Dues, District Dues, and Zonal Dues) are automatically calculated based on subtotal receipts (a-c) and percentage settings set by the Admin for Chartered vs Unchartered churches.',
        'dues,calculate,percentage,chartered,unchartered,subtotal'
    ),
    (
        'Can I edit a report after submitting it?',
        'Once a report is submitted, it becomes locked / view-only. If you need to make changes, click the "🔓 Pay to Unlock & Edit" button on the report page to unlock it back to draft status.',
        'edit,submitted,unlock,change,locked,pay'
    ),
    (
        'How does the free trial and monthly subscription work?',
        'Every new Church Admin and Zonal Admin automatically receives 3 months of 100% free trial. After the trial ends, you can easily renew your monthly subscription directly via Paystack from your dashboard.',
        'subscription,trial,free,paystack,pay,renewal,month'
    ),
    (
        'How do I contact the Admin or Zonal Superintendent directly?',
        'You can use the Live Chat tab in this widget! Select the Admin or Zonal Superintendent from the recipient list to send a direct WhatsApp-style message on this platform.',
        'contact,admin,superintendent,help,support,chat,message,live'
    )
]

def init_db():
    try:
        print("Connecting to database and creating tables...")
        Base.metadata.create_all(bind=engine)
        print("Tables verified/created successfully.")

        # --- Safe column migrations (checked via inspect to avoid duplicate column errors) ---
        print("Running safe schema migrations...")
        from sqlalchemy import inspect as _inspect, text as _text
        _insp = _inspect(engine)
        if _insp.has_table("due_percentage_audit_log"):
            _existing_cols = [c["name"] for c in _insp.get_columns("due_percentage_audit_log")]
            with engine.connect() as _conn:
                if "action" not in _existing_cols:
                    _conn.execute(_text("ALTER TABLE due_percentage_audit_log ADD COLUMN action VARCHAR(50)"))
                    print("  Migration: Added column 'action' to due_percentage_audit_log")
                if "note" not in _existing_cols:
                    _conn.execute(_text("ALTER TABLE due_percentage_audit_log ADD COLUMN note VARCHAR(255)"))
                    print("  Migration: Added column 'note' to due_percentage_audit_log")
                _conn.commit()

        if _insp.has_table("users"):
            _u_cols = [c["name"] for c in _insp.get_columns("users")]
            with engine.connect() as _conn:
                if "google_id" not in _u_cols:
                    _conn.execute(_text("ALTER TABLE users ADD COLUMN google_id VARCHAR(200)"))
                    print("  Migration: Added column 'google_id' to users")
                if "google_avatar" not in _u_cols:
                    _conn.execute(_text("ALTER TABLE users ADD COLUMN google_avatar VARCHAR(500)"))
                    print("  Migration: Added column 'google_avatar' to users")
                _conn.commit()
        print("Schema migrations complete.")
    except Exception as e:
        print(f"[InitDB] Warning during table creation: {e}")

    db: Session = SessionLocal()
    try:
        # 1. Seed Due Percentage Settings
        print("Checking due percentage settings...")
        for due_key, label, pct_chartered, pct_unchartered, base_field, is_locked in DUE_ITEMS:
            # Chartered
            existing_c = db.query(DuePercentageSettings).filter_by(church_type="chartered", due_key=due_key).first()
            if not existing_c:
                db.add(DuePercentageSettings(
                    church_type="chartered",
                    due_key=due_key,
                    label=label,
                    percentage_value=Decimal(str(pct_chartered)),
                    base_field=base_field,
                    is_locked=is_locked
                ))
            
            # Unchartered
            existing_u = db.query(DuePercentageSettings).filter_by(church_type="unchartered", due_key=due_key).first()
            if not existing_u:
                db.add(DuePercentageSettings(
                    church_type="unchartered",
                    due_key=due_key,
                    label=label,
                    percentage_value=Decimal(str(pct_unchartered)),
                    base_field=base_field,
                    is_locked=is_locked
                ))

        # 2. Seed Site Settings
        print("Checking site settings...")
        for key, val in SITE_SETTINGS_DEFAULTS.items():
            existing = db.query(SiteSetting).filter_by(setting_key=key).first()
            if not existing:
                db.add(SiteSetting(setting_key=key, setting_value=val))

        # 3. Seed Chatbot KB
        print("Checking chatbot knowledge base...")
        for q, a, keywords in CHATBOT_KB_DEFAULTS:
            existing = db.query(ChatbotKnowledgeBase).filter_by(question=q).first()
            if not existing:
                db.add(ChatbotKnowledgeBase(question=q, answer=a, keywords=keywords))

        db.commit()
        print("Database seed completed successfully.")
        
    except Exception as e:
        db.rollback()
        print(f"[InitDB] Warning seeding database ({e}). Will retry on next request.")
    finally:
        db.close()

if __name__ == "__main__":
    init_db()

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
        'To create a report, log in to your Church or Zonal dashboard. Under "Start a New Report", select the Month and Year, then click "Create Report". Fill in all the financial receipts, attendance figures, and spiritual entries. Click "Save Draft" at any time to preserve your work, or "Submit Report" when it is ready to finalize.',
        'create,submit,new,report,how,financial,spiritual,draft,monthly,start'
    ),
    (
        'How are dues calculated?',
        'Dues such as National Dues, Regional Dues, District Dues, and Zonal Dues are automatically calculated based on your Subtotal Receipts (a-c) and the percentage rates set by the Admin. The rates differ for Chartered and Unchartered churches. You do not need to compute them manually — the system does it for you.',
        'dues,calculate,percentage,chartered,unchartered,subtotal,national,regional,district,zonal,rate'
    ),
    (
        'Can I edit a report after submitting it?',
        'Once a report is submitted it becomes locked and view-only. To make corrections, click the "🔓 Unlock & Edit" button on the report page. An unlock fee may apply. After unlocking, the report returns to Draft status and you can edit and re-submit it.',
        'edit,submitted,unlock,change,locked,correction,modify,resubmit'
    ),
    (
        'How do I contact the Admin or Zonal Superintendent?',
        'Use the Live Chat tab inside this widget. Select the Admin or your Zonal Superintendent from the recipient list and type your message. They will receive it instantly on the platform.',
        'contact,admin,superintendent,help,support,chat,message,live,reach,talk'
    ),
    (
        'What are the different types of financial receipts in the church report?',
        'The Church Financial Report is divided into several receipt categories:\n• (a) Tithes\n• (b) Sunday Offerings\n• (c) Building / Welfare Fund\n• Subtotal (a-c) — used for due calculations\n• Sunday School Offerings\n• Missionary Offerings\n• Love / Welfare Offerings\n• 3rd Sunday Offerings\n• Other special offerings\n\nEach category is filled separately and the system totals them automatically.',
        'receipts,financial,tithes,offerings,sunday,building,welfare,missionary,love,special,categories,income'
    ),
    (
        'What is the difference between Chartered and Unchartered churches?',
        'A Chartered Local Church is a fully established Foursquare church that has been officially chartered. An Unchartered Local Church is a new or developing church that has not yet received its charter. The distinction affects the due percentage rates applied to your receipts. Your church type is set at registration and can be updated by the Admin.',
        'chartered,unchartered,type,difference,church,established,new,status'
    ),
    (
        'How do I save a report as a draft?',
        'While filling in your monthly report, click the "Save Draft" button at any point. Your entries will be saved and you can return to complete the report at a later time. A report in Draft status is not yet submitted and can still be edited freely.',
        'draft,save,progress,incomplete,later,partial,continue'
    ),
    (
        'How do I download or print my report as PDF?',
        'After submitting or saving a report, open the report and click the "Download PDF" or "Print Report" button. The system will generate a formatted PDF of your complete monthly report which you can save or print for physical records.',
        'pdf,download,print,export,paper,copy,generate,report'
    ),
    (
        'What spiritual information do I fill in the church report?',
        'The Spiritual Report section includes:\n• Total attendance (adults, youths, children)\n• New converts\n• Baptisms (Water and Holy Spirit)\n• Dedications\n• Weddings\n• Funerals / Burials\n• Home cells / Outreaches\n• Membership figures\n\nAll these figures help the zonal and national leadership track church growth.',
        'spiritual,attendance,converts,baptism,dedication,wedding,funeral,membership,growth,cell,outreach'
    ),
    (
        'How does the Zonal Report work?',
        'The Zonal Admin collects monthly financial and spiritual summaries from all churches under the zone. The zonal report consolidates these figures: total receipts, total dues, attendance across all branches, and other aggregated statistics. The Zone dashboard shows a breakdown per church and allows the Zonal Superintendent to review and submit the combined zonal report.',
        'zonal,zone,report,consolidate,summary,branches,superintendent,aggregate,churches'
    ),
    (
        'How do I register a new church on the portal?',
        'Go to the Register Church page from the homepage. Fill in the Church Name, District, Church Type (Chartered or Unchartered), Church Address, and Pastor details. Then provide the administrator credentials (name, email, password). Once submitted you will receive a confirmation and can log in to your church dashboard.',
        'register,church,new,signup,setup,create account,pastor,district,address'
    ),
    (
        'How do I register a new zone on the portal?',
        'Go to the Register Zone page from the homepage. Enter the Zone Name, list the churches under the zone (one per line), and provide the Zonal Secretary credentials. Once submitted, the zone is created with its church list and the Zonal Admin can log in immediately.',
        'register,zone,new,signup,setup,create,zonal,secretary,churches,list'
    ),
    (
        'What happens if I enter wrong figures in my report?',
        'If the report is still in Draft status, simply go back and correct the figures before submitting. If you have already submitted, you will need to unlock the report first using the "Unlock & Edit" button. After unlocking, edit the figures and re-submit.',
        'wrong,mistake,error,incorrect,fix,figures,change,correction,submitted'
    ),
    (
        'How do I view past reports?',
        'From your Church or Zone dashboard, click on "Report History" or "View Past Reports". You can filter reports by month and year. Each report shows its status (Draft, Submitted, Locked) and can be opened for viewing or downloaded as PDF.',
        'past,history,previous,old,view,records,archive,month,year'
    ),
    (
        'What is the Pastor Welfare due and how is it calculated?',
        'Pastor\'s Welfare is a due deducted from the Subtotal Receipts (a-c). The percentage rate is set by the Admin for both Chartered and Unchartered churches. It is automatically calculated when you fill in your receipts — you do not need to enter it manually.',
        'pastor,welfare,due,percentage,calculated,automatically,subtotal'
    ),
    (
        'What does Subtotal (a-c) mean in the financial report?',
        'Subtotal (a-c) is the sum of the three main receipt categories:\n• (a) Tithes\n• (b) Sunday Offerings\n• (c) Building / Welfare Fund\n\nThis subtotal is the base amount used to calculate most of the church dues such as National Dues, District Dues, Zonal Dues, Pastor\'s Welfare, and others.',
        'subtotal,a-c,tithes,sunday,building,welfare,base,due,calculation'
    ),
    (
        'Can multiple users use the same church account?',
        'Each church has one administrator account (the Church Admin). That account is used to fill and submit the monthly reports. If you need to change the registered administrator, please contact the Zonal Superintendent or the platform Admin to update the account details.',
        'multiple,users,account,access,administrator,login,shared,church,change'
    ),
    (
        'What is the 3rd Sunday Offering and how is it reported?',
        'The 3rd Sunday Offering is a special offering collected specifically on the third Sunday of every month. It is reported separately in the financial receipts section of the Church Monthly Report. The full amount (100%) is remitted as due.',
        '3rd,third,sunday,offering,special,remit,monthly,due,hundred'
    ),
    (
        'How do I reset or change my password?',
        'To change your password, log in and go to your Profile settings on the dashboard. If you have forgotten your password and cannot log in, please contact your Zonal Superintendent or the platform Admin via the Live Chat to have your credentials reset.',
        'password,reset,change,forgot,credentials,login,access,profile'
    ),
    (
        'What is the Macpherson University due?',
        'Macpherson University due is a percentage deducted from the Subtotal Receipts (a-c). It is a contribution by all Foursquare churches toward the development and running of Macpherson University. The rate is set centrally by the Admin.',
        'macpherson,university,due,percentage,contribution,foursquare'
    ),
    (
        'What is the FFS Savings due?',
        'FFS Savings (Foursquare Financial Services Savings) is a percentage deducted from the Subtotal Receipts (a-c). It is a savings contribution made by each church to the Foursquare Financial Services scheme. The rate is set by the Admin.',
        'ffs,savings,foursquare,financial,services,due,percentage'
    ),
    (
        'What is the Augmentation Fund?',
        'The Augmentation Fund is a due deducted from the Subtotal Receipts (a-c). It is used to augment the welfare and remuneration of pastors within the district or zone. The percentage is set by the Admin.',
        'augmentation,fund,due,pastor,welfare,remuneration,district'
    ),
    (
        'What is the Foursquare TV due?',
        'Foursquare TV is a percentage contribution from the Subtotal Receipts (a-c) dedicated to supporting the Foursquare Gospel Church television ministry and media outreach. The rate is centrally set by the Admin.',
        'foursquare,tv,television,media,outreach,due,percentage,contribution'
    ),
    (
        'How do I update my church or zone information?',
        'Church and Zone information such as address, pastor name, district, and church type can be updated from your dashboard under Profile or Settings. If you need to change critical details like the church name or type, contact the platform Admin.',
        'update,church,information,address,pastor,district,profile,settings,change'
    ),
    (
        'Who can see my church reports?',
        'Your submitted reports are visible to your Church Admin (you), your Zonal Superintendent, and the platform Super Admin. Reports are confidential and not shared outside the platform. The Zonal Admin sees an aggregated summary of all churches in the zone.',
        'see,view,access,reports,visible,confidential,zone,admin,superintendent,privacy'
    ),
    (
        'What is the Project Development Fund?',
        'The Project Development Fund is a due deducted from the Subtotal Receipts (a-c). It is used to fund infrastructure, property, and development projects within the Foursquare church organization. The percentage rate is set by the Admin.',
        'project,development,fund,due,infrastructure,property,percentage'
    ),
    (
        'What is the Regional Fund due?',
        'The Regional Fund due is a percentage deducted from the Subtotal Receipts (a-c) and remitted to the Regional office. It supports regional administration and activities. The rate is set by the Admin.',
        'regional,fund,due,percentage,remit,administration,office'
    ),
    (
        'How do I log out of the portal?',
        'To log out, click on your profile name or avatar on the top right corner of your dashboard, then select "Log Out". Your session will be ended securely. Always log out when using a shared device.',
        'logout,log out,sign out,end session,exit,close,account'
    ),
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
        # Remove outdated subscription entry if it exists
        old_sub = db.query(ChatbotKnowledgeBase).filter(
            ChatbotKnowledgeBase.question.ilike("%subscription%")
        ).all()
        for row in old_sub:
            db.delete(row)

        for q, a, keywords in CHATBOT_KB_DEFAULTS:
            existing = db.query(ChatbotKnowledgeBase).filter_by(question=q).first()
            if existing:
                # Update answer and keywords if they changed
                if existing.answer != a or existing.keywords != keywords:
                    existing.answer = a
                    existing.keywords = keywords
            else:
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

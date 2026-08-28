from datetime import datetime
from sqlalchemy import Column, Integer, String, Text, Boolean, Numeric, Date, DateTime, ForeignKey, UniqueConstraint, JSON
from sqlalchemy.orm import relationship
from app.database import Base

class User(Base):
    __tablename__ = "users"
    
    id = Column(Integer, primary_key=True, index=True)
    full_name = Column(String(200), nullable=False)
    email = Column(String(200), nullable=False, unique=True, index=True)
    phone = Column(String(30), nullable=False, default="")
    password_hash = Column(String(255), nullable=False, default="")
    role = Column(String(50), nullable=False)  # 'super_admin', 'zonal_admin', 'church_admin'
    status = Column(String(50), nullable=False, default="pending")  # 'active', 'pending', 'suspended'
    profile_photo = Column(String(300), nullable=True)
    bio = Column(Text, nullable=True)
    last_active = Column(DateTime, nullable=True)
    google_id = Column(String(200), nullable=True, unique=True, index=True)   # Google OAuth UID
    google_avatar = Column(String(500), nullable=True)                         # Google profile photo URL
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)
    updated_at = Column(DateTime, nullable=False, default=datetime.utcnow, onupdate=datetime.utcnow)

    # Relationships
    created_zones = relationship("Zone", back_populates="creator")
    created_churches = relationship("Church", back_populates="creator")
    payments = relationship("UserPayment", back_populates="user")
    sent_messages = relationship("UserMessage", foreign_keys="UserMessage.sender_id", back_populates="sender")
    received_messages = relationship("UserMessage", foreign_keys="UserMessage.receiver_id", back_populates="receiver")


class Zone(Base):
    __tablename__ = "zones"
    
    id = Column(Integer, primary_key=True, index=True)
    zone_name = Column(String(200), nullable=False)
    created_by = Column(Integer, ForeignKey("users.id", ondelete="RESTRICT"), nullable=False)
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)
    updated_at = Column(DateTime, nullable=False, default=datetime.utcnow, onupdate=datetime.utcnow)

    # Relationships
    creator = relationship("User", back_populates="created_zones")
    churches = relationship("Church", back_populates="zone")
    zone_churches = relationship("ZoneChurch", back_populates="zone", cascade="all, delete-orphan")
    reports = relationship("ZonalReport", back_populates="zone")


class Church(Base):
    __tablename__ = "churches"
    
    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(200), nullable=False)
    address = Column(Text, nullable=False, default="")
    district = Column(String(100), nullable=False, default="")
    pastor_name = Column(String(200), nullable=False, default="")
    pastor_address = Column(Text, nullable=False, default="")
    church_type = Column(String(50), nullable=False)  # 'chartered', 'unchartered'
    zone_id = Column(Integer, ForeignKey("zones.id", ondelete="SET NULL"), nullable=True)
    created_by = Column(Integer, ForeignKey("users.id", ondelete="RESTRICT"), nullable=False)
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)
    updated_at = Column(DateTime, nullable=False, default=datetime.utcnow, onupdate=datetime.utcnow)

    # Relationships
    creator = relationship("User", back_populates="created_churches")
    zone = relationship("Zone", back_populates="churches")
    financial_reports = relationship("ChurchFinancialReport", back_populates="church")
    spiritual_reports = relationship("ChurchSpiritualReport", back_populates="church")
    expense_items = relationship("ChurchExpenseItem", back_populates="church", cascade="all, delete-orphan")


class ZoneChurch(Base):
    __tablename__ = "zone_churches"
    
    id = Column(Integer, primary_key=True, index=True)
    zone_id = Column(Integer, ForeignKey("zones.id", ondelete="CASCADE"), nullable=False)
    church_name = Column(String(200), nullable=False)
    display_order = Column(Integer, nullable=False, default=0)
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)

    # Relationships
    zone = relationship("Zone", back_populates="zone_churches")


class DuePercentageSettings(Base):
    __tablename__ = "due_percentage_settings"
    
    id = Column(Integer, primary_key=True, index=True)
    church_type = Column(String(50), nullable=False)  # 'chartered', 'unchartered'
    due_key = Column(String(100), nullable=False)
    label = Column(String(300), nullable=False)
    percentage_value = Column(Numeric(8, 4), nullable=False, default=0.0000)
    base_field = Column(String(100), nullable=False)
    is_locked = Column(Boolean, nullable=False, default=False)
    updated_by = Column(Integer, ForeignKey("users.id", ondelete="SET NULL"), nullable=True)
    updated_at = Column(DateTime, nullable=True)

    __table_args__ = (
        UniqueConstraint("church_type", "due_key", name="uq_type_key"),
    )


class DuePercentageAuditLog(Base):
    __tablename__ = "due_percentage_audit_log"
    
    id = Column(Integer, primary_key=True, index=True)
    due_setting_id = Column(Integer, ForeignKey("due_percentage_settings.id", ondelete="CASCADE"), nullable=False)
    old_value = Column(Numeric(8, 4), nullable=False)
    new_value = Column(Numeric(8, 4), nullable=False)
    changed_by = Column(Integer, ForeignKey("users.id", ondelete="RESTRICT"), nullable=False)
    changed_at = Column(DateTime, nullable=False, default=datetime.utcnow)
    action = Column(String(50), nullable=True)   # e.g. 'rate_change', 'lock_change'
    note = Column(String(255), nullable=True)    # optional description of the change


class ChurchFinancialReport(Base):
    __tablename__ = "church_financial_reports"
    
    id = Column(Integer, primary_key=True, index=True)
    church_id = Column(Integer, ForeignKey("churches.id", ondelete="RESTRICT"), nullable=False)
    report_month = Column(Integer, nullable=False)  # 1-12
    report_year = Column(Integer, nullable=False)
    status = Column(String(50), nullable=False, default="draft")  # 'draft', 'submitted'
    
    # Financial receipts
    general_tithe = Column(Numeric(12, 2), nullable=False, default=0.00)
    minister_tithe = Column(Numeric(12, 2), nullable=False, default=0.00)
    worship_offerings = Column(Numeric(12, 2), nullable=False, default=0.00)
    subtotal_ac = Column(Numeric(12, 2), nullable=False, default=0.00)
    missionary_offerings = Column(Numeric(12, 2), nullable=False, default=0.00)
    midweek_offerings = Column(Numeric(12, 2), nullable=False, default=0.00)
    sunday_school_offerings = Column(Numeric(12, 2), nullable=False, default=0.00)
    thanksgiving_offerings = Column(Numeric(12, 2), nullable=False, default=0.00)
    love_welfare_offerings = Column(Numeric(12, 2), nullable=False, default=0.00)
    building_pledge_offerings = Column(Numeric(12, 2), nullable=False, default=0.00)
    church_pioneering_receipts = Column(Numeric(12, 2), nullable=False, default=0.00)
    donation_other_churches = Column(Numeric(12, 2), nullable=False, default=0.00)
    other_pledges = Column(Numeric(12, 2), nullable=False, default=0.00)
    seed_faith = Column(Numeric(12, 2), nullable=False, default=0.00)
    staff_loans_repayment = Column(Numeric(12, 2), nullable=False, default=0.00)
    loan_cash_deposit = Column(Numeric(12, 2), nullable=False, default=0.00)
    pastor_pension_5pct = Column(Numeric(12, 2), nullable=False, default=0.00)
    national_grant = Column(Numeric(12, 2), nullable=False, default=0.00)
    convention_pledges = Column(Numeric(12, 2), nullable=False, default=0.00)
    special_projects = Column(Numeric(12, 2), nullable=False, default=0.00)
    decade_multiplication_receipts = Column(Numeric(12, 2), nullable=False, default=0.00)
    third_sunday_offering = Column(Numeric(12, 2), nullable=False, default=0.00)
    total_receipts = Column(Numeric(12, 2), nullable=False, default=0.00)
    
    # Calculated National Dues
    national_dues_total = Column(Numeric(12, 2), nullable=False, default=0.00)
    
    # Right Column Dues (calculated)
    regional_dues = Column(Numeric(12, 2), nullable=False, default=0.00)
    district_dues = Column(Numeric(12, 2), nullable=False, default=0.00)
    zonal_dues = Column(Numeric(12, 2), nullable=False, default=0.00)
    life_dues = Column(Numeric(12, 2), nullable=False, default=0.00)
    
    # Locked settings
    straight_love_offering = Column(Numeric(12, 2), nullable=False, default=0.00)
    pastors_staff_pension_8 = Column(Numeric(12, 2), nullable=False, default=0.00)
    church_staff_pension_10 = Column(Numeric(12, 2), nullable=False, default=0.00)
    
    # Summary sums
    payable = Column(Numeric(12, 2), nullable=False, default=0.00)
    total_emoluments = Column(Numeric(12, 2), nullable=False, default=0.00)
    total_expenses_block = Column(Numeric(12, 2), nullable=False, default=0.00)
    total_payment = Column(Numeric(12, 2), nullable=False, default=0.00)
    less_total_payment = Column(Numeric(12, 2), nullable=False, default=0.00)
    balance_surplus_deficit = Column(Numeric(12, 2), nullable=False, default=0.00)
    
    # Manual fields
    balance_last_month = Column(Numeric(12, 2), nullable=False, default=0.00)
    balance_this_month = Column(Numeric(12, 2), nullable=False, default=0.00)
    cash_in_hand_bank = Column(Numeric(12, 2), nullable=False, default=0.00)
    investment = Column(Numeric(12, 2), nullable=False, default=0.00)
    total_balance = Column(Numeric(12, 2), nullable=False, default=0.00)
    outstanding_loan = Column(Numeric(12, 2), nullable=False, default=0.00)
    
    special_projects_details = Column(Text, nullable=True)
    due_rates_snapshot = Column(JSON, nullable=True)
    
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)
    updated_at = Column(DateTime, nullable=False, default=datetime.utcnow, onupdate=datetime.utcnow)

    # Relationships
    church = relationship("Church", back_populates="financial_reports")
    expense_items = relationship("ChurchExpenseItem", back_populates="report", cascade="all, delete-orphan")

    __table_args__ = (
        UniqueConstraint("church_id", "report_month", "report_year", name="uq_church_month_year"),
    )


class ChurchExpenseItem(Base):
    __tablename__ = "church_expense_items"
    
    id = Column(Integer, primary_key=True, index=True)
    church_id = Column(Integer, ForeignKey("churches.id", ondelete="CASCADE"), nullable=False)
    report_id = Column(Integer, ForeignKey("church_financial_reports.id", ondelete="CASCADE"), nullable=True)
    item_key = Column(String(100), nullable=False)
    label = Column(String(300), nullable=False)
    amount = Column(Numeric(12, 2), nullable=False, default=0.00)
    is_custom = Column(Boolean, nullable=False, default=False)
    display_order = Column(Integer, nullable=False, default=0)
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)
    updated_at = Column(DateTime, nullable=False, default=datetime.utcnow, onupdate=datetime.utcnow)

    # Relationships
    church = relationship("Church", back_populates="expense_items")
    report = relationship("ChurchFinancialReport", back_populates="expense_items")


class ChurchSpiritualReport(Base):
    __tablename__ = "church_spiritual_reports"
    
    id = Column(Integer, primary_key=True, index=True)
    church_id = Column(Integer, ForeignKey("churches.id", ondelete="RESTRICT"), nullable=False)
    report_month = Column(Integer, nullable=False)
    report_year = Column(Integer, nullable=False)
    status = Column(String(50), nullable=False, default="draft")
    
    # Attendance fields
    pre_sun_school_children = Column(Integer, nullable=False, default=0)
    pre_sun_school_adults = Column(Integer, nullable=False, default=0)
    pre_sun_school_total = Column(Integer, nullable=False, default=0)
    
    sun_school_children = Column(Integer, nullable=False, default=0)
    sun_school_adults = Column(Integer, nullable=False, default=0)
    sun_school_total = Column(Integer, nullable=False, default=0)
    
    sun_worship_children = Column(Integer, nullable=False, default=0)
    sun_worship_adults = Column(Integer, nullable=False, default=0)
    sun_worship_total = Column(Integer, nullable=False, default=0)
    
    house_fellowship_children = Column(Integer, nullable=False, default=0)
    house_fellowship_adults = Column(Integer, nullable=False, default=0)
    house_fellowship_total = Column(Integer, nullable=False, default=0)
    
    bible_study_children = Column(Integer, nullable=False, default=0)
    bible_study_adults = Column(Integer, nullable=False, default=0)
    bible_study_total = Column(Integer, nullable=False, default=0)
    
    prayer_meeting_children = Column(Integer, nullable=False, default=0)
    prayer_meeting_adults = Column(Integer, nullable=False, default=0)
    prayer_meeting_total = Column(Integer, nullable=False, default=0)
    
    # Decisions
    total_new_comers = Column(Integer, nullable=False, default=0)
    total_decision_christ = Column(Integer, nullable=False, default=0)
    total_water_baptism = Column(Integer, nullable=False, default=0)
    total_holy_spirit_baptism = Column(Integer, nullable=False, default=0)
    total_healings = Column(Integer, nullable=False, default=0)
    total_house_fellowship_centres = Column(Integer, nullable=False, default=0)
    
    # Intakes
    intake_above_18 = Column(Integer, nullable=False, default=0)
    intake_under_18 = Column(Integer, nullable=False, default=0)
    intake_total = Column(Integer, nullable=False, default=0)
    
    # Withdrawals
    withdrawn_above_18 = Column(Integer, nullable=False, default=0)
    withdrawn_under_18 = Column(Integer, nullable=False, default=0)
    withdrawn_total = Column(Integer, nullable=False, default=0)
    
    # Membership summary
    membership_above_18 = Column(Integer, nullable=False, default=0)
    membership_under_18 = Column(Integer, nullable=False, default=0)
    membership_total = Column(Integer, nullable=False, default=0)
    
    credential_workers_data = Column(JSON, nullable=True)
    report_date = Column(Date, nullable=True)
    
    # Signatures
    pastor_signature_name = Column(String(200), nullable=False, default="")
    treasurer_signature_name = Column(String(200), nullable=False, default="")
    secretary_signature_name = Column(String(200), nullable=False, default="")
    
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)
    updated_at = Column(DateTime, nullable=False, default=datetime.utcnow, onupdate=datetime.utcnow)

    # Relationships
    church = relationship("Church", back_populates="spiritual_reports")

    __table_args__ = (
        UniqueConstraint("church_id", "report_month", "report_year", name="uq_church_spiritual_month_year"),
    )


class ZonalReport(Base):
    __tablename__ = "zonal_reports"
    
    id = Column(Integer, primary_key=True, index=True)
    zone_id = Column(Integer, ForeignKey("zones.id", ondelete="RESTRICT"), nullable=False)
    report_month = Column(Integer, nullable=False)
    report_year = Column(Integer, nullable=False)
    status = Column(String(50), nullable=False, default="draft")
    
    page1_data = Column(JSON, nullable=True)
    page2_data = Column(JSON, nullable=True)
    page3_data = Column(JSON, nullable=True)
    page4_data = Column(JSON, nullable=True)
    planting_data = Column(JSON, nullable=True)
    summary_data = Column(JSON, nullable=True)
    
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)
    updated_at = Column(DateTime, nullable=False, default=datetime.utcnow, onupdate=datetime.utcnow)

    # Relationships
    zone = relationship("Zone", back_populates="reports")

    __table_args__ = (
        UniqueConstraint("zone_id", "report_month", "report_year", name="uq_zone_month_year"),
    )


class SiteSetting(Base):
    __tablename__ = "site_settings"
    
    id = Column(Integer, primary_key=True, index=True)
    setting_key = Column(String(100), nullable=False, unique=True)
    setting_value = Column(Text, nullable=False, default="")
    updated_by = Column(Integer, ForeignKey("users.id", ondelete="SET NULL"), nullable=True)
    updated_at = Column(DateTime, nullable=True)


class UserPayment(Base):
    __tablename__ = "user_payments"
    
    id = Column(Integer, primary_key=True, index=True)
    user_id = Column(Integer, ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    report_id = Column(Integer, nullable=True)
    report_type = Column(String(50), nullable=True, default="church")
    payment_type = Column(String(50), nullable=False, default="subscription")  # 'subscription', 'report_unlock'
    amount = Column(Numeric(12, 2), nullable=False, default=0.00)
    reference = Column(String(100), nullable=False, unique=True, index=True)
    status = Column(String(50), nullable=False, default="pending")  # 'pending', 'success', 'failed'
    expires_at = Column(DateTime, nullable=True)
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)

    # Relationships
    user = relationship("User", back_populates="payments")


class HeroVideo(Base):
    __tablename__ = "hero_videos"
    
    id = Column(Integer, primary_key=True, index=True)
    video_path = Column(String(255), nullable=False)
    is_active = Column(Boolean, nullable=False, default=True)
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)


class HeroShowcaseVideo(Base):
    __tablename__ = "hero_showcase_videos"
    
    id = Column(Integer, primary_key=True, index=True)
    video_path = Column(String(255), nullable=False)
    is_active = Column(Boolean, nullable=False, default=True)
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)


class ChatbotKnowledgeBase(Base):
    __tablename__ = "chatbot_knowledge_base"
    
    id = Column(Integer, primary_key=True, index=True)
    question = Column(String(300), nullable=False)
    answer = Column(Text, nullable=False)
    keywords = Column(String(300), nullable=False, default="")
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)


class UserMessage(Base):
    __tablename__ = "user_messages"
    
    id = Column(Integer, primary_key=True, index=True)
    sender_id = Column(Integer, ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    receiver_id = Column(Integer, ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    message = Column(Text, nullable=False)
    is_read = Column(Boolean, nullable=False, default=False)
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)

    # Relationships
    sender = relationship("User", foreign_keys=[sender_id], back_populates="sent_messages")
    receiver = relationship("User", foreign_keys=[receiver_id], back_populates="received_messages")


class Notification(Base):
    __tablename__ = "notifications"
    
    id = Column(Integer, primary_key=True, index=True)
    user_id = Column(Integer, ForeignKey("users.id", ondelete="CASCADE"), nullable=True)
    role_target = Column(String(50), nullable=True)
    title = Column(String(200), nullable=False)
    message = Column(Text, nullable=False)
    link = Column(String(300), nullable=True, default="")
    category = Column(String(50), nullable=False, default="info")
    is_read = Column(Boolean, nullable=False, default=False)
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)

    user = relationship("User", foreign_keys=[user_id])


class PasswordResetToken(Base):
    __tablename__ = "password_reset_tokens"
    
    id = Column(Integer, primary_key=True, index=True)
    user_id = Column(Integer, ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    token = Column(String(128), unique=True, index=True, nullable=False)
    otp_code = Column(String(10), nullable=False)
    expires_at = Column(DateTime, nullable=False)
    used = Column(Boolean, nullable=False, default=False)
    created_at = Column(DateTime, nullable=False, default=datetime.utcnow)

    user = relationship("User", foreign_keys=[user_id])


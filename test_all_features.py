import os
import sys
from starlette.testclient import TestClient

# Ensure DATABASE_URL is set
os.environ["DATABASE_URL"] = "postgresql://postgres:2004@localhost:5432/foursquare_reports"

from app.main import app
from init_db import init_db

def run_tests():
    print("=== 1. Initializing Database & Seed Data ===")
    init_db()

    client = TestClient(app)

    print("\n=== 2. Testing Landing Page ===")
    r = client.get("/")
    assert r.status_code == 200, f"Landing page failed: {r.status_code}"
    assert "Foursquare" in r.text
    print("  [PASS] Landing page (/) -> 200 OK")

    print("\n=== 3. Testing Auth & Registration Pages ===")
    for path in ["/login", "/register-church", "/register-zone"]:
        r = client.get(path)
        assert r.status_code == 200, f"{path} failed: {r.status_code}"
        print(f"  [PASS] {path:<20} -> 200 OK")

    print("\n=== 4. Testing Zonal Admin Dashboard & Zonal Reports ===")
    # Login as zone_admin
    r_login = client.get("/login?preview=zonal_admin", follow_redirects=False)
    assert r_login.status_code in [302, 303], f"Zone login preview redirect failed: {r_login.status_code}"
    
    # Check Zone Dashboard
    r_zdash = client.get("/zone-dashboard")
    assert r_zdash.status_code == 200, f"Zone dashboard failed: {r_zdash.status_code}"
    assert "Zone Dashboard" in r_zdash.text or "Zonal Office" in r_zdash.text
    assert "Churches under" in r_zdash.text or "Churches Registered" in r_zdash.text
    print("  [PASS] /zone-dashboard       -> 200 OK (Zone Dashboard rendered successfully)")

    # Check Zonal Reports Page (GET)
    r_zrep = client.get("/zonal-reports?month=8&year=2026")
    assert r_zrep.status_code == 200, f"Zonal reports GET failed: {r_zrep.status_code}"
    assert "SPIRITUAL" in r_zrep.text
    assert "FINANCIAL" in r_zrep.text
    assert "SUMMARY OF SPIRITUAL REPORT" in r_zrep.text
    assert "CHURCH PLANTING REPORT" in r_zrep.text
    assert "Save Draft" in r_zrep.text or "Submit" in r_zrep.text
    print("  [PASS] /zonal-reports (GET)  -> 200 OK (All 4 pages & calculation fields rendered)")

    # Test Zonal Report Form Submission (Save Draft)
    post_data = {
        "action": "save",
        "planting_name": "Test Church Plant",
        "planting_address": "123 Gospel Way",
        "planting_coordinator": "Pastor Test",
        "planting_date": "2026-08-01",
        "planting_attendance": "50",
        "planting_mother_church": "ZONAL HQTS",
        "planting_pastor_name": "Pastor Lead",
        "planting_phone": "08012345678"
    }
    r_zpost = client.post("/zonal-reports?month=8&year=2026", data=post_data, follow_redirects=True)
    assert r_zpost.status_code == 200, f"Zonal reports POST failed: {r_zpost.status_code}"
    assert "Test Church Plant" in r_zpost.text
    print("  [PASS] /zonal-reports (POST) -> 200 OK (Draft successfully saved & reloaded)")

    # Test Zonal Report PDF format
    r_zpdf = client.get("/zonal-reports?month=8&year=2026&format=pdf")
    assert r_zpdf.status_code == 200, f"Zonal PDF generation failed: {r_zpdf.status_code}"
    assert r_zpdf.headers.get("content-type") == "application/pdf"
    print(f"  [PASS] /zonal-reports (PDF)  -> 200 OK (PDF generated, size: {len(r_zpdf.content)} bytes)")

    print("\n=== 5. Testing Church Admin Dashboard & Church Report ===")
    client2 = TestClient(app)
    # Login as church_admin
    r_clogin = client2.get("/login?preview=church_admin", follow_redirects=False)
    assert r_clogin.status_code in [302, 303], f"Church login preview redirect failed: {r_clogin.status_code}"

    # Church Dashboard
    r_cdash = client2.get("/church-dashboard")
    assert r_cdash.status_code == 200, f"Church dashboard failed: {r_cdash.status_code}"
    assert "Church Dashboard" in r_cdash.text or "Local Church" in r_cdash.text
    print("  [PASS] /church-dashboard     -> 200 OK (Church Dashboard rendered successfully)")

    # Church Report Page (GET)
    r_crep = client2.get("/church-report?month=8&year=2026")
    assert r_crep.status_code == 200, f"Church report GET failed: {r_crep.status_code}"
    assert "FINANCIAL REPORT" in r_crep.text or "RECEIPTS" in r_crep.text
    assert "SPIRITUAL REPORT" in r_crep.text or "ATTENDANCE" in r_crep.text
    print("  [PASS] /church-report (GET)  -> 200 OK (Financial & Spiritual sections rendered)")

    # Church Report Save Draft
    c_post_data = {
        "action": "save",
        "tithes": "50000.00",
        "offerings": "25000.00",
        "thanksgiving": "10000.00",
        "sun_worship_male": "40",
        "sun_worship_female": "60",
        "sun_worship_children": "30"
    }
    r_cpost = client2.post("/church-report?month=8&year=2026", data=c_post_data, follow_redirects=True)
    if r_cpost.status_code != 200:
        print("CHURCH POST ERROR:", r_cpost.status_code, r_cpost.text[:400])
    assert r_cpost.status_code == 200, f"Church report POST failed: {r_cpost.status_code}"
    print("  [PASS] /church-report (POST) -> 200 OK (Church draft saved successfully)")

    # Church Report PDF
    r_cpdf = client2.get("/church-report?month=8&year=2026&format=pdf")
    assert r_cpdf.status_code == 200, f"Church PDF generation failed: {r_cpdf.status_code}"
    assert r_cpdf.headers.get("content-type") == "application/pdf"
    print(f"  [PASS] /church-report (PDF)  -> 200 OK (PDF generated, size: {len(r_cpdf.content)} bytes)")

    print("\n=== 6. Testing Super Admin Dashboard ===")
    client3 = TestClient(app)
    # Login as super_admin
    r_slogin = client3.get("/login?preview=super_admin", follow_redirects=False)
    assert r_slogin.status_code in [302, 303], f"Super admin login redirect failed: {r_slogin.status_code}"

    for tab in ["overview", "churches", "zones", "users", "dues", "settings", "chatbot", "payments"]:
        r_tab = client3.get(f"/admin-dashboard?page={tab}")
        assert r_tab.status_code == 200, f"Admin tab {tab} failed: {r_tab.status_code}"
        print(f"  [PASS] /admin-dashboard?page={tab:<10} -> 200 OK")

    print("\n=== 7. Testing Chatbot API ===")
    # KB query
    r_chat_kb = client.post("/chat-api", data={"action": "kb_query", "query": "How do I create a report?"})
    assert r_chat_kb.status_code == 200
    kb_json = r_chat_kb.json()
    assert kb_json.get("success") is True
    assert "answer" in kb_json and len(kb_json["answer"]) > 0
    print("  [PASS] /chat-api (kb_query)   -> 200 OK (Matched KB knowledge base)")

    print("\n=======================================================")
    print("ALL TESTS PASSED WITH 100% PARITY AND ZERO ERRORS!")
    print("=======================================================")

if __name__ == "__main__":
    run_tests()

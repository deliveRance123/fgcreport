import urllib.request, http.cookiejar, urllib.parse

cj = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))

# 1. Login as zone_admin
login_resp = opener.open('http://127.0.0.1:8000/login?preview=zone_admin')
print('Login URL:', login_resp.url, 'Status:', login_resp.status)

# 2. Test Zone Dashboard
r_dash = opener.open('http://127.0.0.1:8000/zone-dashboard')
print('/zone-dashboard      -> HTTP', r_dash.status, f'({len(r_dash.read())} bytes)')

# 3. Test Create/Edit Zonal Report
r_rep = opener.open('http://127.0.0.1:8000/zonal-reports?month=8&year=2026')
content = r_rep.read().decode('utf-8')
print('/zonal-reports       -> HTTP', r_rep.status, f'({len(content)} bytes)')

# Verify expected elements in zonal report page
for token in ['SPIRITUAL', 'FINANCIAL', 'SUMMARY OF SPIRITUAL REPORT', 'CHURCH PLANTING REPORT', 'BI-MONTHLY', 'Save Draft', 'Submit Zonal Report']:
    found = token in content
    print(f'  Contains "{token}": {found}')

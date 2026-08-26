import urllib.request, urllib.parse, http.cookiejar

def test_session(preview_role, pages_to_test):
    cj = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    
    # 1. Login via preview bypass
    login_url = f'http://127.0.0.1:8000/login?preview={preview_role}'
    resp = opener.open(login_url)
    print(f'=== Testing role [{preview_role}] ===')
    print(f'Login status: {resp.status}, final URL: {resp.url}')
    
    # 2. Test each page
    for p in pages_to_test:
        url = f'http://127.0.0.1:8000{p}'
        try:
            r = opener.open(url)
            html = r.read().decode('utf-8', errors='replace')
            print(f'  {p:<35} -> HTTP {r.status} (Length: {len(html)} bytes)')
        except urllib.error.HTTPError as e:
            err_body = e.read().decode('utf-8', errors='replace')
            print(f'  {p:<35} -> ERROR {e.code} : {err_body[:200]}')
        except Exception as e:
            print(f'  {p:<35} -> EXCEPTION {e}')

test_session('church_admin', ['/church-dashboard', '/church-report?month=8&year=2026'])
test_session('zone_admin', ['/zone-dashboard', '/zonal-reports?month=8&year=2026'])
test_session('super_admin', ['/admin-dashboard', '/admin-dashboard?page=dues', '/admin-dashboard?page=users', '/admin-dashboard?page=settings'])

"""
Fix Starlette 1.x TemplateResponse signature across all route files.

Old (Starlette 0.x):  templates.TemplateResponse("name.html", {"request": request, "k": v})
New (Starlette 1.x):  templates.TemplateResponse(request, "name.html", {"k": v})
"""
import os, re

base = r'c:\xampp\htdocs\fgc_report_web'
routes_dir = os.path.join(base, 'app', 'routes')
files = [os.path.join(routes_dir, f) for f in os.listdir(routes_dir) if f.endswith('.py')]
files.append(os.path.join(base, 'app', 'main.py'))

# Regex that matches multi-line TemplateResponse calls (old style)
# Captures: template name, and the full context dict content
PATTERN = re.compile(
    r'templates\.TemplateResponse\(\s*'     # open call
    r'("[\w\-\.]+"|\'[\w\-\.]+\')'         # group 1: template name (quoted)
    r'\s*,\s*\{'                            # comma + opening {
    r'(.*?)'                                # group 2: context dict contents
    r'\}'                                   # closing }
    r'\s*\)',                               # closing )
    re.DOTALL
)

def fix_template_response(m):
    name = m.group(1)
    ctx_body = m.group(2)
    
    # Remove "request": request from the context
    # Handle both: "request": request,  and  "request": request (last item)
    ctx_cleaned = re.sub(r'\s*["\']request["\']\s*:\s*request\s*,?\s*', '', ctx_body)
    ctx_cleaned = ctx_cleaned.strip().strip(',').strip()
    
    if ctx_cleaned:
        return f'templates.TemplateResponse(request, {name}, {{{ctx_cleaned}}})'
    else:
        return f'templates.TemplateResponse(request, {name}, {{}})'

changed = []
for filepath in files:
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    new_content = PATTERN.sub(fix_template_response, content)
    
    if new_content != content:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(new_content)
        changed.append(os.path.basename(filepath))
        print(f'[UPDATED] {os.path.basename(filepath)}')
    else:
        print(f'[NO CHANGE] {os.path.basename(filepath)}')

print(f'\nDone. Updated {len(changed)} files: {changed}')

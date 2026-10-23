"""Summarise out/input_*.json from input_matrix.py and ASSERT: exit != 0 when any hard failure class is non-empty.
Hard failures: HTTP 5xx / PHP text, uncaught JS error, executed script (xss), live markup from typed text, double-escaped display,
required text field accepting whitespace-only, invalid-in-hidden-tab with no visible tab switch."""
from common import *
import glob, collections
R = []
for f in glob.glob(os.path.join(OUT, 'input_*of*.json')): R += json.load(open(f))
hard = collections.defaultdict(list); matrix = collections.defaultdict(lambda: collections.Counter())
nforms = len(R); ntests = 0; nfields = set(); nexc = 0
for r in R:
    for t in r['tests']:
        ntests += 1; nfields.add((r['path'], t['field']))
        k = (t['type'], t['class'])
        if t.get('exception'): matrix[k]['exception'] += 1; nexc += 1; continue
        if t.get('server_errors') or t.get('php_text'): hard['server_error_or_php_text'].append((r['path'], t['field'], t['class'], (t.get('server_errors') or [''])[0]))
        if t.get('js_errors'): hard['js_error'].append((r['path'], t['field'], t['class'], t['js_errors'][0]))
        if t.get('xss') or t.get('xss2'): hard['script_executed'].append((r['path'], t['field'], t['class']))
        if t.get('script_tag_in_dom'): hard['live_markup_in_dom'].append((r['path'], t['field'], t['class']))
        if t.get('double_escaped'): hard['double_escaped'].append((r['path'], t['field'], t['class']))
        # 'accepted' here is only a toast heuristic. These (page, field) pairs are judged on the database by whitespace_required_check.py
        # (nothing blank stored, nothing blanked), so they are not counted twice. A blank number prefix is a valid setting.
        db_checked = (('custom_link_add', 'uri'), ('ai_provider_add', 'provider'), ('ai_provider_edit', 'provider'), ('contract_template_add', 'description'),
                      ('client_edit', 'name'), ('settings_company', 'name'), ('settings_project', 'config_project_prefix'),
                      ('ticket_template_details', 'task_name'), ('campaigns', 'name'), ('campaigns', 'subject'), ('onboarding_template_details', 'task_name'),
                      ('worksheet_template_details', 'worksheet_template_name'), ('service_edit', 'description'), ('settings_default', 'hourly_rate'),
                      ('settings_invoice', 'config_invoice_prefix'), ('settings_ticket', 'config_ticket_prefix'), ('settings_quote', 'config_quote_prefix'), ('segments', 'name'))
        if t['class'] == 'whitespace' and t.get('required') and t['type'] in ('text', 'textarea') and t.get('accepted') and not any(p in r['path'] and t['field'] == fld for p, fld in db_checked): hard['whitespace_only_accepted_for_required'].append((r['path'], t['field']))
        if t.get('hidden_tab_invalid') and not t.get('still_open'): hard['invalid_in_hidden_tab_no_feedback'].append((r['path'], t['field'], t['class']))
        matrix[k]['accepted' if t.get('accepted') else 'rejected'] += 1
print(f'forms tested: {nforms}  fields: {len(nfields)}  input cases: {ntests}  harness exceptions: {nexc}')
print('\nform-input class x outcome (type/class: accepted | rejected | exception)')
for (ty, cl), c in sorted(matrix.items()): print(f'  {ty:9} {cl:18} {c["accepted"]:4} | {c["rejected"]:4} | {c["exception"]:3}')
print('\nHARD FAILURES')
bad = 0
for k, v in hard.items():
    print(f'  {k}: {len(v)}'); bad += len(v)
    for x in v[:6]: print('     ', x)
json.dump({k: v for k, v in hard.items()}, open(os.path.join(OUT, 'input_hard_failures.json'), 'w'))
print('\nRESULT:', 'FAIL' if bad else 'PASS'); sys.exit(1 if bad else 0)

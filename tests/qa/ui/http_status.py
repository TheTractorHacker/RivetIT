"""Print HTTP status for paths using a logged-in session: python3 http_status.py [--role tech] path [path...]"""
import sys
from common import *
args = sys.argv[1:]; role = 'admin'
if args and args[0] == '--role': role = args[1]; args = args[2:]
p, b, ctx, pg = start(role, storage=state_file(role))
for a in args:
    r = ctx.request.get(BASE + a, max_redirects=0)
    body = r.text()
    print(r.status, a, len(body), 'FATAL' if 'Fatal error' in body or 'Uncaught' in body else '')
b.close(); p.stop()

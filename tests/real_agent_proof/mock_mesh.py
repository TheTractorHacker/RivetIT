#!/usr/bin/env python3
"""A MOCK MeshCentral agent download for tests/real_agent_proof/run_rc13.sh. It is NOT MeshCentral: it answers GET /meshagents?id=N&meshid=HEX&installflags=F
with a tiny shell script that behaves like a MeshAgent for the three commands the RivetIT installer uses (-install, -nodeid, -uninstall), over TLS with a
self-signed certificate the proof pins. Every request is appended to the log file. usage: mock_mesh.py <host> <port> <cert> <key> <group-hex> <node-id> <log>"""
import http.server, ssl, sys, urllib.parse

host, port, cert, key, group, node, log = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5], sys.argv[6], sys.argv[7]
SCRIPT = f"""#!/bin/sh
# FAKE MeshAgent for the proof
case "$1" in
  -nodeid) echo "MeshAgent (fake)"; echo '{node}'; exit 0;;
  -install|-fullinstall)
    mkdir -p /usr/local/mesh_services/meshagent
    cp "$0" /usr/local/mesh_services/meshagent/meshagent
    chmod 755 /usr/local/mesh_services/meshagent/meshagent
    echo "installed by: $0 $*" > /usr/local/mesh_services/meshagent/installed.marker
    exit 0;;
  -uninstall|-fulluninstall) rm -rf /usr/local/mesh_services/meshagent; exit 0;;
esac
exit 0
"""

class H(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        q = urllib.parse.parse_qs(u.query)
        with open(log, 'a') as f:
            f.write(f"MESH-DOWNLOAD path={u.path} id={q.get('id',[''])[0]} meshid={q.get('meshid',[''])[0]} installflags={q.get('installflags',[''])[0]}\n")
        if u.path != '/meshagents' or q.get('meshid', [''])[0] != group:
            self.send_response(404); self.end_headers(); return
        b = SCRIPT.encode()
        self.send_response(200); self.send_header('Content-Type', 'application/octet-stream'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def log_message(self, *a): pass

s = http.server.ThreadingHTTPServer((host, port), H)
c = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH); c.minimum_version = ssl.TLSVersion.TLSv1_2; c.load_cert_chain(cert, key)
s.socket = c.wrap_socket(s.socket, server_side=True)
s.serve_forever()

#!/usr/bin/env python3
"""Minimal SMTP sink for tests/mail_intake_db.php: accepts every message, appends each DATA payload to <outfile>
separated by a line of 60 '=' characters. Usage: smtp_sink.py <port> <outfile>. Stops on SIGTERM."""
import socket, sys, signal
port, out = int(sys.argv[1]), sys.argv[2]
srv = socket.socket(); srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(('127.0.0.1', port)); srv.listen(5)
signal.signal(signal.SIGTERM, lambda *a: sys.exit(0))
while True:
    c, _ = srv.accept(); f = c.makefile('rwb', buffering=0)
    def send(s): f.write((s + '\r\n').encode())
    send('220 sink ESMTP')
    data = False; buf = []
    while True:
        line = f.readline()
        if not line: break
        t = line.decode('utf-8', 'replace').rstrip('\r\n')
        if data:
            if t == '.':
                with open(out, 'a') as o: o.write('\n'.join(buf) + '\n' + '=' * 60 + '\n')
                data = False; buf = []; send('250 queued')
            else:
                buf.append(t[1:] if t.startswith('..') else t)
            continue
        u = t.upper()
        if u.startswith('EHLO') or u.startswith('HELO'): send('250 sink')
        elif u.startswith('MAIL') or u.startswith('RCPT') or u.startswith('RSET') or u.startswith('NOOP'): send('250 ok')
        elif u.startswith('DATA'): data = True; send('354 go')
        elif u.startswith('QUIT'): send('221 bye'); break
        else: send('250 ok')
    c.close()

#!/usr/bin/env python3
"""Remove the QA-UI- rows the audit creates in the SCRATCH DB (also clears enrollment-attempt throttle state)."""
from common import *
q = """
delete a from ticket_attachments a join tickets t on t.ticket_id=a.ticket_attachment_ticket_id where t.ticket_source='Panel';
delete r from ticket_replies r join tickets t on t.ticket_id=r.ticket_reply_ticket_id where t.ticket_source='Panel';
delete from tickets where ticket_source='Panel';
delete from panel_ticket_owner;
delete from endpoint_agent_devices where kind='panel';
delete from endpoint_agent_enrollment_tokens where kind='panel';
delete from endpoint_agent_enroll_attempts;
delete from panel_client_settings;
"""
for stmt in q.strip().split(';\n'):
    if stmt.strip():
        sql(stmt)
print('scratch panel rows cleared')

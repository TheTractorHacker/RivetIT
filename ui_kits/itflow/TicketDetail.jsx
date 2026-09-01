/* Ticket detail — conversation thread + reply composer, with a sidebar of
   ticket fields, client info and a time-entry card. */
function TicketDetail({ onNavigate }) {
  const D = window.ITF_DATA;
  const t = D.tickets[0];
  const { Card, Button, Badge, PriorityDot, Avatar, Select } = window.ITFlow_b1b893;
  const [tab, setTab] = React.useState('all');

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14 }}>
        <a onClick={() => onNavigate('tickets')} style={{ cursor: 'pointer', color: 'var(--color-accent)', fontSize: 13 }}><i className="fas fa-arrow-left" style={{ marginRight: 6 }}></i>Tickets</a>
        <span style={{ color: 'var(--color-text-muted)' }}>/</span>
        <span style={{ fontFamily: 'var(--font-mono)', color: 'var(--color-text-muted)' }}>#{t.id}</span>
      </div>

      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 16, marginBottom: 16, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: '0 0 6px', fontSize: 22, fontWeight: 700, color: 'var(--color-text)' }}>{t.subject}</h1>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, fontSize: 13, color: 'var(--color-text-muted)' }}>
            <span><i className="fas fa-building" style={{ marginRight: 5 }}></i>{t.client}</span>
            <span><i className="fas fa-user" style={{ marginRight: 5 }}></i>{t.contact}</span>
            <span><i className="fas fa-layer-group" style={{ marginRight: 5 }}></i>{t.board}</span>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <Button variant="secondary" icon="check">Resolve</Button>
          <Button variant="secondary" icon="ellipsis-v" />
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 16, alignItems: 'start' }}>
        <div>
          <Card noBody>
            <div style={{ display: 'flex', gap: 4, padding: '4px 12px 0', borderBottom: '1px solid var(--color-border)' }}>
              {[['all', 'All Comments'], ['client', 'Client'], ['internal', 'Internal']].map(([id, label]) => (
                <button key={id} onClick={() => setTab(id)} style={{
                  border: 'none', background: 'none', cursor: 'pointer', padding: '10px 12px', fontSize: 13, fontWeight: 500,
                  color: tab === id ? 'var(--color-accent)' : 'var(--color-text-muted)',
                  borderBottom: `2px solid ${tab === id ? 'var(--color-accent)' : 'transparent'}`, marginBottom: -1,
                }}>{label}</button>
              ))}
            </div>
            <div style={{ padding: 16, display: 'flex', flexDirection: 'column', gap: 14 }}>
              {D.conversation.filter((c) => tab === 'all' || (tab === 'internal' ? c.internal : !c.internal)).map((c, i) => (
                <div key={i} style={{ display: 'flex', gap: 12 }}>
                  <Avatar name={c.who} size={36} />
                  <div style={{ flex: 1, background: c.internal ? 'rgba(255,193,7,.10)' : 'var(--color-surface-alt)', border: c.internal ? '1px solid rgba(255,193,7,.35)' : '1px solid var(--color-border-soft)', borderRadius: 10, padding: '10px 13px' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 5, fontSize: 13 }}>
                      <strong>{c.who}</strong>
                      <span style={{ fontSize: 11, color: 'var(--color-text-muted)' }}>{c.role}</span>
                      {c.internal && <Badge soft color="warning" style={{ fontSize: '.65rem' }}>Internal</Badge>}
                      <span style={{ marginLeft: 'auto', fontSize: 11, color: 'var(--color-text-muted)' }}>{c.when}</span>
                    </div>
                    <div style={{ fontSize: 13, lineHeight: 1.5, color: 'var(--color-text)' }}>{c.body}</div>
                  </div>
                </div>
              ))}
            </div>
          </Card>

          <div style={{ marginTop: 16 }}>
            <Card title="Reply" icon="reply">
              <textarea placeholder="Type your reply…" rows={4} style={{ width: '100%', boxSizing: 'border-box', resize: 'vertical', padding: 12, fontFamily: 'var(--font-sans)', fontSize: 13, color: 'var(--color-text)', background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 'var(--input-radius)', outline: 'none' }}></textarea>
              <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 12 }}>
                <Button icon="paper-plane">Send Reply</Button>
                <Button variant="secondary" icon="lock">Internal Note</Button>
                <span style={{ marginLeft: 'auto', fontSize: 12, color: 'var(--color-text-muted)' }}><i className="fas fa-paperclip" style={{ marginRight: 5 }}></i>Attach</span>
              </div>
            </Card>
          </div>
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <Card title="Details" icon="info-circle">
            <Field label="Status"><Badge customColor={t.statusColor}>{t.status}</Badge></Field>
            <Field label="Priority"><PriorityDot priority={t.priority} label /></Field>
            <Field label="Assigned"><span style={{ display: 'inline-flex', alignItems: 'center', gap: 7 }}><Avatar name={t.assignee} size={22} />{t.assignee}</span></Field>
            <Field label="Board">{t.board}</Field>
            <Field label="Created">Today, 9:02 AM</Field>
            <Field label="SLA"><span style={{ color: 'var(--success)' }}><i className="fas fa-check-circle" style={{ marginRight: 5 }}></i>On track</span></Field>
          </Card>

          <Card title="Time Entry" icon="stopwatch" style={{ background: 'rgba(13,148,136,.05)' }}>
            <div style={{ fontFamily: 'var(--font-mono)', fontSize: 26, fontWeight: 700, color: 'var(--color-accent)' }}>00:42:18</div>
            <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
              <Button size="sm" icon="pause" variant="secondary">Pause</Button>
              <Button size="sm" icon="stop">Stop &amp; Log</Button>
            </div>
          </Card>

          <Card title="Client" icon="building">
            <div style={{ fontWeight: 600, marginBottom: 4 }}>{t.client}</div>
            <div style={{ fontSize: 13, color: 'var(--color-text-muted)', lineHeight: 1.6 }}>
              <div><i className="fas fa-user fa-fw" style={{ marginRight: 6 }}></i>{t.contact}</div>
              <div><i className="fas fa-phone fa-fw" style={{ marginRight: 6 }}></i>(503) 555-0188</div>
              <div><i className="fas fa-ticket-alt fa-fw" style={{ marginRight: 6 }}></i>6 open tickets</div>
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '7px 0', borderBottom: '1px solid var(--color-border-soft)', fontSize: 13 }}>
      <span style={{ color: 'var(--color-text-muted)' }}>{label}</span>
      <span>{children}</span>
    </div>
  );
}

Object.assign(window, { TicketDetail });

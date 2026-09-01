/* Dashboard — greeting, small-box KPIs, charts (CSS bars) and recent tickets. */
function Dashboard({ onNavigate }) {
  const D = window.ITF_DATA;
  const { SmallBox, Card, Switch, Badge, PriorityDot, Avatar } = window.ITFlow_b1b893;
  const hour = new Date().getHours();
  const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const opened =  [22,28,24,31,27,34,29,36,30,33,38,26];
  const resolved =[20,25,26,28,29,30,31,33,29,35,34,30];
  const max = 40;

  return (
    <div>
      <div style={{ marginBottom: 18 }}>
        <h1 style={{ margin: 0, fontSize: 24, fontWeight: 700, color: 'var(--color-text)' }}>{greet}, {D.user.name.split(' ')[0]}!</h1>
        <div style={{ fontSize: 13, color: 'var(--color-text-muted)' }}>{new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 14, marginBottom: 16 }}>
        <SmallBox value={42} label="Open Tickets" icon="ticket-alt" color="primary" href="#" onClick={(e)=>{e.preventDefault();onNavigate('tickets');}} />
        <SmallBox value={9} label="My Tickets" icon="user-check" color="violet" href="#" onClick={(e)=>{e.preventDefault();onNavigate('tickets');}} />
        <SmallBox value={38} label="Active Clients" icon="building" color="success" href="#" onClick={(e)=>{e.preventDefault();onNavigate('clients');}} />
        <SmallBox value={6} label="Unpaid Invoices" icon="file-invoice-dollar" color="warning" href="#" />
      </div>

      <div style={{ background: 'var(--color-surface)', borderRadius: 'var(--card-radius)', boxShadow: 'var(--card-shadow)', padding: '12px 16px', marginBottom: 16, display: 'flex', alignItems: 'center', gap: 22 }}>
        <span style={{ fontSize: 13, fontWeight: 600 }}>2026</span>
        <Switch label="Financial" defaultChecked />
        <Switch label="Technical" defaultChecked />
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: 16, marginBottom: 16 }}>
        <Card title="Tickets Opened vs Resolved" icon="chart-line">
          <div style={{ display: 'flex', alignItems: 'flex-end', gap: 10, height: 210, padding: '4px 0' }}>
            {months.map((m, i) => (
              <div key={m} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
                <div style={{ display: 'flex', alignItems: 'flex-end', gap: 3, height: 180, width: '100%', justifyContent: 'center' }}>
                  <div title={`Opened ${opened[i]}`} style={{ width: '38%', height: `${opened[i]/max*100}%`, background: 'var(--color-accent)', borderRadius: '3px 3px 0 0' }}></div>
                  <div title={`Resolved ${resolved[i]}`} style={{ width: '38%', height: `${resolved[i]/max*100}%`, background: 'var(--slate-300)', borderRadius: '3px 3px 0 0' }}></div>
                </div>
                <span style={{ fontSize: 10, color: 'var(--color-text-muted)' }}>{m}</span>
              </div>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 18, fontSize: 12, color: 'var(--color-text-muted)', marginTop: 6 }}>
            <span><i className="fas fa-square" style={{ color: 'var(--color-accent)', marginRight: 5 }}></i>Opened</span>
            <span><i className="fas fa-square" style={{ color: 'var(--slate-300)', marginRight: 5 }}></i>Resolved</span>
          </div>
        </Card>

        <Card title="By Priority" icon="chart-pie">
          {[['High', 14, 'var(--priority-high)'], ['Medium', 19, 'var(--priority-medium)'], ['Low', 9, 'var(--priority-low)']].map(([l, v, c]) => (
            <div key={l} style={{ marginBottom: 12 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 4 }}><span>{l}</span><strong>{v}</strong></div>
              <div style={{ height: 8, borderRadius: 4, background: 'var(--slate-100)' }}><div style={{ width: `${v/42*100}%`, height: '100%', borderRadius: 4, background: c }}></div></div>
            </div>
          ))}
          <div style={{ marginTop: 18, paddingTop: 14, borderTop: '1px solid var(--color-border-soft)', display: 'flex', justifyContent: 'space-between', fontSize: 13 }}>
            <span style={{ color: 'var(--color-text-muted)' }}>Avg. resolution</span><strong>5.2 h</strong>
          </div>
        </Card>
      </div>

      <Card title="My Active Tickets" icon="user-check" tools={<a onClick={() => onNavigate('tickets')} style={{ cursor: 'pointer', color: 'var(--color-accent)', fontSize: 13 }}>View all</a>} noBody>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <tbody>
            {D.tickets.filter((t) => t.assignee === D.user.name || !t.assignee).slice(0, 4).map((t) => (
              <tr key={t.id} style={{ borderTop: '1px solid var(--color-border-soft)', cursor: 'pointer' }} onClick={() => onNavigate('ticket')}>
                <td style={{ padding: '10px 16px', color: 'var(--color-text-muted)', fontFamily: 'var(--font-mono)', width: 60 }}>#{t.id}</td>
                <td style={{ padding: '10px 8px', fontWeight: 500 }}>{t.subject}</td>
                <td style={{ padding: '10px 8px', color: 'var(--color-text-muted)' }}>{t.client}</td>
                <td style={{ padding: '10px 8px' }}><PriorityDot priority={t.priority} label /></td>
                <td style={{ padding: '10px 16px', textAlign: 'right' }}><Badge soft customColor={t.statusColor}>{t.status}</Badge></td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  );
}

Object.assign(window, { Dashboard });

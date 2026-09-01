/* Clients list — searchable table of managed clients. */
function Clients({ onNavigate }) {
  const D = window.ITF_DATA;
  const { Card, Button, Input, Badge, Avatar } = window.ITFlow_b1b893;
  const [q, setQ] = React.useState('');
  const rows = D.clients.filter((c) => c.name.toLowerCase().includes(q.toLowerCase()));
  const fmt = (n) => '$' + n.toLocaleString();

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
        <h1 style={{ margin: 0, fontSize: 22, fontWeight: 700, color: 'var(--color-text)' }}>Clients</h1>
        <Button icon="plus">New Client</Button>
      </div>

      <Card noBody>
        <div style={{ padding: 14, borderBottom: '1px solid var(--color-border-soft)' }}>
          <Input pill icon="search" placeholder="Search clients..." value={q} onChange={(e) => setQ(e.target.value)} containerStyle={{ maxWidth: 320 }} />
        </div>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--color-text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.03em' }}>
              <th style={{ padding: '10px 16px', fontWeight: 600 }}>Client</th>
              <th style={{ padding: '10px 8px', fontWeight: 600 }}>Primary Contact</th>
              <th style={{ padding: '10px 8px', fontWeight: 600 }}>Type</th>
              <th style={{ padding: '10px 8px', fontWeight: 600, textAlign: 'right' }}>Open Tickets</th>
              <th style={{ padding: '10px 8px', fontWeight: 600, textAlign: 'right' }}>Assets</th>
              <th style={{ padding: '10px 8px', fontWeight: 600, textAlign: 'right' }}>Balance</th>
              <th style={{ padding: '10px 16px', fontWeight: 600, textAlign: 'right' }}>MRR</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((c) => (
              <tr key={c.name} style={{ borderTop: '1px solid var(--color-border-soft)', cursor: 'pointer' }}
                onMouseEnter={(e) => e.currentTarget.style.background = 'var(--color-accent-soft)'}
                onMouseLeave={(e) => e.currentTarget.style.background = 'transparent'}>
                <td style={{ padding: '11px 16px', fontWeight: 600 }}>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 10 }}><Avatar name={c.name} size={28} />{c.name}</span>
                </td>
                <td style={{ padding: '11px 8px', color: 'var(--color-text-muted)' }}>{c.contact}</td>
                <td style={{ padding: '11px 8px' }}><Badge soft color={c.tag === 'Managed' ? 'accent' : 'secondary'}>{c.tag}</Badge></td>
                <td style={{ padding: '11px 8px', textAlign: 'right' }}>{c.tickets || '—'}</td>
                <td style={{ padding: '11px 8px', textAlign: 'right', fontFamily: 'var(--font-mono)' }}>{c.assets}</td>
                <td style={{ padding: '11px 8px', textAlign: 'right', color: c.balance > 0 ? 'var(--danger)' : 'var(--color-text)' }}>{fmt(c.balance)}</td>
                <td style={{ padding: '11px 16px', textAlign: 'right', fontWeight: 600 }}>{fmt(c.mrr)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>
    </div>
  );
}

Object.assign(window, { Clients });

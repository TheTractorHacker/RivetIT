/* Tickets list — the redesigned centerpiece: stat boxes, pill filter bar,
   ticket table grouped by board with inline status/priority pills. */
const { useState: useTicketState } = React;

function Tickets({ onNavigate }) {
  const D = window.ITF_DATA;
  const { StatBox, Card, Button, Input, Select, Badge, PriorityDot, Avatar, IconButton } = window.ITFlow_b1b893;
  const [statFilter, setStatFilter] = useTicketState('all');
  const [query, setQuery] = useTicketState('');

  const stats = [
    { id: 'all', label: 'All Tickets', value: 49, icon: 'list', color: 'var(--stat-slate)' },
    { id: 'unassigned', label: 'Unassigned', value: 2, icon: 'user-slash', color: 'var(--stat-amber)' },
    { id: 'open', label: 'Unresolved', value: 42, icon: 'exclamation-circle', color: 'var(--stat-blue)' },
    { id: 'due', label: 'Due Today', value: 5, icon: 'clock', color: 'var(--stat-orange)' },
    { id: 'overdue', label: 'Overdue', value: 2, icon: 'fire', color: 'var(--stat-red)' },
    { id: 'onsite', label: 'On-Site Open', value: 1, icon: 'map-marker-alt', color: 'var(--stat-violet)' },
  ];

  let rows = D.tickets;
  if (statFilter === 'unassigned') rows = rows.filter((t) => !t.assignee);
  else if (statFilter === 'overdue') rows = rows.filter((t) => t.overdue);
  else if (statFilter === 'onsite') rows = rows.filter((t) => t.onsite);
  if (query) rows = rows.filter((t) => (t.subject + t.client + t.id).toLowerCase().includes(query.toLowerCase()));

  const boards = [...new Set(rows.map((t) => t.board))];

  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(6, 1fr)', gap: 10, marginBottom: 16 }}>
        {stats.map((s) => (
          <StatBox key={s.id} {...s} active={statFilter === s.id} href="#"
            onClick={(e) => { e.preventDefault(); setStatFilter(s.id); }} />
        ))}
      </div>

      <Card noBody>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', borderBottom: '1px solid var(--color-border-soft)' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontWeight: 600 }}>
            <i className="fas fa-life-ring" style={{ color: 'var(--color-text-muted)' }}></i>Tickets
            <Badge soft color="accent">{rows.length} shown</Badge>
          </div>
          <Button icon="plus">New Ticket</Button>
        </div>

        <div style={{ padding: 14 }}>
          <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center', background: 'var(--color-surface-alt)', border: '1px solid var(--color-border)', borderRadius: 'var(--card-radius)', padding: 12 }}>
            <Select pill options={['Network', 'Hardware', 'Software']} placeholder="All Boards" containerStyle={{ minWidth: 140 }} />
            <Select pill options={['New', 'In Progress', 'Waiting on Customer', 'Resolved']} placeholder="Status" containerStyle={{ minWidth: 150 }} />
            <Select pill options={['High', 'Medium', 'Low']} placeholder="All Priorities" containerStyle={{ minWidth: 140 }} />
            <Input pill button="search" placeholder="Search tickets..." value={query} onChange={(e) => setQuery(e.target.value)} containerStyle={{ flex: 1, minWidth: 200 }} />
            <Button variant="secondary" icon="undo" onClick={() => { setStatFilter('all'); setQuery(''); }}>Reset</Button>
          </div>
        </div>

        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--color-text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.03em' }}>
              <th style={{ padding: '8px 16px', fontWeight: 600 }}>#</th>
              <th style={{ padding: '8px', fontWeight: 600 }}>Subject</th>
              <th style={{ padding: '8px', fontWeight: 600 }}>Client</th>
              <th style={{ padding: '8px', fontWeight: 600 }}>Priority</th>
              <th style={{ padding: '8px', fontWeight: 600 }}>Status</th>
              <th style={{ padding: '8px', fontWeight: 600 }}>Assigned</th>
              <th style={{ padding: '8px 16px', fontWeight: 600, textAlign: 'right' }}>Updated</th>
            </tr>
          </thead>
          <tbody>
            {boards.map((b) => (
              <React.Fragment key={b}>
                <tr style={{ background: 'var(--color-surface-alt)' }}>
                  <td colSpan={7} style={{ padding: '7px 16px', fontSize: 12, fontWeight: 700, color: 'var(--color-text-muted)', textTransform: 'uppercase', letterSpacing: '.03em' }}>
                    <i className="fas fa-layer-group" style={{ marginRight: 7 }}></i>{b}
                    <span style={{ marginLeft: 8, fontWeight: 400 }}>({rows.filter((t) => t.board === b).length})</span>
                  </td>
                </tr>
                {rows.filter((t) => t.board === b).map((t) => (
                  <tr key={t.id} onClick={() => onNavigate('ticket')} style={{ borderTop: '1px solid var(--color-border-soft)', cursor: 'pointer' }}
                    onMouseEnter={(e) => e.currentTarget.style.background = 'var(--color-accent-soft)'}
                    onMouseLeave={(e) => e.currentTarget.style.background = 'transparent'}>
                    <td style={{ padding: '11px 16px', fontFamily: 'var(--font-mono)', color: 'var(--color-text-muted)' }}>#{t.id}</td>
                    <td style={{ padding: '11px 8px', fontWeight: 500, maxWidth: 320 }}>
                      {t.subject}
                      {t.onsite && <Badge soft customColor="var(--stat-violet)" style={{ marginLeft: 8, fontSize: '.7rem' }}>On-Site</Badge>}
                      {t.overdue && <Badge soft color="danger" style={{ marginLeft: 6, fontSize: '.7rem' }}>Overdue</Badge>}
                    </td>
                    <td style={{ padding: '11px 8px', color: 'var(--color-text-muted)' }}>{t.client}</td>
                    <td style={{ padding: '11px 8px' }}><PriorityDot priority={t.priority} label /></td>
                    <td style={{ padding: '11px 8px' }}><Badge customColor={t.statusColor} style={{ color: t.priority === 'x' ? '' : undefined }}>{t.status}</Badge></td>
                    <td style={{ padding: '11px 8px' }}>
                      {t.assignee
                        ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7 }}><Avatar name={t.assignee} size={24} />{t.assignee.split(' ')[0]}</span>
                        : <span style={{ color: 'var(--stat-amber)' }}><i className="fas fa-user-slash" style={{ marginRight: 5 }}></i>Unassigned</span>}
                    </td>
                    <td style={{ padding: '11px 16px', textAlign: 'right', color: 'var(--color-text-muted)' }}>{t.updated}</td>
                  </tr>
                ))}
              </React.Fragment>
            ))}
          </tbody>
        </table>
        {rows.length === 0 && <div style={{ padding: 40, textAlign: 'center', color: 'var(--color-text-muted)' }}>No tickets match your filters.</div>}
      </Card>
    </div>
  );
}

Object.assign(window, { Tickets });

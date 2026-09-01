/* Root app: login gate + simple screen routing across the shell. */
const { useState: useAppState } = React;

function Placeholder({ id }) {
  const D = window.ITF_DATA;
  const item = D.nav.find((n) => n.id === id);
  const { Card } = window.ITFlow_b1b893;
  return (
    <div>
      <h1 style={{ margin: '0 0 16px', fontSize: 22, fontWeight: 700, color: 'var(--color-text)' }}>{item ? item.label : id}</h1>
      <Card>
        <div style={{ textAlign: 'center', padding: '50px 20px', color: 'var(--color-text-muted)' }}>
          <i className={`fas fa-${item ? item.icon : 'cube'}`} style={{ fontSize: 40, color: 'var(--color-border)' }}></i>
          <p style={{ marginTop: 14, fontSize: 14 }}>The <strong>{item ? item.label : id}</strong> module isn't part of this UI-kit demo.</p>
          <p style={{ fontSize: 13 }}>Explore <strong>Dashboard</strong>, <strong>Tickets</strong> and <strong>Clients</strong> for the interactive screens.</p>
        </div>
      </Card>
    </div>
  );
}

function App() {
  const [authed, setAuthed] = useAppState(false);
  const [screen, setScreen] = useAppState('dashboard');

  if (!authed) return <Login onLogin={() => { setAuthed(true); setScreen('dashboard'); }} />;

  // Determine if we're in admin or agent context
  const adminScreens = ['admin', 'settings_company', 'settings_localization', 'settings_theme',
    'settings_security', 'settings_mail', 'settings_notification', 'settings_default',
    'settings_invoice', 'settings_quote', 'settings_ticket', 'settings_module',
    'settings_webhooks', 'settings_integrations', 'settings_calendar_sync',
    'users', 'roles', 'api_keys', 'tag', 'category', 'custom_link', 'ai_provider',
    'tax', 'payment_method', 'payment_provider', 'ticket_status', 'labor_type',
    'ticket_automation', 'contract_template', 'project_template', 'onboarding_template',
    'ticket_template', 'canned_responses', 'worksheet_template', 'document_template',
    'cron', 'mail_queue', 'audit_log', 'app_log', 'backup', 'credential_restore', 'debug', 'update'];

  const isAdmin = adminScreens.includes(screen);
  const D = window.ITF_DATA;

  // Find active admin nav id
  const adminNavActive = isAdmin ? screen : 'settings_company';

  if (isAdmin) {
    let view;
    if (screen === 'settings_company' || screen === 'admin') {
      view = <AdminSettings onNavigate={setScreen} />;
    } else {
      view = <AdminPlaceholder id={screen} onNavigate={setScreen} />;
    }
    return (
      <Shell
        active={adminNavActive}
        onNavigate={setScreen}
        onLogout={() => setAuthed(false)}
        nav={D.adminNav}
        brand="Administration"
        brandHref="dashboard"
      >
        {view}
      </Shell>
    );
  }

  let view;
  if (screen === 'dashboard') view = <Dashboard onNavigate={setScreen} />;
  else if (screen === 'tickets') view = <Tickets onNavigate={setScreen} />;
  else if (screen === 'ticket') view = <TicketDetail onNavigate={setScreen} />;
  else if (screen === 'clients') view = <Clients onNavigate={setScreen} />;
  else view = <Placeholder id={screen} />;

  const activeNav = screen === 'ticket' ? 'tickets' : screen;

  return (
    <Shell active={activeNav} onNavigate={setScreen} onLogout={() => setAuthed(false)}>
      {view}
    </Shell>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App />);

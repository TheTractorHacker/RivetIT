/* App shell: dark sidebar + top navbar + content wrapper (AdminLTE chrome). */
const { useState } = React;

function NavItem({ n, active, onNavigate }) {
  const on = active === n.id;
  return (
    <a onClick={() => onNavigate(n.id)} style={{
      display: 'flex', alignItems: 'center', gap: 11, padding: '9px 12px', margin: '1px 0', borderRadius: 6,
      cursor: 'pointer', color: on ? '#fff' : 'rgba(255,255,255,.82)', background: on ? 'var(--color-accent)' : 'transparent',
      fontSize: 14, whiteSpace: 'nowrap', textDecoration: 'none',
    }}
    onMouseEnter={(e) => { if (!on) e.currentTarget.style.background = 'rgba(255,255,255,.08)'; }}
    onMouseLeave={(e) => { if (!on) e.currentTarget.style.background = 'transparent'; }}>
      <i className={`fas fa-fw fa-${n.icon}`} style={{ width: 20, textAlign: 'center' }}></i>
      <span style={{ flex: 1 }}>{n.label}</span>
      {n.arrow && <i className="fas fa-angle-right" style={{ fontSize: 11, opacity: .6 }}></i>}
      {n.badge != null && (
        <span style={{ fontSize: 11, fontWeight: 700, padding: '1px 7px', borderRadius: 10, background: n.badgeColor || 'rgba(255,255,255,.16)', color: '#fff' }}>{n.badge}</span>
      )}
    </a>
  );
}

function NavGroup({ n, active, onNavigate }) {
  const childActive = n.children && n.children.some(c => c.id === active);
  const [open, setOpen] = useState(!!(n.open || childActive));
  return (
    <div>
      <a onClick={() => setOpen(o => !o)} style={{
        display: 'flex', alignItems: 'center', gap: 11, padding: '9px 12px', margin: '1px 0', borderRadius: 6,
        cursor: 'pointer', color: 'rgba(255,255,255,.82)', fontSize: 14, whiteSpace: 'nowrap', textDecoration: 'none',
        background: childActive ? 'rgba(255,255,255,.06)' : 'transparent',
      }}
      onMouseEnter={(e) => e.currentTarget.style.background = 'rgba(255,255,255,.08)'}
      onMouseLeave={(e) => e.currentTarget.style.background = childActive ? 'rgba(255,255,255,.06)' : 'transparent'}>
        <i className={`fas fa-fw fa-${n.icon}`} style={{ width: 20, textAlign: 'center' }}></i>
        <span style={{ flex: 1 }}>{n.label}</span>
        <i className={`fas fa-angle-${open ? 'down' : 'left'}`} style={{ fontSize: 11, opacity: .6 }}></i>
      </a>
      {open && (
        <div style={{ paddingLeft: 12 }}>
          {n.children.map((c, i) => <NavItem key={i} n={c} active={active} onNavigate={onNavigate} />)}
        </div>
      )}
    </div>
  );
}

function Sidebar({ active, onNavigate, collapsed, nav, brand, brandHref }) {
  const D = window.ITF_DATA;
  return (
    <aside style={{
      width: collapsed ? 0 : 250, flexShrink: 0, background: 'var(--chrome-bg)', color: 'var(--chrome-text)',
      overflow: 'hidden', transition: 'width .2s ease', display: 'flex', flexDirection: 'column',
    }}>
      <a onClick={() => onNavigate(brandHref || 'dashboard')} style={{
        display: 'flex', alignItems: 'center', gap: 10, padding: '14px 16px', cursor: 'pointer',
        borderBottom: '1px solid rgba(255,255,255,.08)', color: '#fff', textDecoration: 'none', whiteSpace: 'nowrap',
      }}>
        {brandHref === 'dashboard' || !brandHref ? (
          <>
            <span style={{ width: 30, height: 30, borderRadius: 7, background: 'var(--color-accent)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
              <i className="fas fa-bolt" style={{ color: '#fff', fontSize: 15 }}></i>
            </span>
            <span style={{ fontSize: 20, fontWeight: 700 }}>{D.company}</span>
          </>
        ) : (
          <span style={{ fontSize: 15, fontWeight: 600 }}>
            <i className="fas fa-arrow-left" style={{ marginRight: 8, opacity: .7 }}></i>
            Back | <strong>{brand}</strong>
          </span>
        )}
      </a>
      <nav style={{ padding: '12px 10px', overflowY: 'auto', flex: 1 }}>
        {(nav || D.nav).map((n, i) => {
          if (n.type === 'header') {
            return <div key={i} style={{ fontSize: 11, fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', color: 'rgba(255,255,255,.4)', padding: '16px 12px 6px' }}>{n.label}</div>;
          }
          if (n.type === 'divider') {
            return <div key={i} style={{ height: 1, background: 'rgba(255,255,255,.08)', margin: '10px 12px' }}></div>;
          }
          if (n.type === 'group') {
            return <NavGroup key={i} n={n} active={active} onNavigate={onNavigate} />;
          }
          return <NavItem key={i} n={n} active={active} onNavigate={onNavigate} />;
        })}
      </nav>
    </aside>
  );
}

function TopNav({ onToggle, onLogout, onNavigate }) {
  const D = window.ITF_DATA;
  const [menu, setMenu] = useState(false);
  return (
    <nav style={{
      height: 'var(--navbar-height)', flexShrink: 0, background: 'var(--chrome-bg)', display: 'flex',
      alignItems: 'center', padding: '0 14px', gap: 14, color: 'var(--chrome-text)', position: 'relative', zIndex: 20,
    }}>
      <button onClick={onToggle} style={{ background: 'none', border: 'none', color: 'rgba(255,255,255,.85)', fontSize: 17, cursor: 'pointer', padding: 6 }}>
        <i className="fas fa-bars"></i>
      </button>
      <div style={{ flex: 1 }}></div>
      <div style={{ display: 'flex', alignItems: 'center', background: 'rgba(255,255,255,.1)', borderRadius: 6, padding: '5px 10px', gap: 8, width: 230, maxWidth: '34vw' }}>
        <i className="fas fa-search" style={{ fontSize: 12, color: 'rgba(255,255,255,.6)' }}></i>
        <input placeholder="Search everywhere" style={{ background: 'none', border: 'none', outline: 'none', color: '#fff', fontSize: 13, width: '100%' }} />
      </div>
      <button style={{ background: 'none', border: 'none', color: 'rgba(255,255,255,.85)', fontSize: 16, cursor: 'pointer', position: 'relative', padding: 6 }}>
        <i className="fas fa-bell"></i>
        <span style={{ position: 'absolute', top: 0, right: 0, background: 'var(--slate-50)', color: 'var(--slate-900)', fontSize: 10, fontWeight: 700, borderRadius: 8, padding: '0 5px' }}>5</span>
      </button>
      <button onClick={() => onNavigate && onNavigate('admin')} title="Administration" style={{ background: 'none', border: 'none', color: 'rgba(255,255,255,.75)', fontSize: 15, cursor: 'pointer', padding: 6 }}>
        <i className="fas fa-cog"></i>
      </button>
      <div style={{ position: 'relative' }}>
        <button onClick={() => setMenu((m) => !m)} style={{ display: 'flex', alignItems: 'center', gap: 8, background: 'none', border: 'none', color: '#fff', cursor: 'pointer', fontSize: 14 }}>
          <i className="fas fa-user-circle" style={{ fontSize: 18 }}></i>
          <span>{D.user.name}</span>
          <i className="fas fa-caret-down" style={{ fontSize: 11, opacity: .7 }}></i>
        </button>
        {menu && (
          <div style={{ position: 'absolute', right: 0, top: '100%', marginTop: 8, background: 'var(--color-surface)', color: 'var(--color-text)', borderRadius: 10, boxShadow: 'var(--shadow-hover)', width: 220, overflow: 'hidden', border: '1px solid var(--color-border)' }}>
            <div style={{ background: 'var(--chrome-bg)', color: '#fff', padding: '16px', textAlign: 'center' }}>
              <i className="fas fa-user-circle" style={{ fontSize: 40 }}></i>
              <div style={{ marginTop: 6, fontWeight: 600 }}>{D.user.name}</div>
              <div style={{ fontSize: 12, opacity: .7 }}>{D.user.role}</div>
            </div>
            <div style={{ padding: 8, display: 'flex', flexDirection: 'column', gap: 4 }}>
              <div style={{ padding: '7px 10px', borderRadius: 6, fontSize: 13, cursor: 'pointer' }}><i className="fas fa-user-cog fa-fw" style={{ marginRight: 8, color: 'var(--color-text-muted)' }}></i>Account</div>
              <div onClick={onLogout} style={{ padding: '7px 10px', borderRadius: 6, fontSize: 13, cursor: 'pointer', color: 'var(--danger)' }}><i className="fas fa-sign-out-alt fa-fw" style={{ marginRight: 8 }}></i>Logout</div>
            </div>
          </div>
        )}
      </div>
    </nav>
  );
}

function Shell({ active, onNavigate, onLogout, children, nav, brand, brandHref }) {
  const [collapsed, setCollapsed] = useState(false);
  return (
    <div style={{ display: 'flex', height: '100vh', overflow: 'hidden', fontFamily: 'var(--font-sans)', fontSize: 'var(--text-sm)' }}>
      <Sidebar active={active} onNavigate={onNavigate} collapsed={collapsed} nav={nav} brand={brand} brandHref={brandHref} />
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', minWidth: 0 }}>
        <TopNav onToggle={() => setCollapsed((c) => !c)} onLogout={onLogout} onNavigate={onNavigate} />
        <main style={{ flex: 1, overflowY: 'auto', background: 'var(--color-surface-alt)', padding: '18px 22px' }}>
          {children}
        </main>
      </div>
    </div>
  );
}

Object.assign(window, { Shell });

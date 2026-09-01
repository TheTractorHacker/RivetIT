/* Login screen — matches ITFlow's centered card on a teal-tinted page. */
function Login({ onLogin }) {
  const D = window.ITF_DATA;
  const { Button, Input } = window.ITFlow_b1b893;
  return (
    <div style={{
      minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center',
      fontFamily: 'var(--font-sans)', background: 'var(--chrome-bg)',
      backgroundImage: 'radial-gradient(circle at 30% 20%, rgba(13,148,136,.35), transparent 55%), radial-gradient(circle at 80% 90%, rgba(13,148,136,.20), transparent 50%)',
      padding: 20,
    }}>
      <div style={{ width: 380, maxWidth: '100%' }}>
        <div style={{ textAlign: 'center', marginBottom: 22, color: '#fff' }}>
          <span style={{ display: 'inline-flex', width: 52, height: 52, borderRadius: 13, background: 'var(--color-accent)', alignItems: 'center', justifyContent: 'center' }}>
            <i className="fas fa-bolt" style={{ fontSize: 26, color: '#fff' }}></i>
          </span>
          <div style={{ fontSize: 26, fontWeight: 700, marginTop: 12 }}>{D.company}</div>
          <div style={{ fontSize: 13, opacity: .65 }}>ITFlow · MSP Edition</div>
        </div>
        <div style={{ background: 'var(--color-surface)', borderRadius: 'var(--card-radius)', boxShadow: 'var(--shadow-hover)', padding: 26 }}>
          <h2 style={{ margin: '0 0 4px', fontSize: 20, color: 'var(--color-text)' }}>Sign in</h2>
          <p style={{ margin: '0 0 18px', fontSize: 13, color: 'var(--color-text-muted)' }}>Welcome back. Please enter your details.</p>
          <form onSubmit={(e) => { e.preventDefault(); onLogin(); }}>
            <div style={{ marginBottom: 14 }}>
              <Input label="Email" type="email" icon="envelope" defaultValue="jane@foleyit.com" placeholder="you@company.com" />
            </div>
            <div style={{ marginBottom: 18 }}>
              <Input label="Password" type="password" icon="lock" defaultValue="password" placeholder="••••••••" />
            </div>
            <Button type="submit" block>Sign in</Button>
          </form>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, margin: '16px 0' }}>
            <div style={{ flex: 1, height: 1, background: 'var(--color-border)' }}></div>
            <span style={{ fontSize: 12, color: 'var(--color-text-muted)' }}>or</span>
            <div style={{ flex: 1, height: 1, background: 'var(--color-border)' }}></div>
          </div>
          <Button variant="secondary" block icon="fingerprint" onClick={onLogin}>Sign in with passkey</Button>
        </div>
        <p style={{ textAlign: 'center', fontSize: 12, color: 'rgba(255,255,255,.5)', marginTop: 18 }}>Looking for the client portal? <span style={{ color: 'var(--teal-300)', cursor: 'pointer' }}>Go here</span></p>
      </div>
    </div>
  );
}

Object.assign(window, { Login });

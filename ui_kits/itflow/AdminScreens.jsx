/* Admin — Company Details settings form (mirrors admin/settings_company.php).
   Uses the AdminShell (dark sidebar with "← Back | Administration" header). */

function AdminSettings({ onNavigate }) {
  const D = window.ITF_DATA;
  const { Card, Button, Input, Select } = window.ITFlow_b1b893;
  const [saved, setSaved] = React.useState(false);

  const countries = ['United States', 'Canada', 'United Kingdom', 'Australia', 'Germany', 'France', 'Netherlands', 'New Zealand'];

  function FormGroup({ label, icon, children }) {
    return (
      <div style={{ marginBottom: 16 }}>
        <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: 'var(--color-text)' }}>{label}</label>
        <div style={{ display: 'flex', alignItems: 'stretch', border: '1px solid var(--color-border)', borderRadius: 'var(--input-radius)', overflow: 'hidden', background: 'var(--color-surface)' }}>
          <span style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', width: 38, background: 'var(--color-surface-alt)', borderRight: '1px solid var(--color-border)', color: 'var(--color-text-muted)', flexShrink: 0 }}>
            <i className={`fas fa-fw fa-${icon}`}></i>
          </span>
          <div style={{ flex: 1, minWidth: 0 }}>{children}</div>
        </div>
      </div>
    );
  }

  function FieldInput(props) {
    return (
      <input style={{ width: '100%', border: 'none', outline: 'none', padding: '0.375rem 0.75rem', fontSize: 13, fontFamily: 'var(--font-sans)', color: 'var(--color-text)', background: 'transparent', boxSizing: 'border-box' }} {...props} />
    );
  }

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 18 }}>
        <h1 style={{ margin: 0, fontSize: 22, fontWeight: 700, color: 'var(--color-text)' }}>Administration</h1>
      </div>

      <div style={{ background: 'var(--color-surface)', borderRadius: 'var(--card-radius)', boxShadow: 'var(--card-shadow)', overflow: 'hidden' }}>
        {/* Card header — "card-dark" style */}
        <div style={{ background: 'var(--chrome-bg)', color: '#fff', padding: '12px 16px', display: 'flex', alignItems: 'center', gap: 10 }}>
          <i className="fas fa-fw fa-briefcase"></i>
          <span style={{ fontWeight: 600 }}>Company Details</span>
        </div>

        <div style={{ padding: 20 }}>
          {saved && (
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 14px', background: 'color-mix(in srgb, var(--success) 12%, transparent)', border: '1px solid color-mix(in srgb, var(--success) 30%, transparent)', borderRadius: 'var(--input-radius)', marginBottom: 20, fontSize: 13, color: 'var(--color-text)' }}>
              <i className="fas fa-check-circle" style={{ color: 'var(--success)' }}></i> Company details saved successfully.
            </div>
          )}

          <div style={{ display: 'grid', gridTemplateColumns: '200px 1fr', gap: 24 }}>
            {/* Logo column */}
            <div style={{ textAlign: 'center' }}>
              <div style={{ width: 160, height: 160, borderRadius: 'var(--card-radius)', border: '2px dashed var(--color-border)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', color: 'var(--color-text-muted)', margin: '0 auto 12px', background: 'var(--color-surface-alt)', cursor: 'pointer' }}>
                <i className="fas fa-cloud-upload-alt" style={{ fontSize: 28, marginBottom: 8 }}></i>
                <span style={{ fontSize: 12 }}>Upload logo</span>
                <span style={{ fontSize: 11, marginTop: 4, opacity: .7 }}>JPG / PNG</span>
              </div>
              <div style={{ fontSize: 11, color: 'var(--color-text-muted)' }}>Shown on invoices and the client portal</div>
            </div>

            {/* Fields column */}
            <div>
              <FormGroup label={<>Name <strong style={{ color: 'var(--danger)' }}>*</strong></>} icon="building">
                <FieldInput defaultValue={D.company} placeholder="Company Name" />
              </FormGroup>
              <FormGroup label="Address" icon="map-marker-alt">
                <FieldInput defaultValue="1420 Harbor View Dr" placeholder="Street Address" />
              </FormGroup>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <FormGroup label="City" icon="city">
                  <FieldInput defaultValue="Portland" placeholder="City" />
                </FormGroup>
                <FormGroup label="State / Province" icon="flag">
                  <FieldInput defaultValue="OR" placeholder="State or Province" />
                </FormGroup>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <FormGroup label="Postal Code" icon="envelope">
                  <FieldInput defaultValue="97201" placeholder="Zip or Postal Code" />
                </FormGroup>
                <div style={{ marginBottom: 16 }}>
                  <label style={{ display: 'block', fontSize: 13, fontWeight: 600, marginBottom: 6, color: 'var(--color-text)' }}>Country</label>
                  <div style={{ display: 'flex', alignItems: 'stretch', border: '1px solid var(--color-border)', borderRadius: 'var(--input-radius)', overflow: 'hidden', background: 'var(--color-surface)' }}>
                    <span style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', width: 38, background: 'var(--color-surface-alt)', borderRight: '1px solid var(--color-border)', color: 'var(--color-text-muted)', flexShrink: 0 }}>
                      <i className="fas fa-fw fa-globe-americas"></i>
                    </span>
                    <select defaultValue="United States" style={{ flex: 1, border: 'none', outline: 'none', padding: '0.375rem 0.75rem', fontSize: 13, fontFamily: 'var(--font-sans)', color: 'var(--color-text)', background: 'transparent' }}>
                      {countries.map(c => <option key={c}>{c}</option>)}
                    </select>
                  </div>
                </div>
              </div>
              <FormGroup label="Phone" icon="phone">
                <div style={{ display: 'flex' }}>
                  <FieldInput defaultValue="+1" placeholder="+" style={{ width: 56, borderRight: '1px solid var(--color-border)', flexShrink: 0 }} />
                  <FieldInput defaultValue="(503) 555-0188" placeholder="Phone Number" style={{ flex: 1 }} />
                </div>
              </FormGroup>
              <FormGroup label="Email" icon="envelope">
                <FieldInput type="email" defaultValue="hello@foleyit.com" placeholder="Email address" />
              </FormGroup>
              <FormGroup label="Website" icon="globe">
                <FieldInput defaultValue="https://foleyit.com" placeholder="Website address" />
              </FormGroup>
              <FormGroup label="Tax ID" icon="balance-scale">
                <FieldInput defaultValue="47-0382918" placeholder="Tax ID" />
              </FormGroup>

              <hr style={{ border: 'none', borderTop: '1px solid var(--color-border)', margin: '20px 0' }} />
              <Button icon="check" onClick={() => { setSaved(true); setTimeout(() => setSaved(false), 3000); }}>Save</Button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

/* Admin placeholder for other settings pages */
function AdminPlaceholder({ id, onNavigate }) {
  const D = window.ITF_DATA;
  const allItems = D.adminNav.flatMap(n => n.type === 'group' ? n.children : (n.type === 'item' ? [n] : []));
  const item = allItems.find(n => n.id === id);
  const { Card } = window.ITFlow_b1b893;
  return (
    <div>
      <h1 style={{ margin: '0 0 16px', fontSize: 22, fontWeight: 700, color: 'var(--color-text)' }}>{item ? item.label : id}</h1>
      <Card>
        <div style={{ textAlign: 'center', padding: '50px 20px', color: 'var(--color-text-muted)' }}>
          <i className={`fas fa-${item ? item.icon : 'cog'}`} style={{ fontSize: 40, color: 'var(--color-border)' }}></i>
          <p style={{ marginTop: 14, fontSize: 14 }}>This admin module isn't part of this UI-kit demo.</p>
          <p style={{ fontSize: 13 }}>Explore <strong>Company Details</strong> for a full interactive example.</p>
        </div>
      </Card>
    </div>
  );
}

Object.assign(window, { AdminSettings, AdminPlaceholder });

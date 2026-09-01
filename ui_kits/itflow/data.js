/* Fake data for the ITFlow UI kit — representative MSP records. */
window.ITF_DATA = {
  // Matches $session_company_name from ITFlow admin config
  // (Admin → Settings → Company Name). Change this to match your instance.
  company: 'Foley IT',
  user: { name: 'Jane Quinn', role: 'Administrator' },

  // Agent sidebar — mirrors agent/includes/side_nav.php exactly
  nav: [
    { type: 'item', id: 'dashboard',   label: 'Dashboard',          icon: 'tachometer-alt' },
    { type: 'item', id: 'alerts',      label: 'Alerts',             icon: 'bell',              badge: 4,  badgeColor: 'var(--danger)' },
    { type: 'item', id: 'clients',     label: 'Clients',            icon: 'users',             badge: 38 },

    { type: 'header', label: 'Support' },
    { type: 'item', id: 'tickets',         label: 'Tickets',             icon: 'life-ring',       badge: 42 },
    { type: 'item', id: 'recurring',       label: 'Recurring Tickets',   icon: 'redo-alt' },
    { type: 'item', id: 'projects',        label: 'Projects',            icon: 'project-diagram', badge: 6 },
    { type: 'item', id: 'calendar',        label: 'Calendar',            icon: 'calendar-alt' },
    { type: 'item', id: 'kb',             label: 'Knowledge Base',      icon: 'book' },

    { type: 'header', label: 'Billing' },
    { type: 'item', id: 'quotes',             label: 'Quotes',             icon: 'comment-dollar',    badge: 3 },
    { type: 'item', id: 'invoices',           label: 'Invoices',           icon: 'file-invoice',      badge: 6 },
    { type: 'item', id: 'recurring_invoices', label: 'Recurring Invoices', icon: 'redo-alt' },
    { type: 'item', id: 'revenues',           label: 'Revenues',           icon: 'hand-holding-usd' },
    { type: 'item', id: 'products',           label: 'Products',           icon: 'box-open' },

    { type: 'header', label: 'Finance' },
    { type: 'item', id: 'payments',           label: 'Payments',           icon: 'credit-card' },
    { type: 'item', id: 'vendors',            label: 'Vendors',            icon: 'building' },
    { type: 'item', id: 'expenses',           label: 'Expenses',           icon: 'shopping-cart' },
    { type: 'item', id: 'recurring_expenses', label: 'Recurring Expenses', icon: 'redo-alt' },
    { type: 'item', id: 'accounts',           label: 'Accounts',           icon: 'piggy-bank' },
    { type: 'item', id: 'transfers',          label: 'Transfers',          icon: 'exchange-alt' },
    { type: 'item', id: 'trips',             label: 'Trips',              icon: 'route' },

    { type: 'header', label: 'RMM' },
    { type: 'item', id: 'rmm_dashboard',  label: 'RMM Dashboard',   icon: 'tachometer-alt' },
    { type: 'item', id: 'rmm_assets',     label: 'Assets',           icon: 'desktop' },
    { type: 'item', id: 'rmm_alerts',     label: 'RMM Alerts',       icon: 'bell' },
    { type: 'item', id: 'rmm_scripts',    label: 'Scripts',          icon: 'code' },
    { type: 'item', id: 'rmm_checks',     label: 'Check Policies',   icon: 'heartbeat' },
    { type: 'item', id: 'network',        label: 'Network',          icon: 'network-wired' },

    { type: 'header', label: 'Backups' },
    { type: 'item', id: 'backups',        label: 'Dashboard',        icon: 'cloud-upload-alt' },

    { type: 'divider' },
    { type: 'item', id: 'client_overview', label: 'Client Overview', icon: 'users',      arrow: true },
    { type: 'item', id: 'reports',         label: 'Reports',         icon: 'chart-line',  arrow: true },
  ],

  // Admin sidebar — mirrors admin/includes/side_nav.php exactly
  adminNav: [
    { type: 'header', label: 'Access' },
    { type: 'item', id: 'users',    label: 'Users',    icon: 'users' },
    { type: 'item', id: 'roles',    label: 'Roles',    icon: 'user-shield' },
    { type: 'item', id: 'api_keys', label: 'API Keys', icon: 'key' },

    { type: 'header', label: 'Configuration' },
    { type: 'group', id: 'tags_cats', label: 'Tags & Categories', icon: 'sliders-h', children: [
      { id: 'tag',          label: 'Tags',          icon: 'tags' },
      { id: 'category',     label: 'Categories',    icon: 'list-ul' },
      { id: 'custom_link',  label: 'Custom Links',  icon: 'external-link-alt' },
      { id: 'ai_provider',  label: 'AI Providers',  icon: 'robot' },
    ]},
    { type: 'group', id: 'billing_cfg', label: 'Billing', icon: 'hand-holding-usd', children: [
      { id: 'tax',              label: 'Taxes',             icon: 'balance-scale' },
      { id: 'payment_method',   label: 'Payment Methods',   icon: 'money-check-alt' },
      { id: 'payment_provider', label: 'Payment Providers', icon: 'credit-card' },
    ]},
    { type: 'group', id: 'ticketing_cfg', label: 'Ticketing', icon: 'life-ring', children: [
      { id: 'ticket_status',     label: 'Ticket Statuses',   icon: 'info-circle' },
      { id: 'labor_type',        label: 'Labor Types',       icon: 'clock' },
      { id: 'ticket_automation', label: 'Ticket Automation', icon: 'robot' },
    ]},
    { type: 'group', id: 'templates_cfg', label: 'Templates', icon: 'copy', children: [
      { id: 'contract_template',   label: 'Contract Templates',   icon: 'file-contract' },
      { id: 'project_template',    label: 'Project Templates',    icon: 'project-diagram' },
      { id: 'onboarding_template', label: 'Onboarding Templates', icon: 'user-plus' },
      { id: 'ticket_template',     label: 'Ticket Templates',     icon: 'life-ring' },
      { id: 'canned_responses',    label: 'Canned Responses',     icon: 'comment-dots' },
      { id: 'worksheet_template',  label: 'Worksheet Templates',  icon: 'clipboard-list' },
      { id: 'document_template',   label: 'Document Templates',   icon: 'file-alt' },
    ]},
    { type: 'group', id: 'maintenance_cfg', label: 'Maintenance', icon: 'tools', children: [
      { id: 'cron',               label: 'Cron',               icon: 'clock' },
      { id: 'mail_queue',         label: 'Mail Queue',         icon: 'mail-bulk' },
      { id: 'audit_log',          label: 'Audit Logs',         icon: 'history' },
      { id: 'app_log',            label: 'App Logs',           icon: 'history' },
      { id: 'backup',             label: 'Backup',             icon: 'cloud-upload-alt' },
      { id: 'credential_restore', label: 'Credential Restore', icon: 'key' },
      { id: 'debug',              label: 'Debug',              icon: 'bug' },
      { id: 'update',             label: 'Update',             icon: 'download' },
    ]},
    { type: 'group', id: 'settings_cfg', label: 'Settings', icon: 'cog', open: true, children: [
      { id: 'settings_company',       label: 'Company Details', icon: 'briefcase' },
      { id: 'settings_localization',  label: 'Localization',    icon: 'globe' },
      { id: 'settings_theme',         label: 'Theme',           icon: 'paint-brush' },
      { id: 'settings_security',      label: 'Security',        icon: 'shield-alt' },
      { id: 'settings_mail',          label: 'Mail',            icon: 'envelope' },
      { id: 'settings_notification',  label: 'Notifications',   icon: 'bell' },
      { id: 'settings_default',       label: 'Defaults',        icon: 'cogs' },
      { id: 'settings_invoice',       label: 'Invoice',         icon: 'file-invoice' },
      { id: 'settings_quote',         label: 'Quote',           icon: 'comment-dollar' },
      { id: 'settings_ticket',        label: 'Ticket',          icon: 'life-ring' },
      { id: 'settings_module',        label: 'Modules',         icon: 'cube' },
      { id: 'settings_webhooks',      label: 'Webhooks',        icon: 'satellite-dish' },
      { id: 'settings_integrations',  label: 'Integrations',    icon: 'plug' },
      { id: 'settings_calendar_sync', label: 'Calendar Sync',   icon: 'calendar-alt' },
    ]},
  ],

  tickets: [
    { id: 4821, board: 'Network', subject: 'VPN tunnel dropping every few hours', client: 'Brightwave Dental', contact: 'Erin Mossback', priority: 'High', status: 'In Progress', statusColor: '#0D9488', assignee: 'Marco Diaz', onsite: false, updated: '12m ago', overdue: false },
    { id: 4820, board: 'Network', subject: 'New switch install — 24 port PoE', client: 'Harbor Logistics', contact: 'Sam Tran', priority: 'Medium', status: 'Scheduled', statusColor: '#6610F2', assignee: 'Jane Quinn', onsite: true, updated: '1h ago', overdue: false },
    { id: 4818, board: 'Hardware', subject: 'Laptop won’t boot — blue screen on startup', client: 'Pinewood Realty', contact: 'Dana Cole', priority: 'High', status: 'Waiting on Customer', statusColor: '#FFC107', assignee: 'Priya Shah', onsite: false, updated: '3h ago', overdue: true },
    { id: 4815, board: 'Hardware', subject: 'Printer offline in accounting', client: 'Brightwave Dental', contact: 'Erin Mossback', priority: 'Low', status: 'New', statusColor: '#17A2B8', assignee: null, onsite: false, updated: '4h ago', overdue: false },
    { id: 4811, board: 'Software', subject: 'Office 365 license reassignment for new hire', client: 'Lumen Studio', contact: 'Theo Park', priority: 'Medium', status: 'In Progress', statusColor: '#0D9488', assignee: 'Marco Diaz', onsite: false, updated: '6h ago', overdue: false },
    { id: 4809, board: 'Software', subject: 'QuickBooks multi-user mode error H202', client: 'Harbor Logistics', contact: 'Sam Tran', priority: 'High', status: 'New', statusColor: '#17A2B8', assignee: null, onsite: false, updated: '1d ago', overdue: true },
    { id: 4804, board: 'Software', subject: 'Password reset — domain admin', client: 'Pinewood Realty', contact: 'Dana Cole', priority: 'Low', status: 'Resolved', statusColor: '#28A745', assignee: 'Priya Shah', onsite: false, updated: '1d ago', overdue: false },
  ],

  clients: [
    { name: 'Brightwave Dental', contact: 'Erin Mossback', tickets: 6, assets: 24, balance: 1240, mrr: 850, tag: 'Managed' },
    { name: 'Harbor Logistics', contact: 'Sam Tran', tickets: 4, assets: 58, balance: 0, mrr: 2100, tag: 'Managed' },
    { name: 'Pinewood Realty', contact: 'Dana Cole', tickets: 3, assets: 12, balance: 480, mrr: 540, tag: 'Break-Fix' },
    { name: 'Lumen Studio', contact: 'Theo Park', tickets: 2, assets: 9, balance: 0, mrr: 320, tag: 'Managed' },
    { name: 'Cedar Grove Clinic', contact: 'Nina Alvarez', tickets: 1, assets: 31, balance: 95, mrr: 1180, tag: 'Managed' },
    { name: 'Atlas Manufacturing', contact: 'Owen Bell', tickets: 0, assets: 76, balance: 0, mrr: 3400, tag: 'Managed' },
  ],

  conversation: [
    { who: 'Erin Mossback', role: 'Contact', when: '9:02 AM', internal: false, body: 'The VPN keeps dropping for our remote front desk. It reconnects after a minute but it’s happened maybe a dozen times since yesterday.' },
    { who: 'Marco Diaz', role: 'Technician', when: '9:18 AM', internal: false, body: 'Thanks Erin — I can see the tunnel resets in the firewall logs around the top of each hour. Looks tied to the ISP’s DHCP lease renewal. I’m setting a static WAN reservation now and will monitor.' },
    { who: 'Marco Diaz', role: 'Technician', when: '9:21 AM', internal: true, body: 'Internal: opened a ticket with the ISP (ref 88213) to confirm lease time. Will escalate to onsite if the static reservation doesn’t hold overnight.' },
  ],
};

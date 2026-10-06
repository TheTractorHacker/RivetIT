// Edition differences live here so suite.mjs stays one body of code for both products.
// Product bugs already reported; matching browser errors are tolerated (and listed in the summary) until fixed.
// Remove an entry when its bug is fixed so a regression fails the run again.
export const KNOWN_ISSUES = [
  { re: /Executing inline event handler violates the following Content Security Policy/, note: 'CSP blocks an inline event handler (client_add modal onfocusout="client_duplicate_check()")' },
];

export const EDITIONS = {
  it: {
    label: 'RivetIT',
    detect: /RivetIT/i,
    // guided "Add webhook": 'wizard' (data-wz-*) in RivetIT, 'whf' (data-whf-*) in RivetMSP
    webhookUi: 'wizard',
    webhookNew: '/admin/webhook_new.php',
    webhookList: '/admin/settings_webhooks.php',
    guidesPage: '/admin/settings_webhook_guides.php',
    // pages that exist only in this edition: [name, path, text the page must contain]
    extraPages: [
      ['Departments list', '/agent/clients.php', /Departments?/i],
      ['Training dashboard', '/agent/training_dashboard.php', /Training/i],
      ['Modules settings (Odoo/Training toggles)', '/admin/settings_module.php', /Modules?/i],
    ],
    // ticket-list date-range picker markup (the two editions ship different generations of the widget)
    dr: { btn: '[data-drp] .drp-btn', panelOpen: '.drp-panel:not([hidden])', panel: '.drp-panel', opt: '.drp-opt', custom: '.drp-custom',
      from: '.drp-from', to: '.drp-to', apply: '.drp-apply', label: '[data-drp] .drp-label', fromParam: 'dtf', toParam: 'dtt', selectedAttr: 'aria-selected' },
    clientNoun: 'Department',
    clientAddSubmit: 'button[name=add_client]',
  },
  msp: {
    label: 'RivetMSP',
    detect: /RivetMSP/i,
    webhookUi: 'whf',
    webhookNew: '/admin/webhook_form.php',
    webhookList: '/admin/settings_webhooks.php',
    guidesPage: '/admin/webhook_guides.php',
    extraPages: [
      ['Clients list', '/agent/clients.php', /Clients?/i],
      ['Invoices (Billing)', '/agent/invoices.php', /Invoices?/i],
      ['Quotes (Billing)', '/agent/quotes.php', /Quotes?/i],
    ],
    dr: { btn: '[data-date-range-picker] .drp-button', panelOpen: '.drp-panel:not([hidden])', panel: '.drp-panel', opt: '.drp-option', custom: '.drp-custom',
      from: '[data-drp-in-from]', to: '[data-drp-in-to]', apply: '.drp-apply', label: '[data-date-range-picker] .drp-text', fromParam: 'dtf', toParam: 'dtt', selectedAttr: 'aria-selected' },
    clientNoun: 'Client',
    clientAddSubmit: 'button[name=add_client]',
  },
};

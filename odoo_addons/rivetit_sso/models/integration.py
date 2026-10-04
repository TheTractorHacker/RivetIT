import hashlib
import hmac
import logging
import secrets
from urllib.parse import urlsplit

from odoo import api, fields, models
from odoo.exceptions import ValidationError


_logger = logging.getLogger(__name__)

CALLBACK_PATH = '/client/login_odoo.php'


def valid_https_url(value):
    try:
        parts = urlsplit(value or '')
        return (parts.scheme == 'https' and bool(parts.hostname)
                and not parts.username and not parts.password
                and not parts.fragment and not parts.query)
    except ValueError:
        return False


def _urls_from_rivetit_address(address):
    """The one RivetIT address (https://host) determines both endpoint URLs."""
    base = (address or '').strip().rstrip('/')
    if not base:
        return {}
    url = base + CALLBACK_PATH
    return {'callback_url': url, 'portal_start_url': url}


class RivetITSSOIntegration(models.Model):
    _name = 'rivetit.sso.integration'
    _description = 'RivetIT Department Portal SSO integration'

    def _default_issuer_url(self):
        # Odoo 20 replaced get_param with typed getters; support both.
        params = self.env['ir.config_parameter'].sudo()
        getter = getattr(params, 'get_str', None) or params.get_param
        base = getter('web.base.url') or ''
        netloc = urlsplit(base).netloc
        return 'https://%s' % netloc if netloc else False

    name = fields.Char(required=True, default='RivetIT')
    active = fields.Boolean(default=False)
    client_id = fields.Char(string='Integration ID', required=True, index=True,
                            default='rivetit-department-portal',
                            help='Enter the same Integration ID in RivetIT.')
    issuer_url = fields.Char(string='This Odoo address', required=True, default=_default_issuer_url,
                             help='This Odoo site\'s own address (https, no path).')
    rivetit_url = fields.Char(
        string='RivetIT address',
        help='Your RivetIT address, for example https://helpdesk.example.com. '
             'The sign-in endpoint URLs are filled in from it.')
    portal_start_url = fields.Char(string='RivetIT start URL', required=True)
    callback_url = fields.Char(string='RivetIT callback URL', required=True)
    company_id = fields.Many2one('res.company', required=True,
                                 default=lambda self: self.env.company)
    allow_all_employees = fields.Boolean(
        string='All employees may use it',
        help='When set, every internal user who is linked to an employee in the company can open the '
             'Department Portal; no group membership is needed. RivetIT still only signs in people '
             'who have a RivetIT Department Login set to Odoo employee.')
    secret_hash = fields.Char(copy=False, groups='base.group_system')

    # Write-only secret entry, following the res.users.password pattern: the
    # compute never reveals anything, the inverse hashes on write. The raw
    # value lives only for the duration of the request, so the original
    # guarantee still holds - only the SHA-256 digest is ever persisted, and
    # no database column exists for the raw value at all.
    secret = fields.Char(
        string='Set integration secret', store=False, copy=False,
        compute='_compute_secret', inverse='_set_secret',
        groups='base.group_system',
        help="Paste the same secret you entered in RivetIT. Only its SHA-256 "
             "hash is saved; the raw value is never written to the database. "
             "Leave blank to keep the current secret.")
    has_secret = fields.Boolean(
        string='Secret configured', compute='_compute_has_secret',
        groups='base.group_system')

    @api.onchange('rivetit_url')
    def _onchange_rivetit_url(self):
        for record in self:
            record.update(_urls_from_rivetit_address(record.rivetit_url))

    @api.model_create_multi
    def create(self, vals_list):
        for vals in vals_list:
            vals.update(_urls_from_rivetit_address(vals.get('rivetit_url')))
        return super().create(vals_list)

    def write(self, vals):
        if 'rivetit_url' in vals:
            vals = dict(vals, **_urls_from_rivetit_address(vals.get('rivetit_url')))
        return super().write(vals)

    def _compute_secret(self):
        # Never disclose, not even to an administrator.
        for record in self:
            record.secret = ''

    def _set_secret(self):
        for record in self:
            raw = record.secret
            if not raw:
                # Blank means "leave the existing secret alone".
                continue
            if len(raw) < 32 or len(raw) > 256:
                raise ValidationError(
                    'The integration secret must be between 32 and 256 '
                    'characters. RivetIT requires at least 32.')
            record.secret_hash = hashlib.sha256(raw.encode('utf-8')).hexdigest()

    @api.depends('secret_hash')
    def _compute_has_secret(self):
        for record in self:
            record.has_secret = bool(record.secret_hash)

    def action_generate_secret(self):
        """Mint a strong secret, store its hash, and show it once.

        Returned in a sticky notification rather than written to a field, so
        it still never reaches the database.
        """
        self.ensure_one()
        raw = secrets.token_urlsafe(36)
        self.secret_hash = hashlib.sha256(raw.encode('utf-8')).hexdigest()
        return {
            'type': 'ir.actions.client',
            'tag': 'display_notification',
            'params': {
                'title': 'Copy this secret into RivetIT now',
                'message': '%s\n\nIt is shown only once and is not stored '
                           'anywhere. Paste it into RivetIT\'s "Dedicated '
                           'integration secret" field.' % raw,
                'sticky': True,
                'type': 'warning',
            },
        }

    def action_check_setup(self):
        """Read-only readiness check an administrator can run before the first employee tries."""
        self.ensure_one()
        lines = []
        ok = True

        def report(good, text):
            nonlocal ok
            ok = ok and good
            lines.append(('OK: ' if good else 'PROBLEM: ') + text)

        report(bool(self.secret_hash), 'integration secret is set' if self.secret_hash
               else 'no integration secret is set (use Generate a secret, then paste it into RivetIT)')
        report(bool(self.active), 'integration is active' if self.active
               else 'integration is not active yet (tick Active)')
        employees = self.env['hr.employee'].sudo().search([
            ('company_id', '=', self.company_id.id), ('active', '=', True), ('user_id', '!=', False),
        ], limit=500)
        eligible = employees.filtered(lambda e: e.user_id.active and (
            e.user_id.has_group('base.group_user') if self.allow_all_employees
            else e.user_id.has_group('rivetit_sso.group_rivetit_portal')))
        report(bool(eligible), '%d employee(s) can open the portal' % len(eligible) if eligible
               else 'no employee can open the portal yet (link a user to an employee%s)'
               % ('' if self.allow_all_employees else ' and add them to the RivetIT Department Portal group'))
        if self.rivetit_url or self.callback_url:
            try:
                import requests
                response = requests.get(self.callback_url, timeout=5, allow_redirects=False)
                location = response.headers.get('Location', '')
                if response.status_code in (301, 302, 303) and '/rivetit/sso/authorize' in location:
                    report(True, 'RivetIT answers and Odoo sign-in is enabled there')
                elif response.status_code in (200, 301, 302, 303):
                    report(False, 'RivetIT answers but Odoo sign-in looks switched off or not configured there '
                           '(Administration > Settings > Integrations > Odoo Department Portal sign-in)')
                else:
                    report(False, 'RivetIT answered HTTP %s at the callback address' % response.status_code)
            except Exception as error:
                _logger.info('RivetIT SSO setup check could not reach RivetIT: %s', type(error).__name__)
                report(False, 'could not reach RivetIT at the callback address (%s)' % type(error).__name__)
        return {
            'type': 'ir.actions.client',
            'tag': 'display_notification',
            'params': {
                'title': 'RivetIT SSO is ready' if ok else 'RivetIT SSO needs attention',
                'message': '\n'.join(lines),
                'sticky': True,
                'type': 'success' if ok else 'warning',
            },
        }

    _client_id_unique = models.Constraint('unique(client_id)', 'The SSO client ID must be unique.')

    @api.constrains('issuer_url', 'portal_start_url', 'callback_url')
    def _check_urls(self):
        for record in self:
            if (not valid_https_url(record.issuer_url)
                    or not valid_https_url(record.portal_start_url)
                    or not valid_https_url(record.callback_url)):
                raise ValidationError('SSO URLs must be HTTPS URLs without query strings or fragments.')
            if urlsplit(record.issuer_url).path not in ('', '/'):
                raise ValidationError('The Odoo issuer URL must be the site root.')
            if (not record.callback_url.endswith('/client/login_odoo.php')
                    or record.portal_start_url != record.callback_url):
                raise ValidationError('The RivetIT start and callback URL must be the same login_odoo.php endpoint.')

    @api.constrains('secret_hash')
    def _check_secret_hash(self):
        for record in self:
            if record.secret_hash and (len(record.secret_hash) != 64
                                       or any(c not in '0123456789abcdef' for c in record.secret_hash)):
                raise ValidationError('The integration secret hash must be a lowercase SHA-256 hex digest.')

    def verify_secret(self, raw_secret):
        self.ensure_one()
        if not isinstance(raw_secret, str) or len(raw_secret) < 32 or len(raw_secret) > 256:
            return False
        expected = self.secret_hash or ''
        actual = hashlib.sha256(raw_secret.encode('utf-8')).hexdigest()
        return len(expected) == 64 and hmac.compare_digest(expected, actual)

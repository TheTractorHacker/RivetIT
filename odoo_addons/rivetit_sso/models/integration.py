import hashlib
import hmac
import secrets
from urllib.parse import urlsplit

from odoo import api, fields, models
from odoo.exceptions import ValidationError


def valid_https_url(value):
    try:
        parts = urlsplit(value or '')
        return (parts.scheme == 'https' and bool(parts.hostname)
                and not parts.username and not parts.password
                and not parts.fragment and not parts.query)
    except ValueError:
        return False


class RivetITSSOIntegration(models.Model):
    _name = 'rivetit.sso.integration'
    _description = 'RivetIT Department Portal SSO integration'

    name = fields.Char(required=True, default='RivetIT')
    active = fields.Boolean(default=False)
    client_id = fields.Char(required=True, index=True)
    issuer_url = fields.Char(required=True)
    portal_start_url = fields.Char(required=True)
    callback_url = fields.Char(required=True)
    company_id = fields.Many2one('res.company', required=True)
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

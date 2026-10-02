import hashlib
import hmac
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

    _sql_constraints = [
        ('client_id_unique', 'unique(client_id)', 'The SSO client ID must be unique.'),
    ]

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

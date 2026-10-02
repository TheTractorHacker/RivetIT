from odoo import fields, models


class RivetITSSOCode(models.Model):
    _name = 'rivetit.sso.code'
    _description = 'Short-lived RivetIT SSO authorization code'
    _log_access = True

    code_hash = fields.Char(required=True, index=True, copy=False)
    correlation_id = fields.Char(required=True, index=True)
    issuer_url = fields.Char(required=True)
    database_name = fields.Char(required=True)
    integration_id = fields.Many2one('rivetit.sso.integration', required=True, ondelete='cascade')
    user_id = fields.Many2one('res.users', required=True, ondelete='cascade')
    employee_id = fields.Many2one('hr.employee', required=True, ondelete='cascade')
    company_id = fields.Many2one('res.company', required=True)
    callback_url = fields.Char(required=True)
    challenge = fields.Char(required=True)
    expires_at = fields.Datetime(required=True, index=True)
    consumed_at = fields.Datetime(index=True)

    _sql_constraints = [('code_hash_unique', 'unique(code_hash)', 'The authorization code must be unique.')]

    def _cleanup_codes(self):
        # No user access is granted to this model; scheduled cleanup uses sudo.
        cutoff = fields.Datetime.subtract(fields.Datetime.now(), days=1)
        self.sudo().search([('expires_at', '<', cutoff)]).unlink()

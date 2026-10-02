"""Browser-bound, single-use Department Portal handoff for Odoo 19."""

import base64
import hashlib
import hmac
import json
import logging
import re
import secrets
from datetime import timedelta
from urllib.parse import urlencode

from odoo import fields, http
from odoo.http import request

_logger = logging.getLogger(__name__)
_TOKEN = re.compile(r'^[A-Za-z0-9_-]{43}$')


def _response(message, status=400):
    return request.make_response(
        json.dumps({'error': message}),
        headers=[('Content-Type', 'application/json'), ('Cache-Control', 'no-store'),
                 ('Referrer-Policy', 'no-referrer')], status=status,
    )


def _redirect(url):
    response = request.redirect(url, local=False)
    response.headers['Cache-Control'] = 'no-store'
    response.headers['Referrer-Policy'] = 'no-referrer'
    return response


def _eligible_employee(user, company_id):
    if not user.active or not user.has_group('rivetit_sso.group_rivetit_portal'):
        return None
    employees = request.env['hr.employee'].sudo().search([
        ('user_id', '=', user.id), ('active', '=', True), ('company_id', '=', company_id),
    ], limit=2)
    return employees[0] if len(employees) == 1 else None


class RivetITSSOController(http.Controller):
    @http.route('/rivetit/sso/health', type='http', auth='public', methods=['POST'], csrf=False,
                save_session=False)
    def health(self, **kwargs):
        if request.httprequest.content_length is not None and request.httprequest.content_length > 4096:
            return _response('Request too large.', 413)
        auth = request.httprequest.headers.get('Authorization', '')
        client_id = request.httprequest.form.get('client_id')
        if (not auth.startswith('Bearer ') or len(auth) > 300
                or not isinstance(client_id, str) or len(client_id) > 200):
            return _response('Invalid integration credential.', 401)
        integrations = request.env['rivetit.sso.integration'].sudo().search([
            ('active', '=', True), ('client_id', '=', client_id),
        ], limit=2)
        if len(integrations) != 1 or not integrations.verify_secret(auth[7:]):
            return _response('Invalid integration credential.', 401)
        integration = integrations[0]
        return request.make_response(json.dumps({
            'issuer': integration.issuer_url,
            'database': request.env.cr.dbname,
            'company_id': integration.company_id.id,
        }), headers=[('Content-Type', 'application/json'), ('Cache-Control', 'no-store')])

    @http.route('/rivetit/sso/launch', type='http', auth='user', methods=['GET'], csrf=False)
    def launch(self, **kwargs):
        integration = request.env['rivetit.sso.integration'].sudo().search([
            ('active', '=', True), ('company_id', '=', request.env.company.id),
        ], limit=2)
        if (len(integration) != 1 or not integration.secret_hash
                or not _eligible_employee(request.env.user, integration.company_id.id)):
            return _response('RivetIT portal access is unavailable for this employee.', 403)
        return _redirect(integration.portal_start_url)

    @http.route('/rivetit/sso/authorize', type='http', auth='user', methods=['GET'], csrf=False)
    def authorize(self, client_id=None, redirect_uri=None, state=None,
                  code_challenge=None, code_challenge_method=None, **kwargs):
        if (not isinstance(client_id, str) or not isinstance(redirect_uri, str)
                or not isinstance(state, str) or not _TOKEN.fullmatch(state)
                or not isinstance(code_challenge, str) or not _TOKEN.fullmatch(code_challenge)
                or code_challenge_method != 'S256' or kwargs):
            _logger.warning('RivetIT SSO authorization denied: invalid_request')
            return _response('Invalid authorization request.')
        integrations = request.env['rivetit.sso.integration'].sudo().search([
            ('active', '=', True), ('client_id', '=', client_id),
        ], limit=2)
        if len(integrations) != 1 or not integrations.secret_hash or integrations.callback_url != redirect_uri:
            _logger.warning('RivetIT SSO authorization denied: integration_or_callback')
            return _response('Unknown or disabled RivetIT integration.', 403)
        integration = integrations[0]
        if request.env.company.id != integration.company_id.id:
            _logger.info('RivetIT SSO denied: wrong active company; user=%s', request.env.user.id)
            return _response('Switch to the configured Odoo company before opening RivetIT.', 403)
        employee = _eligible_employee(request.env.user, integration.company_id.id)
        if employee is None:
            _logger.info('RivetIT SSO denied: employee not eligible; user=%s', request.env.user.id)
            return _response('RivetIT portal access is unavailable for this employee.', 403)
        code = secrets.token_urlsafe(32)
        correlation_id = hashlib.sha256(state.encode('ascii')).hexdigest()[:16]
        request.env['rivetit.sso.code'].sudo().create({
            'code_hash': hashlib.sha256(code.encode('ascii')).hexdigest(),
            'correlation_id': correlation_id,
            'issuer_url': integration.issuer_url,
            'database_name': request.env.cr.dbname,
            'integration_id': integration.id,
            'user_id': request.env.user.id,
            'employee_id': employee.id,
            'company_id': integration.company_id.id,
            'callback_url': integration.callback_url,
            'challenge': code_challenge,
            'expires_at': fields.Datetime.now() + timedelta(seconds=60),
        })
        _logger.info('RivetIT SSO authorization issued: correlation=%s user=%s employee=%s integration=%s',
                     correlation_id, request.env.user.id, employee.id, integration.id)
        return _redirect(integration.callback_url + '?' + urlencode({
            'code': code, 'state': state,
        }))

    @http.route('/rivetit/sso/token', type='http', auth='public', methods=['POST'], csrf=False,
                save_session=False)
    def token(self, **kwargs):
        if request.httprequest.content_length is not None and request.httprequest.content_length > 4096:
            return _response('Request too large.', 413)
        # auth='bearer' in Odoo 19 falls back to a browser session when no
        # Authorization header is supplied. Always inspect the header here.
        auth = request.httprequest.headers.get('Authorization', '')
        if not auth.startswith('Bearer ') or len(auth) > 300:
            _logger.warning('RivetIT SSO token denied: missing_credential')
            return _response('Invalid integration credential.', 401)
        raw_secret = auth[7:]
        client_id = request.httprequest.form.get('client_id')
        code = request.httprequest.form.get('code')
        verifier = request.httprequest.form.get('code_verifier')
        callback = request.httprequest.form.get('redirect_uri')
        if (not isinstance(client_id, str) or len(client_id) > 200
                or not isinstance(code, str) or not _TOKEN.fullmatch(code)
                or not isinstance(verifier, str) or not _TOKEN.fullmatch(verifier)
                or not isinstance(callback, str) or len(callback) > 500):
            _logger.warning('RivetIT SSO token denied: invalid_request')
            return _response('Invalid authorization code request.')
        integrations = request.env['rivetit.sso.integration'].sudo().search([
            ('active', '=', True), ('client_id', '=', client_id),
        ], limit=2)
        if (len(integrations) != 1 or integrations.callback_url != callback
                or not integrations.verify_secret(raw_secret)):
            _logger.warning('RivetIT SSO token denied: invalid_credential_or_callback')
            return _response('Invalid integration credential.', 401)
        integration = integrations[0]
        code_hash = hashlib.sha256(code.encode('ascii')).hexdigest()
        # One SQL statement claims a valid code. Concurrent redemptions cannot
        # both succeed; PostgreSQL rechecks the predicate after a row lock wait.
        request.env.cr.execute('''
            UPDATE rivetit_sso_code
               SET consumed_at = NOW()
             WHERE code_hash = %s AND integration_id = %s
               AND callback_url = %s AND issuer_url = %s AND database_name = %s
               AND consumed_at IS NULL
               AND expires_at > NOW()
         RETURNING id
        ''', (code_hash, integration.id, callback, integration.issuer_url, request.env.cr.dbname))
        claimed = request.env.cr.fetchone()
        if not claimed:
            _logger.warning('RivetIT SSO token denied: invalid_expired_or_used_code')
            return _response('Invalid or expired authorization code.', 400)
        record = request.env['rivetit.sso.code'].sudo().browse(claimed[0])
        expected = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode('ascii')).digest()).rstrip(b'=').decode('ascii')
        if not hmac.compare_digest(expected, record.challenge):
            _logger.warning('RivetIT SSO token denied: invalid_verifier correlation=%s', record.correlation_id)
            return _response('Invalid code verifier.', 400)
        user = record.user_id
        employee = _eligible_employee(user, integration.company_id.id)
        if (employee is None or employee.id != record.employee_id.id
                or record.company_id.id != integration.company_id.id):
            _logger.warning('RivetIT SSO token denied: employee_ineligible correlation=%s', record.correlation_id)
            return _response('Employee is no longer eligible.', 403)
        _logger.info('RivetIT SSO authorization redeemed: correlation=%s user=%s employee=%s integration=%s',
                     record.correlation_id, user.id, employee.id, integration.id)
        return request.make_response(json.dumps({
            'issuer': integration.issuer_url,
            'database': request.env.cr.dbname,
            'user_id': user.id,
            'employee_id': employee.id,
            'company_id': integration.company_id.id,
        }), headers=[('Content-Type', 'application/json'), ('Cache-Control', 'no-store'),
                     ('Referrer-Policy', 'no-referrer')])

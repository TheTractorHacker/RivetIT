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

from markupsafe import escape

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


def _page(title, message, status=403):
    """A plain, self-contained explanation page for people (not machines)."""
    body = (
        '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width, initial-scale=1">'
        '<title>%s</title><style>body{font-family:system-ui,sans-serif;background:#f4f6f8;color:#1f2933;'
        'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
        '.c{background:#fff;border-radius:12px;padding:32px;max-width:480px;box-shadow:0 2px 12px rgba(0,0,0,.08)}'
        'h1{font-size:20px;margin:0 0 12px}p{line-height:1.5;margin:0 0 20px}'
        'a{color:#714b67}</style></head><body><div class="c"><h1>%s</h1><p>%s</p>'
        '<a href="/odoo">Back to Odoo</a></div></body></html>'
    ) % (escape(title), escape(title), escape(message))
    return request.make_response(
        body, status=status,
        headers=[('Content-Type', 'text/html; charset=utf-8'), ('Cache-Control', 'no-store'),
                 ('Referrer-Policy', 'no-referrer'),
                 ('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'")],
    )


def _redirect(url):
    response = request.redirect(url, local=False)
    response.headers['Cache-Control'] = 'no-store'
    response.headers['Referrer-Policy'] = 'no-referrer'
    return response


def _check_employee(user, integration):
    """Return (employee, None) when allowed, else (None, a message a person can act on)."""
    if not user.active:
        return None, 'Your Odoo account is not active.'
    if integration.allow_all_employees:
        if not user.has_group('base.group_user'):
            return None, 'The Department Portal is only available to internal Odoo users.'
    elif not user.has_group('rivetit_sso.group_rivetit_portal'):
        return None, ('Your account has not been given access to the Department Portal. Ask an '
                      'administrator to add you to the "RivetIT Department Portal" group.')
    employees = request.env['hr.employee'].sudo().search([
        ('user_id', '=', user.id), ('active', '=', True), ('company_id', '=', integration.company_id.id),
    ], limit=2)
    if not employees:
        return None, ('No employee record is linked to your Odoo login in %s. Ask an administrator to '
                      'set your user as the Related User on your employee record.' % integration.company_id.name)
    if len(employees) > 1:
        return None, 'More than one employee record is linked to your login. Ask an administrator to fix this.'
    return employees[0], None


def _eligible_employee(user, integration):
    return _check_employee(user, integration)[0]


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
        if len(integration) != 1:
            return _page('Department Portal is not set up',
                         'Sign-in to the Department Portal is not set up for %s. Ask an administrator to '
                         'check Settings > RivetIT SSO.' % request.env.company.name)
        if not integration.secret_hash:
            return _page('Department Portal setup is unfinished',
                         'The integration has no secret yet. Ask an administrator to finish it in '
                         'Settings > RivetIT SSO.')
        employee, problem = _check_employee(request.env.user, integration)
        if employee is None:
            return _page('Department Portal is unavailable', problem)
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
            return _page('Department Portal is not set up',
                         'This sign-in request does not match an active RivetIT integration. Ask an '
                         'administrator to check Settings > RivetIT SSO.')
        integration = integrations[0]
        if request.env.company.id != integration.company_id.id:
            _logger.info('RivetIT SSO denied: wrong active company; user=%s', request.env.user.id)
            return _page('Switch company first',
                         'Switch to %s using the company switcher at the top of Odoo, then open the '
                         'Department Portal again.' % integration.company_id.name)
        employee, problem = _check_employee(request.env.user, integration)
        if employee is None:
            _logger.info('RivetIT SSO denied: employee not eligible; user=%s', request.env.user.id)
            return _page('Department Portal is unavailable', problem)
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
        employee = _eligible_employee(user, integration)
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

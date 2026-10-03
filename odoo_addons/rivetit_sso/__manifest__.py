{
    'name': 'RivetIT Department Portal SSO',
    'version': '20.0.1.0.0',
    'summary': 'Launch the RivetIT Department Portal from an Odoo employee session',
    'category': 'Human Resources',
    'license': 'LGPL-3',
    'depends': ['base', 'hr'],
    'data': [
        'security/groups.xml',
        'security/ir.access.csv',
        'views/integration_views.xml',
        'views/portal_menu.xml',
        'data/cleanup_cron.xml',
    ],
    'installable': True,
    'application': False,
}

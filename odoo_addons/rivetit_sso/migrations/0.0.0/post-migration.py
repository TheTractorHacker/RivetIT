def migrate(cr, version):
    # Older integrations stored only the two endpoint URLs; derive the single RivetIT address from them.
    cr.execute("""
        UPDATE rivetit_sso_integration
           SET rivetit_url = regexp_replace(callback_url, '/client/login_odoo\\.php$', '')
         WHERE rivetit_url IS NULL AND callback_url LIKE '%/client/login_odoo.php'
    """)

-- Scratch demo only: fake ManageEngine MDM state so the Users-to-contacts / Mapping tabs render. Nothing here talks to ManageEngine.
INSERT INTO manageengine_mdm_integrations (id, enabled, edition, server_url, stale_days) SELECT 1, 1, 'cloud', 'https://mdm.invalid', 7 WHERE NOT EXISTS (SELECT 1 FROM manageengine_mdm_integrations);
INSERT IGNORE INTO mdm_user_contact_map (integration_id, user_key, user_name, user_email, source) VALUES
 (1, 'qa-ui-1', 'QA-UI- Charles Pachl', 'qa-ui-cpachl@example.invalid', ''),
 (1, 'qa-ui-2', 'QA-UI- Javier Marin-Chavez', 'qa-ui-jmarin@example.invalid', ''),
 (1, 'qa-ui-3', 'QA-UI- Chandra Rao', 'qa-ui-crao@example.invalid', ''),
 (1, 'qa-ui-4', 'QA-UI- A user with a very long display name that keeps going and going', 'qa-ui-long.address.that.keeps.going@subdomain.example.invalid', ''),
 (1, 'qa-ui-5', '', '', '');

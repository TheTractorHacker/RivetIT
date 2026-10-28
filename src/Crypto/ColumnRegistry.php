<?php

declare(strict_types=1);

namespace ITFlow\Crypto;

/**
 * Every column that holds a value written by encryptSetting() (or the TOTP seed form), as one list for the rewrap tool, the Keys panel
 * and the tests. Adding a column that stores encryptSetting() output means adding it here, or a key rotation will not reach it.
 *
 * A column the install does not have (an older schema, a module that was never set up) is skipped at run time, not an error.
 * The vault's own columns (credentials, shares, mobile tokens) are NOT here: see {@see VaultV3}.
 */
final class ColumnRegistry
{
    /** @return list<ColumnSpec> */
    public static function all(): array
    {
        $specs = [];
        // settings (single row, company_id = 1). The first eight were cleartext on old installs and are wrapped by the same lazy re-wrap.
        foreach ([
            'config_slack_bot_token', 'config_login_key_secret', 'config_whitelabel_key', 'config_smtp_password', 'config_azure_client_secret',
            'config_mail_oauth_client_secret', 'config_mail_oauth_refresh_token', 'config_mail_oauth_access_token',
        ] as $c) {
            $specs[] = new ColumnSpec('settings', 'company_id', $c, 'settings', true);
        }
        foreach ([
            'config_outlook_cal_client_secret', 'config_imap_password', 'config_backup_s3_secret_key', 'config_backup_passphrase',
            'config_comet_admin_pass', 'config_comet_totp_secret', 'config_comet_webhook_secret', 'config_oidc_client_secret',
            'config_kb_media_key', 'config_training_youtube_api_key', 'config_redis_password',
            // The RMM external metric store (RivetCore 1.0.0-rc.11, off by default): the endpoint URLs and the bearer token, sealed with encryptSetting().
            'config_rmm_extstore_write_url', 'config_rmm_extstore_read_url', 'config_rmm_extstore_token',
        ] as $c) {
            $specs[] = new ColumnSpec('settings', 'company_id', $c);
        }
        $specs[] = new ColumnSpec('settings', 'company_id', 'config_vault_canonical_key', 'canonical');

        foreach ([
            ['webhooks', 'webhook_id', ['webhook_url', 'webhook_secret', 'webhook_auth_enc']],
            ['payment_providers', 'payment_provider_id', ['payment_provider_private_key', 'payment_provider_webhook_secret']],
            ['ai_providers', 'ai_provider_id', ['ai_provider_api_key']],
            ['rmm_integrations', 'id', ['api_key_enc']],
            ['unifi_integrations', 'id', ['api_key_enc']],
            ['odoo_integrations', 'odoo_integration_id', ['api_key_enc', 'sso_secret_enc']],
            ['microsoft_integrations', 'microsoft_integration_id', ['client_secret_enc', 'token_cache_enc']],
            ['manageengine_mdm_integrations', 'id', ['client_secret_enc', 'refresh_token_enc', 'token_cache_enc', 'api_key_enc']],
            ['google_integrations', 'google_integration_id', ['service_account_json_enc']],
            ['accounting_integrations', 'accounting_id', ['accounting_client_secret', 'accounting_access_token', 'accounting_refresh_token']],
            ['mailboxes', 'mailbox_id', ['mailbox_imap_password_enc', 'mailbox_oauth_refresh_token_enc', 'mailbox_oauth_access_token_enc']],
            ['workflow_run_tasks', 'run_task_id', ['secret_result_enc']],
            ['endpoint_agent_settings', 'id', ['signing_private_key_enc', 'mesh_login_key_enc']],
        ] as [$table, $pk, $cols]) {
            foreach ($cols as $c) {
                $specs[] = new ColumnSpec($table, $pk, $c);
            }
        }
        // The RMM module seals these two through EndpointSecretBox -> encryptSetting() (generic context) too: the secret parameters of a queued job (keyed by the job's uuid, a
        // string) and the values of secret custom fields (a composite integer key: field_id + scope_id). A cleartext value in either is never a ciphertext the module would
        // accept (its box refuses it), so a rewrap never wraps one (wrapPlaintext false). They were missing from this list until now: after a key rotation those values stayed
        // on the old key (v3: under a retired key, or ENC2:), so retiring the key would have made them unreadable.
        $specs[] = new ColumnSpec('rmm_job_extra', 'job_id', 'secret_params_enc', 'settings', false, false);
        $specs[] = new ColumnSpec('rmm_custom_field_values', 'field_id', 'value_enc', 'settings', false, true, null, 'scope_id');
        // RivetCore's auto-install parameter VALUES (rmm_package_params, added with the auto-install tables) are sealed with the same settings vault through
        // PackageParams -> EndpointSecretBox -> encryptSetting() (generic context, so no row binding), but the table is keyed by four columns (package, scope type,
        // scope id, name), which no earlier spec could express. Left out, a rotation would have kept these values on the old key and retiring it would have made
        // them unreadable, exactly as for the two RMM columns above.
        $specs[] = new ColumnSpec('rmm_package_params', 'package_id', 'value_enc', 'settings', false, true, null, null, ['scope_type' => 's', 'scope_id' => 'i', 'name' => 's']);
        // The RMM data store setting's PostgreSQL password (RivetCore 1.0.0-rc.41, migration 0044): a single row, id = 1, sealed the same way
        // (DataStoreSwitch calls SecretBoxInterface::encrypt() with no context, so the generic AAD). Missing from this list, a rotation would
        // leave it on the old key exactly as the two RMM columns above did before they were added; wrapPlaintext stays false - the column is
        // always either NULL or already sealed, never a legacy cleartext value to migrate.
        $specs[] = new ColumnSpec('rmm_data_store_connection', 'id', 'password_sealed', 'settings', false, true);
        // The SSO provider's ID-token signing keys (issue #55): sealed with encryptSetting($pem, 'oidc_signing_key'), i.e. under their OWN context name, so the row
        // is kind 'custom' (the AAD is the fixed name, not the generic one) and keyed by the string kid. Without this entry a settings-key rotation would leave the
        // private key on the old key and retiring that key would make every ID token unsignable.
        $specs[] = new ColumnSpec('oidc_signing_keys', 'okey_kid', 'okey_private_enc', 'custom', false, false,
            static fn (string $id): string => SettingsCrypto::contextFor(\RivetCore\Crypto\KeyPurpose::SETTINGS, \ITFlow\Oidc\KeyStore::CONTEXT));
        // The license keys were cleartext on old installs; every reader goes through decryptSetting().
        $specs[] = new ColumnSpec('software', 'software_id', 'software_key', 'settings', true);
        $specs[] = new ColumnSpec('software_keys', 'software_key_id', 'software_key', 'settings', true);
        $specs[] = new ColumnSpec('recovery_settings', 'setting_key', 'setting_value', 'settings', false, false);
        // TOTP seeds: bound to the user, totp purpose. Cleartext seeds are wrapped.
        $specs[] = new ColumnSpec('users', 'user_id', 'user_token', 'totp', true);

        return $specs;
    }

    /**
     * recovery_settings holds many unrelated keys in one value column; only 'drill_db_pass' is a secret, so that source is filtered
     * (see {@see MysqliRewrapSource}).
     */
    public static function rowFilter(ColumnSpec $spec): ?string
    {
        return $spec->table === 'recovery_settings' ? "`setting_key` = 'drill_db_pass'" : null;
    }

    /** @return array<string, ColumnSpec> name => spec */
    public static function byName(): array
    {
        $out = [];
        foreach (self::all() as $s) {
            $out[$s->name()] = $s;
        }

        return $out;
    }
}

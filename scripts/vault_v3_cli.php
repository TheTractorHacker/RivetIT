#!/usr/bin/env php
<?php

/*
 * Credential vault v3 (RivetCore\Crypto, ADR-011). The flag settings.config_vault_v3_enabled is OFF until you turn it on here.
 *
 *   php scripts/vault_v3_cli.php status
 *   php scripts/vault_v3_cli.php prepare     create the vault data key (DEK) and store its copy wrapped by the key file
 *   php scripts/vault_v3_cli.php enable      turn on v3 for writes and for per-user wraps (after the rewrap has drained the credentials)
 *   php scripts/vault_v3_cli.php disable     turn it off again (v3 fields already written stay readable for sessions that hold the data key)
 *
 * Order: keys_cli.php generate -> rewrap_cli.php -> vault_v3_cli.php prepare -> rewrap_cli.php --vault --dry-run -> rewrap_cli.php --vault
 * -> vault_v3_cli.php enable. Take a database dump first. See docs/KEY_MANAGEMENT.md.
 */
if (php_sapi_name() !== 'cli') { die("CLI only.\n"); }
$root = dirname(__DIR__);
chdir($root);
$cmd = $argv[1] ?? 'status';
require_once $root . '/config.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/functions.php';
use ITFlow\Crypto\VaultV3;
$db = $GLOBALS['mysqli'];
try {
    switch ($cmd) {
        case 'status':
            $r = $db->query('SELECT config_vault_v3_enabled f, config_vault_dek_wrap w, config_vault_v3_prepared_at p FROM settings WHERE company_id = 1')->fetch_assoc();
            echo "flag: " . ((int) $r['f'] === 1 ? 'ON' : 'off') . "\nprepared: " . ($r['w'] !== null && $r['w'] !== '' ? 'yes (' . $r['p'] . ')' : 'no') . "\ncanonical legacy key: " . (getCanonicalVaultKey($db) !== null ? 'present' : 'MISSING') . "\n";
            $c = $db->query("SELECT SUM(credential_password LIKE 'v3:%') v3, SUM(credential_password NOT LIKE 'v3:%' AND credential_password <> '') legacy FROM credentials")->fetch_assoc();
            $u = $db->query("SELECT SUM(user_specific_encryption_ciphertext LIKE 'vw3:%') v3, SUM(user_specific_encryption_ciphertext NOT LIKE 'vw3:%' AND user_specific_encryption_ciphertext <> '') legacy FROM users WHERE user_status = 1")->fetch_assoc();
            echo "credential passwords: v3 " . (int) $c['v3'] . ", legacy " . (int) $c['legacy'] . "\nuser wraps: vw3 " . (int) $u['v3'] . ", legacy " . (int) $u['legacy'] . " (they move at the next login)\n";
            break;
        case 'prepare':
            $r = VaultV3::prepare($db);
            echo $r['message'] . "\n";
            exit($r['status'] === 'refused' ? 1 : 0);
        case 'enable':
            VaultV3::setFlag($db, true);
            \ITFlow\Audit\AuditService::record('crypto.vault_v3_enabled', null, 'settings', 'vault_v3', 'enable', 'Credential vault v3 turned on (CLI)');
            echo "Vault v3 is ON.\n";
            break;
        case 'disable':
            VaultV3::setFlag($db, false);
            \ITFlow\Audit\AuditService::record('crypto.vault_v3_disabled', null, 'settings', 'vault_v3', 'disable', 'Credential vault v3 turned off (CLI)');
            echo "Vault v3 is off.\n";
            break;
        default:
            fwrite(STDERR, "Usage: vault_v3_cli.php status|prepare|enable|disable\n");
            exit(2);
    }
} catch (\RuntimeException $e) {
    fwrite(STDERR, "Refused: " . $e->getMessage() . "\n");
    exit(1);
}

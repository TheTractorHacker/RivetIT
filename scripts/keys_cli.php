#!/usr/bin/env php
<?php

/*
 * Key file management (RivetCore\Crypto, ADR-011). Run on the server, as the owner of the key file (root for /etc/rivetit/keys.json) or
 * as the web server user when it may write the key file's directory.
 *
 *   php scripts/keys_cli.php status [--json] [--inventory]
 *   php scripts/keys_cli.php generate [--fresh] [--path=/etc/rivetit/keys.json]
 *   php scripts/keys_cli.php fingerprint [<kid>]
 *   php scripts/keys_cli.php add-key [--no-activate]
 *   php scripts/keys_cli.php set-active <kid>
 *   php scripts/keys_cli.php retire <kid> [--force]
 *
 * Nothing prints key material: only kids, fingerprints, dates and counts. Nothing runs by itself: an existing install is never changed
 * until you run `generate`. Read docs/KEY_MANAGEMENT.md before rotating.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);
chdir($root);
$argvIn = $argv;
array_shift($argvIn);
$flags = [];
$args = [];
foreach ($argvIn as $a) {
    if (str_starts_with($a, '--')) {
        $eq = strpos($a, '=');
        $flags[$eq === false ? substr($a, 2) : substr($a, 2, $eq - 2)] = $eq === false ? true : substr($a, $eq + 1);
    } else {
        $args[] = $a;
    }
}
$cmd = array_shift($args) ?? 'help';

function keys_out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function keys_warn(string $s): void { fwrite(STDERR, "WARNING: " . $s . "\n"); }
function keys_fail(string $s, int $code = 1): never { fwrite(STDERR, "Error: " . $s . "\n"); exit($code); }

if ($cmd === 'help' || isset($flags['help'])) {
    keys_out("Usage: php scripts/keys_cli.php <status|generate|fingerprint|add-key|set-active|retire> [options]");
    keys_out("  status [--json] [--inventory]  where the keys come from, the kids, fingerprints, ages, permissions; --inventory adds how many stored values use each key");
    keys_out("  generate [--fresh] [--path=]    create the key file. With config.php's \$config_settings_enc_key the legacy key becomes kid k1 (existing values and the");
    keys_out("                                  backup fingerprint carry over); --fresh starts from a new random key instead. Refuses to overwrite.");
    keys_out("  fingerprint [<kid>]             the public fingerprint of a key (the value a backup manifest records)");
    keys_out("  add-key [--no-activate]         add a new random key and make it active (then run scripts/rewrap_cli.php)");
    keys_out("  set-active <kid>                choose which key new values are sealed with");
    keys_out("  retire <kid> [--force]          remove a non-active key from the file (refused while stored values still use it)");
    exit(0);
}

require_once $root . '/config.php';
require_once $root . '/vendor/autoload.php';

use ITFlow\Crypto\KeyAdmin;
use ITFlow\Crypto\KeyStore;

if (isset($flags['path']) && is_string($flags['path'])) {
    $GLOBALS['config_keyfile'] = $flags['path'];
}

/** kid => number of stored values using it, from the live database (needs the key file to be readable). */
function keys_usage(): array
{
    global $mysqli;
    $svc = new ITFlow\Crypto\RewrapService($mysqli);
    $inv = $svc->inventory();
    $usage = [];
    foreach ($inv['totals'] as $label => $n) {
        if (str_starts_with($label, 'v3:')) {
            $usage[substr($label, 3)] = $n;
        }
    }

    return $usage;
}

try {
    switch ($cmd) {
        case 'status':
            $st = KeyAdmin::status();
            if (isset($flags['inventory'])) {
                global $mysqli;
                $svc = new ITFlow\Crypto\RewrapService($mysqli);
                $inv = $svc->inventory();
                $st['inventory'] = $inv['totals'];
                $st['cleartext_skipped'] = $inv['skipped_cleartext'];
                if (KeyStore::load()->ring->hasActive()) {
                    $plan = $svc->plan($inv);
                    $st['plan'] = $plan->toArray();
                    $st['steps'] = $plan->steps();
                }
            }
            $baks = $st['path'] ? glob($st['path'] . '.bak-*') : [];
            $st['backup_copies'] = $baks ? count($baks) : 0;
            if (isset($flags['json'])) {
                keys_out(json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                break;
            }
            keys_out("Key source        : " . $st['source'] . ($st['path'] ? "  (" . $st['path'] . ", mode " . ($st['mode'] ?? '-') . ")" : ''));
            keys_out("v3 settings stage : " . ($st['stage_on'] ? 'ON' : 'off') . "   v3 TOTP stage: " . ($st['totp_stage_on'] ? 'ON' : 'off'));
            keys_out("Legacy config key : " . ($st['legacy_key_present'] ? 'present in config.php (kept for ENC:/ENC2: values and derived keys)' : 'absent'));
            foreach ($st['keys'] as $k) {
                keys_out(sprintf("  %-18s fp %s  created %s%s%s", $k['kid'], $k['fingerprint'], $k['created'] ?? 'unknown', $k['active'] ? '  ACTIVE' : '', $k['age_months'] !== null ? "  (" . $k['age_months'] . " months)" : ''));
            }
            if (!$st['keys']) {
                keys_out("  (no keys)");
            }
            foreach ($st['warnings'] as $w) { keys_warn($w); }
            if ($st['error']) { keys_warn($st['error']); }
            if ($st['rotation_due']) { keys_warn("The active key is " . KeyAdmin::ROTATION_WARN_MONTHS . " months old or older; plan a rotation (add-key, rewrap, retire)."); }
            if ($st['backup_copies'] > 0) { keys_warn($st['backup_copies'] . " backup copy file(s) of the key file exist (keys.json.bak-N). They hold key material, including keys you retired. Delete them once the change is confirmed and the offline copy is refreshed."); }
            if (isset($st['inventory'])) {
                keys_out("Stored values by format:");
                foreach ($st['inventory'] as $label => $n) { keys_out(sprintf("  %-22s %d", $label, $n)); }
                if ($st['cleartext_skipped'] > 0) { keys_out("  (+ " . $st['cleartext_skipped'] . " cleartext value(s) in columns the rewrap leaves alone)"); }
                if (isset($st['steps'])) { foreach ($st['steps'] as $s) { keys_out("  - " . $s); } }
            }
            break;

        case 'generate':
            keys_warn("This creates the key file. Back it up OFFLINE, separately from the database dump and from the backup passphrase, as soon as it exists.");
            if (isset($flags['fresh']) && KeyStore::legacyKey() !== '') {
                keys_warn("--fresh: a new random key becomes the only key in the file. The values already stored (ENC2:) keep opening through config.php, which must stay as it is. The backup fingerprint recorded so far no longer matches this file.");
            }
            $r = KeyAdmin::generate(null, isset($flags['fresh']));
            keys_out("Created " . $r['path'] . " (" . ($r['origin'] === 'legacy' ? 'from the legacy key' : 'new key') . ")");
            keys_out("Active kid " . $r['kid'] . ", fingerprint " . $r['fingerprint']);
            keys_out("Next: make the file readable by the web server only (chown root:www-data, chmod 0640), then run: php scripts/rewrap_cli.php --dry-run");
            break;

        case 'fingerprint':
            $st = KeyStore::load();
            if (!$st->ring->hasActive()) { keys_fail('No key is available.'); }
            $kids = $args ? [$args[0]] : $st->ring->kids();
            foreach ($kids as $kid) {
                if (!$st->ring->has($kid)) { keys_fail("No key \"$kid\" in the ring."); }
                keys_out($kid . '  ' . $st->ring->fingerprint($kid) . ($kid === $st->ring->activeKid() ? '  (active)' : ''));
            }
            break;

        case 'add-key':
            keys_warn("Take a fresh offline copy of the key file after this, and keep the old keys until every stored value has moved and one backup cycle has passed.");
            $r = KeyAdmin::addKey(!isset($flags['no-activate']));
            keys_out("Added key " . $r['kid'] . " (fingerprint " . $r['fingerprint'] . ")" . ($r['active'] ? ' and made it active' : ''));
            keys_out("Next: php scripts/rewrap_cli.php --dry-run, then php scripts/rewrap_cli.php, repeated until it reports 0 rewrapped.");
            break;

        case 'set-active':
            if (!$args) { keys_fail('Usage: set-active <kid>'); }
            $r = KeyAdmin::setActive($args[0]);
            keys_out("Key " . $r['kid'] . " is now active. New values are sealed with it; run scripts/rewrap_cli.php to move the existing ones.");
            break;

        case 'retire':
            if (!$args) { keys_fail('Usage: retire <kid> [--force]'); }
            if (isset($flags['force'])) {
                keys_warn("--force: any stored value that still uses this key becomes UNREADABLE unless you restore the key from the offline copy.");
            }
            $r = KeyAdmin::retire($args[0], keys_usage(), isset($flags['force']));
            keys_out("Retired key " . $r['kid'] . ($r['values_left_unreadable'] > 0 ? " (" . $r['values_left_unreadable'] . " stored value(s) now unreadable)" : '') . ". Refresh the offline copy of the key file now.");
            break;

        default:
            keys_fail("Unknown command \"$cmd\". Try: php scripts/keys_cli.php help", 2);
    }
} catch (\RuntimeException $e) {
    keys_fail($e->getMessage());
}

<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * Administration > Compliance > Framework (RivetCore 1.0.0-rc.44 customizable frameworks, phase 4).
 *
 * admin/post.php loads this file only for an administrator (and from the page that was the Referer). Every action below still re-checks the
 * administrator flag itself, so the handler is safe if it is ever included from somewhere else. Core has no permissions: this is the gate.
 */

use ITFlow\Compliance\ComplianceService;
use RivetCore\Compliance\Backup\BackupStore;
use RivetCore\Compliance\Profile\ProfileValidationException;
use RivetCore\Compliance\Profile\Requirement;
use RivetCore\Compliance\Profile\RequirementType;

if (!isset($session_is_admin) || !$session_is_admin) {
    http_response_code(403);
    exit('Administrators only.');
}

if (!ComplianceService::profilesReady($mysqli)) {
    flash_alert('Run the database update first.', 'error');
    redirect();
}

/** @return list<Requirement> the requirement rows of the editor form (a row with no type is skipped). Throws ProfileValidationException on a bad row. */
$cf_requirements = static function (): array {
    $types = (array) ($_POST['req_type'] ?? []);
    $classes = (array) ($_POST['req_class'] ?? []);
    $values = (array) ($_POST['req_value'] ?? []);
    $notes = (array) ($_POST['req_note'] ?? []);
    $out = [];
    $errors = [];
    foreach ($types as $i => $type) {
        $type = (string) $type;
        if ($type === '') {
            continue;
        }
        if (!RequirementType::isValid($type)) {
            $errors[] = 'Row ' . ((int) $i + 1) . ': unknown requirement type.';
            continue;
        }
        $class = (string) ($classes[$i] ?? '');
        $unit = RequirementType::unit($type);
        $value = $unit === RequirementType::UNIT_FLAG ? 1 : (int) ($values[$i] ?? 0);
        $note = trim((string) ($notes[$i] ?? ''));
        try {
            $out[] = new Requirement($type, RequirementType::takesClass($type) && $class !== '' ? $class : null, $value, $note === '' ? null : $note);
        } catch (\InvalidArgumentException $e) {
            $errors[] = 'Row ' . ((int) $i + 1) . ': ' . $e->getMessage();
        }
    }
    if ($errors !== []) {
        throw new ProfileValidationException($errors);
    }

    return $out;
};

/** Show every message of a validation problem, then go back. */
$cf_fail = static function (\Throwable $e): void {
    $msgs = $e instanceof ProfileValidationException ? $e->errors() : [$e->getMessage()];
    flash_alert(implode('<br>', array_map(static fn ($m) => nullable_htmlentities((string) $m), $msgs)), 'error');
    redirect();
};

$cf_user = (string) $session_name;
$cf_uid = (int) $session_user_id;

// ---- export (GET: read-only, a download that cannot be sniffed as HTML) --------------------------------------------------------------------------
if (isset($_GET['export_compliance_profile'])) {
    validateCSRFToken($_GET['csrf_token'] ?? '');
    $key = (string) $_GET['export_compliance_profile'];
    $version = isset($_GET['version']) ? (int) $_GET['version'] : null;
    try {
        $json = ComplianceService::profiles($mysqli)->export($key, $version);
    } catch (\Throwable $e) {
        $cf_fail($e);
    }
    $fname = preg_replace('/[^a-z0-9_]/', '', $key) ?: 'framework';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="framework-' . $fname . '.json"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

// ---- select / clear -------------------------------------------------------------------------------------------------------------------------------
if (isset($_POST['select_compliance_profile'])) {
    validateCSRFToken($_POST['csrf_token']);
    $key = trim((string) ($_POST['profile_key'] ?? ''));
    try {
        ComplianceService::profiles($mysqli)->select($key === '' ? null : $key, $cf_uid, $cf_user);
    } catch (\Throwable $e) {
        $cf_fail($e);
    }
    logAction('Compliance', 'Edit', "$session_name " . ($key === '' ? 'cleared the compliance framework' : "selected the compliance framework $key"));
    flash_alert($key === '' ? 'No framework is selected. Nothing is enforced or checked from a framework.' : 'Framework selected. Its requirements are checked from now on and its retention minimums apply.');
    redirect();
}

// ---- create / copy / edit ---------------------------------------------------------------------------------------------------------------------
if (isset($_POST['create_compliance_profile'])) {
    validateCSRFToken($_POST['csrf_token']);
    try {
        ComplianceService::profiles($mysqli)->create(
            trim((string) ($_POST['profile_key'] ?? '')),
            (string) ($_POST['label'] ?? ''),
            (string) ($_POST['description'] ?? ''),
            $cf_requirements(),
            $cf_uid,
            $cf_user,
            null,
            (string) ($_POST['change_note'] ?? '') ?: null
        );
    } catch (\Throwable $e) {
        $cf_fail($e);
    }
    logAction('Compliance', 'Create', "$session_name created a compliance framework");
    flash_alert('Framework created.');
    redirect();
}

if (isset($_POST['copy_compliance_profile'])) {
    validateCSRFToken($_POST['csrf_token']);
    try {
        ComplianceService::profiles($mysqli)->copy(trim((string) ($_POST['source_key'] ?? '')), trim((string) ($_POST['profile_key'] ?? '')), (string) ($_POST['label'] ?? ''), $cf_uid, $cf_user);
    } catch (\Throwable $e) {
        $cf_fail($e);
    }
    logAction('Compliance', 'Create', "$session_name copied a compliance framework");
    flash_alert('Framework copied. Edit the copy to change its requirements.');
    redirect();
}

if (isset($_POST['update_compliance_profile'])) {
    validateCSRFToken($_POST['csrf_token']);
    try {
        // expectedVersion makes a stale edit (somebody saved first) fail with a clear message instead of overwriting.
        ComplianceService::profiles($mysqli)->update(
            trim((string) ($_POST['profile_key'] ?? '')),
            (string) ($_POST['label'] ?? ''),
            (string) ($_POST['description'] ?? ''),
            $cf_requirements(),
            $cf_uid,
            $cf_user,
            (string) ($_POST['change_note'] ?? '') ?: null,
            isset($_POST['expected_version']) ? (int) $_POST['expected_version'] : null
        );
    } catch (\Throwable $e) {
        $cf_fail($e);
    }
    logAction('Compliance', 'Edit', "$session_name edited a compliance framework");
    flash_alert('Framework saved as a new version (if anything changed).');
    redirect();
}

if (isset($_POST['revert_compliance_profile'])) {
    validateCSRFToken($_POST['csrf_token']);
    try {
        ComplianceService::profiles($mysqli)->revertTo(trim((string) ($_POST['profile_key'] ?? '')), (int) ($_POST['version'] ?? 0), $cf_uid, $cf_user);
    } catch (\Throwable $e) {
        $cf_fail($e);
    }
    flash_alert('Restored as a new version.');
    redirect();
}

foreach (['archive' => 'archived', 'unarchive' => 'unarchived', 'delete' => 'deleted'] as $verb => $past) {
    if (isset($_POST[$verb . '_compliance_profile'])) {
        validateCSRFToken($_POST['csrf_token']);
        $key = trim((string) ($_POST['profile_key'] ?? ''));
        try {
            $store = ComplianceService::profiles($mysqli);
            $store->{$verb}($key, $cf_uid, $cf_user);
        } catch (\Throwable $e) {
            $cf_fail($e);
        }
        logAction('Compliance', $verb === 'delete' ? 'Delete' : 'Edit', "$session_name $past a compliance framework");
        flash_alert('Framework ' . $past . '.');
        redirect();
    }
}

// ---- import (a JSON file of at most 64 KiB, parsed strictly by Core) ----------------------------------------------------------------------
if (isset($_POST['import_compliance_profile'])) {
    validateCSRFToken($_POST['csrf_token']);
    $f = $_FILES['profile_file'] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
        flash_alert('Choose a framework JSON file to import.', 'error');
        redirect();
    }
    if ((int) $f['size'] > 65536 || (int) $f['size'] <= 0) {
        flash_alert('The file is too large (64 KiB at most).', 'error');
        redirect();
    }
    $json = (string) file_get_contents((string) $f['tmp_name']);   // size checked above, before parsing
    $asKey = trim((string) ($_POST['as_key'] ?? ''));
    try {
        ComplianceService::profiles($mysqli)->import($json, $cf_uid, $cf_user, $asKey === '' ? null : $asKey, !empty($_POST['as_new_version']));
    } catch (\Throwable $e) {
        $cf_fail($e);
    }
    logAction('Compliance', 'Create', "$session_name imported a compliance framework");
    flash_alert('Framework imported.');
    redirect();
}

// ---- a restore test (the evidence behind restore_test_max_age) ----------------------------------------------------------------------
if (isset($_POST['record_restore_test'])) {
    validateCSRFToken($_POST['csrf_token']);
    try {
        // The form shows and takes the company time zone (docs/TIMESTAMP_DISPLAY.md); the record keeps UTC.
        require_once dirname(__DIR__, 2) . '/includes/rivet_time.php';
        $tz = new \DateTimeZone(rivetCompanyTimezone());
        $utc = new \DateTimeZone('UTC');
        $parseWhen = static function (string $raw, string $label) use ($tz, $utc): \DateTimeImmutable {
            try {
                return (new \DateTimeImmutable($raw, $tz))->setTimezone($utc);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException($label . ' is not a date and time we can read. Use a format like 2026-11-04 20:17.');
            }
        };
        $testedAt = $parseWhen((string) ($_POST['tested_at'] ?? 'now'), 'Tested at');
        $takenRaw = trim((string) ($_POST['backup_taken_at'] ?? ''));
        $taken = $takenRaw === '' ? null : $parseWhen($takenRaw, 'Backup taken at');
        $stores = array_values(array_intersect((array) ($_POST['stores'] ?? []), [BackupStore::CONTROL_DB, BackupStore::RMM_POSTGRES]));
        $counts = [];
        $verify = null;
        $vf = $_FILES['verify_json'] ?? null;
        if (is_array($vf) && ($vf['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $vf['tmp_name'])) {
            if ((int) $vf['size'] > \RivetCore\Compliance\Backup\RestoreTestStore::MAX_VERIFY_BYTES) {
                throw new \InvalidArgumentException('The verify file is too large.');
            }
            $verify = \RivetCore\Compliance\Backup\RestoreTestStore::verifySummary((string) file_get_contents((string) $vf['tmp_name']));
        }
        ComplianceService::restoreTests($mysqli)->record(
            $testedAt,
            ($_POST['result'] ?? '') === 'pass',
            $stores,
            (string) (rivetRmmModuleDataStore($mysqli)),
            $taken,
            $counts,
            $verify,
            ($_POST['duration_s'] ?? '') !== '' ? (int) $_POST['duration_s'] : null,
            (string) ($_POST['note'] ?? '') ?: null,
            $cf_uid,
            trim((string) ($_POST['who'] ?? '')) !== '' ? trim((string) $_POST['who']) : $cf_user
        );
    } catch (\Throwable $e) {
        $cf_fail($e);
    }
    logAction('Compliance', 'Create', "$session_name recorded a restore test");
    flash_alert('Restore test recorded.');
    redirect();
}

/** The current RMM data store kind ('mariadb' or 'postgres'). */
function rivetRmmModuleDataStore(\mysqli $mysqli): string
{
    require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
    require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

    return (string) rivetRmmModule($mysqli)->settings()->dataStore();
}

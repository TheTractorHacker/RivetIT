<?php

/*
 * Demo seed 55-training-delivery: the DELIVERY side of Training (LMS) for the user guide
 * (08-training-assignments-and-records.md and 08b-training-kiosks-learners-certificates.md).
 *
 * Run it AFTER 00-core.sql, 50-training-authoring.php (the courses) and, if you want the employee portal logins,
 * 70-portal.php:
 *
 *     RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/55-training-delivery.php
 *
 * RIVETIT_APP_DIR is the application directory the demo server serves (it holds config.php).
 *
 * WHY PHP AND NOT SQL. Assignments, completions, certificates and kiosk runs are written by the app's own
 * Training services (src/Training): completions are row-hashed, certificate tokens are derived from the
 * server's certificate key, and every change is appended to the hash-chained ledger. Hand-written rows would
 * not verify. This script drives those same services, in the order the Training pages do:
 *
 *   1  rules and manual assignments   RequirementService::save / createManual (they reconcile the assignments)
 *   2  completions                    CompletionService::issue (kiosk-style online records, with certificates),
 *                                     ExternalRecordService (a paper record and an outside card), and a
 *                                     Forklift practical evaluation
 *   3  aging                          the only SQL: a few OPEN assignments get an earlier due date so the demo has
 *                                     overdue and due-soon rows (a rule can never create an assignment that is
 *                                     already late)
 *   4  kiosk                          two training devices, training PINs for a handful of employees, and
 *                                     kiosk runs left part-way through a course (RunService, the same code the
 *                                     kiosk API runs). Waits a few seconds per lesson: the server credits
 *                                     reading time, and an article needs 15 s.
 *
 * WHAT IT ADDS (all fictional, Summit Ridge Manufacturing, "today" = the day you run it):
 *   - Rules: Cybersecurity Awareness for everyone; Workplace Safety Basics for Production, Warehouse & Logistics and
 *     Engineering; HazCom for Production; IT Acceptable Use Policy for Finance, HR and Sales; Forklift Safety
 *     Refresher for the two forklift people. A few manual "Assign training" batches.
 *   - Completions with certificates, an outside forklift card, a paper acknowledgment and a Forklift practical.
 *   - Overdue, due-soon, not-started and in-progress assignments across all seven departments.
 *   - Devices: "Fab Shop Training iPad" (shared, not in Assets) and "Warehouse Break Room PC" (shared, not in Assets).
 *   - Training PINs for five employees; every one uses the demo PIN below.
 *
 * FIXED KEYS the capture script (capture/training-delivery.cjs) relies on - keep the two files in step:
 *   device start token (43 chars) ...... DELIVERY_DEVICE_TOKEN     (the Fab Shop Training iPad)
 *   learner PIN ........................ DELIVERY_PIN
 *
 * IDEMPOTENT: rules use fixed request uids, completions use fixed source keys, devices and PINs are looked up by
 * name, and the kiosk step skips anyone who already has a run. Prints no secrets except the demo PIN.
 */

if (php_sapi_name() !== 'cli') {
    exit("Run this seed from the command line.\n");
}

$app = getenv('RIVETIT_APP_DIR') ?: '/tmp/claude-0/-home-user-RivetIT/8339db22-55d4-5b6a-82d2-15f5d4fccf58/scratchpad/demo-app';
$app = rtrim($app, '/');
if (!is_file($app . '/config.php') || !is_dir($app . '/scripts')) {
    fwrite(STDERR, "seed 55-training-delivery: '$app' is not an application directory (set RIVETIT_APP_DIR).\n");
    exit(1);
}
chdir($app . '/scripts');   // the app uses relative ../ requires, exactly like scripts/setup_cli.php

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Assign\RequirementService;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\SystemCtx;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KioskKeys;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Device\DeviceEnrollment;
use ITFlow\Training\Kiosk\Core\RevisionCache;
use ITFlow\Training\Kiosk\Learn\AttemptService;
use ITFlow\Training\Kiosk\Learn\RunRepo;
use ITFlow\Training\Kiosk\Learn\RunService;
use ITFlow\Training\Kiosk\Pin\PinAdmin;
use ITFlow\Training\Kiosk\Pin\PinService;
use ITFlow\Training\Records\SessionService;
use ITFlow\Training\Records\TrainerService;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Records\CertSecret;
use ITFlow\Training\Reports\SnapshotService;
use ITFlow\Training\Records\CompletionService;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

const DELIVERY_DEVICE_TOKEN = 'ugdeliveryfabshopipadtoken00000000000001abc';   // exactly 43 URL-safe characters
const DELIVERY_PIN = '481516';

// ---- schema repair --------------------------------------------------------------------------------------
// A database built from db.sql (a fresh install) already says "version 2.6.101" but lacks the settings column that
// migration 2.6.101 adds (config_training_device_code_days), and without it the training kiosk treats the schema as
// "not installed": /kiosk/ answers 404 and Devices & PINs shows "The training kiosk tables are not installed yet".
// This is the migration's own statement (admin/database_updates.php, 2.6.101); IF NOT EXISTS makes it a no-op elsewhere.
$mysqli->query("ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_training_device_code_days` tinyint(3) unsigned NOT NULL DEFAULT 3 AFTER `config_training_setup_code_days`");

// ---- who is "logged in" -----------------------------------------------------------------------------------
$row = $mysqli->query("SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example' LIMIT 1")->fetch_assoc();
if (!$row) {
    fwrite(STDERR, "seed 55-training-delivery: run 00-core first (Alex Morgan is missing).\n");
    exit(1);
}
$adminId = (int) $row['user_id'];
$session_user_id = $adminId;
$session_ip = '127.0.0.1';
$session_user_agent = 'user-guide demo seed';

$mod = $mysqli->query("SELECT config_module_enable_training AS enabled FROM settings WHERE company_id = 1")->fetch_assoc();
if ((int) ($mod['enabled'] ?? 0) !== 1) {
    fwrite(STDERR, "seed 55-training-delivery: the Training module is off (Settings > Modules > Show Training (LMS)).\n");
    exit(1);
}

$host = preg_replace('#^https?://#i', '', trim((string) ($config_base_url ?? 'localhost')));
$ctx = new Ctx($mysqli, $adminId, true, 3, 'https://' . rtrim((string) $host, '/'), TrainingSettings::fromDb($mysqli), 'user-guide demo seed', null);
$certKey = CertSecret::fromGlobals();
$completions = new CompletionService($ctx, $certKey);                                        // an agent recording something
$kioskCompletions = new CompletionService(SystemCtx::make($mysqli, 0, 'user-guide demo seed'), $certKey);   // the kiosk finishing a course

// =====================================================================================================
// Helpers
// =====================================================================================================

function say(string $line): void
{
    echo $line . "\n";
}

function one(string $sql): ?array
{
    global $mysqli;
    $r = $mysqli->query($sql);
    $row = $r ? $r->fetch_assoc() : null;
    return $row ?: null;
}

function q(string $s): string
{
    global $mysqli;
    return "'" . $mysqli->real_escape_string($s) . "'";
}

function courseId(string $name): int
{
    $r = one('SELECT course_id FROM training_courses WHERE course_name = ' . q($name) . ' ORDER BY course_id LIMIT 1');
    if (!$r) {
        throw new RuntimeException("Course '$name' does not exist (run 50-training-authoring first).");
    }
    return (int) $r['course_id'];
}

function contactId(string $email): int
{
    $r = one('SELECT contact_id FROM contacts WHERE contact_email = ' . q($email));
    if (!$r) {
        throw new RuntimeException("Person $email is missing (run 00-core first).");
    }
    return (int) $r['contact_id'];
}

function deptId(string $name): int
{
    $r = one('SELECT client_id FROM clients WHERE client_name = ' . q($name));
    if (!$r) {
        throw new RuntimeException("Department $name is missing (run 00-core first).");
    }
    return (int) $r['client_id'];
}

function em(string $first, string $last): string
{
    return strtolower($first) . '.' . strtolower(str_replace("'", '', $last)) . '@summitridge.example';
}

function ymd(int $daysFromToday): string
{
    return Clock::addDays(Clock::todayLocal(), $daysFromToday);
}

function uid32(string $seed): string
{
    return md5('ug-delivery|' . $seed);
}

try {
    $svc = new RequirementService($ctx);
    $today = Clock::todayLocal();

    // =================================================================================================
    // 0  Training devices, a trainer and training PINs
    // =================================================================================================
    say('Devices, trainer and PINs');
    $keys = KioskKeys::fromSecret((string) ($config_settings_enc_key ?? ''));
    $ks = KioskSettings::fromDb($mysqli);
    $enroll = new DeviceEnrollment($ctx, $keys);
    $devices = [
        ['Fab Shop Training iPad', 'Production'],
        ['Warehouse Break Room PC', 'Warehouse & Logistics'],
    ];
    foreach ($devices as [$label, $dept]) {
        if (!one('SELECT kiosk_id FROM training_kiosks WHERE kiosk_label = ' . q($label))) {
            $enroll->enrollHere(null, $label, deptId($dept), false);   // an unlisted (not in Assets), shared device
            say("  device enrolled: $label");
        }
    }
    // The start token is random and shown once; the capture script needs a known one, so the first device gets a fixed token.
    $mysqli->query('UPDATE training_kiosks SET kiosk_token_hash = ' . q(KioskAuth::tokenHash(DELIVERY_DEVICE_TOKEN))
        . ' WHERE kiosk_label = ' . q('Fab Shop Training iPad') . " AND kiosk_status = 'active'");
    $fabId = (int) one('SELECT kiosk_id FROM training_kiosks WHERE kiosk_label = ' . q('Fab Shop Training iPad'))['kiosk_id'];

    // One trainer: Carlos Mendoza (Plant Manager) trains and evaluates, and may set up PINs.
    $carlos = contactId(em('Carlos', 'Mendoza'));
    if (!one('SELECT trainer_contact_id FROM training_trainers WHERE trainer_contact_id = ' . $carlos)) {
        (new TrainerService($ctx))->save($carlos, null, [
            'title' => 'Plant Manager and safety trainer',
            'flags' => ['can_train' => true, 'can_evaluate' => true, 'can_setup_pins' => true, 'can_unlock' => true, 'can_view_team' => true],
            'all_courses' => true, 'all_departments' => true, 'course_ids' => [], 'client_ids' => [],
            'qualifications' => 'Powered industrial truck trainer, 10-hour OSHA outreach trainer.',
            'active' => true,
        ]);
        say('  trainer added: Carlos Mendoza');
    }

    // Kiosk context of the Fab Shop iPad (what kiosk/includes/bootstrap.php builds for a request).
    $kcore = new Ctx($mysqli, 0, false, 0, $ctx->baseUrl, TrainingSettings::fromDb($mysqli), 'user-guide demo seed', null);
    $device = KioskAuth::deviceByTokenHash($mysqli, $ks, KioskAuth::tokenHash(DELIVERY_DEVICE_TOKEN));
    if ($device === null) {
        throw new RuntimeException('The Fab Shop Training iPad could not be opened (see the device list).');
    }
    $kbase = new KioskCtx($kcore, $ks, $keys, $device, null, 'en', hrtime(true));

    // Training PINs: issue a setup slip, then redeem it on the device exactly as the person would (setup code, then PIN twice).
    $withPin = [em('Jake', 'Sullivan'), em('Lena', 'Fischer'), em('Owen', 'Baker'), em('Liam', "O'Connor"), em('Tara', 'Whitfield'), em('Carlos', 'Mendoza')];
    $slipOnly = [em('Maya', 'Singh'), em('Helen', 'Brandt')];
    $need = [];
    foreach ($withPin as $email) {
        $cred = one('SELECT tcred_pin_hash FROM training_learner_credentials WHERE tcred_contact_id = ' . contactId($email));
        if (!$cred || $cred['tcred_pin_hash'] === null) {
            $need[] = contactId($email);
        }
    }
    foreach ($slipOnly as $email) {
        if (!one('SELECT tcred_contact_id FROM training_learner_credentials WHERE tcred_contact_id = ' . contactId($email))) {
            $need[] = contactId($email);
        }
    }
    if ($need !== []) {
        $admin = new PinAdmin($ctx, $keys);
        $batchToken = $admin->issueSlips($need, false);
        $slips = PinAdmin::openSlips($ctx, $keys, $batchToken) ?? [];
        $codes = [];
        foreach ($slips as $sl) {
            $codes[(int) $sl['cid']] = (string) $sl['code'];
        }
        PinAdmin::clearSlips($ctx, $batchToken);
        $pins = new PinService($kbase);
        foreach ($withPin as $email) {
            $cid = contactId($email);
            if (!isset($codes[$cid])) {
                continue;
            }
            $v = $pins->setupCodeVerify($cid, $codes[$cid]);
            if ($v['setup_token'] === null) {
                throw new RuntimeException("Setup code was refused for $email");
            }
            $made = $pins->createFromSetup($cid, $v['setup_token'], DELIVERY_PIN, DELIVERY_PIN, 'learner');
            // That call opened a kiosk session for the person; close it, nobody is sitting at the device.
            $sess = one('SELECT ksess_id FROM training_kiosk_sessions WHERE ksess_contact_id = ' . $cid . ' AND ksess_ended_at_utc IS NULL');
            if ($sess) {
                KioskAuth::endSessionAs($mysqli, (int) $sess['ksess_id'], 'done', ['actor_type' => 'kiosk', 'kiosk_id' => $fabId, 'user_agent' => 'user-guide demo seed']);
            }
            say("  training PIN set: $email");
        }
        say('  setup slips left unredeemed for: ' . implode(', ', $slipOnly));
    }

    // =================================================================================================
    // 1  Rules and manual assignments
    // =================================================================================================
    say('Rules and manual assignments');
    $rules = [
        ['Cybersecurity Awareness - everyone', 'Cybersecurity Awareness', true, [], 30, 21, 'Company-wide annual requirement.'],
        ['Workplace Safety - plant and warehouse', 'Workplace Safety Basics', false,
            ['department' => ['Production', 'Warehouse & Logistics', 'Engineering']], 30, 14, 'Everyone who works on the plant or warehouse floor.'],
        ['HazCom - Production', 'Hazard Communication (HazCom)', false, ['department' => ['Production']], 30, 10, 'Required wherever hazardous chemicals are used.'],
        ['IT Acceptable Use Policy - office staff', 'IT Acceptable Use Policy', false,
            ['department' => ['Finance & Accounting', 'Human Resources', 'Sales & Marketing']], 30, 25, 'Read and acknowledge the current policy.'],
        ['Forklift refresher - operators', 'Forklift Safety Refresher', false,
            ['contact' => [em('Liam', "O'Connor"), em('Frank', 'Delgado')]], 30, 21, 'Powered industrial truck operators.'],
    ];
    foreach ($rules as [$name, $course, $all, $crit, $dueDays, $baselineOffset, $note]) {
        $criteria = [];
        foreach ($crit as $kind => $vals) {
            $criteria[$kind] = array_map(static fn($v) => $kind === 'department' ? deptId($v) : contactId($v), $vals);
        }
        $r = $svc->save(null, null, [
            'request_uid' => uid32('rule|' . $name),
            'name' => $name,
            'course_id' => courseId($course),
            'all_people' => $all,
            'criteria' => $criteria,
            'new_hires_only' => false,
            'due_days' => $dueDays,
            'baseline_due_on' => ymd($baselineOffset),
            'due_days_from_hire' => 7,
            'one_time' => false,
            'required' => true,
            'note' => $note,
        ]);
        say(($r['duplicate'] ? '  rule exists, skipped: ' : '  rule created: ') . $name);
    }

    // Manual "Assign training" batches (what the Assign training button does).
    $manual = [
        ['Workplace Safety Basics', [em('Ben', 'Carter'), em('Zoe', 'Hartman')], 12, 'Trade-show booth duty at the plant next month.', 'ben-zoe-safety'],
        ['Hazard Communication (HazCom)', [em('Tara', 'Whitfield'), em('Liam', "O'Connor")], 9, 'Warehouse now stores solvent drums.', 'whse-hazcom'],
    ];
    foreach ($manual as [$course, $emails, $days, $note, $key]) {
        $ids = array_map('contactId', $emails);
        $already = one('SELECT requirement_id FROM training_requirements WHERE requirement_request_uid = ' . q(uid32('manual|' . $key)));
        if ($already) {
            say("  manual batch exists, skipped: $key");
            continue;
        }
        $svc->createManual($ids, courseId($course), ymd($days), $note, uid32('manual|' . $key));
        say("  manual batch created: $key");
    }

    // =================================================================================================
    // 2  Completions
    // =================================================================================================
    say('Completions');
    // [person first, last, course, days ago, minutes spent, score]
    $online = [
        // Cybersecurity Awareness (revision 2 is current)
        ['Grace', 'Okafor', 'Cybersecurity Awareness', 41, 24, '95.00'],
        ['Tom', 'Kessler', 'Cybersecurity Awareness', 38, 31, '90.00'],
        ['Miguel', 'Alvarez', 'Cybersecurity Awareness', 33, 27, '100.00'],
        ['Sophie', 'Tran', 'Cybersecurity Awareness', 33, 22, '85.00'],
        ['Nina', 'Rossi', 'Cybersecurity Awareness', 28, 35, '90.00'],
        ['Zoe', 'Hartman', 'Cybersecurity Awareness', 21, 29, '95.00'],
        ['Carlos', 'Mendoza', 'Cybersecurity Awareness', 19, 26, '85.00'],
        ['Aisha', 'Rahman', 'Cybersecurity Awareness', 17, 30, '100.00'],
        ['Yuki', 'Tanaka', 'Cybersecurity Awareness', 14, 20, '95.00'],
        ['Ivan', 'Petrov', 'Cybersecurity Awareness', 9, 28, '90.00'],
        ['Emma', 'Novak', 'Cybersecurity Awareness', 6, 33, '85.00'],
        ['Frank', 'Delgado', 'Cybersecurity Awareness', 4, 25, '90.00'],
        // Workplace Safety Basics
        ['Carlos', 'Mendoza', 'Workplace Safety Basics', 20, 38, '95.00'],
        ['Aisha', 'Rahman', 'Workplace Safety Basics', 18, 36, '90.00'],
        ['Emma', 'Novak', 'Workplace Safety Basics', 12, 41, '85.00'],
        ['Frank', 'Delgado', 'Workplace Safety Basics', 10, 34, '100.00'],
        ['Yuki', 'Tanaka', 'Workplace Safety Basics', 8, 30, '90.00'],
        ['Ivan', 'Petrov', 'Workplace Safety Basics', 5, 37, '95.00'],
        ['Tara', 'Whitfield', 'Workplace Safety Basics', 3, 39, '85.00'],
        // HazCom
        ['Carlos', 'Mendoza', 'Hazard Communication (HazCom)', 16, 22, '90.00'],
        ['Aisha', 'Rahman', 'Hazard Communication (HazCom)', 15, 25, '100.00'],
        ['Emma', 'Novak', 'Hazard Communication (HazCom)', 7, 21, '90.00'],
        // A manual assignment that is already finished
        ['Ben', 'Carter', 'Workplace Safety Basics', 2, 33, '90.00'],
    ];
    $n = 0;
    foreach ($online as [$first, $last, $course, $daysAgo, $minutes, $score]) {
        $n++;
        $r = $kioskCompletions->issue([
            'contact_id' => contactId(em($first, $last)),
            'course_id' => courseId($course),
            'method' => 'online',
            'proof' => 'self_pin_signature',
            'actor_type' => 'contact',
            'actor_contact_id' => contactId(em($first, $last)),
            'kiosk_id' => $fabId,
            'source_key' => 'ugd:online-' . $n,
            'completed_on' => ymd(-$daysAgo),
            'score_pct' => $score,
            'pass_mark_pct' => 80,
            'attempts_used' => $score === '100.00' || $score === '95.00' ? 1 : ($n % 4 === 0 ? 2 : 1),
            'duration_minutes' => $minutes,
            'language' => 'en',
            'pin_source' => 'local',
        ]);
        if (!$r['duplicate']) {
            say("  online completion: $first $last - $course (" . ($r['cert_number'] ?? 'no number') . ')');
        }
    }

    // Document acknowledgments on paper (a document has no certificate).
    foreach ([['Grace', 'Okafor', 30, 'ugd:paper-ack-1'], ['Sophie', 'Tran', 27, 'ugd:paper-ack-2']] as [$first, $last, $daysAgo, $key]) {
        $r = $completions->issue([
            'contact_id' => contactId(em($first, $last)),
            'course_id' => courseId('IT Acceptable Use Policy'),
            'revision_id' => (int) (one('SELECT course_current_revision_id AS r FROM training_courses WHERE course_name = ' . q('IT Acceptable Use Policy'))['r']),
            'method' => 'legacy_paper',
            'proof' => 'agent_recorded',
            'source_key' => $key,
            'completed_on' => ymd(-$daysAgo),
            'notes' => 'Signed paper acknowledgment collected at HR orientation.',
        ]);
        if (!$r['duplicate']) {
            say("  paper acknowledgment: $first $last");
        }
    }

    // An outside forklift operator card (external record), entered by the office.
    $r = $completions->issue([
        'contact_id' => contactId(em('Frank', 'Delgado')),
        'course_id' => courseId('Forklift Safety Refresher'),
        'method' => 'external',
        'proof' => 'agent_recorded',
        'source_key' => 'ugd:ext-forklift-frank',
        'completed_on' => ymd(-64),
        'trained_on' => ymd(-64),
        'evaluated_on' => ymd(-64),
        'external_issuer' => 'Great Lakes Equipment Safety',
        'external_ref' => 'GLES-88421',
        'notes' => 'Outside operator card, valid three years. No scan on file: card photocopy was lost in the office move.',
    ]);
    if (!$r['duplicate']) {
        say('  external forklift card: Frank Delgado (' . ($r['cert_number'] ?? '') . ')');
    }

    // A finished hands-on session (Forklift practical): two operators marked present, both passed the practical. The online
    // part is still open for Liam, so his record stays pending; that is the state the Sessions tab and his row show.
    $sessions = new SessionService($ctx, $completions);
    $scopeAll = Scope::forCtx($ctx);
    if (!one("SELECT tsession_id FROM training_sessions WHERE tsession_topic = 'Forklift practical - dock 3'")) {
        $saved = $sessions->save(null, null, [
            'request_uid' => uid32('session|forklift-dock3'),
            'course_id' => courseId('Forklift Safety Refresher'),
            'held_on' => ymd(0),
            'start_time' => '13:00',
            'duration_minutes' => 90,
            'client_id' => deptId('Warehouse & Logistics'),
            'location' => 'Distribution Center - Milwaukee, dock 3',
            'topic' => 'Forklift practical - dock 3',
            'notes' => 'Sit-down counterbalance truck. Signed sheet is on file with the trainer.',
            'trainer_contact_id' => contactId(em('Carlos', 'Mendoza')),
            'trainer_name' => null,
            'evidence_token' => null,
            'remove_reason' => null,
            'attendees' => [
                ['contact_id' => contactId(em('Liam', "O'Connor")), 'attendance' => 'present', 'practical' => 'pass', 'proof' => 'document', 'attest_reason' => null, 'notes' => null],
                ['contact_id' => contactId(em('Frank', 'Delgado')), 'attendance' => 'present', 'practical' => 'pass', 'proof' => 'document', 'attest_reason' => null, 'notes' => null],
            ],
        ], $scopeAll);
        $sessions->finalize((int) $saved['id'], (int) $saved['version'], true, $scopeAll);
        say('  session recorded: Forklift practical - dock 3');
    }

    // =================================================================================================
    // 3  Aging: earlier due dates on a few OPEN assignments
    // =================================================================================================
    say('Aging open assignments');
    // [first, last, course, due date offset in days (negative = late)]
    $aging = [
        ['Lena', 'Fischer', 'Cybersecurity Awareness', -12],
        ['Tom', 'Kessler', 'IT Acceptable Use Policy', -5],
        ['Owen', 'Baker', 'Cybersecurity Awareness', -9],
        ['Jake', 'Sullivan', 'Workplace Safety Basics', -18],
        ['Jake', 'Sullivan', 'Hazard Communication (HazCom)', -6],
        ['Liam', "O'Connor", 'Forklift Safety Refresher', -21],
        ['Liam', "O'Connor", 'Workplace Safety Basics', -3],
        ['Maya', 'Singh', 'Cybersecurity Awareness', -2],
        ['Ben', 'Carter', 'Cybersecurity Awareness', 4],
        ['Raj', 'Patel', 'Cybersecurity Awareness', 6],
        ['Helen', 'Brandt', 'Cybersecurity Awareness', 3],
        ['Nina', 'Rossi', 'IT Acceptable Use Policy', 5],
        ['Sophie', 'Tran', 'IT Acceptable Use Policy', 2],
        ['Tara', 'Whitfield', 'Hazard Communication (HazCom)', 4],
    ];
    foreach ($aging as [$first, $last, $course, $off]) {
        $mysqli->query('UPDATE training_assignments SET tassign_due_on = ' . q(ymd($off)) . ', tassign_original_due_on = ' . q(ymd($off))
            . ' WHERE tassign_status = ' . q('open') . ' AND tassign_contact_id = ' . contactId(em($first, $last))
            . ' AND tassign_course_id = ' . courseId($course));
    }
    say('  done');

    // =================================================================================================
    // 4  Kiosk runs left part-way through a course
    // =================================================================================================
    say('Kiosk runs');
    // [person, course, lessons to finish, start the final exam?]
    $runs = [
        ['Jake', 'Sullivan', 'Workplace Safety Basics', 3, false],
        ['Lena', 'Fischer', 'Cybersecurity Awareness', 2, false],
        ['Owen', 'Baker', 'Cybersecurity Awareness', 1, false],
        ['Liam', "O'Connor", 'Forklift Safety Refresher', 1, false],
        ['Tara', 'Whitfield', 'Hazard Communication (HazCom)', 99, true],
    ];
    $active = [];
    foreach ($runs as [$first, $last, $course, $count, $exam]) {
        $cid = contactId(em($first, $last));
        $courseId = courseId($course);
        $existing = one("SELECT trun_id, trun_status FROM training_runs WHERE trun_contact_id = $cid AND trun_course_id = $courseId ORDER BY trun_id DESC LIMIT 1");
        if ($existing && $existing['trun_status'] !== 'in_progress') {
            say("  run finished, skipped: $first $last - $course");
            continue;
        }
        if ($existing) {
            // Nothing left to do for this run: do not open (and close) another kiosk session on every re-run.
            $runRow = one('SELECT trun_revision_id FROM training_runs WHERE trun_id = ' . (int) $existing['trun_id']);
            $revNow = RevisionCache::get($mysqli, (int) $runRow['trun_revision_id']);
            $byUidNow = RunRepo::lessons($revNow['doc']);
            $doneNow = RunRepo::credited($mysqli, (int) $existing['trun_id']);
            $todoNow = array_filter(array_slice(array_values(array_filter(RunRepo::order($revNow['doc']),
                static fn(string $u) => in_array((string) ($byUidNow[$u]['type'] ?? ''), ['article', 'document', 'image'], true))), 0, $count),
                static fn(string $u) => !isset($doneNow[$u]));
            $attempt = one('SELECT tattempt_id FROM training_attempts WHERE tattempt_run_id = ' . (int) $existing['trun_id']);
            if ($todoNow === [] && (!$exam || $attempt)) {
                say("  run up to date, skipped: $first $last - $course");
                continue;
            }
        }
        $started = Db::tx($mysqli, static function () use ($mysqli, $device, $cid, $ks): array {
            $st = KioskAuth::startSession($mysqli, $device, $cid, 'learner', ['ks' => $ks, 'source' => 'local', 'lang' => 'en']);
            foreach (KioskAuth::startEvents($st, 'user-guide demo seed') as $e) {
                Ledger::append($mysqli, $e);
            }
            return $st;
        });
        $person = one('SELECT c.contact_name, cl.client_name FROM contacts c LEFT JOIN clients cl ON cl.client_id = c.contact_client_id WHERE c.contact_id = ' . $cid);
        $k = $kbase->withKsess($started['row'] + ['contact_name' => $person['contact_name'], 'first' => $first, 'dept' => (string) $person['client_name']]);
        $rs = new RunService($k);
        $state = $rs->start($courseId);
        $rev = RevisionCache::get($mysqli, (int) $state['revision_id']);
        $byUid = RunRepo::lessons($rev['doc']);
        $order = array_values(array_filter(RunRepo::order($rev['doc']), static fn(string $u) => in_array((string) ($byUid[$u]['type'] ?? ''), ['article', 'document', 'image'], true)));
        $doneAlready = (array) $state['done'];
        $todo = array_values(array_filter(array_slice($order, 0, $count), static fn(string $u) => !isset($doneAlready[$u])));
        $active[] = ['k' => $k, 'rs' => $rs, 'run' => (int) $state['run_id'], 'lessons' => $todo, 'ksess' => (int) $started['row']['ksess_id'],
                     'exam' => $exam, 'rev' => $rev, 'name' => "$first $last - $course"];
    }
    // Learners work through their lessons side by side: open, wait for the reading time the server requires, finish.
    for ($round = 0; ; $round++) {
        $open = [];
        foreach ($active as $i => $a) {
            if (isset($a['lessons'][$round])) {
                $a['rs']->lessonOpen($a['run'], $a['lessons'][$round]);
                $open[] = $i;
            }
        }
        if ($open === []) {
            break;
        }
        say('  round ' . ($round + 1) . ': waiting for reading time...');
        // The server credits at most 20 s per tick, and an article needs 40% of its length (up to 4 minutes), so tick every 15 s.
        for ($tries = 0; $tries < 24 && $open !== []; $tries++) {
            sleep(15);
            foreach ($open as $j => $i) {
                $a = $active[$i];
                $uid = $a['lessons'][$round];
                $gate = $a['rs']->tick($a['run'], $uid, ['visible' => true, 'active' => true, 'playing' => false, 'pages_seen' => []]);
                if (!empty($gate['can_complete'])) {
                    $a['rs']->lessonComplete($a['run'], $uid, []);
                    unset($open[$j]);
                }
            }
        }
        if ($open !== []) {
            throw new RuntimeException('A lesson never reached its reading-time gate.');
        }
    }
    foreach ($active as $a) {
        if ($a['exam']) {
            $quizUid = '';
            foreach ($a['rev']['doc']['lessons'] as $l) {
                if (($l['type'] ?? '') === 'quiz') {
                    $quizUid = (string) $l['uid'];
                }
            }
            (new AttemptService($a['k']))->start($a['run'], $quizUid);   // the exam is open and unanswered
            say('  exam started (not submitted): ' . $a['name']);
        }
        KioskAuth::endSessionAs($mysqli, $a['ksess'], 'done', ['actor_type' => 'kiosk', 'kiosk_id' => $fabId, 'user_agent' => 'user-guide demo seed']);
        say('  run in progress: ' . $a['name']);
    }

    // A last full reconcile so every list reads the final state, then today's compliance snapshot (what the nightly job
    // and Admin > Training > Compliance "Capture today's snapshot" write), so the Overview trend has its first point.
    (new AssignmentService($ctx))->reconcile(null, 'reconcile_now');
    SnapshotService::capture($mysqli, Clock::todayLocal());

    say('Done.');
} catch (ApiException $e) {
    fwrite(STDERR, 'seed 55-training-delivery: ' . $e->getMessage() . ' ' . json_encode($e->fields) . ' ' . json_encode($e->data) . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'seed 55-training-delivery: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
}

<?php

/*
 * Demo seed 50-training-authoring: the Training (LMS) courses, question banks, learning path and
 * achievements used by the user guide (07-training-courses-and-content.md) and reused by the
 * training-delivery pages (assignments, records, reports, kiosk).
 *
 * WHY PHP AND NOT SQL. Training data is versioned (published revisions are immutable and
 * row-hashed) and every change is appended to a hash-chained ledger. Rows written with plain SQL
 * would not verify. So this script drives the application's own handlers in src/Training/Api and
 * the services under them, in the order the Training pages do: course_create, course_update,
 * section_create, lesson_create, lesson_update, quiz_update, question_create / question_update,
 * quiz_rule_add, publish, path_save, achievement_save.
 *
 * Run it AFTER 00-core.sql (it looks the admin user up by e-mail):
 *
 *     RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/50-training-authoring.php
 *
 * RIVETIT_APP_DIR is the application directory the demo server serves (it holds config.php).
 * Course covers are stored under <app dir>/uploads/training, so it must be the SERVED copy.
 *
 * What it creates (all fictional, English only):
 *   Courses  Cybersecurity Awareness        published: Version 1 and Version 2, plus newer unpublished edits
 *            Workplace Safety Basics        published: Version 1
 *            Hazard Communication (HazCom)  published: Version 1
 *            Forklift Safety Refresher      published: Version 1 (qualification with a practical evaluation)
 *            Code of Conduct & Ethics       NEVER PUBLISHED: an unfinished draft, so the Courses badge shows
 *            IT Acceptable Use Policy       published required document (article + acknowledgment)
 *   Banks    Cybersecurity core questions, Plant safety core questions (shared Question Library banks)
 *   Path     New Employee Onboarding (Workplace Safety Basics, HazCom, Cybersecurity Awareness)
 *   Badges   Onboarding Complete, Phish Spotter, Safety Champion
 *
 * No cover images are stored. The demo server is PHP's built-in server: the app hands file bytes to
 * nginx through X-Accel-Redirect, which it cannot do, so any uploaded or gallery cover would show as
 * a broken image in screenshots. Without a cover a card uses the course colour and an icon, which
 * is a normal, supported look; each course gets an explicit colour instead.
 *
 * Idempotent: anything whose title already exists is skipped. A never-published course that an
 * interrupted run left without lessons is deleted as a draft and rebuilt. Prints no secrets.
 */

if (php_sapi_name() !== 'cli') {
    exit("Run this seed from the command line.\n");
}

$app = getenv('RIVETIT_APP_DIR') ?: '/tmp/claude-0/-home-user-RivetIT/8339db22-55d4-5b6a-82d2-15f5d4fccf58/scratchpad/demo-app';
$app = rtrim($app, '/');
if (!is_file($app . '/config.php') || !is_dir($app . '/scripts')) {
    fwrite(STDERR, "seed 50-training-authoring: '$app' is not an application directory (set RIVETIT_APP_DIR).\n");
    exit(1);
}
chdir($app . '/scripts');   // the app uses relative ../ requires, exactly like scripts/setup_cli.php

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";

use ITFlow\Training\Api\ApiContext;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Api\CatalogActions;
use ITFlow\Training\Api\CourseActions;
use ITFlow\Training\Api\LessonActions;
use ITFlow\Training\Api\PublishActions;
use ITFlow\Training\Api\QuizActions;
use ITFlow\Training\Authoring\CourseService;
use ITFlow\Training\Authoring\LessonService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Quiz\QuizService;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli->set_charset('utf8mb4');

// ---- who is "logged in" -----------------------------------------------------------------------------------
$row = $mysqli->query("SELECT user_id FROM users WHERE user_email = 'alex.morgan@summitridge.example' LIMIT 1")->fetch_assoc();
if (!$row) {
    fwrite(STDERR, "seed 50-training-authoring: run 00-core first (Alex Morgan is missing).\n");
    exit(1);
}
$adminId = (int) $row['user_id'];
// logAction() reads these globals, exactly as a web request would have them.
$session_user_id = $adminId;
$session_ip = '127.0.0.1';
$session_user_agent = 'user-guide demo seed';

$mod = $mysqli->query("SELECT config_module_enable_training AS enabled FROM settings WHERE company_id = 1")->fetch_assoc();
if ((int) ($mod['enabled'] ?? 0) !== 1) {
    fwrite(STDERR, "seed 50-training-authoring: the Training module is off (Settings > Modules > Show Training (LMS)).\n");
    exit(1);
}

$host = preg_replace('#^https?://#i', '', trim((string) ($config_base_url ?? 'localhost')));
$ctx = new Ctx($mysqli, $adminId, true, 3, 'https://' . rtrim((string) $host, '/'), TrainingSettings::fromDb($mysqli), 'user-guide demo seed', null);

// =====================================================================================================
// Helpers
// =====================================================================================================

/** Calls one of the Training JSON handlers exactly as agent/training_ajax.php would. */
function act(array $handler, array $input): array
{
    global $ctx;
    return $handler($ctx, new ApiContext('POST', $input, $ctx->settings));
}

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

function categoryId(string $name): int
{
    $r = one('SELECT tcat_id FROM training_categories WHERE tcat_name = ' . q($name) . ' AND tcat_archived_at IS NULL');
    if (!$r) {
        throw new RuntimeException("Training category '$name' is missing.");
    }
    return (int) $r['tcat_id'];
}

function userId(string $email): int
{
    $r = one('SELECT user_id FROM users WHERE user_email = ' . q($email) . ' AND user_status = 1');
    if (!$r) {
        throw new RuntimeException("User $email is missing (run 00-core first).");
    }
    return (int) $r['user_id'];
}

function courseId(string $name): int
{
    $r = one('SELECT course_id FROM training_courses WHERE course_name = ' . q($name) . ' ORDER BY course_id LIMIT 1');
    if (!$r) {
        throw new RuntimeException("Course '$name' does not exist.");
    }
    return (int) $r['course_id'];
}

function courseVersion(int $courseId): int
{
    global $ctx;
    return (int) (new CourseService($ctx))->courseObject($courseId)['version'];
}

function lessonVersion(int $lessonId): int
{
    global $ctx;
    return (int) (new LessonService($ctx))->get($lessonId)['version'];
}

/** One lesson: creates it, then writes its text (the builder makes the same two calls). */
function addLesson(int $courseId, ?int $sectionId, string $type, string $title, ?string $html = null, ?int $afterLessonId = null): array
{
    $input = ['course_id' => $courseId, 'type' => $type, 'lang' => 'en', 'title' => $title];
    if ($sectionId !== null) {
        $input['section_id'] = $sectionId;
    }
    if ($afterLessonId !== null) {
        $input['after_lesson_id'] = $afterLessonId;
    }
    $lesson = act([LessonActions::class, 'lessonCreate'], $input);
    if ($html !== null) {
        $lesson = act([LessonActions::class, 'lessonUpdate'], [
            'lesson_id' => $lesson['id'], 'version' => $lesson['version'], 'lang' => 'en', 'fields' => ['body_html' => $html],
        ]);
    }
    return $lesson;
}

function setLessonText(int $lessonId, string $html): void
{
    act([LessonActions::class, 'lessonUpdate'], [
        'lesson_id' => $lessonId, 'version' => lessonVersion($lessonId), 'lang' => 'en', 'fields' => ['body_html' => $html],
    ]);
}

/**
 * One question. $qd: type single|multi|truefalse, text, options [[label, correct, feedback?], ...]
 * (true/false: 'answer' => true|false instead), explanation?, topic?, points?, critical?
 */
function addQuestion(int $bankId, array $qd): int
{
    $created = act([QuizActions::class, 'questionCreate'], ['bank_id' => $bankId, 'type' => $qd['type'], 'lang' => 'en', 'text' => $qd['text']]);
    $options = [];
    if ($qd['type'] === 'truefalse') {
        foreach ($created['options'] as $i => $o) {
            $options[] = ['id' => $o['id'], 'correct' => $i === ($qd['answer'] ? 0 : 1)];
        }
    } else {
        foreach ($qd['options'] as $i => $o) {
            $entry = ['text' => $o[0], 'correct' => (bool) $o[1]];
            if (!empty($o[2])) {
                $entry['feedback'] = $o[2];
            }
            if (isset($created['options'][$i])) {
                $entry['id'] = $created['options'][$i]['id'];
            }
            $options[] = $entry;
        }
    }
    $text = ['text' => $qd['text']];
    foreach (['explanation', 'topic'] as $k) {
        if (!empty($qd[$k])) {
            $text[$k] = $qd[$k];
        }
    }
    act([QuizActions::class, 'questionUpdate'], [
        'question_id' => $created['id'], 'version' => $created['version'], 'lang' => 'en',
        'text' => $text,
        'structure' => ['points' => (int) ($qd['points'] ?? 1), 'critical' => !empty($qd['critical'])],
        'options' => $options,
    ]);
    return (int) $created['id'];
}

/** Adds questions to the bank of the quiz on $lessonId ("Written for this quiz"). */
function fillQuiz(int $lessonId, array $questions): void
{
    global $ctx;
    $quiz = (new QuizService($ctx))->get($lessonId);
    foreach ($questions as $qd) {
        addQuestion((int) $quiz['own_bank_id'], $qd);
    }
}

/** Creates a shared Question Library bank with its questions (skipped when a bank of that name exists). */
function ensureBank(string $name, string $description, array $questions): int
{
    $r = one('SELECT qbank_id FROM training_question_banks WHERE qbank_name = ' . q($name)
        . ' AND qbank_course_id IS NULL AND qbank_parent_id IS NULL AND qbank_quiz_lesson_id IS NULL');
    if ($r) {
        say("  bank exists, skipped: $name");
        return (int) $r['qbank_id'];
    }
    $bank = act([QuizActions::class, 'bankCreate'], ['name' => $name, 'description' => $description]);
    foreach ($questions as $qd) {
        addQuestion((int) $bank['id'], $qd);
    }
    say("  bank created: $name (" . count($questions) . ' questions)');
    return (int) $bank['id'];
}

/**
 * Turns the quiz lesson into the course's final exam, sets its rules and intro. The quiz starts with
 * one implicit source that draws every question written for it; $draw adds shared banks as more
 * sources: [[bankId, count], ...] (count 0 = all).
 */
function setupExam(int $lessonId, array $settings, array $draw = [], ?string $intro = null, string $role = 'exam'): void
{
    global $ctx;
    $quiz = (new QuizService($ctx))->get($lessonId);
    $fields = $settings + ['role' => $role];
    if ($intro !== null) {
        $fields['intro'] = $intro;
    }
    act([QuizActions::class, 'quizUpdate'], ['quiz_id' => $quiz['id'], 'version' => $quiz['version'], 'fields' => $fields]);
    foreach ($draw as [$bankId, $count]) {
        act([QuizActions::class, 'quizRuleAdd'], ['quiz_id' => $quiz['id'], 'bank_id' => $bankId, 'include_descendants' => false, 'count' => $count]);
    }
}

function publish(int $courseId, string $note): int
{
    $r = act([PublishActions::class, 'publish'], [
        'course_id' => $courseId, 'change_note' => $note, 'requires_retraining' => false, 'acknowledge_warnings' => true,
    ]);
    return (int) $r['number'];
}

function setCourse(int $courseId, array $fields, array $tags = []): void
{
    act([CourseActions::class, 'courseUpdate'], ['course_id' => $courseId, 'version' => courseVersion($courseId), 'fields' => $fields]);
    if ($tags) {
        act([CourseActions::class, 'courseSetTags'], ['course_id' => $courseId, 'tags' => $tags]);
    }
}

function newCourse(array $in): int
{
    $r = act([CourseActions::class, 'courseCreate'], $in + ['kind' => 'training', 'languages' => ['en']]);
    return (int) $r['course_id'];
}

function section(int $courseId, string $title): int
{
    return (int) act([CourseActions::class, 'sectionCreate'], ['course_id' => $courseId, 'title' => $title])['id'];
}

/**
 * Runs $build for a course that does not exist yet. An existing finished course is skipped; a
 * never-published one with no lessons (an interrupted run) is deleted first. If the build fails
 * before the course was ever published, the half-built draft is deleted so the next run starts clean.
 */
function buildCourse(string $name, callable $build): void
{
    global $ctx;
    $c = one('SELECT c.course_id, c.course_current_revision_id, (SELECT COUNT(*) FROM training_lessons l WHERE l.lesson_course_id = c.course_id) AS lessons
        FROM training_courses c WHERE c.course_name = ' . q($name) . ' ORDER BY c.course_id LIMIT 1');
    if ($c !== null) {
        if ($c['course_current_revision_id'] === null && (int) $c['lessons'] === 0) {
            (new CourseService($ctx))->deleteDraft((int) $c['course_id']);
            say("  removed an empty leftover draft of: $name");
        } else {
            say("  course exists, skipped: $name");
            return;
        }
    }
    try {
        $build();
    } catch (\Throwable $e) {
        $c = one('SELECT course_id, course_current_revision_id FROM training_courses WHERE course_name = ' . q($name) . ' ORDER BY course_id LIMIT 1');
        if ($c !== null && $c['course_current_revision_id'] === null) {
            try {
                (new CourseService($ctx))->deleteDraft((int) $c['course_id']);
            } catch (\Throwable $ignored) {
            }
        }
        throw $e;
    }
}

// =====================================================================================================
// Question content
// =====================================================================================================

$cyberBank = [
    ['type' => 'single', 'topic' => 'MFA', 'text' => 'What does multi-factor authentication (MFA) add to your password?',
     'options' => [['A second proof of identity, such as a code from your phone', true], ['A longer password that expires more often', false],
                   ['A way to skip signing in on computers you trust', false], ['Automatic locking of your account every night', false]],
     'explanation' => 'MFA asks for something you have (like your phone) as well as something you know, so a stolen password alone is not enough to get in.'],
    ['type' => 'single', 'topic' => 'Removable media', 'text' => 'You find a USB drive in the parking lot. What should you do?',
     'options' => [['Plug it into your computer to find out who owns it', false], ['Plug it into a spare computer on the plant floor', false],
                   ['Hand it to the IT Help Desk without plugging it in', true], ['Throw it in the trash', false]],
     'explanation' => 'Drives left where people will find them are a common attack. Never plug in a device you do not recognise.'],
    ['type' => 'single', 'topic' => 'Remote work', 'text' => 'Which is the safest way to work from a coffee shop?',
     'options' => [['Join the free Wi-Fi and open company files directly', false], ['Use the company VPN, or your phone\'s hotspot', true],
                   ['Ask the staff for the Wi-Fi password so it counts as private', false], ['Turn off the laptop firewall so it connects faster', false]],
     'explanation' => 'Other people on public Wi-Fi may be able to watch your traffic. The VPN, or a hotspot, protects it.'],
    ['type' => 'truefalse', 'topic' => 'Physical security', 'text' => 'It is fine to hold the door for someone who says they forgot their badge.', 'answer' => false,
     'explanation' => 'Politely send them to reception. Following someone through a door (tailgating) bypasses badge access.'],
    ['type' => 'single', 'topic' => 'Passwords', 'text' => 'Which password is the strongest?',
     'options' => [['Summit2026!', false], ['P@ssw0rd', false], ['maple-orbit-seventeen-teapot', true], ['Your dog\'s name and birth year', false]],
     'explanation' => 'Length beats complexity. A passphrase of four or more unrelated words is easy to remember and hard to guess.'],
    ['type' => 'single', 'topic' => 'Updates', 'text' => 'A pop-up says a security update is ready and asks you to restart. When should you do it?',
     'options' => [['As soon as it is convenient today', true], ['Never, updates only slow the computer down', false],
                   ['After a month, to be sure it is stable', false], ['Only when IT sends a reminder e-mail', false]],
     'explanation' => 'Updates fix weaknesses that attackers are already using. Save your work and restart the same day.'],
    ['type' => 'multi', 'topic' => 'Data handling', 'text' => 'Where may you store company files? Select all that apply.',
     'options' => [['The company file server', true], ['The approved cloud storage that IT provides', true],
                   ['A personal cloud storage account', false], ['A USB drive you brought from home', false]],
     'explanation' => 'Keep company data where IT can back it up and protect it. Personal accounts and unmanaged drives are not approved.'],
    ['type' => 'truefalse', 'topic' => 'Reporting', 'text' => 'If you clicked a suspicious link, you should wait to see whether anything happens before telling IT.', 'answer' => false,
     'explanation' => 'Report it straight away. A fast report lets IT reset passwords and contain the problem before it spreads.'],
];

$plantBank = [
    ['type' => 'single', 'topic' => 'PPE', 'text' => 'Where must safety glasses be worn?',
     'options' => [['Only when you operate a machine', false], ['Everywhere on the plant floor', true], ['Only in the loading dock', false], ['Only when a supervisor is watching', false]],
     'explanation' => 'Eye protection is required in every marked plant area, not just at machines. Debris travels.'],
    ['type' => 'truefalse', 'topic' => 'Housekeeping', 'text' => 'A small liquid spill can wait until the end of the shift if you put a chair next to it.', 'answer' => false,
     'explanation' => 'Clean it up now, or block it off with a wet-floor sign and report it. Spills cause slips.'],
    ['type' => 'single', 'topic' => 'Emergency', 'text' => 'You hear the fire alarm. What is your first action?',
     'options' => [['Finish the task you are working on', false], ['Take the elevator to get out faster', false],
                   ['Stop work, make the machine safe if you can, and go to the assembly point', true], ['Go back to your locker for your belongings', false]],
     'explanation' => 'Leave at once by the nearest exit and go to the assembly point for your building. Never use the elevator.'],
    ['type' => 'single', 'topic' => 'Lifting', 'text' => 'Which is the correct way to lift a heavy box from the floor?',
     'options' => [['Keep your legs straight and bend at the waist', false], ['Bend your knees, keep the load close and lift with your legs', true],
                   ['Twist your body while lifting to save time', false], ['Jerk the box up quickly using momentum', false]]],
    ['type' => 'multi', 'topic' => 'Near misses', 'text' => 'Which of these should be reported as a near miss? Select all that apply.',
     'options' => [['A pallet that almost tipped off a rack', true], ['A guard that was left off a machine', true],
                   ['A coworker who nearly slipped on an oily patch', true], ['A normal shift with nothing unusual', false]],
     'explanation' => 'A near miss is an event that could have caused harm. Reporting it lets us fix the cause before someone is hurt.'],
    ['type' => 'single', 'topic' => 'Lockout/Tagout', 'text' => 'You see a red lock and tag on a machine\'s disconnect switch. What does it mean?',
     'options' => [['The machine is broken but you may still use it carefully', false], ['Someone is working on it and it must not be started', true],
                   ['The machine is reserved for the next shift', false], ['It is a spare key holder', false]],
     'explanation' => 'A lock and tag means the equipment is being serviced. Only the person who applied the lock may remove it.'],
    ['type' => 'single', 'topic' => 'Walkways', 'text' => 'You have finished with a pallet jack. Where does it go?',
     'options' => [['Left in the aisle where you stopped', false], ['In front of an exit, so it is easy to find', false],
                   ['Back to its marked parking spot with the forks lowered', true], ['Anywhere that is out of your way', false]]],
    ['type' => 'truefalse', 'topic' => 'First aid', 'text' => 'You should report every injury, even a minor cut, to your supervisor during the same shift.', 'answer' => true,
     'explanation' => 'Small injuries can become infected or point to a hazard. Reporting also gets the first aid kit restocked.'],
];

// =====================================================================================================
// Build
// =====================================================================================================

say('Training authoring seed');

try {
    $marcusId = userId('marcus.lee@summitridge.example');

    // Shared Question Library banks come first: editing a bank later marks every course that draws
    // from it as having unpublished changes.
    say('Question Library');
    $bankA = ensureBank('Cybersecurity core questions', 'Everyday security questions. Reused by the Cybersecurity Awareness exam.', $cyberBank);
    $bankB = ensureBank('Plant safety core questions', 'General plant and warehouse safety questions shared by several courses.', $plantBank);

    say('Courses');

    // ---- 1. Cybersecurity Awareness: Version 1, Version 2, then newer unpublished edits ------------------
    buildCourse('Cybersecurity Awareness', function () use ($marcusId, $bankA): void {
        $cid = newCourse(['name' => 'Cybersecurity Awareness', 'category_id' => categoryId('IT'), 'cover_key' => 'none', 'color' => '#4F46E5']);
        setCourse($cid, [
            'code' => 'SEC-101',
            'summary' => 'How to spot phishing, protect your accounts and report problems quickly. Every Summit Ridge employee takes it once a year.',
            'description_html' => '<p>Most security incidents start with an ordinary person doing something ordinary, like opening an attachment or reusing a password. This course shows you the habits that stop them.</p><ul><li>Recognise phishing e-mails and other tricks</li><li>Choose strong passphrases and turn on MFA</li><li>Keep your devices and company data safe</li><li>Know how and when to report a problem</li></ul>',
            'responsible_user_id' => $marcusId, 'sequential' => true, 'requires_signature' => false,
            'validity_months' => 12, 'renewal_lead_days' => 30,
        ], ['Onboarding', 'Annual']);

        $s1 = section($cid, 'The basics');
        addLesson($cid, $s1, 'article', 'Why security is everyone\'s job', <<<'HTML'
<p>Most security problems at Summit Ridge Manufacturing start with an ordinary person doing something ordinary, such as opening an attachment or reusing a password. Attackers know this, so they aim at people rather than machines.</p>
<h3>What is at stake</h3>
<ul>
<li><strong>The plant.</strong> A ransomware infection can stop the production line and the shipping dock for days.</li>
<li><strong>Customer and supplier data.</strong> Orders, drawings and pricing are exactly what a competitor or a criminal wants.</li>
<li><strong>Your own details.</strong> Payroll and benefits records include addresses and bank information.</li>
</ul>
<div class="tr-callout tr-callout--info"><strong>You are the first line of defence.</strong> No filter catches everything. A quick report from you often stops an attack before it spreads.</div>
<p>This course takes about 20 minutes. Pass the exam with 80% or better to complete it.</p>
HTML);
        $phish = addLesson($cid, $s1, 'article', 'Spotting phishing emails', <<<'HTML'
<p>Phishing is a fake message designed to trick you into handing over a password, money or access. It can arrive by e-mail, text message, phone call or chat.</p>
<h3>Check these before you click</h3>
<ul>
<li><strong>The real sender address.</strong> A display name such as "IT Support" can be typed by anyone. Look at the address behind it.</li>
<li><strong>Urgency and threats.</strong> "Your account will be closed in one hour" is meant to make you skip thinking.</li>
<li><strong>Unexpected attachments.</strong> Be suspicious of invoices, voicemails and "shared documents" you were not expecting.</li>
<li><strong>Links that do not match.</strong> Hover over a link to see where it really goes.</li>
<li><strong>Requests for money or gift cards.</strong> A message from an executive asking you to buy gift cards is always a scam.</li>
</ul>
<div class="tr-callout tr-callout--warning"><strong>When in doubt, report it.</strong> Use the Report phishing button in your e-mail program, or forward the message to helpdesk@summitridge.example. Then delete it.</div>
HTML);
        $pass = addLesson($cid, $s1, 'article', 'Passwords, passphrases and MFA', <<<'HTML'
<p>A password is the key to your work account. Treat it like the key to the plant.</p>
<h3>Good habits</h3>
<ul>
<li><strong>Use a passphrase.</strong> Four or more unrelated words, such as "maple-orbit-seventeen-teapot", are easier to remember and much harder to guess than "Summit2026!".</li>
<li><strong>Use a different password for every account.</strong> When one website is breached, attackers try the same password everywhere else.</li>
<li><strong>Let a password manager remember them.</strong> IT provides one. You only need to remember a single strong passphrase.</li>
<li><strong>Turn on multi-factor authentication (MFA).</strong> A code or approval on your phone stops most stolen-password attacks.</li>
<li><strong>Never share your password.</strong> IT will never ask for it, not even during a support call.</li>
</ul>
HTML);
        $s2 = section($cid, 'Working safely');
        $devices = addLesson($cid, $s2, 'article', 'Protecting devices and company data', <<<'HTML'
<p>Your laptop, phone and the files on them are company property. A few habits keep them safe.</p>
<ul>
<li><strong>Lock your screen</strong> whenever you step away (Windows key + L).</li>
<li><strong>Install updates</strong> the same day you are asked to restart.</li>
<li><strong>Use only approved software.</strong> If you need something new, open a ticket instead of installing it yourself.</li>
<li><strong>Do not plug in unknown USB drives</strong>, including ones you find or are handed at a trade show.</li>
<li><strong>Use the company VPN</strong> on public Wi-Fi, or switch to your phone's hotspot.</li>
<li><strong>Keep company files in approved places</strong>: the file server and the cloud storage IT provides, not personal accounts.</li>
</ul>
<p>If a device is lost or stolen, tell the IT Help Desk right away, at any hour. IT can lock it remotely.</p>
HTML);
        $s3 = section($cid, 'Check your knowledge');
        $exam = addLesson($cid, $s3, 'quiz', 'Cybersecurity Awareness exam');
        fillQuiz($exam['id'], [
            ['type' => 'single', 'critical' => true, 'topic' => 'Phishing', 'points' => 2,
             'text' => 'You receive an e-mail from "IT Support" asking you to confirm your password by clicking a link. What do you do?',
             'options' => [['Click the link and sign in so your account is not closed', false], ['Reply with your password', false],
                           ['Do not click. Report the message to the IT Help Desk', true], ['Forward it to your coworkers to warn them', false]],
             'explanation' => 'Real IT staff never ask for your password. Use the Report phishing button, or forward the message to helpdesk@summitridge.example, then delete it.'],
            ['type' => 'truefalse', 'topic' => 'Passwords', 'text' => 'Using the same password for work and personal accounts is fine if the password is long.', 'answer' => false,
             'explanation' => 'When a personal website is breached, attackers try the leaked password on work accounts. Use a different password everywhere.'],
            ['type' => 'multi', 'topic' => 'Phishing', 'text' => 'Which of these are warning signs of a phishing e-mail? Select all that apply.',
             'options' => [['Urgent threats or short deadlines', true], ['A sender address that does not match the company it claims to be', true],
                           ['A link whose real address differs from the text you see', true], ['A message that arrives in the morning', false]],
             'explanation' => 'Urgency, a mismatched sender and misleading links are classic signs. The time of day tells you nothing.'],
        ]);
        setupExam($exam['id'], ['pass_pct' => 80, 'max_attempts' => 3], [[$bankA, 4]],
            'Seven questions. You need 80% to pass, and every question marked critical must be right. You have three attempts.');

        // A quick check attached to the phishing lesson: it does not count toward completion.
        $check = act([QuizActions::class, 'quizAttach'], ['lesson_id' => $phish['id']]);
        addQuestion((int) $check['own_bank_id'], ['type' => 'single', 'topic' => 'Phishing',
            'text' => 'An e-mail that looks like it is from the CEO asks you to buy gift cards and send the codes. What is it most likely?',
            'options' => [['An urgent request you should hurry to complete', false], ['A scam known as business e-mail compromise', true], ['A new company reward program', false]],
            'explanation' => 'Executives do not ask staff to buy gift cards. Scammers copy their names because people hurry to help.']);
        addQuestion((int) $check['own_bank_id'], ['type' => 'truefalse', 'topic' => 'Phishing', 'text' => 'The display name on an e-mail proves who really sent it.', 'answer' => false,
            'explanation' => 'Anyone can type any display name. Check the actual sender address behind it.']);

        $n = publish($cid, 'First version.');
        say("  Cybersecurity Awareness: Version $n published");

        // Version 2: the annual update adds a lesson and a paragraph.
        addLesson($cid, $s2, 'article', 'Reporting a security problem', <<<'HTML'
<p>Reporting quickly is the most useful thing you can do. Nobody is in trouble for reporting a mistake, but a hidden one can cost the company a great deal.</p>
<h3>Report it when you</h3>
<ul>
<li>clicked a link or opened an attachment you now doubt,</li>
<li>entered your password on a page you now doubt,</li>
<li>lost a laptop, phone or badge,</li>
<li>notice a stranger in a restricted area, or</li>
<li>receive a call asking for passwords or codes.</li>
</ul>
<h3>How to report</h3>
<ol>
<li>Open a ticket from the employee portal, or e-mail helpdesk@summitridge.example.</li>
<li>Say what happened and when. Do not delete anything unless you are told to.</li>
<li>If it is urgent, call the IT Help Desk. It answers at any hour.</li>
</ol>
<div class="tr-callout tr-callout--info"><strong>No blame.</strong> The faster IT hears about it, the more we can undo.</div>
HTML, $devices['id']);
        setLessonText($phish['id'], <<<'HTML'
<p>Phishing is a fake message designed to trick you into handing over a password, money or access. It can arrive by e-mail, text message, phone call or chat.</p>
<h3>Check these before you click</h3>
<ul>
<li><strong>The real sender address.</strong> A display name such as "IT Support" can be typed by anyone. Look at the address behind it.</li>
<li><strong>Urgency and threats.</strong> "Your account will be closed in one hour" is meant to make you skip thinking.</li>
<li><strong>Unexpected attachments.</strong> Be suspicious of invoices, voicemails and "shared documents" you were not expecting.</li>
<li><strong>Links that do not match.</strong> Hover over a link to see where it really goes.</li>
<li><strong>Requests for money or gift cards.</strong> A message from an executive asking you to buy gift cards is always a scam.</li>
</ul>
<h3>Messages written by AI</h3>
<p>Scammers now use AI tools to write their messages. They are often polished and free of spelling mistakes, so do not rely on bad grammar as a warning sign. Judge the request, not the writing.</p>
<div class="tr-callout tr-callout--warning"><strong>When in doubt, report it.</strong> Use the Report phishing button in your e-mail program, or forward the message to helpdesk@summitridge.example. Then delete it.</div>
HTML);
        $n = publish($cid, 'Added a lesson on reporting security problems and a note about AI-written phishing messages.');
        say("  Cybersecurity Awareness: Version $n published");

        // Newer edits that are NOT published yet, so the course shows "Unpublished changes".
        setLessonText($pass['id'], <<<'HTML'
<p>A password is the key to your work account. Treat it like the key to the plant.</p>
<h3>Good habits</h3>
<ul>
<li><strong>Use a passphrase.</strong> Four or more unrelated words, such as "maple-orbit-seventeen-teapot", are easier to remember and much harder to guess than "Summit2026!".</li>
<li><strong>Use a different password for every account.</strong> When one website is breached, attackers try the same password everywhere else.</li>
<li><strong>Let a password manager remember them.</strong> IT provides one. You only need to remember a single strong passphrase.</li>
<li><strong>Turn on multi-factor authentication (MFA).</strong> A code or approval on your phone stops most stolen-password attacks.</li>
<li><strong>Never share your password.</strong> IT will never ask for it, not even during a support call.</li>
</ul>
<h3>Passkeys</h3>
<p>Some sites now offer a passkey, which replaces the password with your fingerprint, face or device PIN. A passkey cannot be typed into a fake website, so use one whenever you are offered it.</p>
HTML);
        setCourse($cid, ['summary' => 'How to spot phishing, protect your accounts and report problems quickly. Every Summit Ridge employee takes it once a year, in about 20 minutes.']);
        say('  Cybersecurity Awareness: newer unpublished edits saved');
    });

    // ---- 2. Workplace Safety Basics ------------------------------------------------------------------------
    buildCourse('Workplace Safety Basics', function () use ($bankB, $adminId): void {
        $cid = newCourse(['name' => 'Workplace Safety Basics', 'category_id' => categoryId('Safety'), 'cover_key' => 'none', 'color' => '#2563EB']);
        setCourse($cid, [
            'code' => 'SAF-100',
            'summary' => 'The everyday safety rules for the plant and the warehouse: PPE, housekeeping, safe lifting and what to do in an emergency.',
            'description_html' => '<p>Everyone who works at Plant 1 or the Distribution Center completes this course before starting on the floor. It covers the habits that prevent most injuries.</p><ul><li>Wear the right PPE</li><li>Keep walkways and work areas clear</li><li>Lift and move loads safely</li><li>Know what to do in a fire or an injury</li></ul>',
            'responsible_user_id' => $adminId, 'sequential' => true, 'requires_signature' => true,
            'attestation_text' => 'I completed Workplace Safety Basics and I understand the rules that apply where I work.',
        ], ['Onboarding', 'Plant floor']);

        $s1 = section($cid, 'Protect yourself');
        addLesson($cid, $s1, 'article', 'Personal protective equipment (PPE)', <<<'HTML'
<p>PPE is the last line of defence. Guards and procedures come first, but PPE protects you when they fail.</p>
<h3>What to wear</h3>
<ul>
<li><strong>Safety glasses</strong> everywhere on the plant floor and in the warehouse, not only at machines.</li>
<li><strong>Safety shoes</strong> with a protective toe in production, shipping and receiving.</li>
<li><strong>Hearing protection</strong> wherever a sign shows it is required, or when your supervisor tells you.</li>
<li><strong>Gloves</strong> chosen for the task: cut-resistant for sheet metal, chemical-resistant for solvents.</li>
</ul>
<div class="tr-callout tr-callout--warning"><strong>Damaged PPE is not PPE.</strong> Cracked glasses, split gloves and worn-out shoes must be replaced. Ask your supervisor. Replacements are free.</div>
HTML);
        addLesson($cid, $s1, 'article', 'Housekeeping: slips, trips and falls', <<<'HTML'
<p>Slips, trips and falls cause more injuries at Summit Ridge than any other event, and almost all of them can be prevented.</p>
<ul>
<li><strong>Keep aisles and exits clear.</strong> Nothing is parked in a marked walkway, even for a minute.</li>
<li><strong>Clean up spills now,</strong> or block them off with a wet-floor sign and report them.</li>
<li><strong>Coil cords and hoses</strong> so nobody can trip on them.</li>
<li><strong>Stack materials on pallets and racks</strong> so they cannot slide or fall.</li>
<li><strong>Return tools and pallet jacks</strong> to their marked places when you finish.</li>
</ul>
HTML);
        addLesson($cid, $s1, 'article', 'Lifting and moving loads safely', <<<'HTML'
<p>Back injuries from lifting are painful and slow to heal. Before you lift, stop and think.</p>
<h3>Before you lift</h3>
<ul>
<li>Check the weight. Anything over 50 lb (23 kg) needs a second person or a lifting aid.</li>
<li>Plan your route and clear the path.</li>
<li>Use a pallet jack, cart or hoist whenever you can.</li>
</ul>
<h3>Lifting technique</h3>
<ol>
<li>Stand close to the load with your feet shoulder-width apart.</li>
<li>Bend your knees, keep your back straight and grip firmly.</li>
<li>Lift with your legs and keep the load close to your body.</li>
<li>Turn with your feet. Never twist your back while holding a load.</li>
</ol>
HTML);
        $s2 = section($cid, 'In an emergency');
        addLesson($cid, $s2, 'article', 'Fire, evacuation and first aid', <<<'HTML'
<p>You will not have time to look things up in an emergency, so learn these now.</p>
<ul>
<li><strong>When the alarm sounds,</strong> stop work, make your machine safe if you can, and leave by the nearest exit. Never use the elevator.</li>
<li><strong>Go to your assembly point.</strong> They are marked on the evacuation map by each exit at Headquarters, Plant 1 and the Distribution Center.</li>
<li><strong>Fire extinguishers:</strong> Pull the pin, Aim at the base, Squeeze the handle, Sweep side to side. Only fight a fire that is small and when you have a clear way out.</li>
<li><strong>First aid kits and defibrillators</strong> are marked on the same map.</li>
<li><strong>For a serious injury,</strong> call the emergency number posted at your work area, send someone to meet the responders, and stay with the person.</li>
</ul>
HTML);
        addLesson($cid, $s2, 'article', 'Reporting hazards and near misses', <<<'HTML'
<p>A near miss is an event that did not hurt anyone but could have. Every near miss is a free lesson.</p>
<h3>You can stop any job</h3>
<p>If you believe a task is unsafe, you have the right, and the duty, to stop and tell your supervisor. Nobody will be disciplined for a good-faith stop.</p>
<h3>How to report</h3>
<ol>
<li>Make the area safe if you can do so without risk.</li>
<li>Tell your supervisor the same shift.</li>
<li>Fill in a hazard report card, or ask your supervisor to. Say what, where and when.</li>
</ol>
HTML);
        $s3 = section($cid, 'Final exam');
        $exam = addLesson($cid, $s3, 'quiz', 'Workplace safety exam');
        fillQuiz($exam['id'], [
            ['type' => 'single', 'critical' => true, 'topic' => 'Emergency', 'points' => 2,
             'text' => 'A coworker is injured and does not respond. What do you do first?',
             'options' => [['Move them to a chair straight away', false], ['Call the emergency number posted at your work area, send someone to meet the responders, and stay with them', true],
                           ['Give them water and let them rest', false], ['Wait until the end of the shift to report it', false]],
             'explanation' => 'Call for help first. Do not move an injured person unless they are in immediate danger.'],
            ['type' => 'single', 'topic' => 'PPE', 'text' => 'Hearing protection is required when…',
             'options' => [['Only when a supervisor is watching', false], ['A sign requires it, or your supervisor tells you to wear it', true], ['You feel the noise hurts your ears', false]]],
            ['type' => 'multi', 'topic' => 'Lifting', 'text' => 'Before you lift a load, you should… Select all that apply.',
             'options' => [['Check its weight and get help if it is heavy', true], ['Plan your route and clear the path', true],
                           ['Wear open shoes so you can move easily', false], ['Keep the load close to your body', true]]],
            ['type' => 'truefalse', 'topic' => 'Machine guards', 'text' => 'It is fine to use a machine with a missing guard if you only need it for a minute.', 'answer' => false,
             'explanation' => 'Guards protect you from moving parts. Stop, tag the machine and report the missing guard.'],
        ]);
        setupExam($exam['id'], ['pass_pct' => 80, 'max_attempts' => 3], [[$bankB, 4]],
            'Eight questions. You need 80% to pass, and every question marked critical must be right. You have three attempts.');
        $n = publish($cid, 'First version.');
        say("  Workplace Safety Basics: Version $n published");
    });

    // ---- 3. Hazard Communication (HazCom) -------------------------------------------------------------------
    buildCourse('Hazard Communication (HazCom)', function () use ($adminId): void {
        $cid = newCourse(['name' => 'Hazard Communication (HazCom)', 'category_id' => categoryId('Safety'), 'cover_key' => 'none', 'color' => '#0D9488']);
        setCourse($cid, [
            'code' => 'SAF-210',
            'summary' => 'Your right to know about hazardous chemicals: reading labels and Safety Data Sheets, and protecting yourself.',
            'description_html' => '<p>Cutting fluids, solvents, cleaners and welding gases are part of daily work at Summit Ridge. This course explains how to find out what is in them and how to work safely.</p><ul><li>What HazCom covers and why</li><li>Reading a chemical label and its pictograms</li><li>Finding answers in a Safety Data Sheet</li><li>What to do about spills and exposure</li></ul>',
            'regulation_ref' => '1910.1200(h)', 'responsible_user_id' => $adminId, 'requires_signature' => true,
            'validity_months' => 24, 'renewal_lead_days' => 45,
        ], ['Onboarding', 'Chemicals']);

        $s1 = section($cid, 'Your right to know');
        addLesson($cid, $s1, 'article', 'What HazCom covers', <<<'HTML'
<p>The Hazard Communication standard, called HazCom, gives you the right to know which hazardous chemicals you work with and how to stay safe around them.</p>
<h3>What that means for you</h3>
<ul>
<li>Every container is <strong>labelled</strong>.</li>
<li>Every chemical has a <strong>Safety Data Sheet (SDS)</strong> you can read at any time.</li>
<li>You receive <strong>training</strong> before you first work with a chemical, and again when a new hazard arrives.</li>
<li>Summit Ridge keeps a <strong>written HazCom program</strong> and a list of the chemicals on site.</li>
</ul>
<p>At Summit Ridge this covers cutting fluids and coolants, degreasers and solvents, paints and adhesives, cleaning products, battery charging areas and welding gases.</p>
HTML);
        $s2 = section($cid, 'Labels and pictograms');
        addLesson($cid, $s2, 'article', 'Reading a chemical label', <<<'HTML'
<p>A label from the manufacturer has six parts. Learn to find them in seconds.</p>
<table class="table table-bordered">
<thead><tr><th>Label element</th><th>What it tells you</th></tr></thead>
<tbody>
<tr><td>Product identifier</td><td>The name of the chemical. It matches the name on the Safety Data Sheet.</td></tr>
<tr><td>Signal word</td><td><strong>Danger</strong> means a more severe hazard. <strong>Warning</strong> means a less severe one.</td></tr>
<tr><td>Hazard statements</td><td>What can happen, for example "Causes serious eye damage".</td></tr>
<tr><td>Precautionary statements</td><td>How to prevent harm: storage, handling, PPE and first aid.</td></tr>
<tr><td>Pictograms</td><td>Red-bordered symbols that show the type of hazard at a glance.</td></tr>
<tr><td>Supplier information</td><td>Who made or supplied it and how to reach them.</td></tr>
</tbody>
</table>
HTML);
        addLesson($cid, $s2, 'article', 'The pictograms', <<<'HTML'
<p>Nine pictograms cover the hazards you will meet. Each has a red diamond border.</p>
<table class="table table-bordered table-striped">
<thead><tr><th>Symbol</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td>Flame</td><td>Flammable, self-heating or gives off flammable gas</td></tr>
<tr><td>Flame over a circle</td><td>Oxidizer: can make a fire burn harder</td></tr>
<tr><td>Exploding bomb</td><td>Explosive or self-reactive</td></tr>
<tr><td>Gas cylinder</td><td>Gas under pressure</td></tr>
<tr><td>Corrosion</td><td>Burns skin, damages eyes or corrodes metals</td></tr>
<tr><td>Skull and crossbones</td><td>Acutely toxic: can kill or seriously harm quickly</td></tr>
<tr><td>Health hazard</td><td>Long-term harm such as cancer or organ damage</td></tr>
<tr><td>Exclamation mark</td><td>Irritant, skin sensitizer or other less severe hazard</td></tr>
<tr><td>Environment</td><td>Harmful to aquatic life</td></tr>
</tbody>
</table>
HTML);
        $s3 = section($cid, 'Safety Data Sheets');
        addLesson($cid, $s3, 'article', 'Finding what you need in an SDS', <<<'HTML'
<p>A Safety Data Sheet has 16 numbered sections in the same order for every chemical, so you always know where to look.</p>
<h3>The sections you will use most</h3>
<ul>
<li><strong>Section 2, Hazard identification:</strong> the hazards and the label elements.</li>
<li><strong>Section 4, First-aid measures:</strong> what to do after eye, skin or breathing exposure.</li>
<li><strong>Section 5, Fire-fighting measures:</strong> the right extinguisher and special dangers.</li>
<li><strong>Section 6, Accidental release measures:</strong> how to contain and clean up a spill.</li>
<li><strong>Section 7, Handling and storage:</strong> what not to store it next to.</li>
<li><strong>Section 8, Exposure controls and PPE:</strong> the gloves, eye and breathing protection to use.</li>
</ul>
<div class="tr-callout tr-callout--info"><strong>Where to find them.</strong> A binder at every work area, and the "Safety Data Sheets" folder on the file server. Ask your supervisor if a sheet is missing.</div>
HTML);
        $s4 = section($cid, 'Protecting yourself');
        addLesson($cid, $s4, 'article', 'Chemicals in your work area, spills and exposure', <<<'HTML'
<p>Know what you work with. Before you use a chemical, read its label and check the PPE it calls for.</p>
<h3>Working safely</h3>
<ul>
<li>Keep chemicals in their original containers. If you fill a spray bottle or a bucket, label it with the product name and the main hazard.</li>
<li>Never mix products unless the label says you can.</li>
<li>Store flammables in the marked cabinets, away from ignition sources.</li>
</ul>
<h3>Spills and exposure</h3>
<ol>
<li>Move away, warn others and stop the source if it is safe to do so.</li>
<li>For a small spill you are trained for, use the spill kit and read Section 6 of the SDS.</li>
<li>For a large spill, or any spill you are unsure about, call your supervisor and the emergency number.</li>
<li>If a chemical touches your skin or eyes, go to the nearest eyewash or shower for 15 minutes and report it.</li>
</ol>
HTML);
        $s5 = section($cid, 'Final exam');
        $exam = addLesson($cid, $s5, 'quiz', 'HazCom exam');
        fillQuiz($exam['id'], [
            ['type' => 'single', 'critical' => true, 'topic' => 'SDS', 'points' => 2,
             'text' => 'You got a chemical splash in your eye. Where on the Safety Data Sheet do you find the first-aid measures?',
             'options' => [['Section 1, Identification', false], ['Section 4, First-aid measures', true], ['Section 9, Physical and chemical properties', false], ['Section 16, Other information', false]],
             'explanation' => 'Section 4 lists what to do after each kind of exposure. For the eyes, go straight to the eyewash first and read it on the way.'],
            ['type' => 'single', 'topic' => 'Labels', 'text' => 'Which label element tells you how severe a hazard is?',
             'options' => [['The product identifier', false], ['The signal word: Danger or Warning', true], ['The supplier information', false], ['The barcode', false]]],
            ['type' => 'single', 'topic' => 'Pictograms', 'text' => 'A pictogram showing a flame over a circle means the chemical is…',
             'options' => [['A flammable liquid', false], ['An oxidizer', true], ['Corrosive', false], ['Acutely toxic', false]],
             'explanation' => 'A flame over a circle marks an oxidizer, which can make a fire burn harder. Keep it away from flammables.'],
            ['type' => 'multi', 'topic' => 'Labels', 'text' => 'Which of these appear on a manufacturer\'s label? Select all that apply.',
             'options' => [['The product identifier', true], ['A signal word', true], ['Hazard statements', true], ['The price per gallon', false]]],
            ['type' => 'truefalse', 'topic' => 'Containers', 'text' => 'If you pour a chemical into an unmarked spray bottle, you must label the bottle.', 'answer' => true,
             'explanation' => 'A container you fill needs the product name and the main hazard on it, so the next person knows what is inside.'],
        ]);
        setupExam($exam['id'], ['pass_pct' => 80, 'max_attempts' => 0], [], 'Five questions. You need 80% to pass, and the critical question must be right. You may retry as often as you need.');
        $n = publish($cid, 'First version.');
        say("  Hazard Communication (HazCom): Version $n published");
    });

    // ---- 4. Forklift Safety Refresher --------------------------------------------------------------------------
    buildCourse('Forklift Safety Refresher', function () use ($bankB, $adminId): void {
        $cid = newCourse(['name' => 'Forklift Safety Refresher', 'category_id' => categoryId('Equipment'), 'cover_key' => 'none', 'color' => '#16A34A']);
        setCourse($cid, [
            'code' => 'EQP-300',
            'summary' => 'Refresher for powered industrial truck operators: pre-use inspection, load handling, pedestrians and parking. Ends with a practical evaluation.',
            'description_html' => '<p>Forklift operators renew this qualification every three years. You complete the online lessons and exam, then a trainer watches you operate a truck.</p>',
            'regulation_ref' => '1910.178(l)', 'responsible_user_id' => $adminId, 'requires_signature' => true,
            'validity_months' => 36, 'renewal_lead_days' => 60, 'is_qualification' => true, 'needs_practical' => true,
            'eval_checklist' => "*Completes the pre-use inspection and tags out a truck with a defect\n*Travels with forks low and the load tilted back\n*Slows and sounds the horn at blind corners and intersections\n*Stops and looks in the direction of travel, including when reversing\nPlaces and removes a pallet on the rack without touching the uprights\nParks correctly: forks down, controls neutral, brake set, key out",
        ], ['Equipment', 'Warehouse']);

        $s1 = section($cid, 'Before you drive');
        addLesson($cid, $s1, 'article', 'Pre-use inspection', <<<'HTML'
<p>Inspect your truck at the start of every shift, before you drive it. A defect found in the parking area costs a few minutes. A defect found while carrying a load can cost a life.</p>
<h3>Walk around the truck and check</h3>
<ul>
<li><strong>Tires and wheels:</strong> damage, missing lug nuts, low pressure on pneumatic tires.</li>
<li><strong>Forks:</strong> cracks, bends, wear, and that the locking pins are in place.</li>
<li><strong>Mast and chains:</strong> no broken links, no leaks from the hydraulic hoses or cylinders.</li>
<li><strong>Safety devices:</strong> horn, lights, backup alarm, seat belt and the overhead guard.</li>
<li><strong>Brakes and steering:</strong> test them at low speed before you enter the aisle.</li>
<li><strong>Power:</strong> battery charge, or propane cylinder secure with no smell of gas.</li>
</ul>
<div class="tr-callout tr-callout--danger"><strong>Found a defect? Tag the truck out.</strong> Put a "Do Not Operate" tag on the steering wheel and tell your supervisor. Never drive a truck with a defect that affects safety.</div>
HTML);
        $s2 = section($cid, 'Driving and handling loads');
        addLesson($cid, $s2, 'article', 'Load capacity and stability', <<<'HTML'
<p>A forklift stays upright only while the combined centre of gravity of the truck and load stays inside its stability triangle. Overloading or raising a load while moving can tip it over.</p>
<ul>
<li>Read the <strong>data plate</strong>. It shows the rated capacity at a stated load centre, usually 24 inches.</li>
<li>An <strong>attachment</strong>, a long load or a high lift reduces the safe capacity. Check the plate for the attachment you are using.</li>
<li>Keep the load <strong>against the backrest</strong> and the forks fully under it.</li>
<li>Never exceed capacity because the load "looks stable".</li>
<li>Raise or lower the load only when the truck is <strong>stopped</strong>.</li>
</ul>
HTML);
        addLesson($cid, $s2, 'article', 'Travelling, pedestrians and blind corners', <<<'HTML'
<p>Most forklift injuries at warehouses involve a pedestrian or a collision. Drive as if someone is around every corner, because someone often is.</p>
<ul>
<li>Travel with the forks <strong>4 to 6 inches (10 to 15 cm)</strong> above the floor and the mast tilted back.</li>
<li>Keep to the <strong>posted speed limit</strong> and slow down on wet or uneven floors.</li>
<li><strong>Sound the horn</strong> at intersections, doorways and blind corners.</li>
<li>Look in the direction of travel. If the load blocks your view, <strong>drive in reverse</strong>.</li>
<li><strong>Pedestrians have the right of way.</strong> Stop and wait until they are clear.</li>
<li>Never carry passengers, and never let anyone stand or walk under raised forks.</li>
</ul>
HTML);
        $s3 = section($cid, 'Parking, charging and refuelling');
        addLesson($cid, $s3, 'article', 'Parking, charging and refuelling', <<<'HTML'
<p>How you leave the truck matters as much as how you drive it.</p>
<h3>When you park</h3>
<ol>
<li>Park in a marked area, never in an aisle or in front of an exit or fire equipment.</li>
<li>Lower the forks fully to the floor.</li>
<li>Put the controls in neutral, set the parking brake and turn off the truck.</li>
<li>Remove the key if you are leaving the truck.</li>
</ol>
<h3>Charging and refuelling</h3>
<ul>
<li>Charge batteries only in the designated charging area. No smoking, no sparks, and wear eye protection and an apron.</li>
<li>Only trained employees change a propane cylinder.</li>
<li>Know where the eyewash station is before you start.</li>
</ul>
HTML);
        $s4 = section($cid, 'Final exam');
        $exam = addLesson($cid, $s4, 'quiz', 'Forklift refresher exam');
        fillQuiz($exam['id'], [
            ['type' => 'single', 'critical' => true, 'topic' => 'Pedestrians', 'points' => 2,
             'text' => 'A pedestrian steps into your travel path. What do you do?',
             'options' => [['Speed up to get past them', false], ['Steer around them without slowing down', false],
                           ['Stop, sound the horn if needed, and wait until they are clear', true], ['Flash the lights and keep going', false]],
             'explanation' => 'Pedestrians always have the right of way. Stop completely and let them clear the path.'],
            ['type' => 'single', 'topic' => 'Travel', 'text' => 'How should you carry a load while travelling?',
             'options' => [['Forks raised to chest height for a clear view', false], ['Forks 4 to 6 inches above the floor with the mast tilted back', true], ['Forks fully lowered and dragging on the floor', false]]],
            ['type' => 'single', 'topic' => 'Inspection', 'text' => 'You find a hydraulic leak during your pre-use inspection. What do you do?',
             'options' => [['Drive carefully and tell someone at the end of the shift', false], ['Tag the truck out of service and report it before anyone drives it', true],
                           ['Put a rag under the leak and carry on', false]]],
            ['type' => 'multi', 'topic' => 'Parking', 'text' => 'Before you leave the forklift, you must… Select all that apply.',
             'options' => [['Lower the forks fully', true], ['Set the parking brake', true], ['Put the controls in neutral', true], ['Leave the key in for the next driver', false]]],
            ['type' => 'truefalse', 'topic' => 'Capacity', 'text' => 'You may exceed the capacity on the data plate if the load looks stable.', 'answer' => false,
             'explanation' => 'The data plate is a hard limit. Split the load or use a truck rated for it.'],
        ]);
        setupExam($exam['id'], ['pass_pct' => 90, 'max_attempts' => 2, 'time_limit_s' => 900], [[$bankB, 2]],
            'Seven questions in 15 minutes. You need 90% to pass, and the critical question must be right. You have two attempts.');
        $n = publish($cid, 'First version.');
        say("  Forklift Safety Refresher: Version $n published");
    });

    // ---- 5. Code of Conduct & Ethics: an unfinished draft (never published) --------------------------------
    buildCourse('Code of Conduct & Ethics', function () use ($adminId): void {
        $cid = newCourse(['name' => 'Code of Conduct & Ethics', 'category_id' => categoryId('HR & Policy'), 'cover_key' => 'none', 'color' => '#D97706']);
        setCourse($cid, [
            'code' => 'HR-101',
            'summary' => 'Our shared standards: respect, conflicts of interest, gifts and hospitality, and how to speak up.',
            'description_html' => '<p>HR is revising the Code of Conduct for the coming year. This draft is being written and has not been released.</p>',
            'responsible_user_id' => $adminId, 'requires_signature' => true, 'validity_months' => 12, 'renewal_lead_days' => 30,
        ], ['Policy', 'Annual']);

        $s1 = section($cid, 'Our standards');
        addLesson($cid, $s1, 'article', 'Respect at work', <<<'HTML'
<p>Summit Ridge is a place where everyone should feel safe, respected and able to do their best work.</p>
<ul>
<li>Treat coworkers, customers and suppliers with courtesy, whatever their role.</li>
<li>Do not harass, bully or discriminate. This includes jokes, comments and messages that others find hurtful.</li>
<li>Listen to concerns and take them seriously.</li>
</ul>
HTML);
        addLesson($cid, $s1, 'article', 'Conflicts of interest', <<<'HTML'
<p>A conflict of interest happens when a personal interest could affect, or appear to affect, your judgement at work.</p>
<h3>Examples</h3>
<ul>
<li>A relative owns a company that bids to supply Summit Ridge.</li>
<li>You have a financial stake in a competitor or a supplier.</li>
<li>You take a second job that uses company time or information.</li>
</ul>
<p><strong>What to do:</strong> tell your manager and HR as soon as you notice it, and step away from the decision until it has been reviewed.</p>
HTML);
        addLesson($cid, $s1, 'article', 'Gifts and hospitality', <<<'HTML'
<p>Small courtesies are part of doing business. Gifts that could influence a decision are not.</p>
<ul>
<li>You may accept a modest item such as a branded pen or a working lunch.</li>
<li>Do not accept cash, gift cards or anything that would embarrass Summit Ridge if it became public.</li>
<li>Ask HR before accepting or giving anything worth more than a small amount.</li>
</ul>
HTML);
        $s2 = section($cid, 'Speaking up');
        addLesson($cid, $s2, 'article', 'How to raise a concern');   // left empty on purpose: the draft is unfinished
        $s3 = section($cid, 'Check your knowledge');
        $exam = addLesson($cid, $s3, 'quiz', 'Code of Conduct quiz');
        fillQuiz($exam['id'], [
            ['type' => 'single', 'critical' => true, 'topic' => 'Conflicts of interest', 'text' => 'A cousin of yours owns a company that bids to supply Summit Ridge. What do you do?',
             'options' => [['Say nothing, since it is only a bid', false], ['Tell your manager and HR, and step away from the decision', true], ['Ask your cousin to lower the price', false]],
             'explanation' => 'Disclose it early. Stepping away protects you and the company.'],
            ['type' => 'truefalse', 'topic' => 'Gifts', 'text' => 'You may accept a gift of any value from a supplier if it is given at holiday time.', 'answer' => false,
             'explanation' => 'The time of year does not change the rule. Check with HR before accepting anything of more than modest value.'],
            ['type' => 'single', 'topic' => 'Speaking up', 'text' => 'Which best describes retaliation?',
             'options' => [['Disciplining someone for breaking a safety rule', false], ['Punishing someone for raising a concern in good faith', true], ['Moving an employee to a different shift with notice', false]]],
        ]);
        setupExam($exam['id'], ['pass_pct' => 80, 'max_attempts' => 3], [], 'Three questions. You need 80% to pass.');
        say('  Code of Conduct & Ethics: draft saved (not published)');
    });

    // ---- 6. IT Acceptable Use Policy: a required document (article + acknowledgment + knowledge check) ---------
    buildCourse('IT Acceptable Use Policy', function () use ($marcusId): void {
        global $ctx;
        $cid = newCourse(['kind' => 'document', 'name' => 'IT Acceptable Use Policy', 'category_id' => categoryId('IT'), 'template_key' => 'required_document', 'cover_key' => 'none', 'color' => '#475569']);
        setCourse($cid, [
            'code' => 'POL-201',
            'summary' => 'The rules for using company computers, accounts and data. Read it, then sign to confirm.',
            'responsible_user_id' => $marcusId, 'validity_months' => 12, 'renewal_lead_days' => 30,
        ], ['Policy', 'Annual']);

        $lessons = [];
        $r = $ctx->db->query('SELECT lesson_id, lesson_type FROM training_lessons WHERE lesson_course_id = ' . (int) $cid . ' AND lesson_archived_at IS NULL ORDER BY lesson_sort, lesson_id');
        while ($row = $r->fetch_assoc()) {
            $lessons[$row['lesson_type']] = (int) $row['lesson_id'];
        }
        // This document is written as an article (the other choice is to upload a PDF).
        act([LessonActions::class, 'lessonSetType'], ['lesson_id' => $lessons['document'], 'version' => lessonVersion($lessons['document']), 'type' => 'article']);
        setLessonText($lessons['document'], <<<'HTML'
<h3>Purpose</h3>
<p>This policy explains how Summit Ridge Manufacturing computers, accounts, networks and data may be used. It protects you, our customers and the company.</p>
<h3>You may</h3>
<ul>
<li>Use company systems for your work, and for brief, reasonable personal use that does not interfere with it.</li>
<li>Install software that IT has approved. Ask the IT Help Desk if you are not sure.</li>
<li>Store company files on the file server and in the cloud storage that IT provides.</li>
</ul>
<h3>You may not</h3>
<ul>
<li>Share your password or let someone else use your account.</li>
<li>Install unapproved software, or connect personal storage devices or routers to the network.</li>
<li>Store company data in personal cloud accounts or send it to personal e-mail addresses.</li>
<li>Use company systems for anything illegal, harassing or offensive.</li>
</ul>
<h3>Monitoring and ownership</h3>
<p>Company systems and the data on them belong to Summit Ridge Manufacturing. To keep them secure, IT may monitor their use, as the law allows.</p>
<h3>Questions</h3>
<p>Contact the IT Help Desk at helpdesk@summitridge.example. Breaking this policy may lead to disciplinary action.</p>
HTML);
        act([LessonActions::class, 'lessonUpdate'], ['lesson_id' => $lessons['acknowledgment'], 'version' => lessonVersion($lessons['acknowledgment']), 'lang' => 'en', 'fields' => ['ack_require_signature' => true]]);

        // The optional knowledge check after reading.
        $check = act([QuizActions::class, 'quizAttach'], ['lesson_id' => $lessons['document']]);
        foreach ([
            ['type' => 'single', 'topic' => 'Software', 'text' => 'Which of these is allowed on a company laptop?',
             'options' => [['Software that IT has approved', true], ['A free game from a website', false], ['A browser extension from an unknown author', false]],
             'explanation' => 'Only approved software may be installed. Open a ticket if you need something new.'],
            ['type' => 'truefalse', 'topic' => 'Accounts', 'text' => 'You may share your password with a coworker who is on leave.', 'answer' => false,
             'explanation' => 'Never share your password. Ask your manager to arrange delegated access instead.'],
            ['type' => 'single', 'topic' => 'Ownership', 'text' => 'Who owns the files you create on company systems?',
             'options' => [['You do', false], ['Summit Ridge Manufacturing', true], ['The IT department', false]]],
        ] as $qd) {
            addQuestion((int) $check['own_bank_id'], $qd);
        }
        $n = publish($cid, 'First version.');
        say("  IT Acceptable Use Policy: Version $n published");
    });

    // ---- Learning path ----------------------------------------------------------------------------------------
    say('Learning path and achievements');
    $pathRow = one("SELECT tpath_id, tpath_version, tpath_achievement_id FROM training_paths WHERE tpath_name = 'New Employee Onboarding'");
    if ($pathRow) {
        say('  path exists, skipped: New Employee Onboarding');
        $pathId = (int) $pathRow['tpath_id'];
    } else {
        $path = act([CatalogActions::class, 'pathSave'], ['data' => [
            'name' => 'New Employee Onboarding',
            'description' => 'What every new Summit Ridge employee completes in the first 30 days: safety first, then chemicals, then IT security.',
            'color' => '#16A34A', 'sequential' => true,
            'courses' => [
                ['course_id' => courseId('Workplace Safety Basics'), 'required' => true],
                ['course_id' => courseId('Hazard Communication (HazCom)'), 'required' => true],
                ['course_id' => courseId('Cybersecurity Awareness'), 'required' => true],
            ],
        ]]);
        $pathId = (int) $path['id'];
        say('  path created: New Employee Onboarding');
    }

    // ---- Achievements -----------------------------------------------------------------------------------------
    $achievements = [
        ['Onboarding Complete', 'Finish every course in the New Employee Onboarding path.', 'graduation-cap', '#16A34A', 'path_completed', ['path_id' => $pathId]],
        ['Phish Spotter', 'Score 100% on the Cybersecurity Awareness exam.', 'shield-alt', '#2563EB', 'perfect_score', ['course_id' => courseId('Cybersecurity Awareness')]],
        ['Safety Champion', 'Recognises someone who stopped an unsafe job or coached a coworker. Awarded by a trainer or admin.', 'hard-hat', '#D97706', 'manual', []],
    ];
    foreach ($achievements as [$name, $description, $icon, $color, $rule, $params]) {
        if (one('SELECT achievement_id FROM training_achievements WHERE achievement_name = ' . q($name))) {
            say("  achievement exists, skipped: $name");
            continue;
        }
        act([CatalogActions::class, 'achievementSave'], ['data' => [
            'name' => $name, 'description' => $description, 'icon' => $icon, 'color' => $color, 'rule_type' => $rule, 'rule' => $params, 'active' => true,
        ]]);
        say("  achievement created: $name");
    }
    // The path awards "Onboarding Complete" when finished.
    $path = one("SELECT tpath_version, tpath_achievement_id FROM training_paths WHERE tpath_id = $pathId");
    $badge = one("SELECT achievement_id FROM training_achievements WHERE achievement_name = 'Onboarding Complete'");
    if ($path && $badge && $path['tpath_achievement_id'] === null) {
        act([CatalogActions::class, 'pathSave'], ['path_id' => $pathId, 'version' => (int) $path['tpath_version'], 'data' => ['achievement_id' => (int) $badge['achievement_id']]]);
        say('  path linked to the Onboarding Complete badge');
    }

    say('Done.');
} catch (ApiException $e) {
    fwrite(STDERR, 'seed 50-training-authoring: ' . $e->getMessage() . ' ' . json_encode($e->fields) . ' ' . json_encode($e->data) . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'seed 50-training-authoring: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
}

<?php
// In the editor the breadcrumb reads "All settings / Event rules / New rule" (the middle crumb goes back to the list), instead of a second back link.
if (isset($_GET['new']) || isset($_GET['edit']) || isset($_GET['recipe'])) {
    $admin_breadcrumb_trail = [['Event rules', 'event_rules.php'], [isset($_GET['edit']) ? 'Edit rule' : 'New rule', null]];
}
require_once "includes/inc_all_admin.php";
require_once "../includes/event_bus.php";
require_once "includes/webhook_events.php";
require_once "../includes/event_picker.php";

use ITFlow\Automation\Actions\ActionRegistry;
use ITFlow\Automation\ConditionEvaluator;
use ITFlow\Automation\RuleAdmin;
use ITFlow\Automation\RuleEngine;
use ITFlow\Automation\RuleForm;
use ITFlow\Automation\RuleRecipes;
use ITFlow\Automation\RuleSummary;

/*
 * Event rules: a "When -> If -> Then" rule list and editor.
 *   list    (default)        rich rule rows with a live on/off switch, plain-English summary, run statistics, search, filters, sort, drag to reorder
 *   editor  (?new, ?edit=ID) four cards (When, If, Then, Settings) with a sticky live summary; ?recipe=KEY prefills a ready-made rule
 * The behaviour is in js/event_rules.js, the data it needs in the #er-data JSON block, its server calls in modals/event_rules_api.php; saving and
 * deleting go through post.php (admin/post/event_rules.php) exactly as before.
 */

$csrf = $_SESSION['csrf_token'];
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ready = class_exists(RuleEngine::class) && rivetTableExists($mysqli, 'automation_rules');
$engine = $ready ? new RuleEngine($mysqli, new \ITFlow\Workflow\LiveActionGateway($mysqli)) : null;
$has_engine_columns = $ready && RuleAdmin::hasEngineColumns($mysqli);

// What the page needs to describe things: events (labels, groups), people, statuses...
$catalog = rivetEventCatalogData();
$event_info = [];
foreach ($catalog['events'] as $e) {
    $event_info[$e['id']] = ['label' => $e['label'], 'group' => $e['group'], 'groupLabel' => $e['groupLabel'], 'severity' => $e['severity']];
}
$group_style = [
    'tickets' => ['fa-ticket-alt', '#206bc4'], 'sla' => ['fa-hourglass-half', '#f76707'], 'approvals' => ['fa-check-double', '#4299e1'], 'workflows' => ['fa-project-diagram', '#ae3ec9'],
    'itil' => ['fa-book', '#4263eb'], 'assets' => ['fa-laptop', '#0ca678'], 'clients' => ['fa-users', '#d6336c'], 'billing' => ['fa-file-invoice-dollar', '#2fb344'],
    'security' => ['fa-shield-alt', '#d63939'], 'audit' => ['fa-clipboard-list', '#667382'], 'system' => ['fa-server', '#667382'], 'automation' => ['fa-robot', '#17a2b8'],
    'integrations' => ['fa-plug', '#74b816'], 'training' => ['fa-graduation-cap', '#f59f00'], 'other' => ['fa-bolt', '#667382'],
];
$group_of = static fn (string $event): string => $event_info[$event]['group'] ?? 'other';
$group_label = static fn (string $g): string => ($g === 'other' ? 'Other events' : ($catalog['groups'][$g]['label'] ?? ucfirst($g)));
$action_meta = [
    'notify_user' => ['fa-bell', 'Send an in-app notification to all technicians.'],
    'create_ticket' => ['fa-ticket-alt', 'Open a new ticket with a subject, details and priority.'],
    'send_webhook' => ['fa-paper-plane', 'POST the event to another service: Slack, Teams, n8n or your own endpoint.'],
    'start_workflow' => ['fa-sitemap', 'Start an onboarding or offboarding checklist for the person the event is about.'],
    'set_ticket_field' => ['fa-sliders-h', "Change a ticket's status, priority, category or assignee."],
    'add_ticket_note' => ['fa-sticky-note', 'Add an internal note (staff only) to the ticket.'],
    'assign_ticket' => ['fa-user-check', 'Assign the ticket to one technician, or rotate through several.'],
    'send_mail' => ['fa-envelope', "Queue an email to the assigned technician, the ticket's contact, a technician or a fixed address."],
    'create_task' => ['fa-tasks', 'Add a task (checklist item) to the ticket, optionally assigned and with a due date.'],
];
$rule_actions = ActionRegistry::labels();
$lookups = RuleAdmin::lookups($mysqli);
$agents = $lookups['agents'];
$status_names = $lookups['statuses'];
$categories = $lookups['categories'];
$event_labels = array_map(static fn (array $i): string => $i['label'], $event_info);
$slookups = RuleAdmin::summaryLookups($lookups, $event_labels);
$workflow_templates = [];
if ($ready) {
    $res = mysqli_query($mysqli, "SELECT workflow_template_id, name, type FROM workflow_templates WHERE archived_at IS NULL AND is_active = 1 ORDER BY type, name");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $workflow_templates[] = $r;
    }
}
$recipes = RuleRecipes::available(rivetEventCatalogIds(), $status_names);

$editor = $ready && (isset($_GET['new']) || isset($_GET['edit']));
$edit = null;
$missing_rule = false;
if ($ready && isset($_GET['edit'])) {
    $edit = $engine->find(intval($_GET['edit']));
    if ($edit === null) {
        $editor = false;
        $missing_rule = true;
    }
}
?>

<div class="er-page" id="er-root">
<?php if (!$ready) { ?>
    <div class="alert alert-warning">Run the database update first (Administration &rarr; Update) to turn on event rules.</div>
<?php } else { ?>
    <?php if (!$has_engine_columns) { ?>
    <div class="alert alert-warning">The automation engine tables are not installed yet. Run the database update (Administration &rarr; Update) to use conditions, new actions and the run log.</div>
    <?php } ?>
    <?php if ($missing_rule) { ?>
    <div class="alert alert-warning">That rule no longer exists. Pick one from the list below.</div>
    <?php } ?>
    <?php
    $er_data = [
        'csrf' => $csrf, 'api' => '/admin/modals/event_rules_api.php', 'view' => $editor ? 'editor' : 'list', 'safeToRun' => ['notify_user'],
        'agents' => $agents, 'statuses' => $status_names, 'categories' => $categories, 'clients' => $lookups['clients'],
        'priorities' => ['Low', 'Medium', 'High', 'Critical'],
        'operators' => ConditionEvaluator::OPERATORS, 'maxConditions' => ConditionEvaluator::MAX_CONDITIONS, 'groups' => RuleForm::GROUPS,
        'actions' => array_map(static fn (string $k) => ['key' => $k, 'label' => $rule_actions[$k], 'ticket' => in_array($k, ActionRegistry::TICKET_ACTIONS, true)], array_keys($rule_actions)),
        'eventLabels' => $event_labels,
    ];
    if ($editor) {
        require __DIR__ . '/includes/event_rules_editor.php';
    } else {
        require __DIR__ . '/includes/event_rules_list.php';
    }
    ?>
    <script type="application/json" id="er-data"><?= json_encode($er_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?></script>
<?php } ?>
</div>

<div class="visually-hidden" role="status" aria-live="polite" id="er-live"></div>
<div class="er-toasts" id="er-toasts" aria-live="polite"></div>
<script src="/js/event_rules.js?v=<?= (int) @filemtime(__DIR__ . '/../js/event_rules.js') ?>" defer></script>

<?php require_once "../includes/footer.php";

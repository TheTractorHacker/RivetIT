<?php
/*
 * Client Portal
 * Security and compliance report: the reduced view an administrator chose to publish (never the live admin view).
 */

ob_start();
require_once "includes/inc_all.php";

use ITFlow\Compliance\ComplianceService;
use RivetCore\Compliance\Framework;

// Intended: RivetIT is internal IT, so every portal user is the organisation's own staff and there is one installation-wide
// subject. This is the single reduced report an administrator published (titles and results only, no per-client data).
$shared = null;
try {
    if (ComplianceService::sharedReady($mysqli)) {
        $shared = ComplianceService::shared($mysqli)->current();
    }
} catch (\Throwable $e) {
    $shared = null;
}
if ($shared === null) {
    ob_end_clean();
    header("Location: index.php");
    exit();
}

$view = $shared['view'];
$has_party = (bool) array_filter(array_merge($view['automatic'], $view['manual']), static fn (array $r) => !empty($r['responsible']));
$fw = (string) ($_GET['framework'] ?? '');
$fw = Framework::isValid($fw) ? $fw : '';
$keep = static fn (array $row): bool => $fw === '' || in_array($fw, $row['frameworks'], true);
$auto_badge = ['pass' => 'success', 'warn' => 'warning text-dark', 'fail' => 'danger', 'na' => 'secondary', 'error' => 'secondary'];
$state = ['current' => ['Current', 'success'], 'due_soon' => ['Due soon', 'warning text-dark'], 'overdue' => ['Overdue', 'danger'], 'never' => ['Not yet reviewed', 'secondary']];
?>

<div class="card mb-4">
    <div class="card-body">
        <h3 class="mb-1">Security and compliance</h3>
        <p class="text-muted mb-2">Published <?= nullable_htmlentities(date('M j, Y', strtotime($shared['published_at']))) ?>, based on an assessment taken <?= nullable_htmlentities(date('M j, Y', strtotime($shared['taken_at']))) ?>.</p>
        <?php if ($shared['note']) { ?><p class="mb-2"><?= nl2br(nullable_htmlentities($shared['note'])) ?></p><?php } ?>
        <p class="small text-muted mb-0">This is a self-assessment against common security controls. It is not a certification or an audit opinion.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($view['scores'] as $s) {
        $tone = $s['score'] === null ? 'secondary' : ($s['score'] >= 80 ? 'success' : ($s['score'] >= 50 ? 'warning' : 'danger')); ?>
        <div class="col-6 col-md-4 col-xl">
            <a class="text-decoration-none" href="?<?= $s['key'] === 'all' ? '' : 'framework=' . urlencode($s['key']) ?>">
                <div class="card h-100 <?= ($s['key'] === 'all' ? $fw === '' : $fw === $s['key']) ? 'border-primary' : '' ?>">
                    <div class="card-body py-3">
                        <div class="small text-muted"><?= nullable_htmlentities($s['label']) ?></div>
                        <div class="fs-3 fw-semibold text-<?= $tone ?>"><?= $s['score'] === null ? '&mdash;' : nullable_htmlentities((string) $s['score']) . '%' ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php } ?>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Technical controls</strong></div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Control</th><th>Area</th><th>Result</th><?php if ($has_party) { ?><th>Responsible</th><?php } ?></tr></thead>
        <tbody>
        <?php foreach ($view['automatic'] as $r) { if (!$keep($r)) { continue; } ?>
            <tr><td><?= nullable_htmlentities($r['title']) ?></td><td class="text-muted"><?= nullable_htmlentities($r['category']) ?></td><td><span class="badge text-bg-<?= $auto_badge[$r['status']] ?? 'secondary' ?>"><?= nullable_htmlentities($r['status_label']) ?></span></td><?php if ($has_party) { ?><td><?= nullable_htmlentities($r['responsible'] ?: 'Internal') ?></td><?php } ?></tr>
        <?php } ?>
        </tbody>
    </table></div>
</div>

<div class="card mb-4">
    <div class="card-header"><strong>Policies and reviews</strong></div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Item</th><th>Area</th><th>Status</th><th>Last reviewed</th><?php if ($has_party) { ?><th>Responsible</th><?php } ?></tr></thead>
        <tbody>
        <?php foreach ($view['manual'] as $r) { if (!$keep($r)) { continue; } $st = $state[$r['state']] ?? [$r['state'], 'secondary']; ?>
            <tr><td><?= nullable_htmlentities($r['title']) ?></td><td class="text-muted"><?= nullable_htmlentities($r['category']) ?></td><td><span class="badge text-bg-<?= $st[1] ?>"><?= nullable_htmlentities($st[0]) ?></span></td><td><?= $r['reviewed_on'] ? nullable_htmlentities($r['reviewed_on']) : '&mdash;' ?></td><?php if ($has_party) { ?><td><?= nullable_htmlentities($r['responsible'] ?: 'Internal') ?></td><?php } ?></tr>
        <?php } ?>
        </tbody>
    </table></div>
</div>

<?php
require_once "includes/footer.php";

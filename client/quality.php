<?php
/*
 * Client Portal
 * Quality (issue #52): the signed-in person's own quality items from the QMS - tasks, corrective actions, documents to acknowledge, required training,
 * notifications and history. Read live from the QMS for this person; never cached, never shown stale. Managers also get quality_manage.php.
 */

header("Content-Security-Policy: default-src 'self'");

ob_start();
require_once "includes/inc_all.php";
require_once "includes/quality_common.php";

use ITFlow\Oidc\PortalResume;
use ITFlow\Quality\QualityConfig;
use ITFlow\Quality\QualityService;
use ITFlow\Quality\Reauth;

if (!$q_allowed) {
    ob_end_clean();
    $q_deny();
}

$tab_titles = ['tasks' => 'My tasks', 'actions' => 'Corrective actions', 'documents' => 'Documents', 'training' => 'Training', 'notifications' => 'Notifications', 'history' => 'History'];
$tabs = [];
foreach (QualityService::EMPLOYEE as $key => [$path, $feature]) {
    if (!empty($q_features[$feature])) {
        $tabs[$key] = $tab_titles[$key];
    }
}
$tab = (string) ($_GET['tab'] ?? '');
if (!isset($tabs[$tab])) {
    $tab = (string) (array_key_first($tabs) ?? '');
}

$svc = new QualityService($mysqli);
$result = $tab !== '' ? $svc->employeeView(intval($session_contact_id), $tab) : null;
$sso_url = null;
if (!empty($q_features) && \ITFlow\Oidc\OidcConfig::load($mysqli)['enabled']) {
    $sso_url = PortalResume::openQualityUrl($mysqli);
}
$reauth_fresh = Reauth::fresh($_SESSION, (int) QualityConfig::load($mysqli)['qs_reauth_minutes']);
$reauth_pw = !$reauth_fresh && Reauth::hasLocalPassword($mysqli, intval($session_user_id));
$csrf = $_SESSION['csrf_token'];
$e = static fn($v): string => nullable_htmlentities((string) $v);
$when = static fn(?string $d): string => $d === null || $d === '' ? '' : $d;
?>

<div class="row mb-3 gy-2">
    <div class="col-12 col-xl">
        <h3><i class="fas fa-fw fa-clipboard-check me-2"></i>Quality</h3>
    </div>
    <div class="col-12 col-xl-auto d-flex flex-wrap gap-2">
        <?php if ($q_access['manager'] && !empty($q_features['reports'])) { ?>
            <a href="quality_manage.php" class="btn btn-outline-primary"><i class="fas fa-chart-bar me-2"></i>Department view</a>
        <?php } ?>
        <?php if ($sso_url !== null) { ?>
            <a href="<?= $e($sso_url) ?>" class="btn btn-primary" rel="noopener noreferrer"><i class="fas fa-external-link-alt me-2"></i>Open the quality system</a>
        <?php } ?>
    </div>
</div>

<?php if (!$tabs) { ?>
    <div class="alert alert-info">No Quality pages are switched on for your department yet.</div>
<?php } else { ?>
<ul class="nav nav-pills mb-3 flex-wrap gap-1" aria-label="Quality sections">
    <?php foreach ($tabs as $key => $label) { ?>
        <li class="nav-item"><a class="nav-link <?= $key === $tab ? 'active' : '' ?>" href="quality.php?tab=<?= $e($key) ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= $e($label) ?></a></li>
    <?php } ?>
</ul>

<div class="card card-outline card-primary mb-4">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h5 class="mb-0"><?= $e($tab_titles[$tab] ?? 'Quality') ?></h5>
        <?php if ($result && !empty($result['ok'])) { ?><div class="text-muted small ms-auto">Read from the quality system at <?= $e(date('H:i', strtotime($result['as_of']))) ?>. <a href="quality.php?tab=<?= $e($tab) ?>">Refresh</a></div><?php } ?>
    </div>
    <div class="card-body p-0">
    <?php if ($result === null) { ?>
        <p class="text-muted p-3 mb-0">Nothing to show.</p>
    <?php } elseif (empty($result['ok'])) { ?>
        <div class="p-4 text-center" role="alert" data-quality-unavailable="1">
            <div class="display-6 text-muted mb-2"><i class="fas fa-plug" aria-hidden="true"></i></div>
            <h5><?= $result['error'] === 'unavailable' || $result['error'] === 'not_connected' ? 'The quality system is not available' : 'Quality could not be shown' ?></h5>
            <p class="text-muted mb-2"><?= $e($result['message']) ?></p>
            <?php if (!empty($result['reference'])) { ?><p class="small text-muted mb-2">If this keeps happening, tell your administrator this reference: <code><?= $e($result['reference']) ?></code></p><?php } ?>
            <a class="btn btn-outline-primary btn-sm" href="quality.php?tab=<?= $e($tab) ?>"><i class="fas fa-redo me-1"></i>Try again</a>
        </div>
    <?php } else { ?>
        <?php if ($tab === 'training' && !empty($result['local'])) { ?>
            <div class="p-3 border-bottom">
                <div class="fw-bold mb-2">Assigned to you for a quality requirement</div>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead><tr><th>Course</th><th>Due</th><th>Reference</th></tr></thead>
                    <tbody><?php foreach ($result['local'] as $l) { ?>
                        <tr><td><?= $e($l['title']) ?></td><td><?= $e($l['due_on']) ?> <?= $l['overdue'] ? '<span class="badge bg-danger">Overdue</span>' : '' ?></td><td><?= $e($l['reference']) ?></td></tr>
                    <?php } ?></tbody></table></div>
                <a href="training.php" class="btn btn-outline-primary btn-sm mt-2"><i class="fas fa-graduation-cap me-1"></i>Open my training</a>
            </div>
        <?php } ?>
        <?php if (!$result['items']) { ?>
            <p class="text-muted text-center py-4 mb-0">Nothing here right now.</p>
        <?php } else { ?>
        <ul class="list-group list-group-flush" data-quality-list="<?= $e($tab) ?>">
            <?php foreach ($result['items'] as $it) {
                $meta = [];
                if (!empty($it['due_on'])) { $meta[] = 'Due ' . $it['due_on']; }
                if ($tab === 'documents' && ($it['version'] ?? '') !== '') { $meta[] = 'Version ' . $it['version']; }
                if ($tab === 'documents' && !empty($it['acknowledged_on'])) { $meta[] = 'Acknowledged ' . $it['acknowledged_on']; }
                if (in_array($tab, ['notifications', 'history'], true) && !empty($it['at'])) { $meta[] = $it['at']; }
                if ($tab === 'history' && !empty($it['event'])) { $meta[] = $it['event']; }
                if (!empty($it['owner'])) { $meta[] = 'Owner ' . $it['owner']; }
                if (!empty($it['assignee'])) { $meta[] = $it['assignee']; } ?>
                <li class="list-group-item">
                    <div class="d-flex flex-wrap align-items-start gap-2">
                        <div class="flex-grow-1 text-break">
                            <div class="fw-bold"><?php if ($it['url']) { ?><a href="<?= $e($it['url']) ?>" rel="noopener noreferrer"><?= $e($it['title'] ?: $it['id']) ?></a><?php } else { echo $e($it['title'] ?: $it['id']); } ?></div>
                            <?php if ($meta) { ?><div class="text-muted small"><?= $e(implode('  |  ', $meta)) ?></div><?php } ?>
                        </div>
                        <div>
                        <?php if (!empty($it['overdue'])) { ?><span class="badge bg-danger">Overdue</span>
                        <?php } elseif ($tab === 'documents' && !empty($it['pending'])) { ?><span class="badge bg-warning text-dark">To acknowledge</span>
                        <?php } elseif ($tab === 'notifications' && empty($it['read'])) { ?><span class="badge bg-primary">New</span>
                        <?php } elseif ($it['status'] !== '') { ?><span class="badge bg-secondary"><?= $e(str_replace('_', ' ', $it['status'])) ?></span><?php } ?>
                        </div>
                    </div>
                    <?php if ($tab === 'documents' && !empty($it['pending']) && $it['id'] !== '') { ?>
                        <form action="quality_action.php" method="post" class="mt-2 d-flex flex-wrap gap-2 align-items-center">
                            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="do" value="acknowledge">
                            <input type="hidden" name="id" value="<?= $e($it['id']) ?>">
                            <input type="hidden" name="idem" value="<?= $e(bin2hex(random_bytes(12))) ?>">
                            <?php if ($reauth_pw) { ?><input type="password" class="form-control form-control-sm w-auto flex-grow-1" name="password" placeholder="Your password" autocomplete="current-password" required aria-label="Your password to confirm"><?php } ?>
                            <button class="btn btn-primary btn-sm" <?= (!$reauth_fresh && !$reauth_pw) ? 'disabled' : '' ?>><i class="fas fa-check me-1"></i>I have read and acknowledge</button>
                        </form>
                    <?php } elseif ($tab === 'actions' && !empty($it['requires_response']) && $it['id'] !== '') { ?>
                        <form action="quality_action.php" method="post" class="mt-2">
                            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="do" value="respond">
                            <input type="hidden" name="id" value="<?= $e($it['id']) ?>">
                            <input type="hidden" name="idem" value="<?= $e(bin2hex(random_bytes(12))) ?>">
                            <textarea class="form-control form-control-sm mb-2" name="text" rows="2" maxlength="4000" placeholder="Your response" required aria-label="Your response"></textarea>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                            <?php if ($reauth_pw) { ?><input type="password" class="form-control form-control-sm w-auto flex-grow-1" name="password" placeholder="Your password" autocomplete="current-password" required aria-label="Your password to confirm"><?php } ?>
                            <button class="btn btn-primary btn-sm" <?= (!$reauth_fresh && !$reauth_pw) ? 'disabled' : '' ?>><i class="fas fa-paper-plane me-1"></i>Send</button></div>
                        </form>
                    <?php } ?>
                </li>
            <?php } ?>
        </ul>
        <?php if (!empty($result['more'])) { ?><p class="text-muted small p-3 mb-0">Showing the first <?= count($result['items']) ?>. Open the quality system to see the rest.</p><?php } ?>
        <?php if (!$reauth_fresh && !$reauth_pw && in_array($tab, ['documents', 'actions'], true)) { ?>
            <p class="small text-muted p-3 mb-0 border-top"><i class="fas fa-lock me-1"></i>To acknowledge or respond, sign in again first (your sign-in is older than <?= (int) QualityConfig::load($mysqli)['qs_reauth_minutes'] ?> minutes). <a href="post.php?logout">Sign out</a></p>
        <?php } ?>
        <?php } ?>
    <?php } ?>
    </div>
</div>
<?php } ?>

<?php require_once "includes/footer.php";

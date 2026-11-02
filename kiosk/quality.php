<?php

/*
 * Quality on a shared screen (issue #52): GET /kiosk/quality.php. Learner session only. A shared screen shows COUNTS, the person's required training and the
 * documents waiting for their acknowledgment (title only) - never a corrective action, a nonconformance or any other quality record. Acknowledging a document
 * re-enters the PIN (the same step-up the training sign-off uses). Off unless the Quality module is on, the "kiosk" feature is on and the person's department is
 * enabled; every request re-checks that on the server. Nothing is cached: when the QMS is unreachable the page says so.
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Quality\QualityConfig;
use ITFlow\Quality\QualityLog;
use ITFlow\Quality\QualityService;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Kiosk\Core\KioskCsrf;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Learn\PinGate;
use ITFlow\Training\Kiosk\Pin\PinService;

kiosk_require_session(['learner']);
$db = $kctx->db();
$cid = $kctx->contactId();
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$t = static fn(string $key, array $vars = []): string => KioskStrings::t($kctx->lang, $key, $vars);

$features = QualityConfig::features($db);
$access = QualityConfig::access($db, $cid);
if (!QualityConfig::moduleOn($db) || empty($features['kiosk']) || !$access['ok']) {
    kiosk_redirect('/kiosk/me.php');
}

$svc = new QualityService($db, null, 6);
$notice = null;
$noticeOk = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $given = is_string($_POST['k_csrf'] ?? null) ? $_POST['k_csrf'] : '';
    $expect = KioskCsrf::pageToken($kctx);
    $doc = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
    $idem = is_string($_POST['idem'] ?? null) ? $_POST['idem'] : '';
    if ($expect === '' || !hash_equals($expect, $given) || ($_POST['do'] ?? '') !== 'ack' || preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $doc) !== 1
        || preg_match('/^[a-f0-9]{16,64}$/D', $idem) !== 1 || empty($features['documents'])) {
        kiosk_redirect('/kiosk/quality.php');
    }
    try {
        PinGate::stepUp($kctx, $_POST['pin'] ?? null, 'quality_ack');
        unset($_POST['pin']);
        $r = $svc->acknowledge($cid, $doc, 'kack-' . $idem);
        $noticeOk = !empty($r['ok']);
        $notice = $noticeOk ? $t('quality.ack_done') : $t('quality.ack_failed');
        if ($noticeOk) {
            logAction('Quality', 'Acknowledge', 'Kiosk acknowledgment of ' . substr($doc, 0, 64), (int) $access['client_id'], $cid);
        }
    } catch (ApiException $e) {
        unset($_POST['pin']);
        $notice = str_starts_with($e->errCode, 'pin_locked') ? $t('quality.pin_locked') : $t('quality.pin_failed');
    } catch (\Throwable $e) {
        QualityLog::record($db, 'portal', 'kiosk_ack_failed', get_class($e), null, null, $cid);
        $notice = $t('quality.ack_failed');
    } finally {
        PinService::pad($kctx->startedNs);
    }
}

$counts = [];
$unavailable = null;
$docs = [];
foreach (['tasks' => 'tasks', 'notifications' => 'notifications', 'documents' => 'documents'] as $tab => $feature) {
    if (empty($features[$feature])) {
        continue;
    }
    $r = $svc->employeeView($cid, $tab);
    if (empty($r['ok'])) {
        $unavailable = $r;
        break;
    }
    if ($tab === 'documents') {
        foreach ($r['items'] as $it) {
            if (!empty($it['pending']) && $it['id'] !== '') {
                $docs[] = ['id' => $it['id'], 'title' => $it['title'] !== '' ? $it['title'] : $it['id']];
            }
        }
        $counts['documents'] = count($docs);
    } elseif ($tab === 'notifications') {
        $counts['notifications'] = count(array_filter($r['items'], static fn(array $i): bool => empty($i['read'])));
    } else {
        $counts['tasks'] = count($r['items']);
    }
    if (!empty($r['more'])) {
        $counts[$tab . '_more'] = true;
    }
}
$localTraining = !empty($features['training']) ? count($svc->localTraining($cid)) : null;

$k_page = [
    'title' => $t('quality.title'),
    'css' => ['/css/itflow_training_kiosk_learn.css', '/css/itflow_training_kiosk_kb.css'],
    'js' => [],
    'body_class' => 'kx-learn kx-kb',
    'data' => new \stdClass(),
];
require __DIR__ . '/includes/layout_top.php';
$csrf = KioskCsrf::pageToken($kctx);
?>
<div class="kl-page kb-page">
  <a class="kx-btn kb-back" href="/kiosk/me.php"><i class="fas fa-arrow-left" aria-hidden="true"></i><span><?= $h($t('quality.back_home')) ?></span></a>
  <header class="kb-hero">
    <span class="kb-hero__icon" aria-hidden="true"><i class="fas fa-clipboard-check"></i></span>
    <div class="kb-hero__text">
      <h1 class="kl-h1"><?= $h($t('quality.title')) ?></h1>
      <p class="kl-sub"><?= $h($t('quality.sub')) ?></p>
    </div>
  </header>

  <?php if ($notice !== null) { ?><p class="kl-sub" role="status" data-quality-notice="<?= $noticeOk ? 'ok' : 'error' ?>"><?= $h($notice) ?></p><?php } ?>

  <?php if ($unavailable !== null) { ?>
    <section class="kb-group" role="alert" data-quality-unavailable="1">
      <p class="kl-sub"><?= $h($t('quality.unavailable')) ?></p>
      <?php if (!empty($unavailable['reference'])) { ?><p class="kl-sub"><?= $h($t('quality.unavailable_ref', ['ref' => $unavailable['reference']])) ?></p><?php } ?>
      <a class="kx-btn" href="/kiosk/quality.php"><i class="fas fa-redo" aria-hidden="true"></i><span><?= $h($t('quality.retry')) ?></span></a>
    </section>
  <?php } else { ?>
    <section class="kb-group">
      <ul class="kb-list">
        <?php if (isset($counts['tasks'])) { ?><li class="kb-item"><strong><?= $h($t('quality.tasks')) ?></strong>: <?= !empty($counts['tasks_more']) ? $h($t('quality.more', ['n' => $counts['tasks']])) : (int) $counts['tasks'] ?></li><?php } ?>
        <?php if (isset($counts['notifications'])) { ?><li class="kb-item"><strong><?= $h($t('quality.notifications')) ?></strong>: <?= (int) $counts['notifications'] ?></li><?php } ?>
        <?php if ($localTraining !== null) { ?><li class="kb-item"><strong><?= $h($t('quality.training')) ?></strong>: <?= (int) $localTraining ?>
          <?php if ($localTraining > 0) { ?> <a class="kx-btn" href="/kiosk/me.php"><span><?= $h($t('quality.training_open')) ?></span></a><?php } ?></li><?php } ?>
        <?php if (isset($counts['documents'])) { ?><li class="kb-item"><strong><?= $h($t('quality.documents')) ?></strong>: <?= (int) $counts['documents'] ?></li><?php } ?>
      </ul>
      <?php if ($counts === [] && $localTraining === null) { ?><p class="kl-sub"><?= $h($t('quality.none')) ?></p><?php } ?>
    </section>

    <?php foreach ($docs as $d) { ?>
    <form class="kb-search" method="post" action="/kiosk/quality.php" autocomplete="off">
      <input type="hidden" name="k_csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="do" value="ack">
      <input type="hidden" name="id" value="<?= $h($d['id']) ?>">
      <input type="hidden" name="idem" value="<?= $h(bin2hex(random_bytes(12))) ?>">
      <span class="kb-search__field"><span><?= $h($d['title']) ?></span></span>
      <input class="kb-search__input" type="password" name="pin" inputmode="numeric" pattern="[0-9]*" maxlength="12" required autocomplete="off" placeholder="<?= $h($t('quality.pin')) ?>" aria-label="<?= $h($t('quality.pin')) ?>">
      <button class="kx-btn kx-btn--primary kb-search__go" type="submit"><span><?= $h($t('quality.ack')) ?></span></button>
    </form>
    <?php } ?>
  <?php } ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php';

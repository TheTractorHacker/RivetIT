<?php

/*
 * [S] Kiosk evidence for one course run (P3 spec §7.9): the run timeline, server-credited lesson
 * seconds, quiz attempts with the answers as presented and as chosen, and the finger signatures.
 * Included by Phase 2's record evidence page when a completion has completion_run_id:
 *
 *     $tr_kiosk_run_id = (int) $completion['completion_run_id'];
 *     $tr_kiosk_ctx = $tr_ctx;   // the page's Training Ctx (level >= 1)
 *     require __DIR__ . '/../includes/training/kiosk_evidence_partial.php';
 *
 * Read-only. It re-checks the agent's department scope for the run's person (fail-closed: renders
 * nothing). Every value is escaped; signatures are the stored GD re-encoded PNGs as data: URLs
 * (base64 characters only).
 *
 * The device line names the training device the run was signed off on (else started on): its
 * label and its asset, or "Not in Assets (device #<kiosk_id>)" for an unlisted device (2.6.94; two
 * unlisted devices may share a name, the id tells them apart), plus "temporary device" when it WAS
 * temporary at that moment - read from the ledger as of the sign-off (else start) time
 * (DeviceLifecycle::expiryAsOf), never from the device's current end time, which can change later.
 * The kiosk row is kept after a revoke, so the name survives the device.
 */

if (!isset($tr_kiosk_run_id, $tr_kiosk_ctx) || !($tr_kiosk_ctx instanceof \ITFlow\Training\Core\Ctx) || (int) $tr_kiosk_run_id < 1) {
    return;
}
$tr_k_db = $tr_kiosk_ctx->db;
try {
    $tr_k_run = \ITFlow\Training\Kiosk\Learn\RunRepo::load($tr_k_db, (int) $tr_kiosk_run_id);
    if ($tr_k_run === null) {
        return;
    }
    (new \ITFlow\Training\Kiosk\Bridge\RecordsBridge($tr_kiosk_ctx))->assertContactInScope($tr_kiosk_ctx, (int) $tr_k_run['trun_contact_id']);
    $tr_k_doc = \ITFlow\Training\Kiosk\Core\RevisionCache::get($tr_k_db, (int) $tr_k_run['trun_revision_id'])['doc'];
} catch (\Throwable $tr_k_e) {
    return;
}
$tr_k_h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$tr_k_when = static fn(?string $utc): string => $utc === null ? '' : str_replace('T', ' ', substr((string) \ITFlow\Training\Core\Clock::toIso($utc, true), 0, 16));
$tr_k_lang = \ITFlow\Training\Kiosk\Learn\RunRepo::lang($tr_k_run, $tr_k_doc);
$tr_k_default = (string) ($tr_k_doc['course']['default_language'] ?? 'en');
$tr_k_title = static function (string $uid) use ($tr_k_doc, $tr_k_lang, $tr_k_default): string {
    $l = \ITFlow\Training\Kiosk\Learn\RunRepo::lesson($tr_k_doc, $uid);
    return $l === null ? $uid : (string) (\ITFlow\Training\Kiosk\Learn\RunRepo::variant($l, $tr_k_lang, $tr_k_default)['title'] ?? $uid);
};
$tr_k_lcomps = \ITFlow\Training\Core\Db::all($tr_k_db, 'SELECT lcomp_lesson_uid, lcomp_lesson_type, lcomp_completed_at_utc, lcomp_server_seconds, lcomp_required_seconds, lcomp_coverage_json
    FROM training_lesson_completions WHERE lcomp_run_id = ? ORDER BY lcomp_completed_at_utc, lcomp_id', 'i', [(int) $tr_k_run['trun_id']]);
$tr_k_attempts = \ITFlow\Training\Core\Db::all($tr_k_db, 'SELECT a.tattempt_id, a.tattempt_lesson_uid, a.tattempt_number, a.tattempt_started_at_utc, r.tresult_score_pct, r.tresult_passed,
        r.tresult_timed_out, r.tresult_finalized_by, r.tresult_duration_seconds, r.tresult_rapid_flag
    FROM training_attempts a LEFT JOIN training_attempt_results r ON r.tresult_attempt_id = a.tattempt_id
    WHERE a.tattempt_run_id = ? ORDER BY a.tattempt_lesson_uid, a.tattempt_number', 'i', [(int) $tr_k_run['trun_id']]);
$tr_k_sigs = \ITFlow\Training\Core\Db::all($tr_k_db, 'SELECT tsig_id, tsig_purpose, tsig_signer_name, tsig_png_base64, tsig_captured_at_utc, tsig_kiosk_id
    FROM training_signatures WHERE tsig_run_id = ? ORDER BY tsig_id', 'i', [(int) $tr_k_run['trun_id']]);
$tr_k_kiosk_id = null;
foreach ($tr_k_sigs as $tr_k_s) {
    if ($tr_k_run['trun_attest_tsig_id'] !== null && (int) $tr_k_s['tsig_id'] === (int) $tr_k_run['trun_attest_tsig_id'] && $tr_k_s['tsig_kiosk_id'] !== null) {
        $tr_k_kiosk_id = (int) $tr_k_s['tsig_kiosk_id'];
    }
}
$tr_k_kiosk_id ??= $tr_k_run['trun_started_kiosk_id'] !== null ? (int) $tr_k_run['trun_started_kiosk_id'] : null;
$tr_k_device = $tr_k_kiosk_id === null ? null : \ITFlow\Training\Core\Db::one($tr_k_db, 'SELECT k.kiosk_label, k.kiosk_asset_id, a.asset_name
    FROM training_kiosks k LEFT JOIN assets a ON a.asset_id = k.kiosk_asset_id WHERE k.kiosk_id = ?', 'i', [$tr_k_kiosk_id]);
$tr_k_dev_at = (string) ($tr_k_run['trun_attested_at_utc'] ?? $tr_k_run['trun_started_at_utc'] ?? '');
$tr_k_dev_temp = $tr_k_device !== null && $tr_k_dev_at !== ''
    && \ITFlow\Training\Kiosk\Device\DeviceLifecycle::expiryAsOf($tr_k_db, (int) $tr_k_kiosk_id, $tr_k_dev_at) !== null;
?>
<div class="card mb-3" id="tr-kiosk-evidence">
    <div class="card-header"><h3 class="card-title h6 mb-0"><i class="fas fa-tablet-alt me-2" aria-hidden="true"></i>Kiosk evidence (run #<?= (int) $tr_k_run['trun_id'] ?>)</h3></div>
    <div class="card-body">
        <p class="small text-secondary mb-2">Started <?= $tr_k_h($tr_k_when($tr_k_run['trun_started_at_utc'])) ?> · language <?= $tr_k_h($tr_k_lang) ?>
            <?php if ($tr_k_run['trun_attested_at_utc'] !== null) { ?> · signed off <?= $tr_k_h($tr_k_when($tr_k_run['trun_attested_at_utc'])) ?> (<?= $tr_k_h(str_replace('_', ' ', (string) $tr_k_run['trun_attest_proof'])) ?>, <?= $tr_k_h((string) $tr_k_run['trun_attest_pin_source']) ?> PIN)<?php } ?></p>
        <?php if ($tr_k_device !== null) { ?>
        <p class="small text-secondary mb-2" id="tr-kiosk-evidence-device"><i class="fas fa-tablet-alt me-1" aria-hidden="true"></i><?= $tr_k_run['trun_attested_at_utc'] !== null ? 'Device' : 'Started on' ?>:
            <strong class="text-body"><?= $tr_k_h($tr_k_device['kiosk_label']) ?></strong> ·
            <?= $tr_k_device['kiosk_asset_id'] === null ? 'Not in Assets (device #' . (int) $tr_k_kiosk_id . ')' : 'Asset ' . $tr_k_h(($tr_k_device['asset_name'] ?? '') !== '' ? $tr_k_device['asset_name'] : '#' . (int) $tr_k_device['kiosk_asset_id']) ?><?= $tr_k_dev_temp ? ' · temporary device' : '' ?></p>
        <?php } ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th scope="col">Lesson</th><th scope="col">Type</th><th scope="col">Time credited</th><th scope="col">Completed</th></tr></thead>
                <tbody>
                <?php foreach ($tr_k_lcomps as $tr_k_l) { ?>
                    <tr>
                        <td><?= $tr_k_h($tr_k_title((string) $tr_k_l['lcomp_lesson_uid'])) ?></td>
                        <td><?= $tr_k_h($tr_k_l['lcomp_lesson_type']) ?></td>
                        <td><?= (int) $tr_k_l['lcomp_server_seconds'] ?> s<?= (int) $tr_k_l['lcomp_required_seconds'] > 0 ? ' of ' . (int) $tr_k_l['lcomp_required_seconds'] . ' s required' : '' ?></td>
                        <td class="text-nowrap"><?= $tr_k_h($tr_k_when($tr_k_l['lcomp_completed_at_utc'])) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php foreach ($tr_k_attempts as $tr_k_a) {
            $tr_k_ans = \ITFlow\Training\Core\Db::all($tr_k_db, 'SELECT tanswer_question_uid, tanswer_presented, tanswer_selected, tanswer_is_correct, tanswer_critical
                FROM training_attempt_answers WHERE tanswer_attempt_id = ? ORDER BY tanswer_position', 'i', [(int) $tr_k_a['tattempt_id']]); ?>
            <details class="mb-2">
                <summary><?= $tr_k_h($tr_k_title((string) $tr_k_a['tattempt_lesson_uid'])) ?> · attempt <?= (int) $tr_k_a['tattempt_number'] ?> ·
                    <?= $tr_k_a['tresult_score_pct'] === null ? 'not finished' : $tr_k_h($tr_k_a['tresult_score_pct']) . '% ' . ((int) $tr_k_a['tresult_passed'] === 1 ? 'passed' : 'not passed') ?>
                    <?= (int) ($tr_k_a['tresult_timed_out'] ?? 0) === 1 ? ' · timed out' : '' ?><?= (int) ($tr_k_a['tresult_rapid_flag'] ?? 0) === 1 ? ' · answered very fast' : '' ?></summary>
                <table class="table table-sm mt-2">
                    <thead><tr><th scope="col">Question</th><th scope="col">Choices shown</th><th scope="col">Chosen</th><th scope="col">Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($tr_k_ans as $tr_k_x) { ?>
                        <tr>
                            <td><code><?= $tr_k_h($tr_k_x['tanswer_question_uid']) ?></code><?= (int) $tr_k_x['tanswer_critical'] === 1 ? ' <span class="badge text-bg-warning">critical</span>' : '' ?></td>
                            <td><code><?= $tr_k_h(str_replace(',', ', ', (string) $tr_k_x['tanswer_presented'])) ?></code></td>
                            <td><code><?= $tr_k_h($tr_k_x['tanswer_selected'] === '' ? '—' : str_replace(',', ', ', (string) $tr_k_x['tanswer_selected'])) ?></code></td>
                            <td><?= (int) $tr_k_x['tanswer_is_correct'] === 1 ? 'Correct' : 'Wrong' ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </details>
        <?php } ?>
        <?php foreach ($tr_k_sigs as $tr_k_s) {
            $tr_k_b64 = (string) $tr_k_s['tsig_png_base64'];
            if (preg_match('/^[A-Za-z0-9+\/=]+$/D', $tr_k_b64) !== 1) {
                continue;
            } ?>
            <figure class="d-inline-block me-3 mb-2">
                <img src="data:image/png;base64,<?= $tr_k_b64 ?>" alt="Signature of <?= $tr_k_h($tr_k_s['tsig_signer_name']) ?>" width="300" height="100" class="border rounded bg-white">
                <figcaption class="small text-secondary"><?= $tr_k_h(str_replace('_', ' ', (string) $tr_k_s['tsig_purpose'])) ?> · <?= $tr_k_h($tr_k_s['tsig_signer_name']) ?> · <?= $tr_k_h($tr_k_when($tr_k_s['tsig_captured_at_utc'])) ?></figcaption>
            </figure>
        <?php } ?>
    </div>
</div>

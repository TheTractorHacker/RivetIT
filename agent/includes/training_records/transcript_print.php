<?php
defined('TRAINING_PAGE') || exit;

/*
 * Printable transcript (Phase 2 spec §5.1 "&print=1"): a standalone US Letter portrait
 * document through the print shell. Expects $trr (TranscriptService::build()), $trr_ctx and
 * $trr_generated_by from agent/training_transcript.php, which has already authorized the
 * person. Every value goes out through nullable_htmlentities().
 */

use ITFlow\Training\Reports\Labels;

$trr_h = static fn(mixed $s): string => nullable_htmlentities($s === null ? '' : (string) $s);
$trr_d = static fn(?string $ymd): string => $ymd !== null && $ymd !== '' ? Labels::shortDate($ymd) : '';
$trr_p = $trr['contact'];
$trr_s = $trr['summary'];
$trr_company = (string) ($session_company_name ?? '');
$trr_logo = (string) ($session_company_logo ?? '');
$trr_generated = '';
try {
    $trr_generated = (new DateTimeImmutable($trr['generated_at']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M j, Y g:i A');
} catch (\Exception) {
    $trr_generated = '';
}
$trr_status_text = static function (array $r): string {
    if ($r['voided'] !== null) {
        return 'Voided';
    }
    if ($r['course']['kind'] === 'document') {
        return 'Signed';
    }
    return match ($r['status']) {
        'valid' => 'Valid',
        'expiring' => 'Expiring',
        'expired' => 'Expired',
        'revoked' => $r['status_reason'] === 'retrain_required' ? 'Revoked (retrain)' : 'Revoked',
        default => ucfirst((string) $r['status']),
    };
};
$trr_sub = array_values(array_filter([$trr_p['title'], $trr_p['department']['name'] ?? null, $trr_p['location']]));

trr_print_begin('Training transcript · ' . $trr_p['name'], 'portrait');
?>
<div class="trr-toolbar" role="toolbar" aria-label="Transcript actions">
    <a class="trr-toolbar__link" href="/agent/training_transcript.php?contact_id=<?= (int) $trr_p['id'] ?>">&larr; Back to the transcript</a>
    <span class="trr-toolbar__hint">US Letter, portrait. Choose “Save as PDF” in the print dialog for a file.</span>
    <button type="button" class="trr-toolbar__btn js-print">Print</button>
</div>

<main class="trr-doc" aria-label="Training transcript">
    <header class="trr-doc__head">
        <?php if ($trr_logo !== '') { ?><img src="/uploads/settings/<?= $trr_h($trr_logo) ?>" alt=""><?php } ?>
        <div class="trr-doc__company"><?= $trr_h($trr_company) ?></div>
        <div class="trr-doc__kind">Training transcript</div>
    </header>

    <h1 class="trr-doc__person"><?= $trr_h($trr_p['name']) ?><?= $trr_p['archived'] ? ' <span class="trr-doc__muted">(archived)</span>' : '' ?></h1>
    <div class="trr-doc__sub">
        <?= $trr_h(implode(' · ', $trr_sub)) ?>
        <?php
        $trr_meta = [];
        if ($trr_p['employee_no'] !== null) {
            $trr_meta[] = 'Employee #' . $trr_p['employee_no'];
        }
        if ($trr_p['hire_date'] !== null) {
            $trr_meta[] = 'Hired ' . Labels::shortDate($trr_p['hire_date']);
        }
        if ($trr_p['manager'] !== null) {
            $trr_meta[] = 'Supervisor ' . $trr_p['manager']['name'];
        }
        if ($trr_meta !== []) { ?><br><?= $trr_h(implode(' · ', $trr_meta)) ?><?php } ?>
    </div>

    <div class="trr-doc__summary">
        <span><strong><?= $trr_s['pct'] !== null ? (int) $trr_s['pct'] . '%' : '—' ?></strong> compliant</span>
        <span><strong><?= (int) $trr_s['overdue'] ?></strong> overdue</span>
        <span><strong><?= (int) $trr_s['valid'] ?></strong> of <?= (int) $trr_s['on_file'] ?> qualifications valid</span>
        <span><strong><?= (int) $trr_s['expiring_60'] ?></strong> expiring in 60 days</span>
        <span><strong><?= $trr_h(number_format((float) $trr_s['hours'], 1)) ?></strong> training hours in <?= $trr_h($trr_s['hours_year']) ?></span>
        <span>Last trained <strong><?= $trr_s['last_trained_on'] !== null ? $trr_h($trr_d($trr_s['last_trained_on'])) : 'never' ?></strong></span>
    </div>

    <h2>Qualifications</h2>
    <?php if ($trr['qualifications'] === []) { ?>
    <p class="trr-doc__empty">No qualifications on file.</p>
    <?php } else { ?>
    <table>
        <thead><tr><th>Course</th><th>Status</th><th>Evidence</th><th class="trr-doc__num">Score</th><th>Trained</th><th>Expires</th><th>Trainer / evaluator</th><th>Record #</th></tr></thead>
        <tbody>
            <?php foreach ($trr['qualifications'] as $trr_q) { ?>
            <tr>
                <td><strong><?= $trr_h($trr_q['course']['name']) ?></strong><br><span class="trr-doc__muted"><?= $trr_h(implode(' · ', array_filter([$trr_q['method_label'], $trr_q['revision_number'] !== null ? 'Rev ' . $trr_q['revision_number'] : null, $trr_q['validity_label']]))) ?></span></td>
                <td><?= $trr_h($trr_status_text($trr_q)) ?></td>
                <td><span class="trr-doc__grade"><?= $trr_h($trr_q['grade']) ?></span><?= $trr_h($trr_q['strength_label']) ?></td>
                <td class="trr-doc__num"><?= $trr_q['score_pct'] !== null ? $trr_h($trr_q['score_pct']) . '%' : '—' ?></td>
                <td><?= $trr_h($trr_d($trr_q['trained_on'] ?? $trr_q['completed_on'])) ?></td>
                <td><?= $trr_q['expires_on'] !== null ? $trr_h($trr_d($trr_q['expires_on'])) : 'Does not expire' ?></td>
                <td><?= $trr_q['trainer_or_evaluator'] !== null ? $trr_h($trr_q['trainer_or_evaluator']) : ($trr_q['method'] === 'online' ? 'Self-paced' : '—') ?></td>
                <td class="trr-doc__mono"><?= $trr_h($trr_q['cert_number'] ?? ('#' . $trr_q['completion_id'])) ?></td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
    <?php } ?>

    <h2>Open assignments</h2>
    <?php if ($trr['open_assignments'] === []) { ?>
    <p class="trr-doc__empty">Nothing assigned.</p>
    <?php } else { ?>
    <table>
        <thead><tr><th>Course</th><th>Why</th><th>Due</th><th>Status</th></tr></thead>
        <tbody>
            <?php foreach ($trr['open_assignments'] as $trr_a) { ?>
            <tr>
                <td><strong><?= $trr_h($trr_a['course']['name']) ?></strong></td>
                <td><?= $trr_h($trr_a['anchor_label']) ?></td>
                <td><?= $trr_h($trr_d($trr_a['due_on'])) ?></td>
                <td><?= $trr_a['display_status'] === 'overdue' ? 'Overdue ' . (int) $trr_a['days_overdue'] . ' ' . ((int) $trr_a['days_overdue'] === 1 ? 'day' : 'days') : ($trr_a['display_status'] === 'due_soon' ? 'Due soon' : 'Assigned') ?></td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
    <?php } ?>

    <h2>Training history</h2>
    <?php if ($trr['history'] === []) { ?>
    <p class="trr-doc__empty">No training records yet.</p>
    <?php } else { ?>
    <table>
        <thead><tr><th>Completed</th><th>Course</th><th>Evidence</th><th class="trr-doc__num">Score</th><th>Expires</th><th>Status</th><th>Record #</th></tr></thead>
        <tbody>
            <?php foreach ($trr['history'] as $trr_r) { ?>
            <tr class="<?= $trr_r['voided'] !== null ? 'is-voided' : '' ?>">
                <td class="trr-doc__strike"><?= $trr_h($trr_d($trr_r['completed_on'])) ?></td>
                <td class="trr-doc__strike"><?= $trr_h($trr_r['course']['name']) ?><?= $trr_r['course']['kind'] === 'document' && $trr_r['revision_number'] !== null ? ' (Version ' . (int) $trr_r['revision_number'] . ')' : '' ?></td>
                <td><span class="trr-doc__grade"><?= $trr_h($trr_r['grade']) ?></span><?= $trr_h($trr_r['strength_label']) ?></td>
                <td class="trr-doc__num"><?= $trr_r['score_pct'] !== null ? $trr_h($trr_r['score_pct']) . '%' : '—' ?></td>
                <td><?= $trr_r['course']['kind'] === 'document' ? '—' : ($trr_r['expires_on'] !== null ? $trr_h($trr_d($trr_r['expires_on'])) : 'Does not expire') ?></td>
                <td><?= $trr_h($trr_status_text($trr_r)) ?></td>
                <td class="trr-doc__mono"><?= $trr_h($trr_r['cert_number'] ?? ('#' . $trr_r['completion_id'])) ?></td>
            </tr>
            <?php if ($trr_r['voided'] !== null) { ?>
            <tr class="trr-doc__void-note"><td></td><td colspan="6">Voided <?= $trr_h($trr_d($trr_r['voided']['on'])) ?><?= $trr_r['voided']['by'] !== null ? ' by ' . $trr_h($trr_r['voided']['by']) : '' ?>: <?= $trr_h($trr_r['voided']['reason']) ?></td></tr>
            <?php } ?>
            <?php } ?>
        </tbody>
    </table>
    <?php } ?>

    <h2>Evidence strength</h2>
    <ul class="trr-doc__legend">
        <?php foreach ($trr['legend'] as $trr_g => $trr_l) { ?>
        <li><span class="trr-doc__grade"><?= $trr_h($trr_g) ?></span><strong><?= $trr_h($trr_l['label']) ?></strong> <?= $trr_h($trr_l['detail']) ?></li>
        <?php } ?>
    </ul>

    <footer class="trr-doc__foot">
        As of <?= $trr_h(Labels::shortDate($trr['as_of'])) ?> · Generated <?= $trr_h($trr_generated) ?> by <?= $trr_h($trr_generated_by) ?><?= $trr['ledger'] !== null ? ' · ledger ' . $trr_h($trr['ledger']) : '' ?>
    </footer>
</main>
<?php
trr_print_end();

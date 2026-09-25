<?php
defined('TRAINING_PAGE') || exit;

/*
 * Training panel on the contact card (Phase 2 spec §5.1, M14). Included by
 * agent/contact_details.php (Lane A, Block 5) only when the module is on and the user has
 * module_training >= 1.
 *
 * Tabler/Bootstrap classes only, so the contact page needs no extra CSS. The whole body runs in
 * a static closure: no variable of contact_details.php is read or overwritten, and any failure
 * is logged and renders nothing. A contact outside the caller's fail-closed training scope
 * renders nothing either (the page itself is governed by ITFlow's own client access).
 */

(static function (\mysqli $db, int $cid): void {
    $ob = ob_get_level();
    ob_start();   // nothing reaches the page unless the whole card rendered
    try {
        if ($cid <= 0) {
            ob_end_clean();
            return;
        }
        $ctx = \ITFlow\Training\Core\Access::ctx($db);
        $scope = \ITFlow\Training\People\Scope::forCtx($ctx);
        try {
            $scope->assertContact($db, $cid);
        } catch (\ITFlow\Training\Api\ApiException) {
            ob_end_clean();
            return;
        }
        $h = static fn(mixed $s): string => nullable_htmlentities($s === null ? '' : (string) $s);
        $date = static function (?string $ymd): string {
            if ($ymd === null || !\ITFlow\Training\Core\Clock::isYmd($ymd)) {
                return '';
            }
            $d = new \DateTimeImmutable($ymd . ' 00:00:00');
            return $d->format('Y') === date('Y') ? $d->format('M j') : $d->format('M j, Y');
        };

        $pairs = [];
        $summary = null;
        $onRoster = \ITFlow\Training\People\Roster::isEligible($db, $cid);
        if ($onRoster) {
            try {
                $ps = (new \ITFlow\Training\Compliance\ComplianceService($ctx, $scope))->personStatus($cid);
                $pairs = is_array($ps['pairs'] ?? null) ? $ps['pairs'] : [];
                $summary = is_array($ps['summary'] ?? null) ? $ps['summary'] : null;
            } catch (\ITFlow\Training\Api\ApiException) {
                $pairs = [];
            }
        }

        // Most urgent first; waived last.
        $rank = ['overdue' => 0, 'expired' => 1, 'due_soon' => 2, 'retrain_due' => 3, 'expiring' => 4, 'due' => 5, 'not_started' => 6, 'current' => 7, 'waived' => 8];
        $pairs = array_values(array_filter($pairs, static fn($p) => is_array($p) && !empty($p['required'] ?? true)));
        usort($pairs, static function ($a, $b) use ($rank) {
            // a lapsed renewal (certificate already expired) ranks with overdue even before the renewal is due
            $ka = [!empty($a['lapsed']) ? 0 : ($rank[$a['status'] ?? ''] ?? 9), $a['due_on'] ?? $a['expires_on'] ?? '9999', (string) ($a['course']['name'] ?? '')];
            $kb = [!empty($b['lapsed']) ? 0 : ($rank[$b['status'] ?? ''] ?? 9), $b['due_on'] ?? $b['expires_on'] ?? '9999', (string) ($b['course']['name'] ?? '')];
            return $ka <=> $kb;
        });
        $shown = array_slice($pairs, 0, 5);
        $badge = static fn(string $status): array => match ($status) {
            'current' => ['text-bg-success', 'Current'],
            'expiring' => ['text-bg-warning', 'Expiring'],
            'retrain_due' => ['text-bg-warning', 'Retrain due'],
            'overdue' => ['text-bg-danger', 'Overdue'],
            'due_soon' => ['text-bg-warning', 'Due soon'],
            'due' => ['text-bg-info', 'Assigned'],
            'waived' => ['text-bg-secondary', 'Waived'],
            'expired' => ['text-bg-danger', 'Expired'],
            default => ['text-bg-secondary', 'Not assigned yet'],
        };
        $level = $ctx->level;
        $transcript = '/agent/training_transcript.php?contact_id=' . $cid;
        ?>
            <div class="card card-dark mb-3" id="training-contact-card">
                <div class="card-header">
                    <h5 class="card-title"><i class="fas fa-hard-hat me-2" aria-hidden="true"></i>Training</h5>
                    <?php if ($level >= 2 && $onRoster) { ?>
                    <div class="card-actions">
                        <a class="btn btn-sm btn-primary" href="/agent/training_assignments.php?assign=<?= $cid ?>"><i class="fas fa-plus me-1" aria-hidden="true"></i>Assign training</a>
                    </div>
                    <?php } ?>
                </div>
                <div class="card-body">
                    <?php if (!$onRoster) { ?>
                    <p class="text-muted mb-2">Not on the training roster, so nothing is assigned to this person. Their past records stay on the transcript.</p>
                    <?php } elseif ($summary !== null && (int) ($summary['required'] ?? 0) > 0) {
                        $pct = $summary['pct'] ?? null;
                        ?>
                    <p class="mb-2">
                        <strong><?= $pct === null ? '—' : (int) $pct . '%' ?></strong> compliant
                        · <span class="<?= (int) ($summary['overdue'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>"><?= (int) ($summary['overdue'] ?? 0) ?> overdue</span>
                        · <?= (int) ($summary['expiring_60'] ?? 0) ?> expiring in 60 days
                    </p>
                    <?php } else { ?>
                    <p class="text-muted mb-2">No required training for this person yet.</p>
                    <?php } ?>

                    <?php if ($shown !== []) { ?>
                    <ul class="list-unstyled mb-2">
                        <?php foreach ($shown as $p) {
                            [$cls, $text] = $badge((string) ($p['status'] ?? ''));
                            $lapsed = !empty($p['lapsed']) || (($p['status'] ?? '') === 'overdue' && str_starts_with((string) ($p['label'] ?? ''), 'Expired'));
                            if ($lapsed) {
                                // a lapsed renewal, due or not: "Expired — not qualified", not "Overdue" / "Due soon" (§3.3 frozen label)
                                $cls = 'text-bg-danger';
                                $text = 'Expired — not qualified';
                            }
                            $when = '';
                            if ($lapsed && !empty($p['expires_on'])) {
                                $when = 'Expired ' . $date($p['expires_on']) . (!empty($p['due_on']) ? ' · renewal due ' . $date($p['due_on']) : '');
                            } elseif (in_array($p['status'] ?? '', ['overdue', 'due_soon', 'due', 'retrain_due'], true) && !empty($p['due_on'])) {
                                $when = 'Due ' . $date($p['due_on']);
                            } elseif (!empty($p['expires_on'])) {
                                $when = (($p['status'] ?? '') === 'expired' ? 'Expired ' : 'Expires ') . $date($p['expires_on']);
                            }
                            ?>
                        <li class="py-2 border-bottom">
                            <div class="text-truncate fw-medium" title="<?= $h($p['course']['name'] ?? '') ?>"><?= $h($p['course']['name'] ?? '') ?></div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                                <span class="badge <?= $cls ?>" title="<?= $h($p['label'] ?? '') ?>"><?= $h($text) ?></span>
                                <?php if ($when !== '') { ?><span class="text-secondary small"><?= $h($when) ?></span><?php } ?>
                            </div>
                        </li>
                        <?php } ?>
                    </ul>
                    <?php if (count($pairs) > count($shown)) { ?>
                    <p class="text-secondary small mb-2"><?= count($pairs) - count($shown) ?> more on the transcript.</p>
                    <?php } ?>
                    <?php } ?>

                    <a href="<?= $h($transcript) ?>"><i class="fas fa-id-card me-1" aria-hidden="true"></i>Open transcript</a>
                </div>
            </div>
        <?php
        echo ob_get_clean();
    } catch (\Throwable $e) {
        while (ob_get_level() > $ob) {
            ob_end_clean();
        }
        error_log('Training contact card #' . $cid . ': ' . get_class($e) . ': ' . $e->getMessage());
    }
})($mysqli, intval($contact_id));

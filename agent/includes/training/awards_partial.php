<?php
defined('TRAINING_PAGE') || exit;

/*
 * Achievements section of a person's transcript (P3 spec §7.9 [S], lane K6).
 *
 * Include from a page that has ALREADY checked the person is in the viewer's departments
 * (the Phase 2 transcript does, before it builds anything):
 *
 *     $tr_awards_contact_id = <contact id>;
 *     require __DIR__ . '/includes/training/awards_partial.php';
 *
 * Reads through AwardRepository (newest first, snapshot names; archived badges keep their
 * name) and renders server-side with nullable_htmlentities(). Icons are re-checked against
 * Core\Icons and colors against #RRGGBB before they reach the markup. Renders nothing when
 * the 2.6.93 awards table is not installed. Styled with the transcript's trr-* list classes.
 */

$tr_awards_contact_id = (int) ($tr_awards_contact_id ?? 0);
if ($tr_awards_contact_id > 0 && \ITFlow\Training\Achievements\AwardEngine::ready($mysqli)) {
    $tr_awards_list = \ITFlow\Training\Achievements\AwardRepository::listRows($mysqli, null, null, $tr_awards_contact_id)['rows'];
    $tr_awards_rule_text = [
        'course_completed' => 'Finished a course',
        'category_completed' => 'Finished a category',
        'path_completed' => 'Finished a learning path',
        'courses_completed_count' => 'Finished enough courses',
        'perfect_score' => 'Perfect exam score',
        'first_attempt_pass' => 'Passed on the first try',
        'on_time_streak' => 'On time, month after month',
    ];
    if ($tr_awards_list === []) { ?>
<div class="card-body tr-awards-partial"><?php render_empty_state('fas fa-medal', 'No achievements yet', 'Badges earned on the kiosk appear here.', ''); ?></div>
    <?php } else { ?>
<ul class="trr-rows card-body tr-awards-partial">
        <?php foreach ($tr_awards_list as $tr_aw) {
            $tr_aw_a = $tr_aw['achievement'];
            $tr_aw_icon = \ITFlow\Training\Core\Icons::valid($tr_aw_a['icon']) ? $tr_aw_a['icon'] : 'award';
            $tr_aw_color = preg_match('/^#[0-9A-Fa-f]{6}$/D', $tr_aw_a['color']) === 1 ? $tr_aw_a['color'] : '#D97706';
            if ($tr_aw['source'] === 'manual') {
                $tr_aw_by = $tr_aw['awarded_by']['name'] ?? null;
                $tr_aw_how = 'Given by ' . ($tr_aw_by !== null ? ($tr_aw['awarded_by']['kind'] === 'contact' ? 'trainer ' . $tr_aw_by : $tr_aw_by) : 'hand');
            } else {
                $tr_aw_how = $tr_awards_rule_text[$tr_aw['rule_type']] ?? 'Automatic';
            }
            $tr_aw_on = $tr_aw['awarded_at'] !== null ? (new \DateTimeImmutable($tr_aw['awarded_at']))->setTimezone(new \DateTimeZone(date_default_timezone_get())) : null;
            ?>
    <li class="trr-rows__item">
        <span class="trr-avatar" style="color: <?= nullable_htmlentities($tr_aw_color) ?>" aria-hidden="true"><i class="fas fa-<?= nullable_htmlentities($tr_aw_icon) ?>"></i></span>
        <div class="trr-rows__main">
            <div><strong><?= nullable_htmlentities($tr_aw_a['name']) ?></strong></div>
            <div class="trr-muted"><?= nullable_htmlentities($tr_aw_how) ?><?= $tr_aw['reason'] !== null && $tr_aw['reason'] !== '' ? ' · ' . nullable_htmlentities($tr_aw['reason']) : '' ?></div>
        </div>
        <span class="trr-muted"><?php if ($tr_aw_on !== null) { ?><time datetime="<?= nullable_htmlentities($tr_aw['awarded_at']) ?>"><?= nullable_htmlentities($tr_aw_on->format('M j, Y')) ?></time><?php } ?></span>
    </li>
        <?php } ?>
</ul>
    <?php }
}

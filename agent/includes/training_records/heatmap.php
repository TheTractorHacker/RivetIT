<?php
defined('TRAINING_PAGE') || exit;

/*
 * Department × course heatmap table (dashboard and Reports › Matrix). Expects $trr_m = a
 * MatrixService matrix. Cells are buttons that open the "who is missing" drill-down
 * (training_reports.js, report_cell_people); every cell carries its percentage as text and a
 * band icon, never colour alone (spec §5.1).
 */

$trr_hm_target = (int) ($trr_m['target'] ?? 95);
if ($trr_m['rows'] === [] || $trr_m['courses'] === []) {
    echo '<p class="trr-empty-line">No required training for the people in view yet.</p>';
    return;
}
?>
<div class="trr-heat-wrap" tabindex="0" role="region" aria-label="Department by course compliance">
<table class="trr-heat">
    <thead>
        <tr>
            <th scope="col" class="trr-heat__dept">Department</th>
            <?php foreach ($trr_m['courses'] as $trr_c) { ?>
            <th scope="col" class="trr-heat__course" title="<?= trr_h($trr_c['name']) ?>"><span><?= trr_h($trr_c['short']) ?></span></th>
            <?php } ?>
            <th scope="col" class="trr-heat__overall">Dept. overall</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($trr_m['rows'] as $trr_r) { ?>
        <tr>
            <th scope="row" class="trr-heat__dept"><?= trr_h($trr_r['name']) ?> <span class="trr-muted">· <?= (int) $trr_r['headcount'] ?></span></th>
            <?php foreach ($trr_m['courses'] as $trr_c) {
                $trr_cell = $trr_r['cells']['c' . $trr_c['id']] ?? null;
                if ($trr_cell === null) { ?>
            <td class="trr-heat__cell"><span class="trr-cell trr-cell--na" title="Not required">—<span class="visually-hidden"> not required</span></span></td>
                <?php } elseif ($trr_cell['pct'] === null) { ?>
            <td class="trr-heat__cell"><span class="trr-cell trr-cell--na" title="Everyone here is waived">Waived</span></td>
                <?php } else {
                    $trr_b = (int) $trr_cell['band'];
                    $trr_label = $trr_r['name'] . ', ' . $trr_c['name'] . ': ' . $trr_cell['pct'] . '% current, ' . $trr_cell['current'] . ' of ' . $trr_cell['required']
                        . ' (' . trr_band_label($trr_b, $trr_hm_target) . ').' . ($trr_cell['current'] < $trr_cell['required'] ? ' Show who is missing.' : '');
                    ?>
            <td class="trr-heat__cell">
                <button type="button" class="trr-cell trr-band-<?= $trr_b ?>" data-trr-cell data-client="<?= (int) $trr_r['client_id'] ?>" data-course="<?= (int) $trr_c['id'] ?>"
                    data-dept-name="<?= trr_h($trr_r['name']) ?>" data-course-name="<?= trr_h($trr_c['name']) ?>" aria-label="<?= trr_h($trr_label) ?>" title="<?= (int) $trr_cell['current'] ?> of <?= (int) $trr_cell['required'] ?> current">
                    <i class="<?= trr_band_icon($trr_b) ?>" aria-hidden="true"></i><?= (int) $trr_cell['pct'] ?>%
                </button>
            </td>
                <?php }
            } ?>
            <td class="trr-heat__overall"><?= $trr_r['overall_pct'] === null ? '—' : (int) $trr_r['overall_pct'] . '%' ?></td>
        </tr>
        <?php } ?>
    </tbody>
</table>
</div>
<div class="trr-legend" aria-hidden="true">
    <span class="trr-legend__title">Current</span>
    <span><i class="trr-legend__sw trr-band-0"></i>Under 70%</span>
    <span><i class="trr-legend__sw trr-band-1"></i>70–79%</span>
    <span><i class="trr-legend__sw trr-band-2"></i>80–89%</span>
    <?php if ($trr_hm_target > 90) { ?><span><i class="trr-legend__sw trr-band-3"></i>90–<?= $trr_hm_target - 1 ?>%</span><?php } ?>
    <span><i class="trr-legend__sw trr-band-4"></i><?= $trr_hm_target ?>–100% (at target)</span>
    <span><i class="trr-legend__sw trr-legend__sw--na">—</i>Not required</span>
    <span class="trr-legend__note">The number after each department is its headcount.<?= !empty($trr_m['hidden_courses']) ? ' ' . (int) $trr_m['hidden_courses'] . ' more ' . ((int) $trr_m['hidden_courses'] === 1 ? 'course is' : 'courses are') . ' in the full matrix.' : '' ?></span>
</div>

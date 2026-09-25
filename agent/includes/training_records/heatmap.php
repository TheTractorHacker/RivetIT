<?php
defined('TRAINING_PAGE') || exit;

/*
 * Department × course heatmap table (dashboard and Reports › Matrix). Expects $trr_m = a
 * MatrixService matrix. Cells are buttons that open the "who is missing" drill-down
 * (training_reports.js, report_cell_people); every cell carries its percentage as text and a
 * band icon, never colour alone (spec §5.1).
 */

$trr_hm_target = (int) ($trr_m['target'] ?? 95);
// Reports › Matrix passes its job / location filters on to the drill-down.
$trr_hm_narrow = '';
foreach (['job' => $trr_hm_job ?? null, 'location' => $trr_hm_location ?? null] as $trr_hm_k => $trr_hm_v) {
    if ($trr_hm_v !== null) {
        $trr_hm_narrow .= ' data-' . $trr_hm_k . '="' . (int) $trr_hm_v . '"';
    }
}
// The dashboard leaves out departments with nothing required in the courses shown (all "—" rows push the ones that
// matter down the page) and says how many it left out; Reports › Matrix keeps every department that has people
// (MatrixService::build has no row for a department with nobody on the roster, such as an empty Quality).
$trr_hm_rows = $trr_m['rows'];
$trr_hm_skipped = 0;
if (!empty($trr_hm_skip_empty) && $trr_m['courses'] !== []) {
    $trr_hm_rows = array_values(array_filter($trr_m['rows'], static function (array $r) use ($trr_m): bool {
        foreach ($trr_m['courses'] as $c) {
            if (($r['cells']['c' . $c['id']] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }));
    $trr_hm_skipped = count($trr_m['rows']) - count($trr_hm_rows);
}
if ($trr_hm_rows === [] || $trr_m['courses'] === []) {
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
            <?php $trr_hm_code = trim((string) ($trr_c['code'] ?? '')); ?>
            <th scope="col" class="trr-heat__course" title="<?= trr_h(($trr_hm_code !== '' ? $trr_hm_code . ' · ' : '') . $trr_c['name']) ?>"><?php if ($trr_hm_code !== '' && $trr_hm_code !== $trr_c['name']) { ?><span class="trr-heat__code"><?= trr_h($trr_hm_code) ?></span><span class="trr-heat__name"><?= trr_h(trr_course_short_name($trr_c['name'])) ?></span><?php } else { ?><span><?= trr_h($trr_c['name']) ?></span><?php } ?></th>
            <?php } ?>
            <th scope="col" class="trr-heat__overall">Dept. overall</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($trr_hm_rows as $trr_r) { ?>
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
                <button type="button" class="trr-cell trr-band-<?= $trr_b ?>" data-trr-cell data-client="<?= (int) $trr_r['client_id'] ?>" data-course="<?= (int) $trr_c['id'] ?>"<?= $trr_hm_narrow ?>
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
    <?php if (!empty($trr_hm_totals) && isset($trr_m['totals'])) { $trr_t = $trr_m['totals']; ?>
    <tfoot>
        <tr>
            <th scope="row" class="trr-heat__dept">All departments <span class="trr-muted">· <?= (int) $trr_m['people'] ?></span></th>
            <?php foreach ($trr_m['courses'] as $trr_c) {
                $trr_tc = $trr_t['cells']['c' . $trr_c['id']] ?? null; ?>
            <td class="trr-heat__cell"><?php if ($trr_tc === null || $trr_tc['pct'] === null) { ?><span class="trr-cell trr-cell--na">—</span><?php } else { ?><span class="trr-cell trr-cell--total" title="<?= (int) $trr_tc['current'] ?> of <?= (int) $trr_tc['required'] ?> current"><?= (int) $trr_tc['pct'] ?>%</span><?php } ?></td>
            <?php } ?>
            <td class="trr-heat__overall"><?= $trr_t['overall_pct'] === null ? '—' : (int) $trr_t['overall_pct'] . '%' ?></td>
        </tr>
    </tfoot>
    <?php } ?>
</table>
</div>
<?php if ($trr_hm_skipped > 0) { ?>
<p class="trr-muted small mt-2 mb-0"><?= (int) $trr_hm_skipped ?> <?= $trr_hm_skipped === 1 ? 'department has' : 'departments have' ?> no required training in these courses and <?= $trr_hm_skipped === 1 ? 'is' : 'are' ?> not shown. Reports › Matrix lists every department with people on the roster.</p>
<?php } ?>
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

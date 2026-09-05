<?php
/*
 * Reusable dashboard/list stat tile, replacing ad-hoc AdminLTE 3
 * .small-box/.info-box markup. $tint picks one of the semantic
 * .it-tint-* icon colors defined in css/itflow_design.css
 * (primary, slate, success, warning, danger, info, violet).
 */

function render_stat_card(string $label, string $value, string $icon_class = '', string $tint = 'slate', ?string $href = null): void {
    $tint = preg_replace('/[^a-z]/', '', strtolower($tint)) ?: 'slate';
    $tag = $href !== null ? 'a' : 'div';
    ?>
    <<?= $tag ?> class="it-stat-card"<?= $href !== null ? ' href="' . nullable_htmlentities($href) . '"' : '' ?>>
        <?php if ($icon_class !== ''): ?>
        <div class="it-stat-icon it-tint-<?= $tint ?>"><i class="<?= nullable_htmlentities($icon_class) ?>"></i></div>
        <?php endif; ?>
        <div class="it-stat-body">
            <div class="it-stat-value"><?= nullable_htmlentities($value) ?></div>
            <div class="it-stat-label"><?= nullable_htmlentities($label) ?></div>
        </div>
    </<?= $tag ?>>
    <?php
}

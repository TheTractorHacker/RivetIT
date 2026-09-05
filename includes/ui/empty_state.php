<?php
/*
 * Reusable "nothing here yet" placeholder, replacing ad-hoc blank
 * cards/tables. Modernization plan §73.
 */

function render_empty_state(string $icon_class, string $title, string $subtitle = '', string $actions_html = ''): void {
    ?>
    <div class="it-empty-state">
        <?php if ($icon_class !== ''): ?><div class="it-empty-icon"><i class="<?= nullable_htmlentities($icon_class) ?>"></i></div><?php endif; ?>
        <p class="it-empty-title"><?= nullable_htmlentities($title) ?></p>
        <?php if ($subtitle !== ''): ?><p class="it-empty-subtitle"><?= nullable_htmlentities($subtitle) ?></p><?php endif; ?>
        <?php if ($actions_html !== ''): ?><div class="it-empty-actions"><?= $actions_html ?></div><?php endif; ?>
    </div>
    <?php
}

<?php
/*
 * Reusable card, as an open/close pair rather than one all-in-one call, so
 * existing hand-written card-body markup can adopt it incrementally without
 * first being rewritten into a string param. Modernization plan §8/§9.
 */

function render_card_open(?string $title = null, string $header_actions_html = '', array $opts = []): void {
    $extra_class = (string) ($opts['class'] ?? '');
    ?>
    <div class="it-card<?= $extra_class !== '' ? ' ' . nullable_htmlentities($extra_class) : '' ?>">
        <?php if ($title !== null || $header_actions_html !== ''): ?>
        <div class="it-card-header">
            <?php if ($title !== null): ?><span><?= nullable_htmlentities($title) ?></span><?php endif; ?>
            <?php if ($header_actions_html !== ''): ?><div><?= $header_actions_html ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="it-card-body">
    <?php
}

function render_card_close(): void {
    ?>
        </div>
    </div>
    <?php
}

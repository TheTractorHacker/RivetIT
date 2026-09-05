<?php
/*
 * Renders a colored status pill for any admin-configurable status color
 * (e.g. ticket_statuses.ticket_status_color is a free-form per-row hex, not
 * a fixed palette, so this takes the color as data rather than mapping a
 * name to a hardcoded color). Text color is computed for contrast via the
 * existing tagTextClass() helper (functions.php), same as ticket tags.
 */

function render_status_badge(string $name, string $hex_color): void {
    $hex_color = $hex_color !== '' ? $hex_color : '#6c757d';
    $text_color = (tagTextClass($hex_color) === 'text-light') ? '#fff' : '#212529';
    ?>
    <span class="it-status-pill" style="background-color: <?= nullable_htmlentities($hex_color) ?>; color: <?= $text_color ?>;">
        <?= nullable_htmlentities($name) ?>
    </span>
    <?php
}

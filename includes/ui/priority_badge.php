<?php
/*
 * Renders a priority pill from the fixed low/medium/high/critical set (see
 * the .it-priority-pill rules in css/itflow_design.css, which reuse the
 * --priority-* tokens already defined in css/itflow_custom.css). An
 * unrecognized or missing value falls back to a plain neutral pill with the
 * raw label rather than guessing a color for it.
 */

function render_priority_badge(?string $priority): void {
    $priority = trim((string) $priority);
    if ($priority === '') {
        return;
    }
    $key = strtolower($priority);
    $known = ['low', 'medium', 'high', 'critical'];
    if (in_array($key, $known, true)) {
        ?>
        <span class="it-priority-pill it-priority-<?= $key ?>"><?= nullable_htmlentities($priority) ?></span>
        <?php
    } else {
        ?>
        <span class="it-priority-pill" style="background: rgba(100,116,139,.13); color: #475569;"><?= nullable_htmlentities($priority) ?></span>
        <?php
    }
}

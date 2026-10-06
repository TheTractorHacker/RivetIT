<?php /* The recipe gallery (included by the list for its empty state and its "Start from a recipe" panel, and by the editor). */ ?>
<div class="er-recipes" role="list" aria-label="Rule recipes">
    <?php foreach ($recipes as $rc) {
        $ev = $event_info[$rc['event']] ?? null;
        [$gicon, $gcolor] = $group_style[$group_of($rc['event'])] ?? $group_style['other']; ?>
    <a class="er-recipe" role="listitem" href="event_rules.php?new=1&amp;recipe=<?= $h($rc['key']) ?>" style="--er-c: <?= $h($gcolor) ?>">
        <span class="er-recipe-icon"><i class="fas <?= $h($rc['icon']) ?>" aria-hidden="true"></i></span>
        <span class="er-recipe-body">
            <span class="er-recipe-title"><?= $h($rc['title']) ?></span>
            <span class="er-recipe-blurb"><?= $h($rc['blurb']) ?></span>
            <span class="er-recipe-flow"><i class="fas <?= $h($gicon) ?>" aria-hidden="true"></i> <?= $h($ev['label'] ?? $rc['event']) ?> <i class="fas fa-long-arrow-alt-right" aria-hidden="true"></i> <i class="fas <?= $h($action_meta[$rc['action']][0] ?? 'fa-bolt') ?>" aria-hidden="true"></i> <?= $h($rule_actions[$rc['action']] ?? $rc['action']) ?></span>
        </span>
    </a>
    <?php } ?>
</div>

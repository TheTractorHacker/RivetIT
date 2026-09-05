<?php
/*
 * Reusable page header: title + optional subtitle + optional breadcrumbs +
 * optional right-aligned action buttons. Modernization plan §8/§10.
 */

function render_page_header(string $title, ?string $subtitle = null, string $actions_html = '', array $breadcrumbs = []): void {
    ?>
    <div class="it-page-header">
        <?php if (!empty($breadcrumbs)): ?>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <?php foreach ($breadcrumbs as $crumb):
                    $label = nullable_htmlentities($crumb['label'] ?? '');
                    $url = $crumb['url'] ?? null;
                ?>
                <?php if ($url): ?>
                <li class="breadcrumb-item"><a href="<?= nullable_htmlentities($url) ?>"><?= $label ?></a></li>
                <?php else: ?>
                <li class="breadcrumb-item active" aria-current="page"><?= $label ?></li>
                <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
        <?php endif; ?>
        <div class="it-page-header-row">
            <div>
                <h1 class="it-page-title"><?= nullable_htmlentities($title) ?></h1>
                <?php if ($subtitle !== null && $subtitle !== ''): ?>
                <p class="it-page-subtitle"><?= nullable_htmlentities($subtitle) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($actions_html !== ''): ?>
            <div class="it-page-actions"><?= $actions_html ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

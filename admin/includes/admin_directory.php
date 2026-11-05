<?php
/**
 * Render an Admin navigation directory. Items are [label, description, page, icon, visible?, add-modal?, status?].
 * status is optional: [state, text] where state is ok|warn|off. It renders as a chip on the tile, and every 'warn'
 * item is listed in a "needs attention" strip above the groups. The directory is searchable (type to filter tiles).
 */
function renderAdminDirectory(string $title, string $description, string $icon, array $groups): void
{
    global $csp_nonce;
    ?>
    <style nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
        .admin-directory [id] { scroll-margin-top: 5rem; }
        .admin-directory__head { margin-bottom: 1rem; }
        .admin-directory__head h1 { margin: 0; }
        .admin-directory__head p { margin: .2rem 0 0; color: var(--if-muted, #5d6f76); }
        .admin-directory__nav { position: sticky; top: 0; z-index: 20; margin-bottom: 1.25rem; padding: .5rem 0; background: var(--if-bg, #eef2f2); }
        .admin-directory__nav ul { display: flex; gap: .25rem; overflow-x: auto; margin: 0; padding: .3rem; list-style: none; background: var(--if-surface, #fff); border: 1px solid var(--if-border, #e3e9ea); border-radius: var(--if-radius, 12px); }
        .admin-directory__nav li { flex: 0 0 auto; }
        .admin-directory__nav a { display: flex; align-items: center; gap: .45rem; padding: .45rem .8rem; border-radius: 8px; color: var(--if-muted, #5d6f76); font-weight: 500; white-space: nowrap; text-decoration: none; }
        .admin-directory__nav a:hover, .admin-directory__nav a:focus-visible { color: var(--if-primary, #0d9488); background: rgba(var(--if-primary-rgb, 13, 148, 136), .1); }
        .admin-directory__section + .admin-directory__section { margin-top: 1.5rem; }
        .admin-directory__section-head { margin-bottom: .75rem; padding-bottom: .6rem; border-bottom: 1px solid var(--if-border-strong, #d3dbdc); }
        .admin-directory__section-head h2 { margin: 0; font-size: 1.25rem; }
        .admin-directory__section-head h2 i { color: var(--if-primary, #0d9488); }
        .admin-directory__section-head p { margin: .2rem 0 0; color: var(--if-muted, #5d6f76); font-size: .875rem; }
        .admin-directory__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 17rem), 1fr)); gap: .65rem; }
        .admin-directory__tile { display: flex; align-items: stretch; min-height: 4.5rem; background: var(--if-surface, #fff); border: 1px solid var(--if-border, #e3e9ea); border-radius: var(--if-radius, 12px); }
        .admin-directory__tile:hover, .admin-directory__tile:focus-within { border-color: var(--if-primary, #0d9488); box-shadow: var(--if-shadow, none); }
        .admin-directory__item { display: flex; flex: 1; align-items: flex-start; gap: .75rem; min-width: 0; padding: .85rem; color: var(--if-ink, #16232a); text-decoration: none; }
        .admin-directory__item:hover, .admin-directory__item:focus-visible { color: var(--if-ink, #16232a); text-decoration: none; }
        .admin-directory__item > i { margin-top: .15rem; color: var(--if-primary, #0d9488); }
        .admin-directory__item strong, .admin-directory__item small { display: block; }
        .admin-directory__item small { margin-top: .12rem; color: var(--if-muted, #5d6f76); line-height: 1.35; }
        .admin-directory__tools { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-bottom: 1rem; }
        .admin-directory__search { position: relative; flex: 1 1 18rem; max-width: 26rem; }
        .admin-directory__search i { position: absolute; left: .8rem; top: 50%; transform: translateY(-50%); color: var(--if-muted, #5d6f76); pointer-events: none; }
        .admin-directory__search input { width: 100%; padding-left: 2.2rem; }
        .admin-directory__count { color: var(--if-muted, #5d6f76); font-size: .875rem; }
        .admin-directory__attn { display: flex; flex-wrap: wrap; gap: .4rem .6rem; align-items: center; margin-bottom: 1.25rem; padding: .7rem .9rem; border: 1px solid var(--if-border, #e3e9ea); border-left: 4px solid #d97706; border-radius: var(--if-radius, 12px); background: var(--if-surface, #fff); }
        .admin-directory__attn strong { margin-right: .25rem; }
        .admin-directory__attn a { padding: .15rem .6rem; border-radius: 999px; background: rgba(217, 119, 6, .12); color: var(--if-ink, #16232a); text-decoration: none; font-size: .875rem; white-space: nowrap; }
        .admin-directory__attn a:hover, .admin-directory__attn a:focus-visible { background: rgba(217, 119, 6, .25); }
        .admin-directory__attn.is-clear { border-left-color: #16a34a; }
        .admin-directory__chip { display: inline-block; margin-top: .35rem; padding: .05rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; line-height: 1.5; white-space: nowrap; }
        .admin-directory__chip--ok { background: rgba(22, 163, 74, .14); color: #166534; }
        .admin-directory__chip--warn { background: rgba(217, 119, 6, .16); color: #92400e; }
        .admin-directory__chip--off { background: rgba(100, 116, 139, .16); color: var(--if-muted, #475569); }
        :root[data-bs-theme="dark"] .admin-directory__chip--ok { color: #86efac; }
        :root[data-bs-theme="dark"] .admin-directory__chip--warn { color: #fcd34d; }
        .admin-directory__empty { padding: 2rem 1rem; text-align: center; color: var(--if-muted, #5d6f76); }
        .admin-directory [hidden] { display: none !important; }
        .admin-directory__add { align-self: center; flex: 0 0 auto; min-height: 2rem; margin-right: .65rem; padding: .3rem .5rem; border: 1px solid var(--if-border-strong, #d3dbdc); border-radius: 7px; background: transparent; color: var(--if-primary, #0d9488); cursor: pointer; }
        .admin-directory__add:hover, .admin-directory__add:focus-visible { border-color: var(--if-primary, #0d9488); background: rgba(var(--if-primary-rgb, 13, 148, 136), .1); }
    </style>
    <div class="admin-directory">
        <header class="admin-directory__head">
            <h1 class="h2"><i class="fas fa-fw <?php echo nullable_htmlentities($icon); ?> me-2" aria-hidden="true"></i><?php echo nullable_htmlentities($title); ?></h1>
            <p><?php echo nullable_htmlentities($description); ?></p>
        </header>
        <?php
        $attention = [];
        $status_total = 0;
        foreach ($groups as $g) {
            foreach ($g['items'] as $it) {
                if (isset($it[4]) && !$it[4]) { continue; }
                if (!empty($it[6])) { $status_total++; if ($it[6][0] === 'warn') { $attention[] = $it; } }
            }
        }
        if ($status_total > 0) { ?>
            <div class="admin-directory__attn<?php echo $attention ? '' : ' is-clear'; ?>" role="status">
                <?php if ($attention) { ?>
                    <strong><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>Needs attention (<?php echo count($attention); ?>)</strong>
                    <?php foreach ($attention as $it) { ?>
                        <a href="/admin/<?php echo nullable_htmlentities($it[2]); ?>"><?php echo nullable_htmlentities($it[0]); ?>: <?php echo nullable_htmlentities($it[6][1]); ?></a>
                    <?php } ?>
                <?php } else { ?>
                    <strong><i class="fas fa-check-circle me-1" aria-hidden="true"></i>Everything essential is set up.</strong>
                <?php } ?>
            </div>
        <?php } ?>
        <div class="admin-directory__tools">
            <label class="admin-directory__search">
                <span class="visually-hidden">Filter <?php echo nullable_htmlentities(strtolower($title)); ?></span>
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" class="form-control" placeholder="Filter <?php echo nullable_htmlentities(strtolower($title)); ?> (e.g. mail, backup, SSO)" autocomplete="off" data-admin-directory-filter>
            </label>
            <span class="admin-directory__count" aria-live="polite" data-admin-directory-count></span>
        </div>
        <nav class="admin-directory__nav" aria-label="<?php echo nullable_htmlentities($title); ?> sections">
            <ul>
                <?php foreach ($groups as $group_id => $group) { ?>
                    <li><a href="#<?php echo nullable_htmlentities($group_id); ?>"><i class="fas fa-fw <?php echo nullable_htmlentities($group['icon']); ?>" aria-hidden="true"></i><?php echo nullable_htmlentities($group['title']); ?></a></li>
                <?php } ?>
            </ul>
        </nav>
        <p class="admin-directory__empty" data-admin-directory-empty hidden>Nothing matches that filter.</p>
        <?php foreach ($groups as $group_id => $group) { ?>
            <section data-admin-directory-section id="<?php echo nullable_htmlentities($group_id); ?>" class="admin-directory__section" aria-labelledby="admin-directory-<?php echo nullable_htmlentities($group_id); ?>-title">
                <div class="admin-directory__section-head">
                    <h2 id="admin-directory-<?php echo nullable_htmlentities($group_id); ?>-title"><i class="fas fa-fw <?php echo nullable_htmlentities($group['icon']); ?> me-2" aria-hidden="true"></i><?php echo nullable_htmlentities($group['title']); ?></h2>
                    <p><?php echo nullable_htmlentities($group['description']); ?></p>
                </div>
                <div class="admin-directory__grid">
                    <?php foreach ($group['items'] as $item) {
                        [$label, $item_description, $page, $item_icon] = $item;
                        if (isset($item[4]) && !$item[4]) { continue; }
                        $add = $item[5] ?? null;
                        $add_label = 'Add ' . lcfirst(preg_replace('/s$/', '', $label));
                        ?>
                        <div class="admin-directory__tile" data-admin-directory-tile data-search="<?php echo nullable_htmlentities(strtolower($label . ' ' . $item_description . ' ' . $page)); ?>">
                            <a class="admin-directory__item" href="/admin/<?php echo nullable_htmlentities($page); ?>">
                                <i class="fas fa-fw <?php echo nullable_htmlentities($item_icon); ?>" aria-hidden="true"></i>
                                <span><strong><?php echo nullable_htmlentities($label); ?></strong><small><?php echo nullable_htmlentities($item_description); ?></small><?php if (!empty($item[6])) { $st = in_array($item[6][0], ['ok', 'warn', 'off'], true) ? $item[6][0] : 'off'; ?><span class="admin-directory__chip admin-directory__chip--<?php echo $st; ?>"><?php echo $st === 'ok' ? '&#10003; ' : ($st === 'warn' ? '! ' : ''); echo nullable_htmlentities($item[6][1]); ?></span><?php } ?></span>
                            </a>
                            <?php if ($add) { ?>
                                <button type="button" class="admin-directory__add ajax-modal" data-modal-url="<?php echo nullable_htmlentities($add['url']); ?>"<?php if (!empty($add['size'])) { ?> data-modal-size="<?php echo nullable_htmlentities($add['size']); ?>"<?php } ?> aria-label="<?php echo nullable_htmlentities($add_label); ?>" title="<?php echo nullable_htmlentities($add_label); ?>"><i class="fas fa-plus me-1" aria-hidden="true"></i>New</button>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
            </section>
        <?php } ?>
    </div>
    <script nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
    (function () {
        var box = document.querySelector('[data-admin-directory-filter]');
        if (!box) { return; }
        var tiles = [].slice.call(document.querySelectorAll('[data-admin-directory-tile]'));
        var sections = [].slice.call(document.querySelectorAll('[data-admin-directory-section]'));
        var count = document.querySelector('[data-admin-directory-count]');
        var empty = document.querySelector('[data-admin-directory-empty]');
        function apply() {
            var terms = box.value.toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
            tiles.forEach(function (t) {
                var hay = t.getAttribute('data-search') || '';
                var ok = terms.every(function (w) { return hay.indexOf(w) !== -1; });
                t.hidden = !ok;
                if (ok) { shown++; }
            });
            sections.forEach(function (s) { s.hidden = !s.querySelector('[data-admin-directory-tile]:not([hidden])'); });
            empty.hidden = shown !== 0;
            count.textContent = terms.length ? shown + ' of ' + tiles.length + ' shown' : tiles.length + ' pages';
        }
        box.addEventListener('input', apply);
        box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { box.value = ''; apply(); } });
        apply();
    })();
    </script>
    <?php
}

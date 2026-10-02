<?php
/** Render an Admin navigation directory. Items are [label, description, page, icon, visible?, add-modal?]. */
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
        .admin-directory__add { align-self: center; flex: 0 0 auto; min-height: 2rem; margin-right: .65rem; padding: .3rem .5rem; border: 1px solid var(--if-border-strong, #d3dbdc); border-radius: 7px; background: transparent; color: var(--if-primary, #0d9488); cursor: pointer; }
        .admin-directory__add:hover, .admin-directory__add:focus-visible { border-color: var(--if-primary, #0d9488); background: rgba(var(--if-primary-rgb, 13, 148, 136), .1); }
    </style>
    <div class="admin-directory">
        <header class="admin-directory__head">
            <h1 class="h2"><i class="fas fa-fw <?php echo nullable_htmlentities($icon); ?> me-2" aria-hidden="true"></i><?php echo nullable_htmlentities($title); ?></h1>
            <p><?php echo nullable_htmlentities($description); ?></p>
        </header>
        <nav class="admin-directory__nav" aria-label="<?php echo nullable_htmlentities($title); ?> sections">
            <ul>
                <?php foreach ($groups as $group_id => $group) { ?>
                    <li><a href="#<?php echo nullable_htmlentities($group_id); ?>"><i class="fas fa-fw <?php echo nullable_htmlentities($group['icon']); ?>" aria-hidden="true"></i><?php echo nullable_htmlentities($group['title']); ?></a></li>
                <?php } ?>
            </ul>
        </nav>
        <?php foreach ($groups as $group_id => $group) { ?>
            <section id="<?php echo nullable_htmlentities($group_id); ?>" class="admin-directory__section" aria-labelledby="admin-directory-<?php echo nullable_htmlentities($group_id); ?>-title">
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
                        <div class="admin-directory__tile">
                            <a class="admin-directory__item" href="/admin/<?php echo nullable_htmlentities($page); ?>">
                                <i class="fas fa-fw <?php echo nullable_htmlentities($item_icon); ?>" aria-hidden="true"></i>
                                <span><strong><?php echo nullable_htmlentities($label); ?></strong><small><?php echo nullable_htmlentities($item_description); ?></small></span>
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
    <?php
}

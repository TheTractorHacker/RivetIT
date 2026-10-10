<?php
/*
 * Relationships card: the links of one record (outgoing), what refers to it, and what depends on it (transitively).
 *
 * Drop it into a details page:
 *     $rel_type = 'asset'; $rel_id = $asset_id;
 *     require __DIR__ . '/../includes/relationships_card.php';     // agent/*.php
 * Optional: $rel_in_modal = true renders the "Link item" form inline instead of opening a second modal (used by
 * agent/modals/relationships/relationships.php, which shows the card for record types that have no details page).
 *
 * Rows come from ITFlow\Links\LinkService: real links (entity_links, can be unlinked) and read-only derived rows (the older link
 * tables such as asset_documents and software_assets, shown with where they come from). Everything is filtered to what the signed-in
 * role may see (module permission and department access).
 */
if (!function_exists('rivetRenderRelationshipsCard')) {
/** Echoes the card. A function, so none of the page's own variables are touched by the loops below. */
function rivetRenderRelationshipsCard(\mysqli $mysqli, string $rel_type, int $rel_id, bool $rel_in_modal = false): void
{
if (!\ITFlow\Links\EntityTypes::isType($rel_type) || $rel_id < 1) {
    return;
}
$rel_actor = \ITFlow\Links\LinkActor::fromSession($mysqli);
if (!$rel_actor->canRead($rel_type)) {
    return;
}
$rel_svc = new \ITFlow\Links\LinkService($mysqli);
$rel_view = $rel_svc->view($rel_actor, (string) $rel_type, (int) $rel_id);
$rel_self = $rel_svc->lookup((string) $rel_type, (int) $rel_id);
$rel_client = $rel_self['client_id'] ?? 0;
$rel_h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$rel_csrf = $rel_h($_SESSION['csrf_token'] ?? '');
$rel_vendor_kind = in_array($rel_type, ['asset', 'software'], true) ? (string) $rel_type : '';
$rel_vendors = $rel_vendor_kind !== '' ? (new \ITFlow\Links\VendorRoles($mysqli))->forRecord($rel_vendor_kind, (int) $rel_id) : [];

$rel_row = static function (array $e, bool $outgoing) use ($rel_h, $rel_view, $rel_csrf): string {
    $o = $e['other'];
    $badge = $e['derived']
        ? '<span class="badge text-bg-secondary ms-1" title="Read only: this comes from ' . $rel_h($e['source']) . '">from ' . $rel_h($e['source']) . '</span>'
        : '';
    $note = !empty($e['note']) ? '<div class="text-muted small">' . $rel_h($e['note']) . '</div>' : '';
    $unlink = '';
    if (!$e['derived'] && $rel_view['can_write']) {
        $unlink = '<form method="post" action="post.php" class="d-inline"><input type="hidden" name="csrf_token" value="' . $rel_csrf . '">'
            . '<input type="hidden" name="link_id" value="' . (int) $e['link_id'] . '">'
            . '<button type="submit" name="unlink_entity" value="1" class="btn btn-sm btn-link text-danger p-0" title="Unlink" data-rel-unlink="1"><i class="fas fa-fw fa-unlink"></i></button></form>';
    }

    return '<tr data-rel-row="' . ($e['derived'] ? 'derived' : 'link') . '" data-rel-link-type="' . $rel_h($e['link_type']) . '">'
        . '<td class="text-nowrap text-muted">' . $rel_h($e['relation']) . '</td>'
        . '<td><i class="fas fa-fw ' . $rel_h($o['icon']) . ' me-1 text-muted"></i><span class="text-muted small me-1">' . $rel_h($o['label']) . '</span>'
        . '<a href="' . $rel_h($o['url']) . '">' . $rel_h($o['name']) . '</a>' . ($o['archived'] ? ' <span class="badge text-bg-light">archived</span>' : '') . $badge . $note . '</td>'
        . '<td class="text-end">' . $unlink . '</td></tr>';
};
$rel_types = \ITFlow\Links\EntityTypes::all();
?>
<div class="card card-dark mt-3" id="relationships-card" data-rel-type="<?php echo $rel_h($rel_type); ?>" data-rel-id="<?php echo (int) $rel_id; ?>" data-rel-client="<?php echo (int) $rel_client; ?>">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-project-diagram me-2"></i>Relationships</h3>
        <?php if ($rel_view['can_write'] && !$rel_in_modal) { ?>
            <button type="button" class="btn btn-primary btn-sm ms-auto ajax-modal" data-rel-open-link="1"
                    data-modal-url="modals/relationships/link_add.php?type=<?php echo $rel_h($rel_type); ?>&id=<?php echo (int) $rel_id; ?>">
                <i class="fas fa-fw fa-link me-1"></i>Link item
            </button>
        <?php } ?>
    </div>
    <div class="card-body">

        <?php if ($rel_vendor_kind !== '') { ?>
            <h6 class="text-muted text-uppercase small mb-2"><i class="fas fa-fw fa-building me-1"></i>Vendors</h6>
            <?php if ($rel_vendors) { ?>
                <ul class="list-unstyled mb-3" data-rel-vendors="1">
                    <?php foreach ($rel_vendors as $v) { ?>
                        <li class="mb-1">
                            <a href="vendor_details.php?<?php echo $v['client_id'] > 0 ? 'client_id=' . (int) $v['client_id'] . '&' : ''; ?>vendor_id=<?php echo (int) $v['vendor_id']; ?>"><?php echo $rel_h($v['name']); ?></a>
                            <?php foreach ($v['roles'] as $role) { ?>
                                <span class="badge text-bg-light border ms-1"><?php echo $rel_h($role); ?><?php echo ($v['primary'] && $role === 'support') ? ' (primary)' : ''; ?></span>
                                <?php if ($rel_view['can_write'] && !($v['primary'] && $role === 'support')) { ?>
                                    <form method="post" action="post.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $rel_csrf; ?>">
                                        <input type="hidden" name="vendor_kind" value="<?php echo $rel_h($rel_vendor_kind); ?>"><input type="hidden" name="record_id" value="<?php echo (int) $rel_id; ?>">
                                        <input type="hidden" name="vendor_id" value="<?php echo (int) $v['vendor_id']; ?>"><input type="hidden" name="vendor_role" value="<?php echo $rel_h($role); ?>">
                                        <button type="submit" name="remove_vendor_role" value="1" class="btn btn-sm btn-link text-danger p-0" title="Remove this role"><i class="fas fa-fw fa-times"></i></button>
                                    </form>
                                <?php } ?>
                            <?php } ?>
                        </li>
                    <?php } ?>
                </ul>
            <?php } else { ?>
                <p class="text-muted small mb-2">No vendor yet.</p>
            <?php } ?>
            <?php if ($rel_view['can_write'] && $rel_actor->canRead('vendor')) { ?>
                <form method="post" action="post.php" class="row g-2 align-items-center mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo $rel_csrf; ?>">
                    <input type="hidden" name="vendor_kind" value="<?php echo $rel_h($rel_vendor_kind); ?>"><input type="hidden" name="record_id" value="<?php echo (int) $rel_id; ?>">
                    <div class="col-sm-5">
                        <select class="form-select form-select-sm" name="vendor_id" required>
                            <option value="">Add a vendor...</option>
                            <?php
                            $rel_vq = mysqli_query($mysqli, 'SELECT vendor_id, vendor_name FROM vendors WHERE vendor_archived_at IS NULL AND (vendor_client_id = ' . (int) $rel_client . ' OR vendor_client_id = 0) ORDER BY vendor_name LIMIT 500');
                            while ($rel_vq && ($rv = mysqli_fetch_assoc($rel_vq))) { echo '<option value="' . (int) $rv['vendor_id'] . '">' . $rel_h($rv['vendor_name']) . '</option>'; }
                            ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <select class="form-select form-select-sm" name="vendor_role">
                            <?php foreach (\ITFlow\Links\EntityTypes::VENDOR_ROLES as $role) { echo '<option value="' . $rel_h($role) . '">' . $rel_h(ucfirst($role)) . '</option>'; } ?>
                        </select>
                    </div>
                    <div class="col-sm-3"><button type="submit" name="add_vendor_role" value="1" class="btn btn-sm btn-outline-primary w-100"><i class="fas fa-fw fa-plus me-1"></i>Add vendor</button></div>
                </form>
            <?php } ?>
        <?php } ?>

        <h6 class="text-muted text-uppercase small mb-2"><i class="fas fa-fw fa-arrow-right me-1"></i>Links</h6>
        <?php if ($rel_view['outgoing']) { ?>
            <div class="table-responsive-sm mb-3"><table class="table table-sm table-borderless align-middle mb-0" data-rel-table="outgoing"><tbody>
                <?php foreach ($rel_view['outgoing'] as $e) { echo $rel_row($e, true); } ?>
            </tbody></table></div>
        <?php } else { ?>
            <p class="text-muted small mb-3" data-rel-empty="outgoing">Nothing linked yet.</p>
        <?php } ?>

        <h6 class="text-muted text-uppercase small mb-2"><i class="fas fa-fw fa-arrow-left me-1"></i>Referenced by</h6>
        <?php if ($rel_view['incoming']) { ?>
            <div class="table-responsive-sm mb-3"><table class="table table-sm table-borderless align-middle mb-0" data-rel-table="incoming"><tbody>
                <?php foreach ($rel_view['incoming'] as $e) { echo $rel_row($e, false); } ?>
            </tbody></table></div>
        <?php } else { ?>
            <p class="text-muted small mb-3" data-rel-empty="incoming">Nothing refers to this record.</p>
        <?php } ?>

        <h6 class="text-muted text-uppercase small mb-2"><i class="fas fa-fw fa-bolt me-1"></i>Impact: what depends on this</h6>
        <?php if ($rel_view['impact']) { ?>
            <ul class="list-unstyled mb-0" data-rel-impact="1">
                <?php foreach ($rel_view['impact'] as $n) { ?>
                    <li class="mb-1" data-rel-depth="<?php echo (int) $n['depth']; ?>" style="padding-left: <?php echo ((int) $n['depth'] - 1) * 1.25; ?>rem">
                        <span class="badge text-bg-<?php echo $n['depth'] === 1 ? 'danger' : ($n['depth'] === 2 ? 'warning' : 'secondary'); ?> me-1"><?php echo (int) $n['depth']; ?></span>
                        <i class="fas fa-fw <?php echo $rel_h($n['icon']); ?> me-1 text-muted"></i><span class="text-muted small me-1"><?php echo $rel_h($n['label']); ?></span>
                        <a href="<?php echo $rel_h($n['url']); ?>"><?php echo $rel_h($n['name']); ?></a>
                        <?php if ($n['depth'] > 1 && $n['via_name'] !== '') { ?><span class="text-muted small"> via <?php echo $rel_h($n['via_name']); ?></span><?php } ?>
                    </li>
                <?php } ?>
            </ul>
            <p class="text-muted small mt-2 mb-0">Follows "depends on", "runs on" and "supported by", up to three levels, from links and from the older link tables.</p>
        <?php } else { ?>
            <p class="text-muted small mb-0" data-rel-empty="impact">Nothing depends on this record.</p>
        <?php } ?>

        <?php if ($rel_in_modal && $rel_view['can_write']) { ?>
            <hr>
            <?php
            $rel_form_inline = true;
            require __DIR__ . '/relationships_link_form.php';
            ?>
        <?php } ?>
    </div>
</div>
<?php if (!defined('RIVET_REL_JS')) { define('RIVET_REL_JS', 1); ?>
<script src="/js/relationships.js?v=<?php echo @filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/relationships.js'); ?>" defer></script>
<?php } ?>
<?php
}
}

if (isset($mysqli, $rel_type, $rel_id)) {
    rivetRenderRelationshipsCard($mysqli, (string) $rel_type, (int) $rel_id, !empty($rel_in_modal));
}

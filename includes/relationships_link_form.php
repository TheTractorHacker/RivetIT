<?php
/*
 * The "Link item" form: type picker, search, relationship type, note. Shared by the modal (agent/modals/relationships/link_add.php)
 * and the inline variant inside the card for record types shown in a pop-up. Needs $mysqli, $rel_type, $rel_id and (optionally)
 * $rel_form_inline. The search box calls ajax.php?relationship_search=1 (js/relationships.js).
 */
$lf_h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$lf_actor = \ITFlow\Links\LinkActor::fromSession($mysqli);
$lf_self = (new \ITFlow\Links\LinkService($mysqli))->lookup((string) $rel_type, (int) $rel_id);
$lf_client = $lf_self['client_id'] ?? 0;
?>
<form action="post.php" method="post" autocomplete="off" data-rel-form="1" data-rel-client="<?php echo (int) $lf_client; ?>">
    <input type="hidden" name="csrf_token" value="<?php echo $lf_h($_SESSION['csrf_token'] ?? ''); ?>">
    <input type="hidden" name="src_type" value="<?php echo $lf_h($rel_type); ?>">
    <input type="hidden" name="src_id" value="<?php echo (int) $rel_id; ?>">
    <div class="row g-2">
        <div class="col-md-4">
            <label class="form-label small text-muted mb-1">Relationship</label>
            <select class="form-select" name="link_type" data-rel-link-type-select="1">
                <?php foreach (\ITFlow\Links\EntityTypes::LINK_TYPES as $lt) { echo '<option value="' . $lf_h($lt) . '">' . $lf_h(ucfirst(\ITFlow\Links\EntityTypes::linkTypeLabel($lt))) . '</option>'; } ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small text-muted mb-1">Type</label>
            <select class="form-select" name="dst_type" data-rel-type-select="1" required>
                <?php foreach (\ITFlow\Links\EntityTypes::all() as $t => $spec) { if ($lf_actor->canRead($t)) { echo '<option value="' . $lf_h($t) . '">' . $lf_h($spec['label']) . '</option>'; } } ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small text-muted mb-1">Search</label>
            <input type="search" class="form-control" placeholder="Type to search..." data-rel-search="1">
        </div>
        <div class="col-12">
            <select class="form-select" name="dst_id" data-rel-results="1" size="5" required>
                <option value="">Search, then pick a record</option>
            </select>
        </div>
        <div class="col-12">
            <input type="text" class="form-control" name="note" maxlength="500" placeholder="Note (optional)">
        </div>
    </div>
    <div class="mt-3 d-flex gap-2">
        <button type="submit" name="link_entities" value="1" class="btn btn-primary"><i class="fas fa-fw fa-link me-2"></i>Link</button>
        <?php if (empty($rel_form_inline)) { ?><button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button><?php } ?>
    </div>
</form>
<script src="/js/relationships.js?v=<?php echo @filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/relationships.js'); ?>" defer></script>

<?php

require_once '../../../includes/modal_header.php';

$ticket_ids = array_map('intval', $_GET['ticket_ids'] ?? []);

$count = count($ticket_ids);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-layer-group me-2"></i>Set Category for <strong><?= $count ?></strong> Tickets</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($ticket_ids as $ticket_id) { ?><input type="hidden" name="ticket_ids[]" value="<?= $ticket_id ?>"><?php } ?>

    <div class="modal-body">



        <div class="form-group">
            <label>Category</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span>
                </div>
                <select class="form-control select2" name="bulk_category">
                    <option value="0">- Uncategorized -</option>
                    <?php
                    $sql_groups = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_parent = 0 AND category_archived_at IS NULL ORDER BY category_name ASC");
                    $groups = [];
                    while ($g = mysqli_fetch_assoc($sql_groups)) $groups[] = $g;
                    $sql_subs = mysqli_query($mysqli, "SELECT category_id, category_name, category_parent FROM categories WHERE category_type = 'Ticket' AND category_parent > 0 AND category_archived_at IS NULL ORDER BY category_name ASC");
                    $subs = [];
                    while ($s = mysqli_fetch_assoc($sql_subs)) $subs[intval($s['category_parent'])][] = $s;
                    foreach ($groups as $g) {
                        $gid = intval($g['category_id']);
                        $gname = nullable_htmlentities($g['category_name']);
                        if (isset($subs[$gid])) {
                            echo "<optgroup label=\"$gname\">";
                            foreach ($subs[$gid] as $s) echo "<option value=\"{$s['category_id']}\">" . nullable_htmlentities($s['category_name']) . "</option>";
                            echo "</optgroup>";
                        } else {
                            echo "<option value=\"$gid\">$gname</option>";
                        }
                    }
                    ?>
                </select>
            </div>
        </div>
    </div>

    <div class="modal-footer">
        <button type="submit" name="bulk_edit_ticket_category" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Set</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';

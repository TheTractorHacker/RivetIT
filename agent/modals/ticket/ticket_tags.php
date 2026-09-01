<?php

require_once '../../../includes/modal_header.php';

$ticket_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM tickets
    LEFT JOIN clients ON client_id = ticket_client_id
    WHERE ticket_id = $ticket_id
    LIMIT 1"
);

$row = mysqli_fetch_assoc($sql);
$ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
$ticket_number = intval($row['ticket_number']);
$client_id = intval($row['ticket_client_id']);
enforceClientAccess($client_id);
$client_name = nullable_htmlentities($row['client_name']);

$ticket_tag_id_array = array();
$sql_ticket_tags = mysqli_query($mysqli, "SELECT ticket_tag_tag_id FROM ticket_tags WHERE ticket_tag_ticket_id = $ticket_id");
while ($tag_row = mysqli_fetch_assoc($sql_ticket_tags)) {
    $ticket_tag_id_array[] = intval($tag_row['ticket_tag_tag_id']);
}

// Generate the HTML form content using output buffering.
ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-tags me-2"></i>Tags: <strong><?php echo "$ticket_prefix$ticket_number"; ?></strong> - <?php echo $client_name; ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Tags</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tags"></i></span>
                </div>
                <select class="form-control select2" name="tags[]" data-placeholder="Add some tags" multiple>
                    <?php

                    $sql_tags_select = mysqli_query($mysqli, "SELECT * FROM tags WHERE tag_type = 6 ORDER BY tag_name ASC");
                    while ($tag_select_row = mysqli_fetch_assoc($sql_tags_select)) {
                        $tag_id_select = intval($tag_select_row['tag_id']);
                        $tag_name_select = nullable_htmlentities($tag_select_row['tag_name']);
                        ?>
                        <option value="<?php echo $tag_id_select; ?>" <?php if (in_array($tag_id_select, $ticket_tag_id_array)) { echo "selected"; } ?>><?php echo $tag_name_select; ?></option>
                    <?php } ?>

                </select>
            </div>
        </div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="update_ticket_tags" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save Tags</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';

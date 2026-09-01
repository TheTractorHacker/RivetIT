<?php

require_once '../../../includes/modal_header.php';

$ticket_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM tickets
    LEFT JOIN clients ON client_id = ticket_client_id
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    WHERE ticket_id = $ticket_id
    LIMIT 1"
);

$row = mysqli_fetch_assoc($sql);
$ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
$ticket_number = intval($row['ticket_number']);
$ticket_status_id = intval($row['ticket_status_id']);
$client_id = intval($row['ticket_client_id']);
enforceClientAccess($client_id);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-tag me-2"></i>Editing status: <strong><?php echo "$ticket_prefix$ticket_number"; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Status</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <select class="form-control select2" name="ticket_status_id" required>
                    <?php
                    $sql_statuses = mysqli_query($mysqli, "SELECT * FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order");
                    while ($status_row = mysqli_fetch_assoc($sql_statuses)) {
                        $sid = intval($status_row['ticket_status_id']);
                        $sname = nullable_htmlentities($status_row['ticket_status_name']);
                        $selected = ($sid === $ticket_status_id) ? 'selected' : '';
                        echo "<option value=\"$sid\" $selected>$sname</option>";
                    }
                    ?>
                </select>
            </div>
        </div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="edit_ticket_status" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>

</form>

<?php

require_once '../../../includes/modal_footer.php';

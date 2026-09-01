<?php

// Default Column Sortby Filter
$sort = "canned_response_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS * FROM canned_responses
     WHERE canned_response_name LIKE '%$q%'
     AND canned_response_archived_at IS NULL
     ORDER BY $sort $order
     LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-comment-dots me-2"></i>Canned Responses</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/canned_response/canned_response_add.php" data-modal-size="lg"><i class="fas fa-plus me-2"></i>New Canned Response</button>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-4">
                    <div class="input-group mb-3 mb-md-0">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Canned Responses">
                        <div class="input-group-append">
                            <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
                <div class="col-md-8"></div>
            </div>
        </form>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                <tr>
                    <th>
                        <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=canned_response_name&order=<?= $disp ?>">
                            Name <?php if ($sort == 'canned_response_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Message</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php

                while ($row = mysqli_fetch_assoc($sql)) {
                    $canned_response_id = intval($row['canned_response_id']);
                    $canned_response_name = nullable_htmlentities($row['canned_response_name']);
                    $canned_response_message = $row['canned_response_message'];
                    $canned_response_message_preview = nullable_htmlentities(mb_strimwidth(strip_tags($canned_response_message), 0, 120, '...'));

                    ?>
                    <tr>
                        <td>
                            <a class="text-dark ajax-modal" href="#" data-modal-size="lg" data-modal-url="modals/canned_response/canned_response_edit.php?id=<?= $canned_response_id ?>">
                                <strong><?= $canned_response_name ?></strong>
                            </a>
                        </td>
                        <td><small class="text-secondary"><?= $canned_response_message_preview ?></small></td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-size="lg" data-modal-url="modals/canned_response/canned_response_edit.php?id=<?= $canned_response_id ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_canned_response=<?= $canned_response_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-trash me-2"></i>Delete
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>

                <?php } ?>

                </tbody>
            </table>
        </div>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";

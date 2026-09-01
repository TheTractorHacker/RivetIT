<?php

// Default Column Sortby Filter
$sort = "policy_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

// Show archived toggle
$show_archived = intval($_GET['archived'] ?? 0);
$archived_clause = $show_archived ? "policy_archived_at IS NOT NULL" : "policy_archived_at IS NULL";

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS p.*, c.calendar_name
    FROM sla_policies p
    LEFT JOIN sla_business_hours c ON p.policy_calendar_id = c.calendar_id
    WHERE p.policy_name LIKE '%$q%' AND $archived_clause
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Preload status names for pause-status display
$status_names = [];
$sres = mysqli_query($mysqli, "SELECT ticket_status_id, ticket_status_name FROM ticket_statuses");
while ($sr = mysqli_fetch_assoc($sres)) { $status_names[intval($sr['ticket_status_id'])] = $sr['ticket_status_name']; }

?>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-stopwatch me-2"></i>SLA Policies</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/sla/policy_add.php"><i class="fas fa-plus me-2"></i>New Policy</button>
        </div>
    </div>

    <div class="card-body">
        <div class="row">
            <div class="col-sm-4 mb-2">
                <form autocomplete="off">
                    <input type="hidden" name="archived" value="<?php echo $show_archived; ?>">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Policies">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-sm-8 text-end">
                <?php if ($show_archived) { ?>
                    <a href="?" class="btn btn-secondary btn-sm"><i class="fas fa-list me-1"></i>Show Active</a>
                <?php } else { ?>
                    <a href="?archived=1" class="btn btn-secondary btn-sm"><i class="fas fa-archive me-1"></i>Show Archived</a>
                <?php } ?>
            </div>
        </div>

        <hr>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-1"></i>Targets are in <strong>minutes of business time</strong>. Leave a target blank to fall back to the linked contract's SLA hours for that priority (legacy behaviour).
        </div>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=policy_name&order=<?php echo $disp; ?>">
                            Name <?php if ($sort == 'policy_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Calendar</th>
                    <th>Targets (min: resp / resol)</th>
                    <th>Pause Statuses</th>
                    <th>Default</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php
                while ($row = mysqli_fetch_assoc($sql)) {
                    $policy_id = intval($row['policy_id']);
                    $policy_name = nullable_htmlentities($row['policy_name']);
                    $calendar_name = $row['calendar_name'] ? nullable_htmlentities($row['calendar_name']) : "<span class='text-secondary'>24/7</span>";
                    $policy_is_default = intval($row['policy_is_default']);
                    $is_archived = !empty($row['policy_archived_at']);

                    $fmt = function ($v) { return ($v === null || $v === '') ? '&mdash;' : intval($v); };
                    $targets = "L " . $fmt($row['policy_low_response']) . "/" . $fmt($row['policy_low_resolution'])
                        . " &middot; M " . $fmt($row['policy_medium_response']) . "/" . $fmt($row['policy_medium_resolution'])
                        . " &middot; H " . $fmt($row['policy_high_response']) . "/" . $fmt($row['policy_high_resolution']);

                    $pause_labels = [];
                    if (!empty($row['policy_pause_status_ids'])) {
                        foreach (explode(',', $row['policy_pause_status_ids']) as $sid) {
                            $sid = intval($sid);
                            if (isset($status_names[$sid])) { $pause_labels[] = nullable_htmlentities($status_names[$sid]); }
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <a href="#" class="ajax-modal" data-modal-url="modals/sla/policy_edit.php?id=<?= $policy_id ?>"><?php echo $policy_name; ?></a>
                            <?php if ($is_archived) { echo " <span class='badge text-bg-secondary'>Archived</span>"; } ?>
                        </td>
                        <td><?php echo $calendar_name; ?></td>
                        <td><small><?php echo $targets; ?></small></td>
                        <td><small><?php echo empty($pause_labels) ? "<span class='text-secondary'>None</span>" : implode(', ', $pause_labels); ?></small></td>
                        <td><?php if ($policy_is_default) { echo "<span class='badge text-bg-success'>Default</span>"; } else { echo "<span class='text-secondary'>&mdash;</span>"; } ?></td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/sla/policy_edit.php?id=<?= $policy_id ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <?php if (!$is_archived) { ?>
                                        <?php if (!$policy_is_default) { ?>
                                            <a class="dropdown-item" href="post.php?set_default_sla_policy=<?php echo $policy_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                                <i class="fas fa-fw fa-star me-2"></i>Set as Default
                                            </a>
                                        <?php } ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-warning text-bold confirm-link" href="post.php?archive_sla_policy=<?php echo $policy_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                            <i class="fas fa-fw fa-archive me-2"></i>Archive
                                        </a>
                                    <?php } else { ?>
                                        <a class="dropdown-item text-success" href="post.php?unarchive_sla_policy=<?php echo $policy_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                            <i class="fas fa-fw fa-undo me-2"></i>Unarchive
                                        </a>
                                    <?php } ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
        </div>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";

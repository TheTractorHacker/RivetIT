<?php

// Default Column Sortby Filter
$sort = "calendar_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS * FROM sla_business_hours
    WHERE calendar_name LIKE '%$q%'
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

?>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-business-time me-2"></i>SLA Business Hours Calendars</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/sla/calendar_add.php"><i class="fas fa-plus me-2"></i>New Calendar</button>
        </div>
    </div>

    <div class="card-body">
        <div class="row">
            <div class="col-sm-4 mb-2">
                <form autocomplete="off">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Calendars">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="col-sm-8"></div>
        </div>

        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=calendar_name&order=<?php echo $disp; ?>">
                            Name <?php if ($sort == 'calendar_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Business Hours</th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=calendar_timezone&order=<?php echo $disp; ?>">
                            Timezone <?php if ($sort == 'calendar_timezone') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Default</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php
                while ($row = mysqli_fetch_assoc($sql)) {
                    $calendar_id = intval($row['calendar_id']);
                    $calendar_name = nullable_htmlentities($row['calendar_name']);
                    $calendar_timezone = nullable_htmlentities($row['calendar_timezone']);
                    $calendar_is_default = intval($row['calendar_is_default']);

                    // Summarise the open days
                    $open_days = [];
                    $pres = mysqli_query($mysqli, "SELECT DISTINCT day_of_week FROM sla_business_hours_periods WHERE calendar_id = $calendar_id ORDER BY day_of_week");
                    while ($pr = mysqli_fetch_assoc($pres)) {
                        $dow = intval($pr['day_of_week']);
                        if (isset($day_names[$dow])) { $open_days[] = $day_names[$dow]; }
                    }
                    $holiday_count = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM sla_holidays WHERE calendar_id = $calendar_id"))[0]);
                    ?>
                    <tr>
                        <td>
                            <a href="#" class="ajax-modal" data-modal-url="modals/sla/calendar_edit.php?id=<?= $calendar_id ?>"><?php echo $calendar_name; ?></a>
                        </td>
                        <td>
                            <?php if (empty($open_days)) { echo "<span class='text-secondary'>24/7 (no windows)</span>"; } else { echo implode(', ', $open_days); } ?>
                            <?php if ($holiday_count > 0) { echo " <span class='badge text-bg-secondary'>$holiday_count holiday(s)</span>"; } ?>
                        </td>
                        <td><?php echo $calendar_timezone; ?></td>
                        <td>
                            <?php if ($calendar_is_default) { echo "<span class='badge text-bg-success'>Default</span>"; } else { echo "<span class='text-secondary'>&mdash;</span>"; } ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/sla/calendar_edit.php?id=<?= $calendar_id ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <?php if (!$calendar_is_default) { ?>
                                        <a class="dropdown-item" href="post.php?set_default_sla_calendar=<?php echo $calendar_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                            <i class="fas fa-fw fa-star me-2"></i>Set as Default
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_sla_calendar=<?php echo $calendar_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete
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

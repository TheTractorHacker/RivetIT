<?php

// Default Column Sortby Filter
$sort = "holiday_date";
$order = "ASC";

require_once "includes/inc_all_admin.php";
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/holiday_functions.php';

$company_country = sanitizeInput(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_country FROM companies WHERE company_id = 1"))['company_country'] ?? '');

// Country/Year filters - separate from the free-text search box below.
$filter_country = sanitizeInput($_GET['country'] ?? '');
$filter_year = intval($_GET['year'] ?? 0);

$where = ["holiday_name LIKE '%$q%'"];
if ($filter_country !== '') {
    $filter_country_esc = mysqli_real_escape_string($mysqli, $filter_country);
    $where[] = "holiday_country = '$filter_country_esc'";
}
if ($filter_year > 0) {
    $where[] = "holiday_year = $filter_year";
}
$where_sql = implode(' AND ', $where);

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS * FROM holidays
    WHERE $where_sql
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Distinct countries/years already in the catalog, for the filter dropdowns.
$countries_in_catalog = [];
$cres = mysqli_query($mysqli, "SELECT DISTINCT holiday_country FROM holidays ORDER BY holiday_country ASC");
while ($cr = mysqli_fetch_assoc($cres)) { $countries_in_catalog[] = $cr['holiday_country']; }

$years_in_catalog = [];
$yres = mysqli_query($mysqli, "SELECT DISTINCT holiday_year FROM holidays ORDER BY holiday_year DESC");
while ($yr = mysqli_fetch_assoc($yres)) { $years_in_catalog[] = intval($yr['holiday_year']); }

?>

<!-- Filter querystring extras (country/year), so sort/page links don't drop them -->
<?php $filter_extra_qs = ($filter_country !== '' ? '&country=' . urlencode($filter_country) : '') . ($filter_year > 0 ? '&year=' . $filter_year : ''); ?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2">
            <i class="fas fa-fw fa-calendar-day me-2"></i>Holidays
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/holiday/holiday_load.php">
                    <i class="fas fa-cloud-download-alt me-2"></i>Load Holidays
                </button>
                <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                <div class="dropdown-menu">
                    <a class="dropdown-item text-dark ajax-modal" href="#" data-modal-url="modals/holiday/holiday_add.php">
                        <i class="fa fa-fw fa-plus me-2"></i>Custom Holiday
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <?php if ($filter_country !== '') { ?><input type="hidden" name="country" value="<?php echo nullable_htmlentities($filter_country); ?>"><?php } ?>
            <?php if ($filter_year > 0) { ?><input type="hidden" name="year" value="<?php echo $filter_year; ?>"><?php } ?>
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Holidays">
                        <div class="input-group-append">
                            <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <select class="form-control select2 auto-submit-select" name="country" data-placeholder="Country">
                        <option value="">All Countries</option>
                        <?php foreach ($countries_in_catalog as $c) { ?>
                            <option value="<?php echo nullable_htmlentities($c); ?>" <?php if ($filter_country === $c) { echo "selected"; } ?>><?php echo nullable_htmlentities($c); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-control select2 auto-submit-select" name="year" data-placeholder="Year">
                        <option value="0">All Years</option>
                        <?php foreach ($years_in_catalog as $y) { ?>
                            <option value="<?php echo $y; ?>" <?php if ($filter_year === $y) { echo "selected"; } ?>><?php echo $y; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="dropdown" id="bulkActionButton" hidden>
                        <button class="btn btn-secondary dropdown-toggle btn-block" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-fw fa-layer-group me-2"></i>Bulk (<span id="selectedCount">0</span>)
                        </button>
                        <div class="dropdown-menu">
                            <button class="dropdown-item text-danger text-bold confirm-link"
                                type="submit" form="bulkActions" name="bulk_delete_holidays">
                                <i class="fas fa-fw fa-trash me-2"></i>Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <hr>
        <form id="bulkActions" action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="table-responsive">
                <table class="table table-striped table-borderless table-hover">
                    <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?> text-nowrap">
                    <tr>
                        <td class="bg-light checkbox-column">
                            <div class="form-check">
                                <input class="form-check-input" id="selectAllCheckbox" type="checkbox">
                            </div>
                        </td>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort . $filter_extra_qs; ?>&sort=holiday_date&order=<?php echo $disp; ?>">
                                Date <?php if ($sort == 'holiday_date') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort . $filter_extra_qs; ?>&sort=holiday_name&order=<?php echo $disp; ?>">
                                Name <?php if ($sort == 'holiday_name') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort . $filter_extra_qs; ?>&sort=holiday_country&order=<?php echo $disp; ?>">
                                Country <?php if ($sort == 'holiday_country') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>Source</th>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php

                    while ($row = mysqli_fetch_assoc($sql)) {
                        $holiday_id = intval($row['holiday_id']);
                        $holiday_date = nullable_htmlentities($row['holiday_date']);
                        $holiday_name = nullable_htmlentities($row['holiday_name']);
                        $holiday_country = nullable_htmlentities($row['holiday_country']);
                        $holiday_is_custom = intval($row['holiday_is_custom']);

                        ?>
                        <tr>
                            <td class="bg-light checkbox-column">
                                <div class="form-check">
                                    <input class="form-check-input bulk-select" type="checkbox" name="holiday_ids[]" value="<?php echo $holiday_id ?>">
                                </div>
                            </td>
                            <td>
                                <a class="ajax-modal" href="#" data-modal-url="modals/holiday/holiday_edit.php?id=<?= $holiday_id ?>">
                                    <?php echo date('M j, Y', strtotime($holiday_date)); ?>
                                </a>
                            </td>
                            <td><?php echo $holiday_name; ?></td>
                            <td><?php echo $holiday_country; ?></td>
                            <td>
                                <?php if ($holiday_is_custom) { ?>
                                    <span class="badge text-bg-secondary">Custom</span>
                                <?php } else { ?>
                                    <span class="badge text-bg-info"><?php echo nullable_htmlentities(getHolidayTermForCountry($holiday_country)); ?></span>
                                <?php } ?>
                            </td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" data-boundary="window">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/holiday/holiday_edit.php?id=<?= $holiday_id ?>">
                                            <i class="fas fa-fw fa-edit me-2"></i>Edit
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_holiday=<?= $holiday_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete
                                        </a>
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
        </form>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<script src="../js/bulk_actions.js"></script>

<?php
require_once "../includes/footer.php";

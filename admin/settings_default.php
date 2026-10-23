<?php
require_once "includes/inc_all_admin.php";

$start_page_select_array = array (
    'dashboard.php'=>'Dashboard (each user arranges their own widgets)',
    'clients.php'=> 'Department Management',
    'tickets.php'=> 'Support Tickets'
);
$net_terms_array = array (
    '0'=>'On Receipt',
    '7'=>'7 Days',
    '10'=>'10 Days',
    '15'=>'15 Days',
    '30'=>'30 Days',
    '45'=>'45 Days',
    '60'=>'60 Days',
    '90'=>'90 Days'
);

?>

<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-cogs me-2"></i>Defaults</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label>Start Page</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-home"></i></span>
                    </div>
                    <select class="form-control select2" name="start_page" data-tags="true" required>
                        <?php if (!in_array($config_start_page, array_keys($start_page_select_array))) { ?>
                            <option selected> <?php echo nullable_htmlentities($config_start_page); ?></option>
                        <?php } ?>
                        <?php foreach ($start_page_select_array as $start_page_value => $start_page_name) { ?>
                            <option <?php if ($start_page_value == $config_start_page) { echo "selected"; } ?>
                                value="<?php echo nullable_htmlentities($start_page_value); ?>">
                                <?php echo nullable_htmlentities($start_page_name); ?>
                            </option>
                        <?php }?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Calendar</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                    </div>
                    <select class="form-control select2" name="calendar">
                        <option value="0">- None -</option>
                        <?php

                        $sql = mysqli_query($mysqli, "SELECT * FROM calendars ORDER BY calendar_name ASC");
                        while ($row = mysqli_fetch_assoc($sql)) {
                            $calendar_id = intval($row['calendar_id']);
                            $calendar_name = nullable_htmlentities($row['calendar_name']); ?>
                            <option <?php if ($config_default_calendar == $calendar_id) {
                                        echo "selected";
                                    } ?> value="<?php echo $calendar_id; ?>"><?php echo $calendar_name; ?></option>
                        <?php } ?>

                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Net Terms</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                    </div>
                    <select class="form-control select2" name="net_terms">
                        <?php foreach ($net_terms_array as $net_term_value => $net_term_name) { ?>
                            <option <?php if ($config_default_net_terms == $net_term_value) {
                                        echo "selected";
                                    } ?> value="<?php echo $net_term_value; ?>"><?php echo $net_term_name; ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Department Hourly Rate</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                    </div>
                    <input type="text" class="form-control" inputmode="decimal" pattern="[0-9]*\.?[0-9]{0,2}" name="hourly_rate" value="<?php echo number_format($config_default_hourly_rate, 2, '.', ''); ?>" placeholder="0.00" required>
                </div>
            </div>

            <hr>

            <button type="submit" name="edit_default_settings" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>

        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";

<?php
require_once "includes/inc_all_admin.php";
 ?>

    <div class="card">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-life-ring me-2"></i>Ticket Settings</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                <div class="p-3 mb-3 border border-primary-subtle rounded-3 bg-primary-subtle bg-opacity-10">
                    <label class="fw-bold mb-1"><i class="fab fa-fw fa-google me-1"></i>Google review link <small class="fw-normal text-secondary">(optional &mdash; shown to clients as a "Leave us a Google review" prompt after a 4-5 star rating)</small></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fab fa-fw fa-google"></i></span>
                        </div>
                        <input type="url" class="form-control" name="config_ticket_csat_google_review_url" placeholder="https://g.page/r/.../review" value="<?php echo nullable_htmlentities($config_ticket_csat_google_review_url); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Ticket Prefix</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-life-ring"></i></span>
                        </div>
                        <input type="text" class="form-control" name="config_ticket_prefix" placeholder="Ticket Prefix" value="<?php echo nullable_htmlentities($config_ticket_prefix); ?>" pattern="^[A-Za-z-]+$" title="Only letters and hyphens are allowed" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Next Number</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-barcode"></i></span>
                        </div>
                        <input type="number" min="<?php echo intval($config_ticket_next_number); ?>" class="form-control" name="config_ticket_next_number" placeholder="Next Ticket Number" value="<?php echo intval($config_ticket_next_number); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" name="config_ticket_email_parse" <?php if($config_ticket_email_parse == 1){ echo "checked"; } ?> value="1" id="emailToTicketParseSwitch">
                        <label class="form-check-label" for="emailToTicketParseSwitch">Email-to-ticket parsing <small class="text-secondary">(cron_ticket_email_parser.php must also be added to cron and run every few mins)</small></label>
                    </div>
                </div>

                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-1"></i>
                    "Create tickets for emails from unknown senders/domains" is now configured per-mailbox &mdash; see <a href="mailbox.php" class="alert-link">Admin &gt; Mailboxes</a> &gt; [mailbox] &gt; Parse unknown senders.
                    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>

                <?php if ($config_module_enable_accounting) { ?>
                <div class="form-group">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" name="config_ticket_default_billable" <?php if ($config_ticket_default_billable == 1) { echo "checked"; } ?> value="1" id="ticketBillableSwitch">
                        <label class="form-check-label" for="ticketBillableSwitch">Default to Billable <small class="text-secondary">(This will check the billable box on all new tickets)</small></label>
                    </div>
                </div>
                <?php } ?>

                <div class="form-group">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" name="config_ticket_timer_autostart" <?php if ($config_ticket_timer_autostart == 1) { echo "checked"; } ?> value="1" id="ticketTimerSwitch">
                        <label class="form-check-label" for="ticketTimerSwitch">Autostart Ticket Timer <small class="text-secondary">(This option will control if the timer starts automatically or manually)</small></label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Number of hours to auto close resolved tickets</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                        </div>
                        <input type="number" min="24" class="form-control" name="config_ticket_autoclose_hours" placeholder="Delay in hours before a resolved ticket is fully closed" value="<?php echo intval($config_ticket_autoclose_hours); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Default Ticket Assignee <small class="text-secondary">(every new ticket that isn't otherwise assigned - by a form field, a recurring ticket's own setting, an automation rule, etc. - is automatically assigned to this technician instead of being left unassigned)</small></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-user-check"></i></span>
                        </div>
                        <select class="form-control select2" name="config_ticket_default_technician_id">
                            <option value="0">- Not Assigned -</option>
                            <?php
                            $sql_default_technician = mysqli_query($mysqli, "SELECT user_id, user_name FROM users
                                WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
                                ORDER BY user_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_default_technician)) {
                                $default_technician_id = intval($row['user_id']);
                                $default_technician_name = nullable_htmlentities($row['user_name']); ?>
                                <option <?php if ($config_ticket_default_technician_id == $default_technician_id) { echo "selected"; } ?>
                                    value="<?php echo $default_technician_id; ?>"><?php echo $default_technician_name; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <hr>
                <h6 class="text-muted text-uppercase mb-2" style="font-size:.72rem; letter-spacing:.06em;">Customer Satisfaction (CSAT)</h6>

                <div class="form-group">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" name="config_ticket_csat_enable" <?php if ($config_ticket_csat_enable == 1) { echo "checked"; } ?> value="1" id="csatEnableSwitch">
                        <label class="form-check-label" for="csatEnableSwitch">Enable CSAT ratings <small class="text-secondary">(clients/guests can rate closed tickets <?= csatFaceEmoji(1) ?>&ndash;<?= csatFaceEmoji(5) ?>)</small></label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Reminder email delay <small class="text-secondary">(days after close before an unrated ticket gets one reminder)</small></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-bell"></i></span>
                        </div>
                        <input type="number" min="1" class="form-control" name="config_ticket_csat_reminder_days" value="<?php echo intval($config_ticket_csat_reminder_days); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Low-rating follow-up threshold <small class="text-secondary">(a rating at or below this auto-reopens the ticket)</small></label>
                    <select class="form-control" name="config_ticket_csat_low_rating_threshold">
                        <?php for ($t = 1; $t <= 4; $t++) { ?>
                            <option value="<?= $t ?>" <?php if ($config_ticket_csat_low_rating_threshold == $t) { echo "selected"; } ?>><?= csatFaceEmoji($t) ?> <?= csatFaceLabel($t) ?> or below</option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Email address to notify when new tickets are raised <small class="text-secondary">(Ideally a distribution list/shared mailbox)</small></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-bell"></i></span>
                        </div>
                        <input type="email" class="form-control" name="config_ticket_new_ticket_notification_email" placeholder="Address to notify for new tickets, leave blank for none" value="<?php echo nullable_htmlentities($config_ticket_new_ticket_notification_email); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Tickets Default View</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-eye"></i></span>
                        </div>
                        <select class="form-control" name="config_ticket_default_view">
                            <option value=0 <?php if ($config_ticket_default_view == 0) { echo "selected"; } ?>>List</option>
                            <option value=1 <?php if ($config_ticket_default_view == 1) { echo "selected"; } ?>>Compact</option>
                            <option value=2 <?php if ($config_ticket_default_view == 2) { echo "selected"; } ?>>Kanban</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                <label>Kanban Settings</label>
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" name="config_ticket_ordering" <?php if ($config_ticket_ordering == 1) { echo "checked"; } ?> value="1" id="ticketOrderingSwitch">
                        <label class="form-check-label" for="ticketOrderingSwitch">Allow ticket ordering within its column<small class="text-secondary"> (unchecked = order by priority and id)</small></label>
                    </div>
                    <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_ticket_moving_columns" <?php if ($config_ticket_moving_columns == 1) { echo "checked"; } ?> value="1" id="ticketMovingColumnsSwitch">
                        <label class="form-check-label" for="ticketMovingColumnsSwitch">Allow moving columns</label>
                    </div>
                </div>

                <hr>

                <button type="submit" name="edit_ticket_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

            </form>
        </div>
    </div>

<?php
require_once "../includes/footer.php";


<?php

require_once "includes/inc_all_guest.php";

//Initialize the HTML Purifier to prevent XSS
require "../plugins/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null); // Disable cache by setting a non-existent directory or an invalid one
$purifier_config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);

if (!isset($_GET['ticket_id'], $_GET['url_key'])) {
    echo "<br><h2>Oops, something went wrong! Please raise a ticket if you believe this is an error.</h2>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    exit();
}

// Company info
$company_sql_row = mysqli_fetch_assoc(mysqli_query($mysqli, "
    SELECT
        company_phone,
        company_phone_country_code,
        company_website
    FROM
        companies,
        settings
    WHERE
        companies.company_id = settings.company_id
        AND companies.company_id = 1"
));

$company_phone_country_code = nullable_htmlentities($company_sql_row['company_phone_country_code']);
$company_phone = nullable_htmlentities(formatPhoneNumber($company_sql_row['company_phone'], $company_phone_country_code));
$company_website = nullable_htmlentities($company_sql_row['company_website']);

$url_key = sanitizeInput($_GET['url_key']);
$ticket_id = intval($_GET['ticket_id']);

$ticket_sql = mysqli_query($mysqli,
    "SELECT * FROM tickets
            LEFT JOIN users on ticket_assigned_to = user_id
            LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
            WHERE ticket_id = $ticket_id AND ticket_url_key = '$url_key'"
);

if (mysqli_num_rows($ticket_sql) !== 1) {
    // Invalid invoice/key
    echo "<br><h2>Oops, something went wrong! Please raise a ticket if you believe this is an error.</h2>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

    exit();
}

$ticket_row = mysqli_fetch_assoc($ticket_sql);

if ($ticket_row) {

    $ticket_prefix = nullable_htmlentities($ticket_row['ticket_prefix']);
    $ticket_number = intval($ticket_row['ticket_number']);
    $ticket_status = nullable_htmlentities($ticket_row['ticket_status_name']);
    $ticket_status_color = nullable_htmlentities($ticket_row['ticket_status_color']);
    $ticket_priority = nullable_htmlentities($ticket_row['ticket_priority']);
    $ticket_priority_color = ['Low' => '#17a2b8', 'Medium' => '#ffc107', 'High' => '#dc3545', 'Critical' => '#8b0000'][$ticket_priority] ?? '#6c757d';
    $ticket_subject = nullable_htmlentities($ticket_row['ticket_subject']);
    $ticket_details = $purifier->purify($ticket_row['ticket_details']);
    $ticket_assigned_to = nullable_htmlentities($ticket_row['user_name']);
    $ticket_resolved_at = nullable_htmlentities($ticket_row['ticket_resolved_at']);
    $ticket_closed_at = nullable_htmlentities($ticket_row['ticket_closed_at']);
    $ticket_feedback = nullable_htmlentities($ticket_row['ticket_feedback']);
    $ticket_csat_rating = intval($ticket_row['ticket_csat_rating'] ?? 0);
    $ticket_csat_comment = nullable_htmlentities($ticket_row['ticket_csat_comment']);

    ?>

    <div class="card mt-3">
        <div class="card-header bg-dark text-center">
            <div class="text-uppercase small" style="letter-spacing:.06em; opacity:.7;">Ticket <?php echo $ticket_prefix, $ticket_number ?></div>
            <h4 class="mt-1 mb-0"><?php echo $ticket_subject ?></h4>
        </div>

        <div class="card-body prettyContent">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <span class="badge rounded-pill tkt-pill-badge <?= tagTextClass($ticket_status_color) ?>" style="background-color:<?= $ticket_status_color ?>;"><?php echo $ticket_status ?></span>
                <?php if (!empty($ticket_priority)) { ?>
                    <span class="badge rounded-pill tkt-pill-badge <?= tagTextClass($ticket_priority_color) ?>" style="background-color:<?= $ticket_priority_color ?>;"><?php echo $ticket_priority ?> priority</span>
                <?php } ?>
                <?php if (!empty($ticket_assigned_to) && empty($ticket_closed_at)) { ?>
                    <span class="text-muted small"><i class="fas fa-fw fa-user-headset me-1"></i>Assigned to <?php echo $ticket_assigned_to ?></span>
                <?php } ?>
            </div>
            <?php echo $ticket_details ?>
        </div>
    </div>

    <hr>

    <!-- Either show the reply comments box, option to re-open ticket, show ticket smiley feedback or thanks for feedback -->

    <?php if ($ticket_csat_rating > 0) { ?>

        <div class="card csat-card mt-3">
            <div class="card-body text-center">
                <h4 class="mb-1">
                    <span class="csat-face-display" title="<?= csatFaceLabel($ticket_csat_rating) ?>"><?= csatFaceEmoji($ticket_csat_rating) ?></span>
                    Thanks for your feedback!
                </h4>
                <?php if ($ticket_csat_comment) { ?>
                    <p class="text-muted mb-0 mt-2">"<?php echo $ticket_csat_comment; ?>"</p>
                <?php } else { ?>
                    <form method="post" action="guest_post.php" class="mx-auto mt-3" style="max-width:420px;">
                        <input type="hidden" name="add_ticket_csat_comment" value="1">
                        <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
                        <input type="hidden" name="url_key" value="<?php echo $url_key ?>">
                        <div class="mb-2">
                            <textarea name="csat_comment" class="form-control" rows="2" maxlength="1000" placeholder="Want to tell us more? (optional)"></textarea>
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm"><i class="fas fa-fw fa-comment-dots me-1"></i>Add comment</button>
                    </form>
                <?php } ?>
                <?php if ($ticket_csat_rating >= 4 && !empty($config_ticket_csat_google_review_url)) { ?>
                    <div class="google-review-cta mt-3 text-center">
                        <p class="mb-2 fw-semibold">Glad to hear it! Mind sharing that with others?</p>
                        <a href="<?= nullable_htmlentities($config_ticket_csat_google_review_url) ?>" target="_blank" rel="noopener" class="btn btn-google btn-lg px-4">
                            <i class="fab fa-fw fa-google me-2"></i>Leave us a Google review
                        </a>
                    </div>
                <?php } ?>
            </div>
        </div>

        <?php // A low rating auto-reopens the ticket (clears resolved_at/closed_at) so
              // the agent sees it back in their queue - but the customer who JUST rated
              // it shouldn't then be told "please log in or email to respond" by the
              // branches below, which is why this rated-check runs first regardless of
              // resolved/closed state. ?>

    <?php } elseif (empty($ticket_resolved_at)) { ?>
        <!-- Reply - guest users should email or login so we know exactly who replied -->
        <h6><i>Please <a href="../client">log in</a> or reply to the ticket via email to respond</i></h6>

    <?php } elseif (empty($ticket_closed_at)) { ?>
        <!-- Re-open -->

        <h4>Your ticket has been resolved</h4>

        <div class="col-4">
            <div class="row">
                <div class="col">
                    <form method="post" action="guest_post.php" class="d-inline">
                        <input type="hidden" name="reopen_ticket" value="1">
                        <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
                        <input type="hidden" name="url_key" value="<?php echo $url_key ?>">
                        <button type="submit" class="btn btn-secondary btn-lg"><i class="fas fa-fw fa-redo text-white"></i> Reopen ticket</button>
                    </form>
                </div>

                <div class="col">
                    <form method="post" action="guest_post.php" class="d-inline">
                        <input type="hidden" name="close_ticket" value="1">
                        <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
                        <input type="hidden" name="url_key" value="<?php echo $url_key ?>">
                        <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-fw fa-gavel text-white"></i> Close ticket</button>
                    </form>
                </div>
            </div>
        </div>
        <br>

    <?php } elseif (empty($config_ticket_csat_enable)) { ?>

        <!-- CSAT disabled and this ticket hasn't been rated - nothing to show. -->

    <?php } else { ?>

        <div class="card csat-card mt-3">
            <div class="card-body text-center">
                <h4 class="mb-1">How did we do?</h4>
                <p class="csat-card-lede mb-3">Your ticket is closed &mdash; a quick rating helps us keep improving.</p>
                <form method="post" action="guest_post.php" class="csat-form">
                    <input type="hidden" name="add_ticket_feedback" value="1">
                    <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
                    <input type="hidden" name="url_key" value="<?php echo $url_key ?>">

                    <fieldset class="csat-faces justify-content-center">
                        <legend class="visually-hidden">Rate this ticket</legend>
                        <?php for ($s = 1; $s <= 5; $s++) { ?>
                            <input type="radio" name="csat_rating" id="csat_face_<?= $s ?>" value="<?= $s ?>" class="csat-face-input js-csat-rating-input" data-label="<?= csatFaceLabel($s) ?>">
                            <label for="csat_face_<?= $s ?>" class="csat-face-label" title="<?= csatFaceLabel($s) ?>"><?= csatFaceEmoji($s) ?></label>
                        <?php } ?>
                    </fieldset>
                    <div class="js-csat-live text-muted small mt-1" aria-live="polite"></div>

                    <div class="mt-3 mx-auto" style="max-width:420px;">
                        <textarea name="csat_comment" class="form-control" rows="2" maxlength="1000" placeholder="Anything you'd like to add? (optional)"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3 px-4"><i class="fas fa-fw fa-paper-plane me-2"></i>Submit rating</button>
                </form>
            </div>
        </div>

    <?php } ?>

    <!-- End comments/reopen/feedback -->

    <hr>
    <br>

    <?php
    $sql = mysqli_query($mysqli, "SELECT * FROM ticket_replies LEFT JOIN users ON ticket_reply_by = user_id LEFT JOIN contacts ON ticket_reply_by = contact_id WHERE ticket_reply_ticket_id = $ticket_id AND ticket_reply_archived_at IS NULL AND ticket_reply_type != 'Internal' ORDER BY ticket_reply_id DESC");

    while ($row = mysqli_fetch_assoc($sql)) {
        $ticket_reply_id = intval($row['ticket_reply_id']);
        $ticket_reply = $purifier->purify($row['ticket_reply']);
        $ticket_reply_created_at = nullable_htmlentities($row['ticket_reply_created_at']);
        $ticket_reply_updated_at = nullable_htmlentities($row['ticket_reply_updated_at']);
        $ticket_reply_by = intval($row['ticket_reply_by']);
        $ticket_reply_type = $row['ticket_reply_type'];

        if ($ticket_reply_type == "Client") {
            $ticket_reply_by_display = nullable_htmlentities($row['contact_name']);
            $user_initials = initials($row['contact_name']);
            $user_avatar = $row['contact_photo'];
            $avatar_link = "../uploads/clients/$ticket_reply_by/$user_avatar";
        } else {
            $ticket_reply_by_display = nullable_htmlentities($row['user_name']);
            $user_id = intval($row['user_id']);
            $user_avatar = $row['user_avatar'];
            $user_initials = initials($row['user_name']);
            $avatar_link = "../uploads/users/$user_id/$user_avatar";
        }
        ?>

        <div class="card card-outline <?php if ($ticket_reply_type == 'Client') { echo "card-warning"; } else { echo "card-info"; } ?> mb-3">
            <div class="card-header">
                <h3 class="card-title">
                    <div class="media">
                        <?php
                        if (!empty($user_avatar)) {
                            ?>
                            <img src="<?php echo $avatar_link ?>" alt="User Avatar" class="img-size-50 me-3 img-circle">
                            <?php
                        } else {
                            ?>
                            <span class="fa-stack fa-2x">
                                    <i class="fa fa-circle fa-stack-2x text-secondary"></i>
                                    <span class="fa fa-stack-1x text-white"><?php echo $user_initials; ?></span>
                                </span>
                            <?php
                        }
                        ?>

                        <div class="media-body">
                            <?php echo $ticket_reply_by_display; ?>
                            <br>
                            <small class="text-muted"><?php echo $ticket_reply_created_at; ?> <?php if (!empty($ticket_reply_updated_at)) { echo "(edited: $ticket_reply_updated_at)"; } ?></small>
                        </div>
                    </div>
                </h3>
            </div>

            <div class="card-body prettyContent">
                <?php echo $ticket_reply; ?>
            </div>
        </div>

        <?php

    }

    ?>

    <script src="/js/pretty_content.js"></script>
    <script src="/js/csat_rating.js"></script>

    <?php } else {
        echo "Ticket ID not found!";
    } ?>

<div class="card-footer text-center">
    <?php echo "<i class='fas fa-phone fa-fw me-2'></i>$company_phone | <i class='fas fa-globe fa-fw me-2 ms-2'></i>$company_website"; ?>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

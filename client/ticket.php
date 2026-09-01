<?php
/*
 * Client Portal
 * Ticket detail page
 */

require_once "includes/inc_all.php";

//Initialize the HTML Purifier to prevent XSS
require "../plugins/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null); // Disable cache by setting a non-existent directory or an invalid one
$purifier_config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);

$allowed_extensions = array('jpg', 'jpeg', 'gif', 'png', 'webp', 'pdf', 'txt', 'md', 'doc', 'docx', 'csv', 'xls', 'xlsx', 'xlsm', 'zip', 'tar', 'gz');

if (isset($_GET['id']) && intval($_GET['id'])) {
    $ticket_id = intval($_GET['id']);

    $ticket_contact_snippet = "AND ticket_contact_id = $session_contact_id";
    // Bypass ticket contact being session_id for a primary / technical contact viewing all tickets
    if ($session_contact_primary == 1 || $session_contact_is_technical_contact) {
        $ticket_contact_snippet = '';
    }

    $ticket_sql = mysqli_query($mysqli,
        "SELECT * FROM tickets
            LEFT JOIN users on ticket_assigned_to = user_id
            LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
            LEFT JOIN categories ON ticket_category = category_id
            WHERE ticket_id = $ticket_id AND ticket_client_id = $session_client_id
             $ticket_contact_snippet"
    );

    $ticket_row = mysqli_fetch_assoc($ticket_sql);

    if ($ticket_row) {

        $ticket_prefix = nullable_htmlentities($ticket_row['ticket_prefix']);
        $ticket_number = intval($ticket_row['ticket_number']);
        $ticket_status = nullable_htmlentities($ticket_row['ticket_status_name']);
        $ticket_status_color = nullable_htmlentities($ticket_row['ticket_status_color']);
        $ticket_priority = nullable_htmlentities($ticket_row['ticket_priority']);
        $ticket_subject = nullable_htmlentities($ticket_row['ticket_subject']);
        $ticket_details = $purifier->purify($ticket_row['ticket_details']);
        $ticket_assigned_to = nullable_htmlentities($ticket_row['user_name']);
        $ticket_resolved_at = nullable_htmlentities($ticket_row['ticket_resolved_at']);
        $ticket_closed_at = nullable_htmlentities($ticket_row['ticket_closed_at']);
        $ticket_feedback = nullable_htmlentities($ticket_row['ticket_feedback']);
        $ticket_csat_rating = intval($ticket_row['ticket_csat_rating'] ?? 0);
        $ticket_csat_comment = nullable_htmlentities($ticket_row['ticket_csat_comment']);
        $ticket_category = nullable_htmlentities($ticket_row['category_name']);

        // Get Ticket Attachments (not associated with a specific reply)
        $sql_ticket_attachments = mysqli_query(
            $mysqli,
            "SELECT * FROM ticket_attachments
            WHERE ticket_attachment_reply_id IS NULL
            AND ticket_attachment_ticket_id = $ticket_id"
        );

        // Get Tasks
        $sql_tasks = mysqli_query( $mysqli, "SELECT * FROM tasks WHERE task_ticket_id = $ticket_id ORDER BY task_order ASC, task_id ASC");
        $task_count = mysqli_num_rows($sql_tasks);

        // Get Completed Task Count
        $sql_tasks_completed = mysqli_query($mysqli,
            "SELECT * FROM tasks
            WHERE task_ticket_id = $ticket_id
            AND task_completed_at IS NOT NULL"
        );
        $completed_task_count = mysqli_num_rows($sql_tasks_completed);

        // Get pending task approvals
        $sql_task_approvals = mysqli_query($mysqli,"
            SELECT task_id, task_name, approval_id, approval_scope, approval_type, approval_required_user_id, approval_status, approval_url_key
            FROM tasks
            LEFT JOIN task_approvals ON task_id = task_approvals.approval_task_id
            WHERE task_ticket_id = $ticket_id AND task_completed_at IS NULL AND approval_scope = 'client' AND approval_status = 'pending'
        ");
        ?>

        <ol class="breadcrumb d-print-none">
            <li class="breadcrumb-item">
                <a href="index.php">Home</a>
            </li>
            <li class="breadcrumb-item">
                <a href="tickets.php">Tickets</a>
            </li>
            <li class="breadcrumb-item active">Ticket <?php echo $ticket_prefix . $ticket_number; ?></li>
        </ol>

        <div class="card mb-3" data-ticket-id="<?= $ticket_id ?>" data-live-chat="<?= $config_module_enable_live_chat ? '1' : '0' ?>" data-csrf="<?= $_SESSION['csrf_token'] ?>" data-user-name="<?= nullable_htmlentities($session_contact_name) ?>" data-user-id="<?= intval($session_contact_id) ?>" data-user-type="contact">
            <div class="card-header bg-dark my-2">
                <h4 class="card-title mt-1">
                    Ticket <?php echo $ticket_prefix, $ticket_number ?>
                </h4>
                <div class="card-tools">
                    <?php
                    if (empty($ticket_resolved_at) && $task_count == $completed_task_count) { ?>
                        <a href="post.php?resolve_ticket=<?php echo $ticket_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-success float-end text-white confirm-link"><i class="fas fa-fw fa-check text-success"></i> Resolve ticket</a>
                    <?php } ?>
                </div>
            </div>

            <div class="card-body prettyContent">
                <h5><strong>Subject:</strong> <?php echo $ticket_subject ?></h5>
                <p>
                    <strong>State:</strong>
                    <span id="quickStatusColorDot" class="d-inline-block me-1" style="width:.6rem;height:.6rem;border-radius:50%;background-color: <?= $ticket_status_color ?>;"></span>
                    <span id="ticket-status-text"><?php echo $ticket_status ?></span><br>
                    <strong>Priority:</strong> <?php echo $ticket_priority ?><br>
                    <?php if (!empty($ticket_category)) { ?>
                        <strong>Category:</strong> <?php echo $ticket_category ?><br>
                    <?php } ?>

                    <?php if (empty($ticket_closed_at)) { ?>

                        <?php if ($task_count) { ?>
                            <strong>Tasks: </strong> <?php echo $completed_task_count . " / " .$task_count ?>
                            <br>
                        <?php } ?>

                        <?php if (!empty($ticket_assigned_to)) { ?>
                            <strong>Assigned to: </strong> <?php echo $ticket_assigned_to ?>
                        <?php } ?>

                    <?php } ?>
                </p>
                <hr>
                <?php echo $ticket_details ?>

                <?php
                while ($ticket_attachment = mysqli_fetch_assoc($sql_ticket_attachments)) {
                    $name = nullable_htmlentities($ticket_attachment['ticket_attachment_name']);
                    $ref_name = nullable_htmlentities($ticket_attachment['ticket_attachment_reference_name']);
                    echo "<hr><i class='fas fa-fw fa-paperclip text-secondary me-1'></i>$name | <a href='../uploads/tickets/$ticket_id/$ref_name' download='$name'><i class='fas fa-fw fa-download me-1'></i>Download</a> | <a target='_blank' href='../uploads/tickets/$ticket_id/$ref_name'><i class='fas fa-fw fa-external-link-alt me-1'></i>View</a>";
                }
                ?>
            </div>
        </div>

        <!-- Approvals -->
        <?php if (mysqli_num_rows($sql_task_approvals) > 0) { ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h5>Approvals</h5>
                    This ticket has tasks requiring approval:

                    <ul>
                        <?php

                        while ($approvals = mysqli_fetch_assoc($sql_task_approvals)) {
                            $task_id = intval($approvals['task_id']);
                            $approval_id = intval($approvals['approval_id']);
                            $task_name = nullable_htmlentities($approvals['task_name']);
                            $approval_type = nullable_htmlentities($approvals['approval_type']);
                            $approval_url_key = nullable_htmlentities($approvals['approval_url_key']);

                            $contact_can_approve = false; // Default

                            if ($approval_type == 'any') {
                                $contact_can_approve = true;
                            }

                            if ($session_contact_primary) {
                                $contact_can_approve = true;
                            }

                            if ($approval_type == 'technical' && $session_contact_is_technical_contact) {
                                $contact_can_approve = true;
                            }

                            if ($approval_type == 'billing' && $session_contact_is_billing_contact) {
                                $contact_can_approve = true;
                            }

                            ?>

                            <li>
                                <?php echo $task_name;
                                if ($contact_can_approve) { ?> - <a href="post.php?approve_ticket_task=<?= $task_id ?>&approval_id=<?= $approval_id ?>&approval_url_key=<?= $approval_url_key ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="confirm-link">Approve task</a> <?php }
                                else {?> - Please ask your <?= $approval_type ?> contact to approve this task <?php } ?>
                            </li>

                        <?php } ?>

                    </ul>



                </div>
            </div>
        <?php } ?>

        <?php if ($config_module_enable_live_chat) {
            $ticket_chat_history = [];
            $sql_ticket_chat_history = mysqli_query($mysqli, "SELECT tcm.id, tcm.sender_type, tcm.sender_id, tcm.message, tcm.created_at,
                COALESCE(u.user_name, c.contact_name) AS sender_name
                FROM ticket_chat_messages tcm
                LEFT JOIN users u ON tcm.sender_type = 'agent' AND u.user_id = tcm.sender_id
                LEFT JOIN contacts c ON tcm.sender_type = 'contact' AND c.contact_id = tcm.sender_id
                WHERE tcm.ticket_id = $ticket_id
                ORDER BY tcm.id ASC
                LIMIT 100");
            while ($row = mysqli_fetch_assoc($sql_ticket_chat_history)) {
                $ticket_chat_history[] = [
                    'chat_id' => intval($row['id']),
                    'sender_type' => $row['sender_type'],
                    'sender_id' => intval($row['sender_id']),
                    'sender_name' => $row['sender_name'],
                    'message' => $row['message'],
                    'created_at' => $row['created_at'],
                ];
            }
        ?>
        <!-- Live Chat card -->
        <div class="card mb-3">
            <div class="card-header py-2">
                <h5 class="card-title mt-1"><i class="fas fa-fw fa-comments me-2"></i>Live Chat</h5>
            </div>
            <div class="card-body p-3">
                <div id="ticket-chat-messages" class="mb-2" style="max-height:260px;overflow-y:auto;"></div>
                <form id="ticket-chat-form" class="d-flex" autocomplete="off">
                    <input type="text" id="ticket-chat-input" class="form-control form-control-sm me-2" placeholder="Type a message...">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
        <script>window.__ticketChatHistory = <?= json_encode($ticket_chat_history, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
        <?php } ?>

        <hr>

        <!-- Either show the reply comments box, option to re-open ticket, show ticket smiley feedback or thanks for feedback -->

        <?php if ($ticket_csat_rating > 0) { ?>

            <div class="card csat-card mb-3">
                <div class="card-body text-center">
                    <h4 class="mb-1">
                        <span class="csat-face-display" title="<?= csatFaceLabel($ticket_csat_rating) ?>"><?= csatFaceEmoji($ticket_csat_rating) ?></span>
                        Thanks for your feedback!
                    </h4>
                    <?php if ($ticket_csat_comment) { ?>
                        <p class="text-muted mb-0 mt-2">"<?php echo $ticket_csat_comment; ?>"</p>
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
              // it shouldn't then be dropped back into the plain reply form below, which
              // is why this rated-check runs first regardless of resolved/closed state. ?>

        <?php } elseif (empty($ticket_resolved_at)) { ?>
            <!-- Reply -->

            <form action="post.php" enctype="multipart/form-data" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="ticket_id" value="<?php echo $ticket_id ?>">
                <div class="form-group">
                    <textarea class="form-control tinymce" name="comment" placeholder="Add comments.."></textarea>
                </div>
                <div class="form-group">
                    <input type="file" class="form-control-file" name="file[]" multiple id="fileInput" accept=".jpg, .jpeg, .gif, .png, .webp, .pdf, .txt, .md, .doc, .docx, .odt, .csv, .xls, .xlsx, .ods, .pptx, .odp, .zip, .tar, .gz, .xml, .msg, .json, .wav, .mp3, .ogg, .mov, .mp4, .av1, .ovpn">
                </div>
                <button type="submit" class="btn btn-primary" name="add_ticket_comment">Reply</button>
            </form>

        <?php } elseif (empty($ticket_closed_at)) { ?>
            <!-- Re-open -->

            <h4>Your ticket has been resolved</h4>

            <div class="col-6">
                <div class="row">
                    <div class="col">
                        <a href="post.php?reopen_ticket=<?php echo $ticket_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-secondary btn-lg"><i class="fas fa-fw fa-redo text-white"></i> Reopen ticket</a>
                    </div>

                    <div class="col">
                        <a href="post.php?close_ticket=<?php echo $ticket_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-success btn-lg confirm-link"><i class="fas fa-fw fa-gavel text-white"></i> Close ticket</a>
                    </div>
                </div>
            </div>
            <br>

        <?php } elseif (empty($config_ticket_csat_enable)) { ?>

            <!-- CSAT disabled and this ticket hasn't been rated - nothing to show. -->

        <?php } else { ?>

            <div class="card csat-card mb-3">
                <div class="card-body text-center">
                    <h4 class="mb-1">How did we do?</h4>
                    <p class="csat-card-lede mb-3">Your ticket is closed &mdash; a quick rating helps us keep improving.</p>
                    <form action="post.php" method="post" class="csat-form">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="ticket_id" value="<?php echo $ticket_id ?>">

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

                        <button type="submit" class="btn btn-primary mt-3 px-4" name="add_ticket_feedback" value="1"><i class="fas fa-fw fa-paper-plane me-2"></i>Submit rating</button>
                    </form>
                </div>
            </div>

        <?php } ?>

        <!-- End comments/reopen/feedback -->

        <hr>
        <br>

        <!-- Live update notice (populated by js/live_ticket.js) -->
        <div id="ticket-replies-notice"></div>

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
                $avatar_link = "../uploads/clients/$session_client_id/$user_avatar";
            } else {
                $ticket_reply_by_display = nullable_htmlentities($row['user_name']);
                $user_id = intval($row['user_id']);
                $user_avatar = $row['user_avatar'];
                $user_initials = initials($row['user_name']);
                $avatar_link = "../uploads/users/$user_id/$user_avatar";
            }

            // Get attachments for this reply
            $sql_ticket_reply_attachments = mysqli_query(
                $mysqli,
                "SELECT * FROM ticket_attachments
                        WHERE ticket_attachment_reply_id = $ticket_reply_id
                        AND ticket_attachment_ticket_id = $ticket_id"
            );
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

                    <?php
                    while ($ticket_attachment = mysqli_fetch_assoc($sql_ticket_reply_attachments)) {
                        $name = nullable_htmlentities($ticket_attachment['ticket_attachment_name']);
                        $ref_name = nullable_htmlentities($ticket_attachment['ticket_attachment_reference_name']);
                        echo "<hr><i class='fas fa-fw fa-paperclip text-secondary me-1'></i>$name | <a href='../uploads/tickets/$ticket_id/$ref_name' download='$name'><i class='fas fa-fw fa-download me-1'></i>Download</a> | <a target='_blank' href='../uploads/tickets/$ticket_id/$ref_name'><i class='fas fa-fw fa-external-link-alt me-1'></i>View</a>";
                    }
                    ?>
                </div>
            </div>

            <?php

        }

        ?>

        <script src="../js/pretty_content.js"></script>

        <!-- Live ticket updates (replies/status/chat via SSE) -->
        <script src="../js/live_ticket.js"></script>
        <script src="../js/csat_rating.js"></script>

        <?php
    } else {
        echo "Ticket ID not found!";
    }

} else {
    header("Location: index.php");
}

require_once "includes/footer.php";

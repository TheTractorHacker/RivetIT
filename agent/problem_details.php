<?php

require_once "includes/inc_all.php";

enforceUserPermission('module_support');

$problem_id = intval($_GET['id'] ?? 0);

$problem = mysqli_fetch_assoc(mysqli_query(
    $mysqli,
    "SELECT problems.*, u.user_name AS created_by_name, c.title AS change_title, c.status AS change_status
     FROM problems
     LEFT JOIN users u ON u.user_id = problems.created_by
     LEFT JOIN changes c ON c.change_id = problems.change_problem_id
     WHERE problems.problem_id = $problem_id"
));

if (!$problem) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='problems.php'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
    require_once "includes/footer.php";
    exit;
}

$problem_status_badge = [
    'open' => 'text-bg-danger',
    'investigating' => 'text-bg-warning',
    'resolved' => 'text-bg-info',
    'closed' => 'text-bg-secondary',
];

// Mirrors ProblemService::TRANSITIONS - kept in sync by hand since it's only
// used here to decide which action buttons to show; the service is what
// actually enforces this server-side.
$next_statuses = [
    'open' => ['investigating' => 'Start Investigating', 'resolved' => 'Mark Resolved', 'closed' => 'Close'],
    'investigating' => ['open' => 'Reopen', 'resolved' => 'Mark Resolved', 'closed' => 'Close'],
    'resolved' => ['investigating' => 'Back to Investigating', 'open' => 'Reopen', 'closed' => 'Close'],
    'closed' => ['open' => 'Reopen', 'investigating' => 'Reopen (Investigating)'],
][$problem['status']] ?? [];

$linked_tickets = mysqli_query(
    $mysqli,
    "SELECT tickets.ticket_id, tickets.ticket_prefix, tickets.ticket_number, tickets.ticket_subject,
        tickets.ticket_status, ticket_statuses.ticket_status_name, ticket_statuses.ticket_status_color,
        clients.client_name
     FROM tickets
     LEFT JOIN ticket_statuses ON ticket_statuses.ticket_status_id = tickets.ticket_status
     LEFT JOIN clients ON clients.client_id = tickets.ticket_client_id
     WHERE tickets.ticket_problem_id = $problem_id
     ORDER BY tickets.ticket_created_at DESC"
);

$available_changes = mysqli_query(
    $mysqli,
    "SELECT change_id, title, status FROM changes WHERE change_id != " . intval($problem['change_problem_id'] ?: 0) . " ORDER BY created_at DESC"
);

?>

<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item"><a href="problems.php"><i class="fas fa-fw fa-exclamation-circle me-1"></i>Problems</a></li>
    <li class="breadcrumb-item active"><?= nullable_htmlentities($problem['title']) ?></li>
</ol>

<div class="row">
    <div class="col-md-8">
        <div class="card card-dark mb-3">
            <div class="card-header py-2 d-flex align-items-center">
                <h3 class="card-title me-auto mb-0">
                    <?= nullable_htmlentities($problem['title']) ?>
                    <span class="badge <?= $problem_status_badge[$problem['status']] ?? 'text-bg-secondary' ?> ms-2"><?= ucfirst($problem['status']) ?></span>
                </h3>
                <button type="button" class="btn btn-sm btn-default ajax-modal" data-modal-url="modals/problem/problem_edit.php?id=<?= $problem_id ?>"><i class="fas fa-edit me-1"></i>Edit</button>
            </div>
            <div class="card-body">
                <?php if ($problem['description']) { ?>
                    <p style="white-space: pre-wrap;"><?= nullable_htmlentities($problem['description']) ?></p>
                <?php } else { ?>
                    <p class="text-muted">No description provided.</p>
                <?php } ?>

                <?php if (!empty($next_statuses)) { ?>
                    <div class="mt-2">
                        <?php foreach ($next_statuses as $status_value => $label) { ?>
                            <form action="post.php" method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="problem_id" value="<?= $problem_id ?>">
                                <input type="hidden" name="status" value="<?= $status_value ?>">
                                <button type="submit" name="set_problem_status" class="btn btn-sm btn-outline-secondary mb-1"><?= $label ?></button>
                            </form>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
            <div class="card-footer py-2 text-muted small">
                Created by <?= nullable_htmlentities($problem['created_by_name'] ?? 'Unknown') ?> on <?= nullable_htmlentities($problem['created_at']) ?>
                <?php if ($problem['resolved_at']) { ?>
                    &middot; Resolved <?= nullable_htmlentities($problem['resolved_at']) ?>
                <?php } ?>
            </div>
        </div>

        <div class="card card-dark">
            <div class="card-header py-2">
                <h5 class="card-title mb-0"><i class="fas fa-life-ring me-2"></i>Linked Tickets</h5>
            </div>
            <div class="card-body">
                <?php if (mysqli_num_rows($linked_tickets) === 0) { ?>
                    <p class="text-muted mb-0">No tickets linked to this problem yet.</p>
                <?php } else { ?>
                    <table class="table table-sm table-borderless">
                        <?php while ($t = mysqli_fetch_assoc($linked_tickets)) { ?>
                            <tr>
                                <td>
                                    <a href="ticket.php?ticket_id=<?= intval($t['ticket_id']) ?>">
                                        <?= nullable_htmlentities($t['ticket_prefix'] . $t['ticket_number']) ?> - <?= nullable_htmlentities($t['ticket_subject']) ?>
                                    </a>
                                    <div class="text-muted small"><?= nullable_htmlentities($t['client_name']) ?></div>
                                </td>
                                <td class="text-nowrap">
                                    <span class="badge" style="background-color: <?= nullable_htmlentities($t['ticket_status_color'] ?: '#6c757d') ?>;"><?= nullable_htmlentities($t['ticket_status_name']) ?></span>
                                </td>
                                <td class="text-end">
                                    <form action="post.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="problem_id" value="<?= $problem_id ?>">
                                        <input type="hidden" name="ticket_id" value="<?= intval($t['ticket_id']) ?>">
                                        <button type="submit" name="unlink_problem_ticket" class="btn btn-sm btn-outline-danger" title="Unlink"><i class="fas fa-unlink"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    </table>
                <?php } ?>

                <form action="post.php" method="post" class="row g-2 align-items-end mt-2">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="problem_id" value="<?= $problem_id ?>">
                    <div class="col-auto">
                        <label class="small text-muted mb-1">Link an existing ticket</label>
                        <input type="text" class="form-control form-control-sm" name="ticket_number" placeholder="Ticket # e.g. 1042" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" name="link_problem_ticket" class="btn btn-sm btn-secondary"><i class="fas fa-link me-1"></i>Link Ticket</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card card-dark">
            <div class="card-header py-2">
                <h5 class="card-title mb-0"><i class="fas fa-exchange-alt me-2"></i>Linked Change</h5>
            </div>
            <div class="card-body">
                <?php if ($problem['change_problem_id']) { ?>
                    <p><a href="change_details.php?id=<?= intval($problem['change_problem_id']) ?>"><?= nullable_htmlentities($problem['change_title']) ?></a></p>
                    <p class="text-muted small">Status: <?= ucwords(str_replace('_', ' ', $problem['change_status'])) ?></p>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="problem_id" value="<?= $problem_id ?>">
                        <button type="submit" name="unlink_problem_change" class="btn btn-sm btn-outline-danger"><i class="fas fa-unlink me-1"></i>Unlink</button>
                    </form>
                <?php } else { ?>
                    <p class="text-muted">No change linked yet.</p>
                    <form action="post.php" method="post" class="mb-2">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="problem_id" value="<?= $problem_id ?>">
                        <div class="form-group">
                            <select class="form-control select2" name="change_id" required>
                                <option value="">- Select Change -</option>
                                <?php while ($c = mysqli_fetch_assoc($available_changes)) { ?>
                                    <option value="<?= intval($c['change_id']) ?>"><?= nullable_htmlentities($c['title']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <button type="submit" name="link_problem_change" class="btn btn-sm btn-secondary"><i class="fas fa-link me-1"></i>Link Existing Change</button>
                    </form>
                    <a class="btn btn-sm btn-outline-secondary ajax-modal" href="#" data-modal-url="modals/change/change_add.php?problem_id=<?= $problem_id ?>"><i class="fas fa-plus me-1"></i>Create New Change</a>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php require_once "includes/footer.php"; ?>

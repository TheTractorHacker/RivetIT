<?php
require_once "includes/inc_all_admin.php";

$sql = mysqli_query($mysqli,
    "SELECT r.*, c.client_name, t.ticket_subject, t.ticket_number, t.ticket_prefix, a.asset_name
     FROM ticket_automation_runs r
     LEFT JOIN clients c ON c.client_id = r.client_id
     LEFT JOIN tickets t ON t.ticket_id = r.ticket_id
     LEFT JOIN assets a ON a.asset_id = r.asset_id
     ORDER BY r.id DESC
     LIMIT 200"
);

$trigger_labels = [
    'schedule'       => 'Scheduled check',
    'ticket_created' => 'Ticket created',
    'rmm_alert'      => 'New RMM alert',
    'asset_offline'  => 'Asset offline',
    'asset_online'   => 'Asset online',
];
?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-history me-2"></i>Automation Run Log</h3>
        <div class="card-tools">
            <a href="ticket_automation.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Rules
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Rule</th>
                    <th>Trigger</th>
                    <th>Client</th>
                    <th>Ticket / Asset</th>
                    <th>Result</th>
                </tr>
            </thead>
            <tbody>
            <?php if (mysqli_num_rows($sql) === 0): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        <i class="fas fa-history fa-2x mb-2 d-block"></i>
                        No automation runs recorded yet.
                    </td>
                </tr>
            <?php endif; ?>
            <?php while ($row = mysqli_fetch_assoc($sql)): ?>
                <tr>
                    <td><?php echo nullable_htmlentities($row['created_at']); ?></td>
                    <td><?php echo nullable_htmlentities($row['rule_name']); ?></td>
                    <td><span class="badge text-bg-info"><?php echo $trigger_labels[$row['trigger_type']] ?? $row['trigger_type']; ?></span></td>
                    <td><?php echo nullable_htmlentities($row['client_name']); ?></td>
                    <td>
                        <?php if (!empty($row['ticket_id'])): ?>
                            <a href="/agent/ticket.php?ticket_id=<?php echo intval($row['ticket_id']); ?>">
                                #<?php echo nullable_htmlentities($row['ticket_prefix'] . $row['ticket_number']); ?>
                                <?php echo nullable_htmlentities($row['ticket_subject']); ?>
                            </a>
                        <?php elseif (!empty($row['asset_id'])): ?>
                            <a href="/agent/asset_details.php?asset_id=<?php echo intval($row['asset_id']); ?>">
                                <?php echo nullable_htmlentities($row['asset_name']); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo nullable_htmlentities($row['summary']); ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>

<?php
require_once "includes/inc_all_user.php";

$sql_logins = mysqli_query($mysqli,
    "SELECT * FROM logs
     WHERE (log_type = 'Login' OR log_type = 'Login 2FA') AND log_action = 'Success' AND log_user_id = $session_user_id
     ORDER BY log_id DESC LIMIT 10"
);

$sql_activity = mysqli_query($mysqli,
    "SELECT * FROM logs
     WHERE log_user_id = $session_user_id AND log_type NOT LIKE 'Login%'
     ORDER BY log_id DESC LIMIT 15"
);
?>

<!-- Recent Sign-ins -->
<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title"><i class="fas fa-fw fa-sign-in-alt me-2"></i>Recent Sign-ins</h3>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-borderless table-hover mb-0">
            <thead class="text-muted small border-bottom">
                <tr>
                    <th class="ps-3">When</th>
                    <th>Device</th>
                    <th>Browser</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $login_count = 0;
            while ($row = mysqli_fetch_assoc($sql_logins)) {
                $login_count++;
                $log_ip      = nullable_htmlentities($row['log_ip']);
                $log_ua      = nullable_htmlentities($row['log_user_agent']);
                $log_os      = getOS($row['log_user_agent']);
                $log_browser = getWebBrowser($row['log_user_agent']);
                $log_date    = nullable_htmlentities($row['log_created_at']);
                $time_ago    = timeAgo($row['log_created_at']);

                $os_icon = 'desktop';
                if (stripos($log_ua, 'iphone') !== false || stripos($log_ua, 'android') !== false) $os_icon = 'mobile-alt';
                elseif (stripos($log_ua, 'ipad') !== false || stripos($log_ua, 'tablet') !== false) $os_icon = 'tablet-alt';
                ?>
                <tr>
                    <td class="ps-3">
                        <span title="<?= $log_date ?>"><?= $time_ago ?></span>
                        <div class="text-muted" style="font-size:11px;"><?= $log_date ?></div>
                    </td>
                    <td><i class="fas fa-fw fa-<?= $os_icon ?> text-secondary me-1"></i><?= $log_os ?></td>
                    <td><?= $log_browser ?></td>
                    <td><code class="text-secondary"><?= $log_ip ?></code></td>
                </tr>
            <?php }
            if ($login_count === 0) { ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No sign-in history found.</td></tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php if ($session_is_admin) { ?>
    <div class="card-footer py-2">
        <a href="../../admin/audit_log.php?q=<?= urlencode("$session_name successfully logged in") ?>" class="text-sm">
            <i class="fas fa-external-link-alt me-1"></i>Full audit log
        </a>
    </div>
    <?php } ?>
</div>

<!-- Recent Activity -->
<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title"><i class="fas fa-fw fa-history me-2"></i>Recent Activity</h3>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-borderless table-hover mb-0">
            <thead class="text-muted small border-bottom">
                <tr>
                    <th class="ps-3" style="width:140px;">When</th>
                    <th style="width:130px;">Type</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $act_count = 0;
            while ($row = mysqli_fetch_assoc($sql_activity)) {
                $act_count++;
                $log_type   = nullable_htmlentities($row['log_type']);
                $log_action = nullable_htmlentities($row['log_action']);
                $log_desc   = nullable_htmlentities($row['log_description']);
                $log_date   = nullable_htmlentities($row['log_created_at']);
                $time_ago   = timeAgo($row['log_created_at']);

                $icon_map = [
                    'Create'  => ['plus-circle', 'text-success'],
                    'Edit'    => ['pencil-alt',   'text-info'],
                    'Archive' => ['archive',       'text-warning'],
                    'Delete'  => ['trash-alt',     'text-danger'],
                    'Reply'   => ['reply',         'text-primary'],
                    'Restore' => ['undo',          'text-success'],
                ];
                [$icon, $color] = $icon_map[$log_action] ?? ['circle', 'text-secondary'];
                ?>
                <tr>
                    <td class="ps-3 text-muted small" title="<?= $log_date ?>"><?= $time_ago ?></td>
                    <td>
                        <i class="fas fa-fw fa-<?= $icon ?> <?= $color ?> me-1"></i>
                        <span class="small"><?= $log_type ?></span>
                    </td>
                    <td class="text-muted small"><?= $log_desc ?></td>
                </tr>
            <?php }
            if ($act_count === 0) { ?>
                <tr><td colspan="3" class="text-center text-muted py-3">No activity recorded yet.</td></tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php if ($session_is_admin) { ?>
    <div class="card-footer py-2">
        <a href="../../admin/audit_log.php?q=<?= urlencode($session_name) ?>" class="text-sm">
            <i class="fas fa-external-link-alt me-1"></i>Full audit log
        </a>
    </div>
    <?php } ?>
</div>

<?php require_once "../../includes/footer.php"; ?>

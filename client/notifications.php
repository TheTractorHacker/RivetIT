<?php
/*
 * Client Portal
 * My notifications: announcements, replies and closures on my own requests, approvals waiting on me, checklist tasks that are due and
 * device warranties running out. Read-only and scoped to the signed-in contact (src/Portal/PortalFeed.php).
 */

header("Content-Security-Policy: default-src 'self'");

ob_start();
require_once "includes/inc_all.php";

require_once $_SERVER['DOCUMENT_ROOT'] . '/src/Portal/PortalFeed.php';
$feed = new \ITFlow\Portal\PortalFeed($mysqli);
$feed_items = $feed->items(intval($session_contact_id), intval($session_client_id), 60);
$feed_unread = 0;
foreach ($feed_items as $i) {
    if ($i['unread']) { $feed_unread++; }
}
$feed_icon = ['announcement' => 'fa-bullhorn', 'reply' => 'fa-comment-dots', 'closed' => 'fa-check-circle', 'approval' => 'fa-user-check', 'task' => 'fa-tasks', 'warranty' => 'fa-shield-alt'];
$feed_sev = ['info' => 'secondary', 'important' => 'warning', 'urgent' => 'danger'];
?>

<div class="row mb-4">
    <div class="col">
        <h2><i class="fas fa-fw fa-bell me-2"></i>Notifications</h2>
    </div>
    <?php if ($feed_unread > 0 && intval($session_contact_id) > 0) { ?>
    <div class="col-auto">
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" name="mark_feed_read" class="btn btn-sm btn-outline-primary"><i class="fas fa-check me-1" aria-hidden="true"></i>Mark all as read</button>
        </form>
    </div>
    <?php } ?>
</div>

<div class="card portal-card">
    <ul class="list-group list-group-flush" id="portal-feed">
        <?php if (!$feed_items) { ?>
            <li class="list-group-item"><div class="portal-empty"><i class="far fa-bell" aria-hidden="true"></i>Nothing new. Announcements, replies to your requests and things waiting on you show up here.</div></li>
        <?php }
        foreach ($feed_items as $i) { ?>
            <li class="list-group-item d-flex gap-3 align-items-start portal-feed-item <?= $i['unread'] ? 'portal-feed-unread' : '' ?>" data-feed-type="<?= nullable_htmlentities($i['type']) ?>">
                <span class="text-<?= $feed_sev[$i['severity']] ?? 'secondary' ?> pt-1"><i class="fas fa-fw <?= $feed_icon[$i['type']] ?? 'fa-bell' ?>" aria-hidden="true"></i></span>
                <span class="flex-fill">
                    <a href="<?= nullable_htmlentities($i['link']) ?>" class="<?= $i['unread'] ? 'fw-bold' : '' ?>"><?= nullable_htmlentities($i['title']) ?></a>
                    <?php if ($i['unread']) { ?><span class="badge text-bg-primary ms-1">New</span><?php } ?>
                    <?php if ($i['detail'] !== '') { ?><span class="d-block small text-secondary"><?= nullable_htmlentities($i['detail']) ?></span><?php } ?>
                </span>
                <span class="small text-secondary text-nowrap"><?= nullable_htmlentities(timeAgo($i['at'])) ?></span>
            </li>
        <?php } ?>
    </ul>
</div>

<?php
require_once "includes/footer.php";

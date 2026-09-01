<?php
require_once "includes/inc_all_user.php";

$pref = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT user_config_calendar_first_day, user_config_records_per_page FROM user_settings WHERE user_id = $session_user_id"
));
$calendar_first_day  = intval($pref['user_config_calendar_first_day']);
$records_per_page    = intval($pref['user_config_records_per_page'] ?: 10);
?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title"><i class="fas fa-fw fa-sliders-h me-2"></i>Preferences</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <!-- Theme -->
            <div class="form-group">
                <label class="d-block mb-2">Theme</label>
                <div class="btn-group js-btn-group-toggle">
                    <label class="btn btn-outline-secondary <?= $user_config_theme_dark === 0 ? 'active' : '' ?>">
                        <input type="radio" name="dark_mode" autocomplete="off"
                               <?= $user_config_theme_dark === 0 ? 'checked' : '' ?>>
                        <i class="fas fa-sun me-1"></i> Light
                    </label>
                    <label class="btn btn-outline-secondary <?= $user_config_theme_dark === 1 ? 'active' : '' ?>">
                        <input type="radio" name="dark_mode" value="1" autocomplete="off"
                               <?= $user_config_theme_dark === 1 ? 'checked' : '' ?>>
                        <i class="fas fa-moon me-1"></i> Dark
                    </label>
                </div>
            </div>

            <hr>

            <!-- Calendar -->
            <div class="form-group">
                <label>Calendar starts on</label>
                <div class="input-group" style="max-width:220px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar-day"></i></span>
                    </div>
                    <select class="form-control" name="calendar_first_day">
                        <option value="0" <?= $calendar_first_day == 0 ? 'selected' : '' ?>>Sunday</option>
                        <option value="1" <?= $calendar_first_day == 1 ? 'selected' : '' ?>>Monday</option>
                    </select>
                </div>
            </div>

            <!-- Records per page -->
            <div class="form-group">
                <label>Records per page</label>
                <div class="input-group" style="max-width:220px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-list"></i></span>
                    </div>
                    <select class="form-control" name="records_per_page">
                        <?php foreach ([10, 25, 50, 100] as $n) { ?>
                        <option value="<?= $n ?>" <?= $records_per_page == $n ? 'selected' : '' ?>><?= $n ?></option>
                        <?php } ?>
                    </select>
                </div>
                <small class="text-muted">Number of rows shown in tables.</small>
            </div>

            <hr>

            <button type="submit" name="edit_your_user_preferences" class="btn btn-primary btn-sm">
                <i class="fas fa-check me-1"></i>Save Preferences
            </button>
        </form>
    </div>
</div>

<!-- Push Notifications -->
<?php
$sql_my_devices = mysqli_query($mysqli, "SELECT token_id, token_name, token_fcm_token, token_last_used_at, token_created_at FROM api_tokens WHERE token_user_id = $session_user_id ORDER BY token_last_used_at IS NULL, token_last_used_at DESC, token_created_at DESC");
$my_device_count = mysqli_num_rows($sql_my_devices);
$has_fcm = mysqli_num_rows(mysqli_query($mysqli, "SELECT token_id FROM api_tokens WHERE token_user_id = $session_user_id AND token_fcm_token IS NOT NULL LIMIT 1")) > 0;
?>
<div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto"><i class="fas fa-fw fa-mobile-alt me-2"></i>Push Notifications</h3>
        <?php if ($has_fcm) { ?>
        <form action="post.php" method="post" class="mb-0">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" name="test_push_notification" class="btn btn-primary btn-sm">
                <i class="fas fa-bell me-1"></i>Send Test Notification
            </button>
        </form>
        <?php } ?>
    </div>
    <div class="card-body">
        <?php if (!$has_fcm) { ?>
        <div class="alert alert-warning py-2 mb-3">
            <i class="fas fa-exclamation-triangle me-2"></i>No registered mobile devices found. Log in to the ITFlow mobile app and enable push notifications.
        </div>
        <?php } ?>

        <!-- My devices -->
        <div class="small text-muted fw-bold mb-2 text-uppercase" style="letter-spacing:.05em;font-size:.7rem;">
            <i class="fas fa-tablet-alt me-1"></i>My Devices
        </div>
        <?php if ($my_device_count > 0) { ?>
        <div class="table-responsive mb-3" style="border:1px solid var(--color-border,#eee);border-radius:.4rem;">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>Push</th>
                        <th>Last Active</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($dev = mysqli_fetch_assoc($sql_my_devices)) {
                        $last_used = $dev['token_last_used_at'];
                        $is_active = $last_used && (time() - strtotime($last_used)) < 7 * 86400;
                        $has_push  = !empty($dev['token_fcm_token']);
                    ?>
                    <tr id="my-dev-<?= $dev['token_id'] ?>">
                        <td><i class="fas fa-fw fa-mobile-alt text-secondary me-1"></i><?= nullable_htmlentities($dev['token_name']) ?></td>
                        <td>
                            <?php if ($has_push) { ?>
                            <i class="fas fa-bell text-success" title="Push notifications active"></i>
                            <?php } else { ?>
                            <i class="fas fa-bell-slash text-muted" title="No push token registered"></i>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($last_used) { ?>
                            <span class="badge <?= $is_active ? 'text-bg-success' : 'text-bg-secondary' ?>" style="font-size:.7rem;"><?= $is_active ? 'Active' : 'Idle' ?></span>
                            <span class="text-muted small ms-1"><?= $last_used ?></span>
                            <?php } else { ?>
                            <span class="badge text-bg-light border" style="font-size:.7rem;">Never used</span>
                            <?php } ?>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-xs btn-outline-danger js-revoke-my-device" data-token-id="<?= $dev['token_id'] ?>" data-csrf="<?= $_SESSION['csrf_token'] ?>">
                                <i class="fas fa-times me-1"></i>Revoke
                            </button>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } else { ?>
        <div class="p-3 text-muted text-center small mb-3" style="border:1px solid var(--color-border,#eee);border-radius:.4rem;">
            <i class="fas fa-mobile-alt fa-2x mb-2 d-block text-secondary"></i>
            No devices logged in yet.
        </div>
        <?php } ?>

        <?php
        $push_global_categories = push_globally_enabled_categories();
        $push_my_categories     = push_enabled_categories_for_user($session_user_id);
        ?>
        <div class="small text-muted fw-bold mb-1 text-uppercase" style="letter-spacing:.05em;font-size:.7rem;">
            <i class="fas fa-list-check me-1"></i>What gets pushed to your phone
        </div>
        <?php if (empty($push_global_categories)) { ?>
        <div class="small text-muted mb-0">An administrator has not allowed any notification categories to push yet.</div>
        <?php } else { ?>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="rounded" style="border:1px solid var(--color-border,#eee);overflow:hidden;">
                <?php
                $allowed_cats = array_filter(push_notification_categories(), fn($k) => in_array($k, $push_global_categories, true), ARRAY_FILTER_USE_KEY);
                $cat_total = count($allowed_cats);
                $cat_i = 0;
                foreach ($allowed_cats as $cat_key => $cat) {
                    $cat_i++;
                ?>
                <div class="d-flex align-items-center justify-content-between p-2 px-3"
                     style="<?= $cat_i < $cat_total ? 'border-bottom:1px solid var(--color-border,#eee);' : '' ?>">
                    <span><i class="<?= $cat['icon'] ?> me-2 text-muted" style="width:1.1rem;text-align:center;"></i><?= nullable_htmlentities($cat['label']) ?></span>
                    <div class="form-check form-check form-switch mb-0">
                        <input type="checkbox" class="form-check-input" id="my_push_cat_<?= $cat_key ?>"
                               name="push_categories[]" value="<?= $cat_key ?>"
                               <?= in_array($cat_key, $push_my_categories, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="my_push_cat_<?= $cat_key ?>"></label>
                    </div>
                </div>
                <?php } ?>
            </div>
            <button type="submit" name="save_my_push_categories" class="btn btn-sm btn-primary mt-3">
                <i class="fas fa-save me-1"></i>Save
            </button>
        </form>
        <?php } ?>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
async function revokeMyDevice(id, btn) {
    if (!confirm('Revoke this device? It will be logged out of the mobile app.')) return;
    btn.disabled = true;
    try {
        const r = await fetch('/agent/user/api_token_revoke.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({token_id: id, csrf_token: btn.dataset.csrf})
        });
        const j = await r.json();
        if (j.ok) {
            document.getElementById('my-dev-' + id).remove();
        } else {
            alert('Could not revoke device.');
            btn.disabled = false;
        }
    } catch (e) {
        alert('Network error revoking device.');
        btn.disabled = false;
    }
}
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-revoke-my-device');
    if (btn) { revokeMyDevice(parseInt(btn.dataset.tokenId, 10), btn); }
});
</script>

<?php require_once "../../includes/footer.php"; ?>

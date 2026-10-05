<?php
require_once "includes/inc_all_admin.php";

use ITFlow\Redis\RedisSettings;

$conn = RedisSettings::resolve($mysqli);
$stats = null;
$counts = [];
$connect_error = null;
try {
    $rc = RedisSettings::client($conn, 1.0);
    $rc->connect();
    $stats = RedisSettings::stats($rc);
    $counts = RedisSettings::groupCounts($rc);
} catch (Throwable $e) {
    $connect_error = RedisSettings::test($conn)['message'];
}
$csrf = $_SESSION['csrf_token'];
$state = $stats ? ['Connected', 'success'] : ['Not connected', 'warning'];
$uptime = $stats ? ($stats['uptime_seconds'] >= 86400 ? floor($stats['uptime_seconds'] / 86400) . ' days' : ($stats['uptime_seconds'] >= 3600 ? floor($stats['uptime_seconds'] / 3600) . ' hours' : floor($stats['uptime_seconds'] / 60) . ' min')) : '';
$mem_mb = $stats && $stats['maxmemory'] > 0 ? (int) round($stats['maxmemory'] / 1048576) : 256;
$advice = [];
if ($stats) {
    if ($stats['maxmemory'] === 0) $advice[] = 'No memory limit is set. Set one below so Redis cannot grow without bound.';
    if ($stats['maxmemory'] > 0 && $stats['policy'] === 'noeviction') $advice[] = 'A memory limit with the "noeviction" policy makes Redis refuse writes when full. Choose allkeys-lru or volatile-lru instead.';
    if (empty($conn['password']) && !in_array($conn['host'], ['127.0.0.1', 'localhost', '::1'], true)) $advice[] = 'Redis is on another machine with no password. Set one on the server and here.';
}
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-bolt me-2"></i>Redis</h3>
        <span class="badge bg-<?= $state[1] ?> fs-6"><?= $state[0] ?></span>
    </div>
    <div class="card-body">
        <p class="text-muted">Redis speeds things up: live ticket and chat updates, rate limits and job locks. It never holds the only copy of anything, so if it is down the app keeps working and only those extras pause.</p>
        <?php if ($connect_error) { ?>
            <div class="alert alert-warning"><?= nullable_htmlentities($connect_error) ?> Currently trying <code><?= nullable_htmlentities($conn['host'] . ':' . $conn['port']) ?></code></div>
        <?php } else { ?>
            <div class="row g-3 text-center mb-2">
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Version</div><strong><?= nullable_htmlentities($stats['version']) ?></strong></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Up for</div><strong><?= nullable_htmlentities($uptime) ?></strong></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Memory</div><strong><?= nullable_htmlentities($stats['memory_used']) ?><?= $stats['maxmemory'] > 0 ? ' / ' . round($stats['maxmemory'] / 1048576) . ' MB' : ' (no limit)' ?></strong></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="small text-muted">Clients &middot; ops/s</div><strong><?= (int) $stats['clients'] ?> &middot; <?= (int) $stats['ops_per_sec'] ?></strong></div></div>
            </div>
            <p class="text-muted small mb-0"><?= (int) $stats['keys'] ?> stored key(s)<?= $stats['hit_rate'] !== null ? ', ' . (int) $stats['hit_rate'] . '% cache hit rate' : '' ?>. Saved to disk: <?= $stats['aof'] ? 'append-only file on' : ($stats['last_save'] ? 'last snapshot ' . nullable_htmlentities(date('Y-m-d H:i', $stats['last_save'])) : 'no snapshot yet') ?>.</p>
        <?php } ?>
        <?php foreach ($advice as $a) { ?><div class="alert alert-warning py-2 mt-3 mb-0 small"><?= nullable_htmlentities($a) ?></div><?php } ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-plug me-2"></i>Connection</h4></div>
    <div class="card-body">
        <?php if (!$conn['schema_ready']) { ?>
            <div class="alert alert-warning mb-0">Run the database update first (Administration &rarr; Update) to edit these settings here. Until then the server's environment or the built-in default (127.0.0.1:6380) is used.</div>
        <?php } else { ?>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label" for="redis_host">Host</label>
                    <input class="form-control" id="redis_host" name="redis_host" maxlength="253" value="<?= nullable_htmlentities($conn['host']) ?>" <?= $conn['from_env']['host'] ? 'readonly' : '' ?>>
                    <?php if ($conn['from_env']['host']) { ?><div class="form-text">Set by the server (RIVETIT_REDIS_HOST).</div><?php } ?>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="redis_port">Port</label>
                    <input class="form-control" type="number" min="1" max="65535" id="redis_port" name="redis_port" value="<?= (int) $conn['port'] ?>" <?= $conn['from_env']['port'] ? 'readonly' : '' ?>>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="redis_db">Database</label>
                    <input class="form-control" type="number" min="0" max="15" id="redis_db" name="redis_db" value="<?= (int) $conn['db'] ?>" <?= $conn['from_env']['db'] ? 'readonly' : '' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="redis_password">Password</label>
                    <input class="form-control" type="password" id="redis_password" name="redis_password" autocomplete="new-password" <?= $conn['from_env']['password'] ? 'readonly' : '' ?> placeholder="<?= $conn['from_env']['password'] ? 'Set by the server' : ($conn['has_stored_password'] ? 'Saved. Leave blank to keep' : 'None') ?>">
                </div>
            </div>
            <?php if ($conn['has_stored_password'] && !$conn['from_env']['password']) { ?>
                <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="redis_clear_password" name="redis_clear_password" value="1"><label class="form-check-label" for="redis_clear_password">Remove the saved password</label></div>
            <?php } ?>
            <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="redis_force" name="redis_force" value="1"><label class="form-check-label" for="redis_force">Save even if it cannot connect right now</label></div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="submit" name="test_redis_connection" class="btn btn-outline-secondary"><i class="fa fa-vial me-2"></i>Test only</button>
                <button type="submit" name="save_redis_settings" class="btn btn-primary"><i class="fa fa-check me-2"></i>Test and save</button>
            </div>
            <div class="form-text mt-2">Saving is refused unless the connection test passes. The password is stored encrypted.</div>
        </form>
        <?php } ?>
    </div>
</div>

<?php if ($stats) { ?>
<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-memory me-2"></i>Memory limit</h4></div>
    <div class="card-body">
        <form action="post.php" method="post" class="row g-3 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-sm-4"><label class="form-label" for="redis_mb">Limit (MB)</label><input class="form-control" type="number" min="64" max="65536" id="redis_mb" name="redis_mb" value="<?= $mem_mb ?>"></div>
            <div class="col-sm-4"><label class="form-label" for="redis_policy">When full</label>
                <select class="form-select" id="redis_policy" name="redis_policy">
                    <?php foreach (RedisSettings::POLICIES as $p) { ?><option value="<?= $p ?>" <?= $stats['policy'] === $p || ($stats['policy'] === 'noeviction' && $p === 'allkeys-lru') ? 'selected' : '' ?>><?= $p ?></option><?php } ?>
                </select></div>
            <div class="col-sm-4"><button type="submit" name="set_redis_memory" class="btn btn-primary">Apply</button></div>
            <div class="form-text">Applies immediately. If Redis cannot save it to its own config file you are shown the two lines to add so it survives a restart. <strong>allkeys-lru</strong> drops the least recently used keys when full and is the safe choice for this app.</div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-broom me-2"></i>Clear cached data</h4></div>
    <div class="card-body">
        <p class="text-muted small">Safe to clear: the app rebuilds all of it on demand. Nothing else in Redis is touched.</p>
        <?php foreach (RedisSettings::CLEARABLE as $group => $def) { ?>
            <form action="post.php" method="post" class="d-flex align-items-center justify-content-between border-top py-2" onsubmit="return confirm('Clear <?= nullable_htmlentities(strtolower($def['label'])) ?>?<?= $group === 'locks' ? ' Clearing locks can let a job that is running start a second copy.' : '' ?>')">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="redis_group" value="<?= nullable_htmlentities($group) ?>">
                <div><strong><?= nullable_htmlentities($def['label']) ?></strong> <span class="badge bg-secondary"><?= (int) ($counts[$group] ?? 0) ?></span></div>
                <button type="submit" name="clear_redis_group" class="btn btn-sm btn-outline-danger">Clear</button>
            </form>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php require_once "../includes/footer.php";

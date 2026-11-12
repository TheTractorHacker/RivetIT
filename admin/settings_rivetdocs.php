<?php
require_once "includes/inc_all_admin.php";
require_once dirname(__DIR__) . '/includes/rivetdocs.php';

$csrf = $_SESSION['csrf_token'];

// The two settings are added by database update 2.6.151; until it runs the page explains instead of failing.
$row = null;
try {
    $res = mysqli_query($mysqli, "SELECT config_rivetdocs_enabled, config_rivetdocs_url FROM settings WHERE company_id = 1");
    if ($res) {
        $row = mysqli_fetch_assoc($res) ?: null;
    }
} catch (\Throwable $e) {
    // PHP 8.1+ mysqli throws on an unknown column even behind @; the page then explains instead of answering 500.
    $row = null;
}
$schema_ready = is_array($row);
$enabled = $schema_ready && (int) $row['config_rivetdocs_enabled'] === 1;
$url = $schema_ready ? (string) $row['config_rivetdocs_url'] : '';
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-book me-2"></i>RivetDocs</h3>
        <span class="badge bg-<?= $enabled ? 'success' : 'secondary' ?> fs-6"><?= $enabled ? 'On' : 'Off' ?></span>
    </div>
    <div class="card-body">
        <p class="text-muted">RivetDocs is an optional, separate documentation app. It is not part of <?= nullable_htmlentities(APP_NAME) ?> and you do not need it.
        When this is on, each client page gets an <strong>Open in RivetDocs</strong> link. Nothing is sent to RivetDocs from here, and no passwords are shared.</p>
        <p class="text-muted small">To pull clients, assets and contacts into RivetDocs, add this install in RivetDocs under Admin &rarr; Integrations
        (choose <?= nullable_htmlentities(APP_NAME) ?>), using this install's address and an API key from <a href="api_keys.php">API Keys</a>.</p>
        <?php if (!$schema_ready) { ?>
            <div class="alert alert-warning mb-0">Run the database update first (Administration &rarr; Update).</div>
        <?php } else { ?>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="rivetdocs_enabled" name="rivetdocs_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="rivetdocs_enabled">Show the RivetDocs link on client pages</label>
            </div>
            <div class="mb-3">
                <label class="form-label" for="rivetdocs_url">RivetDocs address</label>
                <input class="form-control" type="url" id="rivetdocs_url" name="rivetdocs_url" maxlength="255" placeholder="https://docs.example.com" value="<?= nullable_htmlentities($url) ?>">
                <div class="form-text">The web address people use to open RivetDocs. Only http and https addresses are accepted.</div>
            </div>
            <button type="submit" name="save_rivetdocs_settings" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save</button>
        </form>
        <?php } ?>
    </div>
</div>

<?php require_once "../includes/footer.php";

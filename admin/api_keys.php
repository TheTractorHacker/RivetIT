<?php

// Retired page. Legacy API keys were converted into integration clients (DB 2.6.204); this page only explains that and lists the old keys
// read-only, each linked to the client that now carries it. Nothing here can create, change or delete a credential.

$sort = "api_key_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

$ak_migrated = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'integration_legacy_keys'")) > 0;
$ak_rows = $ak_migrated ? mysqli_query($mysqli,
    "SELECT k.api_key_id, k.api_key_name, k.api_key_created_at, k.api_key_expire, l.lk_client_id, l.lk_last_used_at, l.lk_use_count, c.client_uid, c.client_status
     FROM api_keys k
     LEFT JOIN integration_legacy_keys l ON l.lk_api_key_id = k.api_key_id
     LEFT JOIN integration_clients c ON c.client_id = l.lk_client_id
     ORDER BY k.api_key_name") : false;
$ak_policy = $ak_migrated ? \ITFlow\Integration\LegacyKeys::policy($mysqli) : null;

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-key me-2"></i>API Keys</h3>
        <div class="card-tools">
            <a class="btn btn-primary" href="/admin/integration_clients.php"><i class="fas fa-plug me-2"></i>Open Integration clients</a>
        </div>
    </div>

    <div class="card-body">
        <div class="alert alert-info">
            <strong>API keys are now integration clients.</strong> There is one machine-credential system: <a href="/admin/integration_clients.php">Administration &gt; Integration clients</a>
            (Client ID + secret, short-lived signed access tokens, per-resource scopes, department limits, secret rotation). Every key below was converted
            automatically with the same permissions, departments, IP allow-list and expiry, and its key still works through the deprecated
            <code>X-Api-Key</code> header for now. Switch each integration to its Client ID and secret, then turn the old header off.
        </div>

        <?php if (!$ak_migrated) { ?>
            <div class="alert alert-warning mb-0">The database has not been updated yet (needs DB 2.6.204). Take a backup, then run <strong>Administration &gt; Update &gt; Update Database</strong>.</div>
        <?php } else { ?>

        <p class="text-muted">
            The <code>X-Api-Key</code> header is currently
            <?php if ($ak_policy['accepted']) { ?>
                <span class="badge text-bg-warning">accepted (deprecated)</span><?php if ($ak_policy['sunset']) { ?> until <strong><?php echo nullable_htmlentities($ak_policy['sunset']); ?></strong><?php } ?>.
            <?php } else { ?>
                <span class="badge text-bg-success">switched off</span>.
            <?php } ?>
            Change this on the Integration clients page. The old key rows below are kept unchanged (read-only) so a rollback stays possible.
        </p>

        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark">
                <tr><th>Name</th><th>Created</th><th>Legacy expiry</th><th>Now managed as</th><th>X-Api-Key last used</th><th class="text-end">Uses</th></tr>
                </thead>
                <tbody>
                <?php while ($ak_rows && ($r = mysqli_fetch_assoc($ak_rows))) { ?>
                    <tr>
                        <td class="text-bold"><?php echo nullable_htmlentities($r['api_key_name']); ?></td>
                        <td><?php echo nullable_htmlentities($r['api_key_created_at']); ?></td>
                        <td><?php echo nullable_htmlentities($r['api_key_expire']); ?></td>
                        <td>
                            <?php if ($r['lk_client_id']) { ?>
                                <a href="/admin/integration_clients.php#client-<?php echo intval($r['lk_client_id']); ?>"><code><?php echo nullable_htmlentities($r['client_uid']); ?></code></a>
                                <?php echo $r['client_status'] === 'revoked' ? '<span class="badge text-bg-danger">Revoked</span>' : ''; ?>
                            <?php } else { ?>
                                <span class="text-muted">converted on first use</span>
                            <?php } ?>
                        </td>
                        <td><?php echo $r['lk_last_used_at'] ? nullable_htmlentities($r['lk_last_used_at']) : 'never'; ?></td>
                        <td class="text-end"><?php echo intval($r['lk_use_count'] ?? 0); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";

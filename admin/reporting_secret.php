<?php
// Shown ONCE, from admin/post/reporting.php, in the response to the POST that created or rotated the reporting account. Never linked, never reachable by GET:
// without $rp_secret (set by the handler) it refuses. The password is not stored anywhere; a reload of this page is a new POST and cannot show it again.
if (!isset($rp_secret) || !is_array($rp_secret)) {
    http_response_code(404);
    exit;
}
require_once "includes/inc_all_admin.php";
$h = static fn ($s): string => nullable_htmlentities((string) $s);
$server = $rp_secret['db_host'] !== '' ? $rp_secret['db_host'] . ':' . $rp_secret['db_port'] : '';
?>
<div class="card card-dark">
    <div class="card-header py-3"><h3 class="card-title"><i class="fas fa-fw fa-key mr-2"></i>Reporting account ready</h3></div>
    <div class="card-body">
        <div class="alert alert-warning"><strong>Copy the password now.</strong> It is shown this one time and is not stored anywhere. If you lose it, rotate the account (run the same form again) to get a new one.</div>
        <table class="table table-sm" style="max-width: 640px">
            <tr><th>Server</th><td><code><?= $server !== '' ? $h($server) : 'enter the public host name of your database server' ?></code></td></tr>
            <tr><th>Database</th><td><code><?= $h($rp_secret['database']) ?></code></td></tr>
            <tr><th>User</th><td><code><?= $h($rp_secret['user']) ?></code><?= $rp_secret['host'] !== '' ? ' @ <code>' . $h($rp_secret['host']) . '</code>' : '' ?></td></tr>
            <tr><th>Password</th><td><input type="text" readonly class="form-control form-control-sm d-inline-block" style="max-width: 360px" id="rp_pw" value="<?= $h($rp_secret['password']) ?>" autocomplete="off" spellcheck="false">
                <button type="button" class="btn btn-sm btn-outline-secondary clipboardjs" data-clipboard-text="<?= $h($rp_secret['password']) ?>">Copy</button></td></tr>
            <tr><th>TLS</th><td><?= $rp_secret['tls'] ? 'Required' : 'Not required' ?></td></tr>
        </table>
        <a class="btn btn-primary" href="reporting.php">Back to Reporting / Power BI</a>
    </div>
</div>
<?php require_once "../includes/footer.php"; ?>

// Fetches client_overview.php's Comet Backup card contents asynchronously -
// comet_get_users()/comet_get_jobs_for_user() make blocking curl_exec() calls
// to an external Comet Backup server (up to ~30s worst case for both calls),
// so this runs after the page itself has already rendered instead of holding
// up the whole page load on a third-party server's health/latency.
document.addEventListener('DOMContentLoaded', function () {
    var body = document.getElementById('cometBackupCardBody');
    if (!body) return;

    fetch('ajax.php?comet_client_status=1&client_id=' + encodeURIComponent(body.dataset.clientId))
        .then(function (r) { return r.text(); })
        .then(function (html) { body.innerHTML = html; })
        .catch(function () {
            body.innerHTML = '<p class="text-muted text-center py-3 mb-0">Unable to load backup status.</p>';
        });
});

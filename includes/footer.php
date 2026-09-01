<?php
require_once "inc_confirm_modal.php";
?>

<?php
if (basename(dirname($_SERVER['REQUEST_URI'])) === 'admin') { ?>
    <p class="text-end fw-light">ITFlow <?php echo APP_VERSION ?> &nbsp; · &nbsp; <a target="_blank" href="https://docs.itflow.org">Docs</a> &nbsp; · &nbsp; <a target="_blank" href="https://forum.itflow.org">Forum</a> &nbsp; · &nbsp; <a target="_blank" href="https://services.itflow.org">Services</a></p>
    <br>
<?php } ?>
<?php
if (basename(dirname($_SERVER['REQUEST_URI'])) === 'guest') { ?>
<p class="text-center">
    <?php echo nullable_htmlentities($session_company_name); ?>
</p>
<?php } ?>

</div><!-- /.container-fluid -->
</div> <!-- /.app-content -->
</main> <!-- /.app-main -->
</div> <!-- /.app-wrapper -->

<!-- Set the browser window title to the clients name -->
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.title = <?php echo json_encode("$tab_title - $page_title"); ?>;
// Exposes this request's CSP nonce so AJAX-injected modal content (whose
// <script> tags arrive via innerHTML and are otherwise inert) can carry a
// valid nonce when re-executed - see executeInjectedScripts() in ajax_modal.js.
// Standard practice for CSP + dynamic script injection (cf. webpack's
// __webpack_nonce__); does not weaken CSP, since a script already needs the
// nonce ATTRIBUTE on its own tag to run at all, independent of this value.
window.CSP_NONCE = <?php echo json_encode($csp_nonce ?? ''); ?>;
</script>

<!-- REQUIRED SCRIPTS -->

<!-- All deferred: fetched in parallel without blocking the parser, still execute in
     document order right before DOMContentLoaded - the same guarantee plain blocking
     <script> tags gave, just without forcing 2.5MB of JS to download+run serially on
     every page load. Safe because every consumer already gates its real work behind
     DOMContentLoaded or a later event/click handler (app.js, ajax_modal.js) - the only
     exceptions were dashboard.php/rmm_dashboard.php's Chart.js init blocks, which ran
     immediately at parse time and have been wrapped in DOMContentLoaded listeners to match. -->

<!-- Bootstrap 5 (bundle includes Popper) -->
<script src="/plugins/bootstrap5/js/bootstrap.bundle.min.js" defer></script>

<!-- Vanilla plugins (BS5 stack) -->
<script src="/plugins/tom-select/js/tom-select.complete.min.js" defer></script>
<script src="/plugins/litepicker/js/litepicker.js" defer></script>
<script src="/plugins/tempus-dominus/js/tempus-dominus.min.js" defer></script>
<script src="/plugins/simple-datatables/js/simple-datatables.js" defer></script>
<script src="/plugins/inputmask5/dist/inputmask.min.js" defer></script>

<!-- Custom js (kept, version-agnostic) -->
<script src="/plugins/moment/moment.min.js" defer></script>
<script src="/plugins/chart.js/chart.umd.min.js" defer></script>
<script src="/plugins/tinymce/tinymce.min.js" referrerpolicy="origin" defer></script>
<script src="/plugins/marked/marked.min.js" defer></script>
<script src="/plugins/turndown/turndown.js" defer></script>
<script src="/plugins/turndown/turndown-plugin-gfm.js" defer></script>
<script src="/plugins/clipboardjs/clipboard.min.js" defer></script>
<script src="/js/keepalive.js" defer></script>
<script src="/plugins/intl-tel-input/js/intlTelInput.min.js" defer></script>

<!-- AdminLTE 4 App -->
<script src="/plugins/adminlte4/js/adminlte.min.js" defer></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">window.csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;</script>
<?php
// Cache-bust first-party JS on every edit (falls back to the request time if the
// file is somehow missing) so a stale Cloudflare/browser cache can't keep serving
// an old copy after a deploy - static assets otherwise have no way to know they changed.
// date_filter.js is intentionally dropped: its litepicker replacement now lives in app.js.
foreach (['app.js', 'ajax_modal.js', 'confirm_modal.js'] as $__asset) {
    $__asset_path = __DIR__ . '/../js/' . $__asset;
    $__asset_version = file_exists($__asset_path) ? filemtime($__asset_path) : time();
    echo '<script src="/js/' . $__asset . '?v=' . $__asset_version . '" defer></script>' . "\n";
}
?>

</body>
</html>

<?php

// Calculate Execution time Uncomment for test

//$time_end = microtime(true);
//$execution_time = ($time_end - $time_start);
//echo '<h2>Total Execution Time: '.number_format((float) $execution_time, 10) .' seconds</h2>';

<?php
// Scheduled report render (scripts/report_render.php, CLI): no page shell was opened, so there is nothing to close.
if (defined('RIVETIT_REPORT_HEADLESS') && PHP_SAPI === 'cli') { return; }
require_once "inc_confirm_modal.php";
?>

<?php
if (basename(dirname($_SERVER['REQUEST_URI'])) === 'admin') { ?>
    <?php /* Docs / Source only when that link is published (empty while APP_REPO_PUBLIC is 0: includes/branding.php). */ ?>
    <p class="text-end fw-light"><?= htmlspecialchars(APP_NAME) ?> <?= htmlspecialchars(APP_VERSION) ?><?php if (APP_DOCS_URL !== '') { ?> &nbsp; · &nbsp; <a target="_blank" rel="noopener" href="<?= htmlspecialchars(APP_DOCS_URL) ?>">Docs</a><?php } ?><?php if (APP_SOURCE_URL !== '') { ?> &nbsp; · &nbsp; <a target="_blank" rel="noopener" href="<?= htmlspecialchars(APP_SOURCE_URL) ?>">Source</a><?php } ?></p>
    <br>
<?php } ?>
<?php
if (basename(dirname($_SERVER['REQUEST_URI'])) === 'guest') { ?>
<p class="text-center">
    <?php echo nullable_htmlentities($session_company_name); ?>
</p>
<?php } ?>

<?php
/* ============================================================================
   TABLER SHELL - part 3 of 3. Closes everything the other two parts opened.

   *** THE NESTING-DEPTH INVARIANT ***

   This file closes FOUR structural levels below <body>, then </body></html>.
   That number - not the class names - is the contract. 209 files require this
   file; if the count ever stops matching what the header + wrapper opened,
   nothing errors, the layout just silently breaks on every one of them.

   The two shells that reach this file, and how they add up to four:

     AGENT / ADMIN  (includes/header.php + includes/inc_wrapper.php)
       1  <div class="page">              header.php
       2  <div class="page-wrapper">      inc_wrapper.php
       3  <div class="page-body">         inc_wrapper.php
       4  <div class="container-xl">      inc_wrapper.php

     GUEST          (guest/includes/guest_header.php + guest/includes/inc_wrapper.php)
       1  <div class="page">              guest_header.php
       2  <div class="page-wrapper">      guest/includes/inc_wrapper.php
       3  <div class="page-body">         guest/includes/inc_wrapper.php
       4  <div class="container">         guest/includes/inc_wrapper.php
                                          ^^^^^^^^^^ NOTE: the guest wrapper opens
                                          .container, NOT .container-xl. Different
                                          class, SAME depth - which is the only
                                          thing this file cares about. Both are a
                                          plain <div>, so the four </div>s below
                                          close both shells correctly.

   Everything else in the shell is internally balanced and contributes no depth:
   every side_nav (<aside> ... </aside>), and the horizontal top navbar, which
   includes/top_nav.php buffers and includes/inc_wrapper.php flushes inside
   .page-wrapper.

   (Previously these four were container-fluid / app-content / app-main /
   app-wrapper from AdminLTE 4. Same depth, different names.)
   ============================================================================ */
?>
</div><!-- /.container-xl (guest: /.container - same depth, see note above) -->
</div><!-- /.page-body -->
</div><!-- /.page-wrapper -->
</div><!-- /.page -->

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
window.RIVETIT_UPLOAD_LIMITS = <?php echo json_encode(['file' => ini_get('upload_max_filesize'), 'request' => ini_get('post_max_size')]); ?>;
</script>

<!-- REQUIRED SCRIPTS -->

<!-- All deferred: fetched in parallel without blocking the parser, still execute in
     document order right before DOMContentLoaded - the same guarantee plain blocking
     <script> tags gave, just without forcing 2.5MB of JS to download+run serially on
     every page load. Safe because every consumer already gates its real work behind
     DOMContentLoaded or a later event/click handler (app.js, ajax_modal.js) - the only
     exceptions were dashboard.php/rmm_dashboard.php's Chart.js init blocks, which ran
     immediately at parse time and have been wrapped in DOMContentLoaded listeners to match. -->

<!-- Bootstrap 5 (bundle includes Popper).
     KEPT DELIBERATELY. Tabler ships plugins/tabler/js/tabler.min.js, which is a
     Bootstrap re-implementation exporting window.tabler (NOT window.bootstrap) and
     which self-wires the data-bs-toggle data-api at load. This app has 22 existing
     window.bootstrap.* call sites, so tabler.min.js is NOT loaded - shipping both
     would double-wire every dropdown, tab and dismiss. Only Tabler's CSS is used. -->
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

<!-- plugins/adminlte4/js/adminlte.min.js is GONE. Nothing outside plugins/ ever
     called the adminlte.* JS API; only PushMenu (sidebar toggle) and Treeview
     (submenu expand) were ever exercised, and both are reimplemented in
     js/shell.js, which is loaded by the versioned first-party loop below. -->
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">window.csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;window.PHONE_FORMAT_DEFAULT_COUNTRY_CODE = <?= json_encode($config_phone_default_country_code ?? '1') ?>;</script>
<?php
// Cache-bust first-party JS on every edit (falls back to the request time if the
// file is somehow missing) so a stale Cloudflare/browser cache can't keep serving
// an old copy after a deploy - static assets otherwise have no way to know they changed.
// date_filter.js is intentionally dropped: its litepicker replacement now lives in app.js.
// shell.js is first in the list: it owns the sidebar toggle + treeview behaviour that
// used to come from adminlte.min.js, and nothing else in the list depends on it, so
// wiring the chrome before the page-level scripts run is the sane order. All entries are
// deferred, so list order IS execution order.
// asset_metrics.js is global but inert: it does nothing unless the page contains a
// [data-asset-metrics] element, which only agent/asset_details.php emits. It is last
// because it reads the Chart.defaults that chart_theme.js sets, and it must also land
// after plugins/chart.js above - both hold with defer, which preserves document order.
foreach (['shell.js', 'chart_theme.js', 'app.js', 'file_upload.js', 'ajax_modal.js', 'confirm_modal.js', 'asset_metrics.js', 'phone_format.js', 'icon_picker.js'] as $__asset) {
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

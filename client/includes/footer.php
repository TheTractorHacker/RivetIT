<?php
/*
 * Client Portal
 * HTML Footer
 *
 * TABLER SHELL - part 2 of 2 for the client portal.
 *
 * *** THE NESTING-DEPTH INVARIANT ***
 *
 * This file closes FOUR structural levels below <body>, then </body></html>.
 * The COUNT, not the class names, is the contract. client/includes/header.php
 * opens exactly those four:
 *
 *   1  <div class="page">          client/includes/header.php
 *   2  <div class="page-wrapper">  client/includes/header.php
 *   3  <div class="page-body">     client/includes/header.php
 *   4  <div class="container">     client/includes/header.php
 *                                  ^^^^^^^^^ .container, not .container-xl -
 *                                  the portal keeps its centred, capped reading
 *                                  width. Different class, same depth, which is
 *                                  the only thing this file cares about.
 *
 * The navbar in the header is internally balanced and contributes no depth.
 *
 * The portal's 23 pages do NOT use /includes/footer.php - they require this
 * file (as "includes/footer.php", relative to client/). This header/footer pair
 * is therefore self-contained: change the depth in one and you must change it
 * in the other, in the same commit. Nothing would error; the layout would just
 * silently break on all 23 pages.
 *
 * Previously this file closed only ONE div and never emitted </body> or </html>
 * at all, because the old header emitted no <body> tag. Both are fixed.
 */
?>

</div><!-- /.container -->
</div><!-- /.page-body -->

<footer class="footer footer-transparent d-print-none">
    <div class="container">
        <hr class="mt-0">
        <p class="text-center mb-0">
            <?php echo nullable_htmlentities($session_company_name); ?>
        </p>
    </div>
</footer>

</div><!-- /.page-wrapper -->
</div><!-- /.page -->


<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_confirm_modal.php'; ?>

<!-- jQuery (kept as a coexistence shim for un-ported inline $() calls) -->
<script src="/plugins/jquery/jquery.min.js"></script>

<!-- Bootstrap 5 (bundle includes Popper).
     KEPT DELIBERATELY. plugins/tabler/js/tabler.min.js is a Bootstrap
     re-implementation exporting window.tabler (NOT window.bootstrap) which
     self-wires the data-bs-toggle data-api at load, so shipping it alongside
     this bundle would double-wire every dropdown, collapse and dismiss on the
     page - including the portal navbar's toggler and its two dropdowns. Only
     Tabler's CSS is used. -->
<script src="/plugins/bootstrap5/js/bootstrap.bundle.min.js"></script>

<!--- TinyMCE -->
<script src="/plugins/tinymce/tinymce.min.js" referrerpolicy="origin"></script>

<script>
    
    // Initialize TinyMCE
    tinymce.init({
        selector: '.tinymce',
        browser_spellcheck: true,
        resize: true,
        min_height: 300,
        max_height: 600,
        promotion: false,
        branding: false,
        menubar: false,
        statusbar: false,
        license_key: 'gpl',
        toolbar: [
            { name: 'styles', items: [ 'styles' ] },
            { name: 'formatting', items: [ 'bold', 'italic', 'forecolor' ] },
            { name: 'lists', items: [ 'bullist', 'numlist' ] },
            { name: 'alignment', items: [ 'alignleft', 'aligncenter', 'alignright', 'alignjustify' ] },
            { name: 'indentation', items: [ 'outdent', 'indent' ] },
            { name: 'table', items: [ 'table' ] },
            { name: 'extra', items: [ 'fullscreen' ] }
        ],
        mobile: {
        menubar: false,
        plugins: 'autosave lists autolink',
        toolbar: 'undo bold italic styles',
    },
        plugins: 'link image lists table code codesample fullscreen autoresize',
    });

</script>

<script src="/js/pretty_content.js"></script>

<script src="/js/confirm_modal.js"></script>

<script src="/js/keepalive.js"></script>

</body>
</html>

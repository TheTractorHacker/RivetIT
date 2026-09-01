<?php
/*
 * Client Portal
 * HTML Footer
 */
?>

<!-- Close container -->
</div>

<br>
<hr>

<p class="text-center">
    <?php echo nullable_htmlentities($session_company_name); ?>
</p>


<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_confirm_modal.php'; ?>

<!-- jQuery (kept as a coexistence shim for un-ported inline $() calls) -->
<script src="/plugins/jquery/jquery.min.js"></script>

<!-- Bootstrap 5 (bundle includes Popper) -->
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

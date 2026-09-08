/*
 * TinyMCE configuration for the CLIENT PORTAL (client/ticket.php and
 * client/ticket_add.php are the only two pages with a .tinymce textarea).
 *
 * Lifted verbatim out of an inline <script> in client/includes/footer.php. It
 * had to move: the portal sends "default-src 'self'" with no nonce, so an inline
 * script is refused outright. It only ever ran at all because the two pages that
 * needed it were the two missing the CSP header - which is now fixed in
 * client/includes/inc_all.php, and would have silently taken the editor with it.
 *
 * The agent side has its own TinyMCE setup and does not load this file.
 */
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

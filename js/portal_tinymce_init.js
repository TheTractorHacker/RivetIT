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
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NO INTERACTIVE-KB PLUGIN HERE, AND THAT IS DELIBERATE
 * ─────────────────────────────────────────────────────────────────────────────
 * js/app.js's `.tinymce` init loads js/tinymce_ikb.js through external_plugins,
 * which gives KB authors the Interactive menu and the structural guard. This
 * init does NOT, and must not gain it:
 *
 *   * The only two .tinymce textareas on the portal are client/ticket.php and
 *     client/ticket_add.php - a ticket reply and a new ticket. Departments do
 *     not author knowledge-base articles; agents do.
 *   * A block authored here would be written to tickets.ticket_details /
 *     ticket_replies, which are NOT purified with the interactive vocabulary
 *     registered (\ITFlow\KB\InteractiveBlocks::apply() is called at five
 *     sites and nothing else: the four KB renderers - agent/kb_article.php,
 *     client/kb_article.php, agent/modals/kb_article/kb_article_version_view.php,
 *     api/v1/kb.php - plus the HTML-import save path,
 *     agent/post/kb_article.php:808, which applies it to attacker-supplied
 *     HTML rather than to a render). It would be flattened to prose on save,
 *     with no error - a button that quietly does nothing.
 *   * The plugin's own isKbEditor() would refuse to register the menu here
 *     anyway, so the only thing loading it would achieve is one more request.
 *
 * If a portal-side KB editor ever exists, the hook is already there: give its
 * textarea the class `tinymce-ikb` and add external_plugins to THAT init.
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

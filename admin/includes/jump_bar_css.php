<?php
// Shared look of the sticky jump bars (page_index.php = heading based, section_index.php = card based).
?>
<style nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
.page-index{position:sticky;top:0;z-index:20;display:flex;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:thin;gap:.35rem;align-items:center;margin:0 0 1rem;padding:.5rem .75rem;background:var(--tblr-bg-surface,#fff);border:1px solid var(--tblr-border-color,#dee2e6);border-radius:.5rem;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.page-index-label{font-size:.7rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--tblr-secondary,#6c757d);margin-right:.25rem;white-space:nowrap}
.page-index a{flex:0 0 auto;display:inline-block;padding:.15rem .65rem;border-radius:999px;border:1px solid var(--tblr-border-color,#dee2e6);font-size:.8rem;text-decoration:none;white-space:nowrap;color:inherit}
.page-index a:hover,.page-index a:focus-visible{background:var(--tblr-primary,#206bc4);border-color:var(--tblr-primary,#206bc4);color:#fff}
.page-index a.active{background:var(--tblr-primary-lt,#e9f0fa);border-color:var(--tblr-primary,#206bc4);font-weight:600}
</style>

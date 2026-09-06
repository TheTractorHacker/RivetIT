<?php
/* ---------------------------------------------------------------------------
   TABLER SHELL - part 2 of 3. Opens THREE wrappers. Closes NOTHING.

     .page-wrapper  >  .page-body  >  .container-xl

   Combined with .page (opened in includes/header.php) that is four structural
   levels below <body>, which is exactly what includes/footer.php closes and
   exactly the depth the old AdminLTE shell had
   (app-wrapper / app-main / app-content / container-fluid).

   guest/includes/inc_wrapper.php is the guest-portal twin of this file. It opens
   the same three levels but uses .container instead of .container-xl, and it
   shares this same includes/footer.php. Agent/admin and guest MUST stay the same
   depth.

   .container-xl is capped at 1140px/1320px by Tabler; includes/header.php sets
   html[data-bs-layout="fluid"], which is Tabler's own switch for
   "[class^=container-] { max-width: 100% }", restoring the full-bleed width the
   old .container-fluid gave. Nothing here needs to change to flip that.

   ---- The top navbar ----
   includes/top_nav.php is required BEFORE the side_nav include and before this
   file (all six inc_all*.php bootstraps do header -> top_nav -> side_nav ->
   inc_wrapper), but the horizontal navbar must NOT be emitted at that point,
   because Tabler 1.5 hides a vertical sidebar whenever a horizontal
   [class*=navbar-expand] is a sibling DIRECT CHILD of .page:

     html:not([data-bs-navbar-position=vertical])
       .page:has(> [class*=navbar-expand]:not(.navbar-vertical)) > .navbar-vertical
         { display: none }

   So top_nav.php buffers its markup into $itflow_top_nav_html and this file
   flushes it here, inside .page-wrapper, where that selector cannot reach it and
   where it also inherits .page-wrapper's margin-inline-start:var(--tblr-sidebar-width)
   for free (from .navbar-vertical ~ .page-wrapper) instead of sliding under the
   fixed sidebar.

   The flushed markup is internally balanced, so this file still opens exactly
   three levels and closes none.
   --------------------------------------------------------------------------- */
?>
<div class="page-wrapper">
<?php
// Emit the buffered top navbar (see above). Guarded: pages that include this
// wrapper without includes/top_nav.php simply render no top navbar, which is the
// same behaviour they had before.
if (!empty($itflow_top_nav_html)) {
    echo $itflow_top_nav_html;
    $itflow_top_nav_html = '';
}
?>
    <div class="page-body">
        <div class="container-xl">

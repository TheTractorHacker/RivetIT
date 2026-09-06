<?php
/* ---------------------------------------------------------------------------
   TABLER SHELL - part 2 of 3 for the guest portal. Opens THREE wrappers.
   Closes NOTHING.

     .page-wrapper  >  .page-body  >  .container

   Combined with .page (opened in guest/includes/guest_header.php) that is FOUR
   structural levels below <body>, which is exactly what the SHARED
   /includes/footer.php closes - every guest/*.php page requires that footer at
   the end, the same file the 209-page agent/admin surface requires.

   *** THE NESTING-DEPTH INVARIANT ***
   includes/inc_wrapper.php is the agent/admin twin of this file. It opens the
   same three levels but ends in .container-xl instead of .container. Different
   class, SAME depth - and depth is the only thing the shared footer can see,
   because all four are plain <div>s. Agent and guest MUST stay the same depth:
   change one without the other (and without includes/footer.php) and both
   surfaces render wrong with no error anywhere.

   Why .container and not .container-xl: the guest surface is a set of
   documents shown to external recipients (invoices, quotes, tickets,
   signature capture). It has always used the narrower centred .container and
   keeps it. The agent shell additionally sets html[data-bs-layout="fluid"] to
   uncap its container; guest_header.php deliberately does not, so .container
   keeps Bootstrap's normal breakpoint max-widths here.

   There is no top navbar and no sidebar on guest pages, so unlike
   includes/inc_wrapper.php this file has no buffered navbar to flush - and
   .page-wrapper picks up no sidebar margin, since Tabler only applies
   margin-inline-start to a .page-wrapper preceded by a .navbar-vertical sibling.

   (Previously: <main class="app-main"> > .app-content > .container, from
    AdminLTE 4. Same depth, different names.)
   --------------------------------------------------------------------------- */
?>
<div class="page-wrapper">
    <div class="page-body">
        <div class="container">

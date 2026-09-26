<?php

/*
 * Moved: Training compliance and the Odoo employee links are sections of the one Admin > Training
 * page since 2026-09-26. This URL stays so bookmarks, old notifications and links keep working.
 * The target page does the login and admin checks; this file reads nothing.
 *
 * Known one-time cost: before the move, the notifications the app stored with this URL were all
 * about Odoo employee links ("N Odoo employee links need review", "Nightly Odoo directory sync
 * failed"), but a redirect cannot tell them from a bookmark (browsers never send the #fragment),
 * so they open at #compliance, one section above #odoo. Notifications made since point straight
 * at settings_training.php#odoo. A tab left open on the old page lands the same way after a post.
 */

header('Location: /admin/settings_training.php#compliance', true, 302);
exit;

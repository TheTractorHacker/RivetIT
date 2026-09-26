<?php

/*
 * Moved: Training compliance and the Odoo employee links are sections of the one Admin > Training
 * page since 2026-09-26. This URL stays so bookmarks, old notifications and links keep working.
 * The target page does the login and admin checks; this file reads nothing.
 */

header('Location: /admin/settings_training.php#compliance', true, 302);
exit;

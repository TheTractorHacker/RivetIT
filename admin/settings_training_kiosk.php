<?php

/*
 * Moved: the Training kiosk settings are the Kiosk & sign-in section of the one Admin > Training
 * page since 2026-09-26. This URL stays so bookmarks and links keep working. The target page
 * does the login and admin checks; this file reads nothing.
 */

header('Location: /admin/settings_training.php#kiosk', true, 302);
exit;

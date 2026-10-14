<?php
// The ManageEngine MDM settings moved to Admin > Integrations > Device Sync. Old bookmarks and links land there.
header('Location: /admin/settings_integrations.php?tab=devicesync', true, 302);
exit;

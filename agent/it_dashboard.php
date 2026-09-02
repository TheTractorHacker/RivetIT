<?php
// Redirect to the main dashboard - its onboarding/offboarding and asset
// widgets were folded in there so there's one dashboard, not two.
// Keeping this file so existing bookmarks or deep-links don't 404.
header('Location: /agent/dashboard.php');
exit;

<?php
// Prints encryptSetting($argv[1]) using the app's real crypto and the config.php of this checkout (scratch databases only).
ob_start();
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';
ob_end_clean();
echo encryptSetting((string) ($argv[1] ?? ''));

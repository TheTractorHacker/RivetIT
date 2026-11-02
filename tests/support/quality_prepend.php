<?php
// auto_prepend_file for the TEST web server only (tests/quality_module.php): lets the Quality client talk http to the mock QMS on 127.0.0.1.
// Never configured on a real server; nothing in the application defines this constant.
if (!defined('RIVETIT_QMS_TEST_LOOPBACK') && getenv('QUALITY_TEST_LOOPBACK') === '1') {
    define('RIVETIT_QMS_TEST_LOOPBACK', true);
}

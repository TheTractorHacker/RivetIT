<?php
// Bootstrap for the RivetCore adapter tests. PHPUnit is not a RivetIT runtime dependency, so point at a
// RivetCore checkout that has run `composer install` (it carries PHPUnit):
//   RIVETCORE_PHPUNIT_AUTOLOAD=/path/to/rivet-core/vendor/autoload.php
// and a scratch MySQL/MariaDB: RIVETCORE_TEST_DB_{NAME,USER,PASS,HOST}. Never point at production.
$phpunit = getenv('RIVETCORE_PHPUNIT_AUTOLOAD');
if ($phpunit && is_file($phpunit)) {
    require $phpunit;
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

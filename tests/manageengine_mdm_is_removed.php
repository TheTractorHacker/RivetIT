<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Regression: the live API sends is_removed as the string "false". No database, no network:  php tests/manageengine_mdm_is_removed.php */
foreach (['MdmDeviceMapper'] as $c) { require_once __DIR__ . "/../src/Integrations/ManageEngine/$c.php"; }
use ITFlow\Integrations\ManageEngine\MdmDeviceMapper;
$fail = 0;
$t = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) { $fail++; } };
$base = ['device_id' => '9001', 'serial_number' => 'SN1', 'platform_type' => 'ios', 'device_name' => 'iPad 1'];
foreach (['"false" (what the API sends)' => 'false', 'bool false' => false, '"0"' => '0', 'missing' => null] as $label => $v) {
    $row = $base + ($v === null ? [] : ['is_removed' => $v]);
    $t(MdmDeviceMapper::normalize($row)['enrollment_status'] === 'enrolled', "is_removed $label: device stays enrolled");
}
foreach (['"true"' => 'true', 'bool true' => true, '"1"' => '1'] as $label => $v) {
    $t(MdmDeviceMapper::normalize($base + ['is_removed' => $v])['enrollment_status'] === 'removed', "is_removed $label: device is removed");
}
$svc = file_get_contents(__DIR__ . '/../src/Integrations/ManageEngine/MdmSyncService.php');
$t(!preg_match('/!empty\(\$row\[.is_removed.\]\)/', $svc) && !preg_match('/!empty\(\$list\[.is_removed.\]\)/', file_get_contents(__DIR__ . '/../src/Integrations/ManageEngine/MdmDeviceMapper.php')), 'no empty() test on is_removed remains');
exit($fail ? 1 : 0);

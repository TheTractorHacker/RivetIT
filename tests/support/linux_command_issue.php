<?php
/*
 * Helper of tests/linux_command_container.sh (scratch database only): hosts the Linux files of a directory (install-linux.sh, rivetit-agent-linux-amd64, ...) as the
 * current ones, makes an enrollment token and writes the Linux install command the Add device page would give to the file named by argv[2].
 *   php tests/support/linux_command_issue.php <dir with the files> <output file> <version> <service url>
 */
if (PHP_SAPI !== 'cli' || getenv('RIVETIT_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "scratch database only\n"); exit(2); }
[, $dir, $out, $version, $service] = $argv + [null, null, null, null, null];
require __DIR__ . '/../endpoint_agent_lib.php';
require_once "$root/includes/rmm_build_host.php";
\ITFlow\EndpointAgent\Config::enable();
\ITFlow\EndpointAgent\Config::set(['service_url' => $service]);
foreach (rivetLxNames() as $n) {
    if (is_file("$dir/$n")) {
        $r = rivetLxStore($db, "$dir/$n", $n, $version, 1, ['activate' => true, 'source' => 'upload']);
        fwrite(STDERR, ($r['ok'] ? 'hosted ' : 'REFUSED ') . $n . ($r['ok'] ? '' : ': ' . $r['error']) . "\n");
    }
}
$tok = ea_token(1, 24, 5);
$core = \RivetCore\Rmm\Installer\InstallerService::linuxSnippet($service, $tok, 'amd64', 'Container test', null, null);
$c = rivetLxCommand($db, $core, 'amd64');
if ($c === null) { fwrite(STDERR, "no command\n"); exit(1); }
file_put_contents($out, $c['command']);
echo json_encode(['token_selector' => explode('.', $tok)[1], 'secret' => explode('.', $tok)[2]]) . "\n";

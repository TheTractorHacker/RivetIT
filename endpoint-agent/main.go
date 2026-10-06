// Command rivetit-agent is the RivetIT Windows endpoint agent.
package main

import (
	"fmt"
	"os"
	"runtime"
)

// Set at build time via -ldflags "-X main.version=... -X main.commit=...".
var (
	version = "0.0.0-dev"
	commit  = "unknown"
)

const usage = `rivetit-agent %s

Usage: rivetit-agent <command> [flags]

Commands:
  run         run in the foreground (also the entry point of the Windows service)
  install     enroll this endpoint and install + start the Windows service (Windows only)
  uninstall   stop and remove the service and binaries   [--purge] [--remove-meshagent]
  enroll      exchange an enrollment token for a device credential
  rotate      re-enroll with a new token, rotating the device credential (same identity)
  status      show enrollment/health state (no network traffic)
  version     print the version
  selftest    verify the binary starts (used by the updater)

Common flags: --state-dir DIR (default on Windows: %%ProgramData%%\RivetIT\Agent; required elsewhere)
Run "rivetit-agent <command> -h" for command flags.
`

func main() {
	if len(os.Args) < 2 {
		fmt.Fprintf(os.Stderr, usage, version)
		os.Exit(2)
	}
	cmd, args := os.Args[1], os.Args[2:]
	var code int
	switch cmd {
	case "run":
		code = cmdRun(args)
	case "enroll":
		code = cmdEnroll(args, false)
	case "rotate":
		code = cmdEnroll(args, true)
	case "status":
		code = cmdStatus(args)
	case "install":
		code = cmdInstall(args)
	case "uninstall":
		code = cmdUninstall(args)
	case "version", "--version", "-v":
		fmt.Printf("rivetit-agent %s (%s) %s/%s\n", version, commit, runtime.GOOS, runtime.GOARCH)
	case "selftest":
		fmt.Printf("rivetit-agent selftest ok version=%s os=%s arch=%s\n", version, runtime.GOOS, runtime.GOARCH)
	case "-h", "--help", "help":
		fmt.Printf(usage, version)
	default:
		fmt.Fprintf(os.Stderr, "unknown command %q\n\n", cmd)
		fmt.Fprintf(os.Stderr, usage, version)
		code = 2
	}
	os.Exit(code)
}

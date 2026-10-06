//go:build !windows

package main

import (
	"flag"
	"fmt"
	"os"
)

func cmdInstall(args []string) int {
	fmt.Fprintln(os.Stderr, "install is Windows-only. On Linux (test mode) use: rivetit-agent enroll --state-dir DIR ... && rivetit-agent run --state-dir DIR")
	return 1
}

// cmdUninstall on Linux only supports removing the state directory.
func cmdUninstall(args []string) int {
	fs, dir := newFlags("uninstall")
	purge := fs.Bool("purge", false, "delete the state directory")
	if err := fs.Parse(args); err != nil && err != flag.ErrHelp {
		return 2
	}
	if !*purge {
		fmt.Fprintln(os.Stderr, "nothing to do on this OS (service removal is Windows-only); use --purge to delete the state directory")
		return 0
	}
	if *dir == "" {
		fmt.Fprintln(os.Stderr, "error: --state-dir is required")
		return 1
	}
	if err := os.RemoveAll(*dir); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	fmt.Println("state directory removed:", *dir)
	return 0
}

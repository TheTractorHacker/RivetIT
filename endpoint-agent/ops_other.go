//go:build !windows

package main

import (
	"fmt"
	"os"

	"rivetit-agent/internal/svc"
)

// realOps on Linux (test platform): no elevation concept, no services, no
// message boxes. `setup --no-service` is the supported Linux test mode.
func realOps() sysOps {
	return sysOps{
		isElevated:   func() bool { return true },
		elevate:      func([]string) (int, error) { return 0, fmt.Errorf("elevation is Windows-only") },
		executable:   os.Executable,
		stopService:  svc.StopService,
		installSvc:   svc.InstallService,
		startService: svc.StartService,
		registerARP:  func(string, string) error { return nil },
		removeARP:    func() error { return nil },
		message:      func(string, string, bool) {},
	}
}

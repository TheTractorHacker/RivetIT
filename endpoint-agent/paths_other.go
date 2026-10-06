//go:build !windows

package main

import "os"

// Linux is a test platform: the state dir must be given explicitly (flag or
// RIVETIT_AGENT_STATE_DIR) so the agent never writes somewhere unexpected.
func defaultStateDir() string { return os.Getenv("RIVETIT_AGENT_STATE_DIR") }

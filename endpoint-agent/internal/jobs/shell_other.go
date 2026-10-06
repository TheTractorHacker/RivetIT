//go:build !windows

package jobs

import (
	"errors"
	"os"
)

// BuildScriptCommand on non-Windows exists so the agent can be exercised on a
// Linux scratch box. It is refused unless RIVETIT_AGENT_TESTMODE=1 so a Linux
// build can never be coerced into running server scripts in production.
func BuildScriptCommand(script string, params []byte) (name string, args []string, env []string, err error) {
	if os.Getenv("RIVETIT_AGENT_TESTMODE") != "1" {
		return "", nil, nil, errors.New("script execution is only supported on Windows (set RIVETIT_AGENT_TESTMODE=1 for Linux test mode)")
	}
	if len(params) == 0 {
		params = []byte("null")
	}
	return "/bin/sh", []string{"-c", script}, []string{"RIVETIT_JOB_PARAMS=" + string(params)}, nil
}

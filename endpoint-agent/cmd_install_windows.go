//go:build windows

// UNVERIFIED on real Windows: compiled and vetted only.
package main

import (
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"time"

	"rivetit-agent/internal/store"
	"rivetit-agent/internal/svc"
)

const exeName = "rivetit-agent.exe"

func copyFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	tmp := dst + ".tmp"
	out, err := os.OpenFile(tmp, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o755)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		os.Remove(tmp)
		return err
	}
	if err := out.Close(); err != nil {
		return err
	}
	_ = os.Remove(dst + ".old")
	if _, err := os.Stat(dst); err == nil {
		if err := os.Rename(dst, dst+".old"); err != nil {
			os.Remove(tmp)
			return fmt.Errorf("cannot replace the installed binary (service running? stop it first): %w", err)
		}
	}
	return os.Rename(tmp, dst)
}

func cmdInstall(args []string) int {
	fs, dir := newFlags("install")
	var f enrollFlags
	fs.StringVar(&f.server, "server", "", "RivetIT base URL")
	fs.StringVar(&f.token, "token", "", "enrollment token (prefer --token-file or RIVETIT_ENROLL_TOKEN)")
	fs.StringVar(&f.tokenFile, "token-file", "", "file containing the enrollment token")
	fs.StringVar(&f.ca, "ca", "", "extra CA certificate (PEM)")
	fs.StringVar(&f.pin, "pin-spki", "", "optional hex SHA-256 of the server SPKI")
	fs.StringVar(&f.department, "department", "", "informational department label")
	installDir := fs.String("install-dir", defaultInstallDir(), "binary directory")
	level := fs.String("log-level", "info", "log level")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	st, err := openStore(*dir)
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	tok, _ := readToken(f.token, f.tokenFile)
	if err := applyConfig(st, f); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	// 1. enroll (idempotent: skipped when already enrolled and no new token)
	existing, _ := st.LoadToken()
	switch {
	case tok != "":
		code := enrollNow(st, tok, *level, existing != "")
		if code == 3 { // permanent rejection: fail the install visibly
			return code
		}
		if code != 0 {
			// Offline at install time (imaging/GPO): keep the one-shot token,
			// protected, and let the service finish enrollment.
			fmt.Fprintln(os.Stderr, "warning: could not enroll now; the service will retry using the stored one-shot token")
			if err := st.SaveEnrollToken(tok); err != nil {
				fmt.Fprintln(os.Stderr, "error:", err)
				return 1
			}
		}
	case existing == "":
		fmt.Fprintln(os.Stderr, "error: an enrollment token is required for a first install")
		return 2
	}
	// 2. binary
	self, err := os.Executable()
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	if err := os.MkdirAll(*installDir, 0o755); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	dst := filepath.Join(*installDir, exeName)
	if filepath.Clean(self) != filepath.Clean(dst) {
		if err := svc.StopService(); err != nil {
			fmt.Fprintln(os.Stderr, "error: stopping existing service:", err)
			return 1
		}
		if err := copyFile(self, dst); err != nil {
			fmt.Fprintln(os.Stderr, "error: installing binary:", err)
			return 1
		}
	}
	// 3. service
	if err := svc.InstallService(dst, []string{"run", "--state-dir", *dir}); err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		return 1
	}
	if err := svc.StartService(); err != nil {
		fmt.Fprintln(os.Stderr, "error: starting service:", err)
		return 1
	}
	fmt.Println("RivetIT Agent installed and started")
	return printStatus(st)
}

func cmdUninstall(args []string) int {
	fs, dir := newFlags("uninstall")
	purge := fs.Bool("purge", false, "also delete local state (config, credential, buffers)")
	meshAgent := fs.Bool("remove-meshagent", false, "ALSO uninstall a separately managed MeshCentral agent (default: leave it untouched)")
	installDir := fs.String("install-dir", defaultInstallDir(), "binary directory")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	if err := svc.RemoveService(); err != nil {
		fmt.Fprintln(os.Stderr, "error: removing service:", err)
		return 1
	}
	fmt.Println("service stopped and removed")
	if *meshAgent {
		removeMeshAgent()
	} else {
		fmt.Println("MeshCentral agent (if any) left untouched; use --remove-meshagent to remove it")
	}
	if *purge {
		if err := os.RemoveAll(*dir); err != nil {
			fmt.Fprintln(os.Stderr, "warning: purging state:", err)
		} else {
			fmt.Println("local state purged")
		}
	} else {
		fmt.Println("local state kept in", *dir, "(use --purge to delete)")
	}
	removeInstallDir(*installDir)
	return 0
}

// removeInstallDir deletes only the agent's own binaries. The running exe
// cannot delete itself, so a detached cmd.exe finishes the job after exit.
func removeInstallDir(dir string) {
	if dir == "" {
		return
	}
	for _, n := range []string{exeName + ".prev", exeName + ".new", exeName + ".failed", exeName + ".old"} {
		_ = os.Remove(filepath.Join(dir, n))
	}
	self, _ := os.Executable()
	if filepath.Dir(self) != filepath.Clean(dir) {
		_ = os.Remove(filepath.Join(dir, exeName))
		_ = os.Remove(dir) // only succeeds when empty
		return
	}
	cmdline := fmt.Sprintf(`/c ping -n 4 127.0.0.1 >nul & del /f /q "%s" & rmdir "%s"`, self, dir)
	c := exec.Command(filepath.Join(os.Getenv("SystemRoot"), "System32", "cmd.exe"))
	c.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CmdLine: `cmd.exe ` + cmdline, CreationFlags: 0x00000008} // DETACHED_PROCESS
	_ = c.Start()
	fmt.Println("agent binary scheduled for deletion")
}

// removeMeshAgent runs the MeshAgent's own uninstaller. The flag name is from
// MeshAgent documentation as summarised by search; it was NOT run here.
func removeMeshAgent() {
	root := os.Getenv("ProgramFiles")
	exe := filepath.Join(root, "Mesh Agent", "MeshAgent.exe")
	if _, err := os.Stat(exe); err != nil {
		fmt.Println("no MeshCentral agent found at", exe)
		return
	}
	c := exec.Command(exe, "-fulluninstall")
	done := make(chan error, 1)
	go func() { done <- c.Run() }()
	select {
	case err := <-done:
		if err != nil {
			fmt.Fprintln(os.Stderr, "warning: MeshAgent uninstall returned:", err)
		} else {
			fmt.Println("MeshCentral agent uninstalled (as requested)")
		}
	case <-time.After(60 * time.Second):
		_ = c.Process.Kill()
		fmt.Fprintln(os.Stderr, "warning: MeshAgent uninstall timed out")
	}
}

var _ = errors.New
var _ = store.Config{}

//go:build linux

package collect

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"syscall"
)

// LinuxPlatform reads /proc and statfs. It exists so the agent can run
// end-to-end against a scratch server on a Linux box; it is not a supported
// production platform.
type LinuxPlatform struct{ Root string } // Root is "" in production; tests point it at a fake /proc tree.

func NewPlatform() Platform { return &LinuxPlatform{} }

func (p *LinuxPlatform) f(path string) string { return p.Root + path }

func (p *LinuxPlatform) readTrim(path string) (string, error) {
	b, err := os.ReadFile(p.f(path))
	return strings.TrimSpace(string(b)), err
}

func optStr(s string, err error) *string {
	if err != nil || s == "" || strings.EqualFold(s, "To Be Filled By O.E.M.") || s == "None" {
		return nil
	}
	return &s
}

func (p *LinuxPlatform) Identity(ctx context.Context) (Identity, error) {
	var id Identity
	id.MachineGUID = optStr(p.readTrim("/etc/machine-id"))
	id.Serial = optStr(p.readTrim("/sys/class/dmi/id/product_serial")) // usually root-only: null when unreadable
	id.Manufacturer = optStr(p.readTrim("/sys/class/dmi/id/sys_vendor"))
	id.Model = optStr(p.readTrim("/sys/class/dmi/id/product_name"))
	return id, nil
}

func (p *LinuxPlatform) OSInfo(ctx context.Context) (string, string, error) {
	b, err := os.ReadFile(p.f("/etc/os-release"))
	if err != nil {
		return "", "", err
	}
	var name, ver string
	for _, l := range strings.Split(string(b), "\n") {
		if v, ok := strings.CutPrefix(l, "PRETTY_NAME="); ok {
			name = strings.Trim(v, `"`)
		}
		if v, ok := strings.CutPrefix(l, "VERSION_ID="); ok {
			ver = strings.Trim(v, `"`)
		}
	}
	if name == "" {
		return "", "", errors.New("no PRETTY_NAME")
	}
	if ver != "" {
		name = name + " (" + ver + ")"
	}
	return "linux", name, nil
}

func (p *LinuxPlatform) CPUModel(ctx context.Context) (string, error) {
	f, err := os.Open(p.f("/proc/cpuinfo"))
	if err != nil {
		return "", err
	}
	defer f.Close()
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		if k, v, ok := strings.Cut(sc.Text(), ":"); ok && strings.TrimSpace(k) == "model name" {
			return strings.TrimSpace(v), nil
		}
	}
	return "", errors.New("model name not found")
}

func (p *LinuxPlatform) CPUTimes(ctx context.Context) (uint64, uint64, error) {
	b, err := os.ReadFile(p.f("/proc/stat"))
	if err != nil {
		return 0, 0, err
	}
	line, _, _ := strings.Cut(string(b), "\n")
	fs := strings.Fields(line)
	if len(fs) < 6 || fs[0] != "cpu" {
		return 0, 0, errors.New("unexpected /proc/stat")
	}
	var total, idle uint64
	for i, s := range fs[1:] {
		if i >= 8 { // user..steal; guest fields are already inside user
			break
		}
		v, err := strconv.ParseUint(s, 10, 64)
		if err != nil {
			return 0, 0, err
		}
		total += v
		if i == 3 || i == 4 { // idle + iowait
			idle += v
		}
	}
	return idle, total, nil
}

func (p *LinuxPlatform) Memory(ctx context.Context) (uint64, uint64, error) {
	b, err := os.ReadFile(p.f("/proc/meminfo"))
	if err != nil {
		return 0, 0, err
	}
	var total, avail uint64
	var haveT, haveA bool
	for _, l := range strings.Split(string(b), "\n") {
		k, v, ok := strings.Cut(l, ":")
		if !ok {
			continue
		}
		f := strings.Fields(v)
		if len(f) == 0 {
			continue
		}
		n, err := strconv.ParseUint(f[0], 10, 64)
		if err != nil {
			continue
		}
		switch k {
		case "MemTotal":
			total, haveT = n*1024, true
		case "MemAvailable":
			avail, haveA = n*1024, true
		}
	}
	if !haveT || !haveA {
		return 0, 0, errors.New("MemTotal/MemAvailable missing")
	}
	return total, avail, nil
}

var realFS = map[string]bool{"ext2": true, "ext3": true, "ext4": true, "xfs": true, "btrfs": true, "vfat": true, "ntfs": true, "ntfs3": true, "zfs": true, "f2fs": true, "exfat": true}

func (p *LinuxPlatform) Disks(ctx context.Context) ([]DiskStat, error) {
	f, err := os.Open(p.f("/proc/mounts"))
	if err != nil {
		return nil, err
	}
	defer f.Close()
	var out []DiskStat
	seen := map[string]bool{}
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fs := strings.Fields(sc.Text())
		if len(fs) < 3 || !realFS[fs[2]] || seen[fs[0]] {
			continue
		}
		seen[fs[0]] = true
		var st syscall.Statfs_t
		if err := syscall.Statfs(fs[1], &st); err != nil {
			continue
		}
		bs := uint64(st.Bsize)
		out = append(out, DiskStat{Mount: fs[1], Total: st.Blocks * bs, Free: st.Bavail * bs, FS: fs[2]})
	}
	return out, sc.Err()
}

func (p *LinuxPlatform) NetBytes(ctx context.Context) (uint64, uint64, error) {
	b, err := os.ReadFile(p.f("/proc/net/dev"))
	if err != nil {
		return 0, 0, err
	}
	var rx, tx uint64
	for _, l := range strings.Split(string(b), "\n")[2:] {
		name, rest, ok := strings.Cut(l, ":")
		if !ok || strings.TrimSpace(name) == "lo" {
			continue
		}
		f := strings.Fields(rest)
		if len(f) < 9 {
			continue
		}
		r, err1 := strconv.ParseUint(f[0], 10, 64)
		t, err2 := strconv.ParseUint(f[8], 10, 64)
		if err1 != nil || err2 != nil {
			continue
		}
		rx += r
		tx += t
	}
	return rx, tx, nil
}

func (p *LinuxPlatform) UptimeS(ctx context.Context) (uint64, error) {
	s, err := p.readTrim("/proc/uptime")
	if err != nil {
		return 0, err
	}
	f, err := strconv.ParseFloat(strings.Fields(s)[0], 64)
	return uint64(f), err
}

func (p *LinuxPlatform) LoggedInUser(ctx context.Context) (string, error) {
	return "", ErrUnsupported
}

func (p *LinuxPlatform) PendingReboot(ctx context.Context) (bool, []string, error) {
	if _, err := os.Stat(p.f("/var/run/reboot-required")); err == nil {
		return true, []string{"/var/run/reboot-required"}, nil
	}
	return false, nil, nil
}

func (p *LinuxPlatform) ServiceState(ctx context.Context, name string) (ServiceInfo, error) {
	if strings.ContainsAny(name, " \t\n/;&|$`") || strings.HasPrefix(name, "-") {
		return ServiceInfo{}, fmt.Errorf("invalid service name")
	}
	out, _ := exec.CommandContext(ctx, "systemctl", "show", "--property=ActiveState,LoadState,UnitFileState", name).Output()
	m := map[string]string{}
	for _, l := range strings.Split(string(out), "\n") {
		if k, v, ok := strings.Cut(l, "="); ok {
			m[k] = v
		}
	}
	if m["LoadState"] == "" {
		return ServiceInfo{}, errors.New("systemctl unavailable")
	}
	if m["LoadState"] == "not-found" {
		return ServiceInfo{}, ErrNotFound
	}
	st := map[string]string{"active": "running", "inactive": "stopped", "failed": "stopped", "activating": "start_pending", "deactivating": "stop_pending"}[m["ActiveState"]]
	if st == "" {
		st = m["ActiveState"]
	}
	su := "manual"
	switch m["UnitFileState"] {
	case "enabled":
		su = "automatic"
	case "disabled":
		su = "disabled"
	}
	return ServiceInfo{State: st, Startup: su}, nil
}

func (p *LinuxPlatform) MeshAgentDir() string {
	for _, d := range []string{"/usr/local/mesh_services/meshagent", "/opt/meshagent"} {
		if _, err := os.Stat(p.f(d)); err == nil {
			return d
		}
	}
	return ""
}

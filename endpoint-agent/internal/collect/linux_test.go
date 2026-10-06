//go:build linux

package collect

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func fakeRoot(t *testing.T) *LinuxPlatform {
	root := t.TempDir()
	w := func(p, s string) {
		full := filepath.Join(root, p)
		os.MkdirAll(filepath.Dir(full), 0o755)
		if err := os.WriteFile(full, []byte(s), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	w("proc/stat", "cpu  100 0 50 800 50 0 0 0 0 0\ncpu0 1 2 3\n")
	w("proc/meminfo", "MemTotal:       2048 kB\nMemFree: 1 kB\nMemAvailable:    512 kB\n")
	w("proc/net/dev", "Inter-|   Receive\n face |bytes\n  lo: 999 0 0 0 0 0 0 0 999 0\neth0: 1000 1 0 0 0 0 0 0 2000 1\n")
	w("proc/uptime", "123.45 99.0\n")
	w("etc/machine-id", "abc123\n")
	return &LinuxPlatform{Root: root + "/"}
}

func TestLinuxParsers(t *testing.T) {
	root := fakeRoot(t)
	p := &LinuxPlatform{Root: root.Root[:len(root.Root)-1]}
	ctx := context.Background()
	idle, total, err := p.CPUTimes(ctx)
	if err != nil || idle != 850 || total != 1000 {
		t.Fatalf("cpu %d %d %v", idle, total, err)
	}
	mt, ma, err := p.Memory(ctx)
	if err != nil || mt != 2048*1024 || ma != 512*1024 {
		t.Fatalf("mem %d %d %v", mt, ma, err)
	}
	rx, tx, err := p.NetBytes(ctx)
	if err != nil || rx != 1000 || tx != 2000 {
		t.Fatalf("net %d %d %v (loopback must be excluded)", rx, tx, err)
	}
	if u, err := p.UptimeS(ctx); err != nil || u != 123 {
		t.Fatalf("uptime %d %v", u, err)
	}
	id, _ := p.Identity(ctx)
	if id.MachineGUID == nil || *id.MachineGUID != "abc123" || id.Serial != nil {
		t.Fatalf("identity %+v", id)
	}
	if _, _, err := (&LinuxPlatform{Root: t.TempDir()}).CPUTimes(ctx); err == nil {
		t.Fatal("missing /proc must be an error, not zeros")
	}
}

func TestRealLinuxPlatformSmoke(t *testing.T) {
	p := NewPlatform()
	if _, tot, err := p.CPUTimes(context.Background()); err != nil || tot == 0 {
		t.Fatalf("%v", err)
	}
	if ds, err := p.Disks(context.Background()); err != nil || len(ds) == 0 {
		t.Fatalf("disks %v %v", ds, err)
	}
}

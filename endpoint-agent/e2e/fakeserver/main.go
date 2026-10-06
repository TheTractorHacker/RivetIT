// Command fakeserver is a minimal, contract-shaped RivetIT server for local
// end-to-end runs and footprint measurement. It is a TEST TOOL: it keeps
// everything in memory, accepts one fixed enrollment token and is not the
// real server.
package main

import (
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"flag"
	"fmt"
	"io"
	"log"
	"math/big"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"sync"
	"time"

	"rivetit-agent/internal/jobs"
)

type server struct {
	mu          sync.Mutex
	pub         ed25519.PublicKey
	priv        ed25519.PrivateKey
	token       string
	devTok      string
	interval    int
	collectIv   int
	jobScript   string
	jobQueued   bool
	jobDone     bool
	checkins    int
	bytesIn     int64
	bytesOut    int64
	lastSeq     float64
	revokeAfter int
	outFile     *os.File
}

func (s *server) logf(f string, a ...any) {
	line := fmt.Sprintf(f, a...)
	log.Println(line)
	if s.outFile != nil {
		fmt.Fprintln(s.outFile, line)
	}
}

func (s *server) writeJSON(w http.ResponseWriter, code int, v any) int {
	b, _ := json.Marshal(v)
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	w.Write(b)
	s.mu.Lock()
	s.bytesOut += int64(len(b))
	s.mu.Unlock()
	return len(b)
}

func (s *server) authed(r *http.Request) bool {
	return r.Header.Get("Authorization") == "Bearer "+s.devTok
}

func (s *server) enroll(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	var req struct {
		EnrollmentToken string `json:"enrollment_token"`
		Device          map[string]any
	}
	json.Unmarshal(body, &req)
	if req.EnrollmentToken != s.token {
		s.writeJSON(w, 401, map[string]any{"error": "invalid enrollment token", "code": "invalid_token"})
		return
	}
	s.logf("ENROLL install_id=%v host=%v os=%v/%v version=%v", req.Device["install_id"], req.Device["hostname"], req.Device["os"], req.Device["arch"], req.Device["agent_version"])
	s.mu.Lock()
	s.devTok = "dev_" + base64.RawURLEncoding.EncodeToString(randBytes(24))
	tok := s.devTok
	s.mu.Unlock()
	s.writeJSON(w, 201, map[string]any{
		"device_id": "fake-device-1", "device_token": tok, "check_in_interval_s": s.interval,
		"server_time": time.Now().UTC().Format(time.RFC3339), "status": "linked", "matched_asset_id": 1,
		"signing_public_key": base64.StdEncoding.EncodeToString(s.pub),
		"config": map[string]any{"collect_interval_s": s.collectIv, "checks": []any{
			map[string]any{"key": "disk_root", "type": "disk", "params": map[string]any{"mount": "/", "warn_free_pct": 20, "fail_free_pct": 10}, "interval_s": 60},
			map[string]any{"key": "reboot", "type": "pending_reboot", "params": map[string]any{}, "interval_s": 60},
		}},
	})
}

func randBytes(n int) []byte { b := make([]byte, n); rand.Read(b); return b }

func (s *server) checkin(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	if !s.authed(r) {
		s.writeJSON(w, 401, map[string]any{"error": "bad token", "code": "invalid_token"})
		return
	}
	var req map[string]any
	json.Unmarshal(body, &req)
	s.mu.Lock()
	s.checkins++
	n := s.checkins
	s.bytesIn += int64(len(body))
	seq, _ := req["seq"].(float64)
	s.lastSeq = seq
	buffered, _ := req["buffered"].([]any)
	hasInv := req["inventory"] != nil
	revoke := s.revokeAfter > 0 && n > s.revokeAfter
	pending := 0
	if s.jobQueued && !s.jobDone {
		pending = 1
	}
	s.mu.Unlock()
	if revoke {
		s.writeJSON(w, 401, map[string]any{"error": "revoked", "code": "revoked"})
		s.logf("CHECKIN #%d REVOKED", n)
		return
	}
	out := s.writeJSON(w, 200, map[string]any{"ok": true, "next_check_in_s": s.interval, "jobs_pending": pending,
		"server_time": time.Now().UTC().Format(time.RFC3339), "update": nil,
		"config": map[string]any{"collect_interval_s": s.collectIv, "checks": []any{
			map[string]any{"key": "disk_root", "type": "disk", "params": map[string]any{"mount": "/"}, "interval_s": 60},
			map[string]any{"key": "reboot", "type": "pending_reboot", "params": map[string]any{}, "interval_s": 60}}}})
	s.logf("CHECKIN #%d seq=%v bytes_in=%d bytes_out=%d buffered=%d inventory=%v", n, seq, len(body), out, len(buffered), hasInv)
	if n == 1 && s.jobScript != "" {
		s.mu.Lock()
		s.jobQueued = true
		s.mu.Unlock()
	}
}

func (s *server) job() json.RawMessage {
	now := time.Now().UTC()
	f := map[string]any{"job_id": "e2e-job-1", "attempt": 1, "type": "powershell", "script": s.jobScript,
		"params": map[string]any{}, "timeout_s": 30, "max_output_bytes": 4096,
		"issued_at": now.Format(time.RFC3339), "expires_at": now.Add(time.Hour).Format(time.RFC3339)}
	raw, _ := json.Marshal(f)
	canon, _ := jobs.Canonical(raw)
	f["signature"] = base64.StdEncoding.EncodeToString(ed25519.Sign(s.priv, canon))
	raw, _ = json.Marshal(f)
	return raw
}

func (s *server) jobsH(w http.ResponseWriter, r *http.Request) {
	if !s.authed(r) {
		s.writeJSON(w, 401, map[string]any{"error": "bad token", "code": "invalid_token"})
		return
	}
	if r.Method == http.MethodGet {
		s.mu.Lock()
		q := s.jobQueued && !s.jobDone
		s.mu.Unlock()
		list := []json.RawMessage{}
		if q {
			list = append(list, s.job())
		}
		s.writeJSON(w, 200, map[string]any{"jobs": list})
		return
	}
	body, _ := io.ReadAll(r.Body)
	var rep map[string]any
	json.Unmarshal(body, &rep)
	s.logf("JOB-REPORT job=%v state=%v exit=%v output=%q", rep["job_id"], rep["state"], rep["exit_code"], rep["output"])
	if st, _ := rep["state"].(string); st != "running" {
		s.mu.Lock()
		s.jobDone = true
		s.mu.Unlock()
	}
	s.writeJSON(w, 200, map[string]any{"ok": true})
}

func selfSigned(dir string) (tls_cert [2][]byte) {
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(time.Now().UnixNano()), Subject: pkix.Name{CommonName: "rivetit-fakeserver"},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(48 * time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature | x509.KeyUsageCertSign, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true, IsCA: true, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")}, DNSNames: []string{"localhost"}}
	der, _ := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	kb, _ := x509.MarshalECPrivateKey(key)
	certPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
	keyPEM := pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb})
	os.WriteFile(filepath.Join(dir, "ca.pem"), certPEM, 0o644)
	return [2][]byte{certPEM, keyPEM}
}

func main() {
	listen := flag.String("listen", "127.0.0.1:0", "listen address")
	dir := flag.String("dir", "", "output directory (ca.pem, url, events.log)")
	token := flag.String("token", "E2E-ENROLL-TOKEN", "accepted enrollment token")
	interval := flag.Int("interval", 5, "check-in interval seconds")
	collect := flag.Int("collect", 10, "collect interval seconds")
	script := flag.String("job-script", "", "if set, queue one signed job with this script after the first check-in")
	revoke := flag.Int("revoke-after", 0, "if >0, answer 401 revoked after this many check-ins")
	flag.Parse()
	if *dir == "" {
		log.Fatal("-dir required")
	}
	os.MkdirAll(*dir, 0o755)
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	s := &server{pub: pub, priv: priv, token: *token, interval: *interval, collectIv: *collect, jobScript: *script, revokeAfter: *revoke}
	s.outFile, _ = os.OpenFile(filepath.Join(*dir, "events.log"), os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o644)
	pems := selfSigned(*dir)
	cert, err := tlsPair(pems)
	if err != nil {
		log.Fatal(err)
	}
	mux := http.NewServeMux()
	mux.HandleFunc("/api/v1/agent_enroll", s.enroll)
	mux.HandleFunc("/api/v1/agent_checkin", s.checkin)
	mux.HandleFunc("/api/v1/agent_jobs", s.jobsH)
	mux.HandleFunc("/_stats", func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		defer s.mu.Unlock()
		json.NewEncoder(w).Encode(map[string]any{"checkins": s.checkins, "bytes_in": s.bytesIn, "bytes_out": s.bytesOut, "last_seq": s.lastSeq, "job_done": s.jobDone, "wire_in": wireIn.Load(), "wire_out": wireOut.Load()})
	})
	ln, err := newTLSListener(*listen, cert)
	if err != nil {
		log.Fatal(err)
	}
	url := "https://" + ln.Addr().String()
	os.WriteFile(filepath.Join(*dir, "url"), []byte(url), 0o644)
	s.logf("LISTEN %s ca=%s", url, filepath.Join(*dir, "ca.pem"))
	log.Fatal(http.Serve(ln, mux))
}

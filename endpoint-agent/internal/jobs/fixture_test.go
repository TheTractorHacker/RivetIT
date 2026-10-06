package jobs

import (
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"os"
	"testing"
	"time"

	"rivetit-agent/internal/api"
)

// The vectors are produced by the SERVER side (tests/fixtures/
// agent_job_signing_vectors.json in the repository). testdata/ holds a copy
// taken when the agent was built; when the repository-level file exists it is
// used instead, so a server-side change that breaks the agent fails here.
func loadVectors(t *testing.T) map[string]json.RawMessage {
	for _, p := range []string{"../../../tests/fixtures/agent_job_signing_vectors.json", "testdata/agent_job_signing_vectors.json"} {
		if b, err := os.ReadFile(p); err == nil {
			t.Logf("using vectors from %s", p)
			var m map[string]json.RawMessage
			if err := json.Unmarshal(b, &m); err != nil {
				t.Fatal(err)
			}
			return m
		}
	}
	t.Skip("no signing vectors available")
	return nil
}

func TestServerSigningVectors(t *testing.T) {
	v := loadVectors(t)
	var key struct {
		PublicKeyBase64 string `json:"public_key_base64"`
	}
	json.Unmarshal(v["test_key"], &key)
	pubB, _ := base64.StdEncoding.DecodeString(key.PublicKeyBase64)
	pub := ed25519.PublicKey(pubB)

	var jobsV []struct {
		Name, JobJSON, Canonical, Signature string
	}
	// field names in the fixture are snake_case
	var raw []map[string]string
	if err := json.Unmarshal(v["jobs"], &raw); err != nil {
		t.Fatal(err)
	}
	for _, r := range raw {
		jobsV = append(jobsV, struct{ Name, JobJSON, Canonical, Signature string }{r["name"], r["job_json"], r["canonical"], r["signature"]})
	}
	if len(jobsV) == 0 {
		t.Fatal("no job vectors")
	}
	for _, j := range jobsV {
		got, err := Canonical([]byte(j.JobJSON))
		if err != nil || string(got) != j.Canonical {
			t.Errorf("%s: canonical mismatch\n got  %s\n want %s (%v)", j.Name, got, j.Canonical, err)
			continue
		}
		var probe struct {
			IssuedAt string `json:"issued_at"`
		}
		json.Unmarshal([]byte(j.JobJSON), &probe)
		issued, _ := time.Parse(time.RFC3339, probe.IssuedAt)
		fixed := issued.Add(time.Minute) // each vector is valid for >= 5 minutes after issue
		ver := &Verifier{PublicKey: pub, Now: func() time.Time { return fixed }}
		if err := ver.VerifyObject([]byte(j.JobJSON), j.Signature); err != nil {
			t.Errorf("%s: server signature rejected: %v", j.Name, err)
		}
		// full policy path using the delivered form (signature member added)
		var obj map[string]any
		d := json.NewDecoder(stringsReader(j.JobJSON))
		d.UseNumber()
		d.Decode(&obj)
		obj["signature"] = j.Signature
		delivered, _ := json.Marshal(obj)
		list, err := api.UnmarshalJobs([]byte(`{"jobs":[` + string(delivered) + `]}`))
		if err != nil {
			t.Errorf("%s: %v", j.Name, err)
			continue
		}
		if err := ver.Verify(list[0]); err != nil {
			t.Errorf("%s: full verification failed: %v", j.Name, err)
		}
		// a one-character tamper must fail
		list[0].Raw = []byte(replaceOnce(string(list[0].Raw), `"attempt":`, `"attempt":9`))
		if ver.Verify(list[0]) == nil {
			t.Errorf("%s: tampered job verified", j.Name)
		}
	}

	var only []map[string]string
	json.Unmarshal(v["canonical_only"], &only)
	for _, c := range only {
		// canonical_only inputs may be arrays; wrap objects/arrays uniformly
		in := c["input"]
		got, err := canonicalAny([]byte(in))
		if err != nil || string(got) != c["canonical"] {
			t.Errorf("canonical_only %q:\n got  %s\n want %s (%v)", c["name"], got, c["canonical"], err)
		}
	}

	var um struct{ SHA256, Signature string }
	json.Unmarshal(v["update_manifest"], &um)
	sig, _ := base64.StdEncoding.DecodeString(um.Signature)
	if !ed25519.Verify(pub, []byte(um.SHA256), sig) {
		t.Error("update manifest signature does not verify as ed25519 over the sha256 hex string")
	}
	_ = sha256.Size

	var cd struct{ CheckJSON, Canonical, Signature string }
	var cdRaw map[string]string
	json.Unmarshal(v["check_definition"], &cdRaw)
	cd.CheckJSON, cd.Canonical, cd.Signature = cdRaw["check_json"], cdRaw["canonical"], cdRaw["signature"]
	got, err := Canonical([]byte(cd.CheckJSON))
	if err != nil || string(got) != cd.Canonical {
		t.Errorf("check canonical: %s (%v)", got, err)
	}
	if err := (&Verifier{PublicKey: pub}).VerifyObject([]byte(cd.CheckJSON), cd.Signature); err != nil {
		t.Errorf("check definition signature: %v", err)
	}
}

# RivetIT endpoint agent build. Requires Go (see go.mod) and, for `make e2e`, bash.
# Reproducible-build notes are in README.md ("Build").
GO        ?= go
# CI passes VERSION (tag agent-vX.Y.Z -> X.Y.Z, otherwise 0.0.0-dev.<sha>). Locally: make dist VERSION=0.1.0
VERSION   ?= 0.0.0-dev.$(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
COMMIT    ?= $(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
LDFLAGS   := -s -w -X main.version=$(VERSION) -X main.commit=$(COMMIT)
BUILDFLAGS:= -trimpath -buildvcs=false
DIST      := dist
export CGO_ENABLED := 0

.PHONY: all build windows linux test vet lint fuzz e2e clean checksums dist clean-dist syso devtools

all: vet test build

build: windows linux

windows:
	mkdir -p $(DIST)
	GOOS=windows GOARCH=amd64 $(GO) build $(BUILDFLAGS) -ldflags '$(LDFLAGS)' -o $(DIST)/rivetit-agent-windows-amd64.exe .
	GOOS=windows GOARCH=arm64 $(GO) build $(BUILDFLAGS) -ldflags '$(LDFLAGS)' -o $(DIST)/rivetit-agent-windows-arm64.exe .

# Linux binary is a TEST build only (scratch-server runs); it is not a supported platform.
linux:
	mkdir -p $(DIST)
	GOOS=linux GOARCH=amd64 $(GO) build $(BUILDFLAGS) -ldflags '$(LDFLAGS)' -o $(DIST)/rivetit-agent-linux-amd64 .

vet:
	$(GO) vet ./...
	GOOS=windows GOARCH=amd64 $(GO) vet ./...
	GOOS=windows GOARCH=arm64 $(GO) vet ./...

# staticcheck is optional: go install honnef.co/go/tools/cmd/staticcheck@latest
lint:
	@command -v staticcheck >/dev/null && staticcheck ./... || echo "staticcheck not installed; skipped"

test:
	CGO_ENABLED=1 $(GO) test -race -count=1 ./...

# TEST/DEV-ONLY binary with the `stamp` command (never part of dist/).
devtools:
	mkdir -p $(DIST)
	$(GO) build $(BUILDFLAGS) -tags devtools -o $(DIST)/rivetit-agent-devtools .

# Regenerate the committed Windows resources (manifest + version info); see winres/gen.sh.
syso:
	./winres/gen.sh

fuzz:
	$(GO) test -run xxx -fuzz=FuzzEmbedded -fuzztime=30s ./internal/embed
	$(GO) test -run xxx -fuzz=FuzzCanonical -fuzztime=30s ./internal/jobs
	$(GO) test -run xxx -fuzz=FuzzDecoders -fuzztime=30s ./internal/api

e2e:
	./e2e/run_e2e.sh

# Checksums cover exactly the release artifacts (no dev tools, no stale files).
checksums:
	cd $(DIST) && sha256sum rivetit-agent-windows-amd64.exe rivetit-agent-windows-arm64.exe rivetit-agent-linux-amd64 > SHA256SUMS

# Clean, reproducible release set: the two Windows exes, the Linux test binary and SHA256SUMS.
dist: clean-dist build checksums

clean-dist:
	rm -rf $(DIST)

clean:
	rm -rf $(DIST) e2e/work

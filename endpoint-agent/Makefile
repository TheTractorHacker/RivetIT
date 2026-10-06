# RivetIT endpoint agent build. Requires Go (see go.mod) and, for `make e2e`, bash.
# Reproducible-build notes are in README.md ("Build").
GO        ?= go
VERSION   ?= $(shell git describe --tags --always --dirty 2>/dev/null | sed 's/^v//' || echo 0.0.0-dev)
COMMIT    ?= $(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
LDFLAGS   := -s -w -X main.version=$(VERSION) -X main.commit=$(COMMIT)
BUILDFLAGS:= -trimpath -buildvcs=false
DIST      := dist
export CGO_ENABLED := 0

.PHONY: all build windows linux test vet lint fuzz e2e clean checksums

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

fuzz:
	$(GO) test -run xxx -fuzz=FuzzCanonical -fuzztime=30s ./internal/jobs
	$(GO) test -run xxx -fuzz=FuzzDecoders -fuzztime=30s ./internal/api

e2e:
	./e2e/run_e2e.sh

checksums: build
	cd $(DIST) && sha256sum rivetit-agent-* > SHA256SUMS

clean:
	rm -rf $(DIST) e2e/work

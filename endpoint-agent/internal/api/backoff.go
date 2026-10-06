package api

import (
	"math/rand/v2"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// Backoff implements exponential backoff with full jitter:
// delay = rand[0, min(Max, Base*2^attempt)].
type Backoff struct {
	Base, Max time.Duration
	Rand      func() float64 // [0,1); default math/rand/v2
}

// DefaultBackoff: 5s base, 15 minute cap.
func DefaultBackoff() Backoff { return Backoff{Base: 5 * time.Second, Max: 15 * time.Minute} }

// Ceiling is the un-jittered upper bound for an attempt (0-based).
func (b Backoff) Ceiling(attempt int) time.Duration {
	if attempt < 0 {
		attempt = 0
	}
	c := b.Base
	for i := 0; i < attempt && c < b.Max; i++ {
		c *= 2
	}
	if c > b.Max || c <= 0 {
		c = b.Max
	}
	return c
}

// Delay returns the jittered delay for an attempt.
func (b Backoff) Delay(attempt int) time.Duration {
	r := b.Rand
	if r == nil {
		r = rand.Float64
	}
	return time.Duration(r() * float64(b.Ceiling(attempt)))
}

// DelayWithRetryAfter honours a server Retry-After as a floor.
func (b Backoff) DelayWithRetryAfter(attempt int, retryAfter time.Duration) time.Duration {
	d := b.Delay(attempt)
	if retryAfter > d {
		return retryAfter
	}
	return d
}

// ParseRetryAfter accepts delta-seconds or an HTTP date; result is capped at 1h.
func ParseRetryAfter(h string, now time.Time) time.Duration {
	h = strings.TrimSpace(h)
	if h == "" {
		return 0
	}
	var d time.Duration
	if n, err := strconv.Atoi(h); err == nil {
		if n < 0 {
			return 0
		}
		d = time.Duration(n) * time.Second
	} else if t, err := http.ParseTime(h); err == nil {
		d = t.Sub(now)
	}
	if d < 0 {
		d = 0
	}
	if d > time.Hour {
		d = time.Hour
	}
	return d
}

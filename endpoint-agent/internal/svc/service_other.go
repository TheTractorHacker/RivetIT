//go:build !windows

// Package svc wraps the Windows service control manager. On other OSes every
// call reports that services are unsupported (Linux is a test platform).
package svc

import (
	"context"
	"errors"
)

const ServiceName = "RivetITAgent"

var ErrUnsupported = errors.New("windows services are not supported on this OS")

func IsWindowsService() bool                             { return false }
func RunAsService(func(ctx context.Context) int) error   { return ErrUnsupported }
func InstallService(exePath string, args []string) error { return ErrUnsupported }
func RemoveService() error                               { return ErrUnsupported }
func StartService() error                                { return ErrUnsupported }
func StopService() error                                 { return ErrUnsupported }
func ServiceExists() (bool, error)                       { return false, ErrUnsupported }

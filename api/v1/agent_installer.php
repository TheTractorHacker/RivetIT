<?php
// POST /api/v1/agent_installer   (public, token-gated: for RMM / GPO / Intune deployment scripts)
//   body (JSON or form): {"token":"rvte1....","arch":"amd64|arm64"}   (the token may instead be an Authorization: Bearer header)
//   -> 200 application/octet-stream: the stamped per-department installer (RivetIT-Agent-Setup-<department>-<arch>.exe)
//   -> 404 generic for any token that is not currently usable (unknown, wrong, revoked, expired, used up)
// The token is NEVER read from the query string, so it stays out of access logs. Dispatched from index.php before any authentication or
// body read. Handled by rivet/rivet-core (RivetCore\Rmm\Http\DeviceApi::installer).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

ea_dispatch('agent_installer');

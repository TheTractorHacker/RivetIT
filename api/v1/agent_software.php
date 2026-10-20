<?php
// GET /api/v1/agent_software/{job_id}/{secret}   (no bearer: the path secret IS the credential)
//   -> 200 application/octet-stream: the hosted installer file of a queued or running software_install job (at most 24 h).
//   -> 404 not_found for every refusal (no such job, wrong secret, expired, finished, file gone/damaged, module or software_deploy off): never distinguishable.
// Install apps when the agent installs (RivetCore 1.0.0-rc.26, migration 0033). Not part of the frozen device protocol; a separate RmmModule API
// (hostedFileApi, not deviceApi). Handled by rivet/rivet-core (RivetCore\Rmm\Http\HostedFileApi).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

ea_dispatch_hosted([(string) ($segments[1] ?? ''), (string) ($segments[2] ?? '')]);

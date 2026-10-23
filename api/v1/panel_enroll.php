<?php
// POST /api/v1/panel_enroll   (enrollment token in the body, no Authorization header yet)
//   -> 201 {device_id, device_token, client, contact, can_create_tickets, limits, server_time}
// Handled by rivet/rivet-core (RivetCore\Panel\Http\PanelApi::enroll via PanelApi::handle()).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/panel_device_api.php';

panel_dispatch('panel_enroll');

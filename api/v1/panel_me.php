<?php
// GET /api/v1/panel_me   (Authorization: Bearer <panel device token>)
//   -> 200 {client, contact, can_create_tickets, can_view_tickets, limits, server_time}
// Handled by rivet/rivet-core (RivetCore\Panel\Http\PanelApi::handle()).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/panel_device_api.php';

panel_dispatch('panel_me');

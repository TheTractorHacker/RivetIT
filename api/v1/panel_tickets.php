<?php
// GET  /api/v1/panel_tickets?limit=20&offset=0   -> 200 {tickets: [...], limit, offset, has_more, server_time}
// POST /api/v1/panel_tickets                     -> 201 {ticket_id, replayed, server_time}
// (Authorization: Bearer <panel device token>.) Handled by rivet/rivet-core (RivetCore\Panel\Http\PanelApi::handle()).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/panel_device_api.php';

panel_dispatch('panel_tickets');

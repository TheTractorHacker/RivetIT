<?php
// GET  /api/v1/panel_ticket_chat?ticket_id=1002&after=0&limit=50  -> 200 {ticket, messages: [...], next_after, server_time}
// POST /api/v1/panel_ticket_chat?ticket_id=1002                   -> 201 {ticket_id, message_id, server_time}
// (Authorization: Bearer <panel device token>.) Handled by rivet/rivet-core (RivetCore\Panel\Http\PanelApi::handle()).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/panel_device_api.php';

panel_dispatch('panel_ticket_chat');

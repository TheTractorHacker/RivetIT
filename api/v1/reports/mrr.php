<?php
// GET /api/v1/reports/mrr
// Monthly Recurring Revenue metrics — mirrors agent/reports/mrr.php.
// Shares getMrrReport() in functions.php so web + API return identical numbers.
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_financial');

api_response(200, getMrrReport($mysqli, !empty($api_key_client_id) ? intval($api_key_client_id) : null));

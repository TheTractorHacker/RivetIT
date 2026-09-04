<?php
// GET /api/v1/clients/{id}/tickets
// GET /api/v1/clients/{id}/assets
// GET /api/v1/clients/{id}/locations
// GET /api/v1/clients/{id}/credentials
// GET /api/v1/clients/{id}/contracts
// GET /api/v1/clients/{id}/files
// GET /api/v1/clients/{id}/allowance
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

if (!$id) api_error(400, 'client_id required');

$uid = $api_user_id;

// Client-scope restriction, mirroring tickets.php/appointments.php: agents with rows in
// user_client_permissions may only access clients in their permitted set.
if (!api_client_scope_ok($id)) api_error(403, 'Access denied');

switch ($sub) {
    case 'tickets':
        api_require_module_permission($mysqli, $uid, 'module_support');
        $rows = []; $sql = mysqli_query($mysqli,
            "SELECT t.ticket_id, t.ticket_number, t.ticket_subject, t.ticket_priority,
                    t.ticket_created_at, t.ticket_resolved_at,
                    ts.ticket_status_name, ts.ticket_status_color, u.user_name AS assigned_to
             FROM tickets t
             LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
             LEFT JOIN users u ON t.ticket_assigned_to = u.user_id
             WHERE t.ticket_client_id = $id AND t.ticket_archived_at IS NULL
             ORDER BY t.ticket_created_at DESC LIMIT 50");
        while ($r = mysqli_fetch_assoc($sql)) {
            $rows[] = ['id'=>intval($r['ticket_id']),'number'=>intval($r['ticket_number']),
                'subject'=>$r['ticket_subject'],'priority'=>$r['ticket_priority'],
                'status'=>$r['ticket_status_name'],'status_color'=>$r['ticket_status_color'],
                'assigned_to'=>$r['assigned_to'],'created_at'=>$r['ticket_created_at'],
                'resolved_at'=>$r['ticket_resolved_at']];
        }
        api_response(200, $rows);

    case 'assets':
        api_require_module_permission($mysqli, $uid, 'module_support');
        $rows = []; $sql = mysqli_query($mysqli,
            "SELECT asset_id, asset_name, asset_type, asset_make, asset_model, asset_serial, asset_status
             FROM assets WHERE asset_client_id = $id AND asset_archived_at IS NULL ORDER BY asset_name ASC");
        while ($r = mysqli_fetch_assoc($sql)) {
            $rows[] = ['id'=>intval($r['asset_id']),'name'=>$r['asset_name'],'type'=>$r['asset_type'],
                'make'=>$r['asset_make'],'model'=>$r['asset_model'],'serial'=>$r['asset_serial'],'status'=>$r['asset_status']];
        }
        api_response(200, $rows);

    case 'locations':
        api_require_module_permission($mysqli, $uid, 'module_client');
        $rows = []; $sql = mysqli_query($mysqli,
            "SELECT location_id, location_name, location_address, location_city, location_state,
                    location_zip, location_phone, location_primary
             FROM locations WHERE location_client_id = $id AND location_archived_at IS NULL ORDER BY location_primary DESC, location_name ASC");
        while ($r = mysqli_fetch_assoc($sql)) {
            $rows[] = ['id'=>intval($r['location_id']),'name'=>$r['location_name'],
                'address'=>$r['location_address'],'city'=>$r['location_city'],'state'=>$r['location_state'],
                'zip'=>$r['location_zip'],'phone'=>$r['location_phone'],'primary'=>(bool)$r['location_primary']];
        }
        api_response(200, $rows);

    case 'credentials':
        if ($legacy_api_key_auth) { api_error(403, 'Credentials endpoint requires a user API token'); }
        api_require_module_permission($mysqli, $uid, 'module_credential');
        $rows = []; $sql = mysqli_query($mysqli,
            "SELECT credential_id, credential_name, credential_username, credential_uri
             FROM credentials WHERE credential_client_id = $id AND credential_archived_at IS NULL ORDER BY credential_name ASC");
        while ($r = mysqli_fetch_assoc($sql)) {
            $rows[] = ['id'=>intval($r['credential_id']),'name'=>$r['credential_name'],
                'username'=>$r['credential_username'],'uri'=>$r['credential_uri']];
        }
        api_response(200, $rows);

    case 'contracts':
        api_require_module_permission($mysqli, $uid, 'module_client');
        $rows = []; $sql = mysqli_query($mysqli,
            "SELECT contract_id, contract_name, contract_status, contract_type
             FROM contracts WHERE contract_client_id = $id AND contract_archived_at IS NULL ORDER BY contract_name ASC");
        while ($r = mysqli_fetch_assoc($sql)) {
            $rows[] = ['id'=>intval($r['contract_id']),'name'=>$r['contract_name'],
                'status'=>$r['contract_status'],'type'=>$r['contract_type']];
        }
        api_response(200, $rows);

    case 'files':
        api_require_module_permission($mysqli, $uid, 'module_support');
        // Signed outtake (device pickup) forms — outtakes have no client_id column of their
        // own, so join through the ticket they belong to.
        $rows = []; $sql = mysqli_query($mysqli,
            "SELECT ot.outtake_id, ot.outtake_signed_name, ot.outtake_signed_at,
                    t.ticket_id, t.ticket_number, t.ticket_subject
             FROM ticket_outtake_forms ot
             JOIN tickets t ON t.ticket_id = ot.outtake_ticket_id
             WHERE t.ticket_client_id = $id AND ot.outtake_signed_at IS NOT NULL
             ORDER BY ot.outtake_signed_at DESC LIMIT 100");
        while ($r = mysqli_fetch_assoc($sql)) {
            $rows[] = [
                'id'            => intval($r['outtake_id']),
                'ticket_id'     => intval($r['ticket_id']),
                'ticket_number' => intval($r['ticket_number']),
                'ticket_subject'=> $r['ticket_subject'],
                'signed_name'   => $r['outtake_signed_name'],
                'signed_at'     => $r['outtake_signed_at'],
            ];
        }
        api_response(200, $rows);

    case 'allowance':
        // Rolled-up included-support-hours allowance vs. usage for the calendar
        // month, mirroring agent/reports/included_issues.php. Same permission as
        // the rest of the client tabs (module_client) since this is billing-adjacent
        // client data, not support-ticket data.
        api_require_module_permission($mysqli, $uid, 'module_client');

        $month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
        $year  = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        if ($month < 1 || $month > 12) api_error(400, 'Invalid month');
        if ($year < 2000 || $year > 2100) api_error(400, 'Invalid year');

        // Per-contract breakdown, computed in one pass alongside the rolled-up total
        // (rather than also calling getClientIncludedIssuesUsage(), which would re-run
        // the same per-contract queries a second time). getContractIncludedIssuesUsage()
        // returns a period's real ticket-derived 'used' even when 'included' is null for
        // that contract (its doc comment: null 'included' means "feature inactive,
        // don't render") - zero 'used' out here in that case so a contract's entry
        // doesn't show nonzero usage for an allowance it doesn't offer, and so summing
        // contracts[].remote/onsite.used always reconciles with the rolled-up totals
        // below (both only count a contract's usage where included !== null).
        $contracts = [];
        $remote_included = 0.0; $remote_used = 0.0; $remote_configured = false;
        $onsite_included = 0.0; $onsite_used = 0.0; $onsite_configured = false;

        $csql = mysqli_query($mysqli,
            "SELECT contract_id, contract_name FROM contracts
             WHERE contract_client_id = $id AND contract_status = 'Active' AND contract_archived_at IS NULL
               AND (contract_support_hours_included_remote IS NOT NULL OR contract_support_hours_included_onsite IS NOT NULL)
             ORDER BY contract_name ASC");
        while ($c = mysqli_fetch_assoc($csql)) {
            $usage = getContractIncludedIssuesUsage($mysqli, intval($c['contract_id']), $month, $year);

            $c_remote = $usage['remote'];
            if ($c_remote['included'] === null) {
                $c_remote['used'] = 0;
            } else {
                $remote_configured = true;
                $remote_included += $c_remote['included'];
                $remote_used += $c_remote['used'];
            }

            $c_onsite = $usage['onsite'];
            if ($c_onsite['included'] === null) {
                $c_onsite['used'] = 0;
            } else {
                $onsite_configured = true;
                $onsite_included += $c_onsite['included'];
                $onsite_used += $c_onsite['used'];
            }

            $contracts[] = [
                'id'     => intval($c['contract_id']),
                'name'   => $c['contract_name'],
                'remote' => $c_remote,
                'onsite' => $c_onsite,
            ];
        }

        // Mirrors getClientIncludedIssuesUsage()'s own summarize() closure exactly.
        $summarize = function (bool $configured, float $included, float $used): array {
            if (!$configured) {
                return ['included' => null, 'used' => 0, 'remaining' => null, 'pct' => null];
            }
            return [
                'included'  => $included,
                'used'      => $used,
                'remaining' => $included - $used,
                'pct'       => $included > 0 ? round(min(100, $used / $included * 100), 1) : null,
            ];
        };

        api_response(200, [
            'month'     => $month,
            'year'      => $year,
            'remote'    => $summarize($remote_configured, $remote_included, $remote_used),
            'onsite'    => $summarize($onsite_configured, $onsite_included, $onsite_used),
            'contracts' => $contracts,
        ]);

    default:
        api_error(404, 'Unknown tab');
}

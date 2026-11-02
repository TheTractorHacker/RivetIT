<?php
// GET /api/v1/portal_checklist   -> 200 {checklist: null | {type,status,template,done,total,tasks:[{id,title,owner,state,required,due_at}]}}
// The employee's own onboarding / offboarding checklist, read-only (same view as the web home): no instructions, automation settings or
// errors, and automated (action) tasks are left out. state is one of todo | not_started | awaiting_approval | done.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/portal_api.php';
require_once $DOCUMENT_ROOT . '/src/Portal/EmployeeHome.php';

portal_api_get_only();
$me = portal_api_require_employee();
api_response(200, ['checklist' => portal_api_checklist((new \ITFlow\Portal\EmployeeHome($mysqli))->myChecklist($me['contact_id'], $me['client_id']))]);

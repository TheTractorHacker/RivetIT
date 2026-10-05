<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Audit\AuditService;
use ITFlow\Compliance\ComplianceService;

if (!ComplianceService::ready($mysqli)) {
    flash_alert('Run the database update first.', 'error');
    redirect();
}

if (isset($_POST['record_compliance_review'])) {
    validateCSRFToken($_POST['csrf_token']);

    $item_id = (string) ($_POST['item_id'] ?? '');
    $known = array_map(static fn ($i) => $i->id, ComplianceService::catalog($mysqli)->manualItems());
    if (!in_array($item_id, $known, true)) {
        flash_alert('Unknown checklist item.', 'error');
        redirect();
    }

    try {
        ComplianceService::attestations($mysqli)->record(
            $item_id,
            (int) $session_user_id,
            (string) ($_POST['reviewer_name'] ?? ''),
            (string) ($_POST['reviewed_on'] ?? ''),
            (string) ($_POST['next_due_on'] ?? '') ?: null,
            (string) ($_POST['note'] ?? '') ?: null
        );
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect();
    }

    logAction('Compliance', 'Edit', "$session_name recorded a compliance review ($item_id)");
    AuditService::record('compliance.review_recorded', (int) $session_user_id, 'compliance', $item_id, 'create', 'Compliance review recorded', ['item' => $item_id]);
    flash_alert('Review recorded.');
    redirect();
}

if (isset($_POST['take_compliance_snapshot'])) {
    validateCSRFToken($_POST['csrf_token']);

    $assessment = ComplianceService::assess($mysqli);
    $id = ComplianceService::snapshots($mysqli)->save($assessment, (int) $session_user_id, 'manual', defined('APP_VERSION') ? APP_VERSION : null);

    logAction('Compliance', 'Create', "$session_name saved a compliance snapshot");
    AuditService::record('compliance.snapshot_taken', (int) $session_user_id, 'compliance', $id, 'create', 'Compliance snapshot saved', ['score' => $assessment->summaries['all']['score'] ?? null]);
    flash_alert('Snapshot saved.');
    redirect();
}

if (isset($_POST['publish_compliance_report'])) {
    validateCSRFToken($_POST['csrf_token']);

    if (!ComplianceService::sharedReady($mysqli)) {
        flash_alert('Run the database update first.', 'error');
        redirect();
    }
    $snapshot_id = intval($_POST['snapshot_id'] ?? 0);
    try {
        ComplianceService::shared($mysqli)->publish($snapshot_id, (string) ($_POST['note'] ?? '') ?: null, (int) $session_user_id);
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect();
    }

    logAction('Compliance', 'Edit', "$session_name published compliance snapshot $snapshot_id to the portal");
    AuditService::record('compliance.report_published', (int) $session_user_id, 'compliance', $snapshot_id, 'update', 'Compliance report published to the portal', ['snapshot_id' => $snapshot_id]);
    flash_alert('Published. Portal users now see it under Security.');
    redirect();
}

if (isset($_POST['unpublish_compliance_report'])) {
    validateCSRFToken($_POST['csrf_token']);

    if (ComplianceService::sharedReady($mysqli)) {
        ComplianceService::shared($mysqli)->unpublish();
        logAction('Compliance', 'Edit', "$session_name stopped sharing the compliance report");
        AuditService::record('compliance.report_unpublished', (int) $session_user_id, 'compliance', 'shared', 'update', 'Compliance report removed from the portal', []);
    }
    flash_alert('No longer shared.');
    redirect();
}

<?php

/*
 * Relationships: link two records (entity_links), unlink them, and manage the extra vendors (with a role) on an asset or software title.
 * All rules (permissions, department scope, audit) live in ITFlow\Links\LinkService / VendorRoles; this file only reads the form.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['link_entities'])) {

    validateCSRFToken($_POST['csrf_token']);

    $actor = \ITFlow\Links\LinkActor::fromSession($mysqli);
    $result = (new \ITFlow\Links\LinkService($mysqli))->create(
        $actor,
        (string) ($_POST['src_type'] ?? ''),
        intval($_POST['src_id'] ?? 0),
        (string) ($_POST['dst_type'] ?? ''),
        intval($_POST['dst_id'] ?? 0),
        (string) ($_POST['link_type'] ?? ''),
        (string) ($_POST['note'] ?? '')
    );

    if ($result['ok']) {
        flash_alert('Linked.');
    } else {
        flash_alert(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), 'error');
    }

    redirect();

}

if (isset($_POST['unlink_entity'])) {

    validateCSRFToken($_POST['csrf_token']);

    $actor = \ITFlow\Links\LinkActor::fromSession($mysqli);
    $result = (new \ITFlow\Links\LinkService($mysqli))->delete($actor, intval($_POST['link_id'] ?? 0));

    if ($result['ok']) {
        flash_alert('Unlinked.', 'error');
    } else {
        flash_alert(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), 'error');
    }

    redirect();

}

if (isset($_POST['add_vendor_role']) || isset($_POST['remove_vendor_role'])) {

    validateCSRFToken($_POST['csrf_token']);

    $actor = \ITFlow\Links\LinkActor::fromSession($mysqli);
    $roles = new \ITFlow\Links\VendorRoles($mysqli);
    $args = [$actor, (string) ($_POST['vendor_kind'] ?? ''), intval($_POST['record_id'] ?? 0), intval($_POST['vendor_id'] ?? 0), (string) ($_POST['vendor_role'] ?? '')];
    $adding = isset($_POST['add_vendor_role']);
    $result = $adding ? $roles->add(...$args) : $roles->remove(...$args);

    if ($result['ok']) {
        flash_alert($adding ? 'Vendor added.' : 'Vendor role removed.', $adding ? 'success' : 'error');
    } else {
        flash_alert(htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8'), 'error');
    }

    redirect();

}

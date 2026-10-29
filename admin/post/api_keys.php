<?php

/*
 * RivetIT - request handler for the retired Administration > API keys page.
 *
 * Legacy API keys no longer exist as a separate system (DB 2.6.204): every key was converted into an integration client and is managed on
 * Administration > Integration clients. This handler therefore changes nothing: it answers every old action (create, revoke, delete, bulk
 * delete) with a pointer to the unified page. The api_keys rows themselves are kept untouched (they are what a rollback would use, and
 * what the X-Api-Key header is verified against during the deprecation period).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_api_key']) || isset($_GET['revoke_api_key']) || isset($_GET['delete_api_key']) || isset($_POST['bulk_delete_api_keys'])) {
    flash_alert('API keys are now managed as <strong>integration clients</strong>. Create, scope, rotate and revoke them under <a href="/admin/integration_clients.php">Administration &gt; Integration clients</a>. Nothing was changed.', 'warning');
    redirect('/admin/api_keys.php');
}

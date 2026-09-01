<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = cleanInput($_POST['name']);
$type = cleanInput($_POST['type']);
$website = preg_replace("(^https?://)", "", cleanInput($_POST['website']));
$referral = cleanInput($_POST['referral']);
$rate = floatval($_POST['rate'] ?? 0);
$net_terms = intval($_POST['net_terms'] ?? $config_default_net_terms);
$support_issues_included_remote = (isset($_POST['support_issues_included_remote']) && trim($_POST['support_issues_included_remote']) !== '')
    ? intval($_POST['support_issues_included_remote']) : null;
$support_issues_included_onsite = (isset($_POST['support_issues_included_onsite']) && trim($_POST['support_issues_included_onsite']) !== '')
    ? intval($_POST['support_issues_included_onsite']) : null;
$tax_id_number = cleanInput($_POST['tax_id_number'] ?? '');
$abbreviation = cleanInput($_POST['abbreviation'] ?? '');
if (empty($abbreviation)) {
    $abbreviation = shortenClient($name);
}
$notes = cleanInput($_POST['notes'] ?? '');
$lead = intval($_POST['lead'] ?? 0);

// CRM lead qualification fields
$lead_source = cleanInput($_POST['lead_source'] ?? '');
$lead_status = cleanInput($_POST['lead_status'] ?? '');
$lead_owner = intval($_POST['lead_owner'] ?? 0);
$lead_score = intval($_POST['lead_score'] ?? 0);

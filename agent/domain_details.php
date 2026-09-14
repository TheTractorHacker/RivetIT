<?php

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND domain_client_id = $client_id";
} else {
    require_once "includes/inc_client_overview_all.php";
    $client_query = '';
}

enforceUserPermission('module_support');

$domain_id = intval($_GET['id'] ?? 0);

$sql = mysqli_query($mysqli, "SELECT domains.*, clients.client_name,
    registrar.vendor_id AS registrar_id, registrar.vendor_name AS registrar_name,
    dnshost.vendor_id AS dnshost_id, dnshost.vendor_name AS dnshost_name,
    mailhost.vendor_id AS mailhost_id, mailhost.vendor_name AS mailhost_name,
    webhost.vendor_id AS webhost_id, webhost.vendor_name AS webhost_name
    FROM domains
    LEFT JOIN clients ON client_id = domain_client_id
    LEFT JOIN vendors AS registrar ON domains.domain_registrar = registrar.vendor_id
    LEFT JOIN vendors AS dnshost ON domains.domain_dnshost = dnshost.vendor_id
    LEFT JOIN vendors AS mailhost ON domains.domain_mailhost = mailhost.vendor_id
    LEFT JOIN vendors AS webhost ON domains.domain_webhost = webhost.vendor_id
    WHERE domain_id = $domain_id
    $client_query
    LIMIT 1");

if (mysqli_num_rows($sql) == 0) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='javascript:history.back()'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
} else {
    $row = mysqli_fetch_assoc($sql);

    // The no-client_id branch above (inc_client_overview_all.php) applies no per-client
    // filtering at all, so re-verify against the client this specific domain actually
    // belongs to before rendering anything - same pattern as asset_details.php.
    $client_id = intval($row['domain_client_id']);
    enforceClientAccess($client_id);

    $client_name = nullable_htmlentities($row['client_name']);
    $domain_name = nullable_htmlentities($row['domain_name']);
    $domain_description = nullable_htmlentities($row['domain_description']);
    $domain_expire = $row['domain_expire'];
    $domain_registered_at = $row['domain_registered_at'];
    $domain_registrar_name = nullable_htmlentities($row['domain_registrar_name']); // WHOIS-reported
    $domain_status = nullable_htmlentities($row['domain_status']);
    $domain_dnssec = nullable_htmlentities($row['domain_dnssec']);
    $domain_ip = nullable_htmlentities($row['domain_ip']);
    $domain_name_servers = nullable_htmlentities($row['domain_name_servers']);
    $domain_mail_servers = nullable_htmlentities($row['domain_mail_servers']);
    $domain_txt = nullable_htmlentities($row['domain_txt']);
    $domain_raw_whois = nullable_htmlentities($row['domain_raw_whois']);
    $domain_notes = nullable_htmlentities($row['domain_notes']);
    $domain_created_at = nullable_htmlentities($row['domain_created_at']);
    $domain_archived_at = $row['domain_archived_at'];

    $registrar_id = intval($row['registrar_id']);
    $registrar_name = nullable_htmlentities($row['registrar_name']);
    $webhost_id = intval($row['webhost_id']);
    $webhost_name = nullable_htmlentities($row['webhost_name']);
    $dnshost_id = intval($row['dnshost_id']);
    $dnshost_name = nullable_htmlentities($row['dnshost_name']);
    $mailhost_id = intval($row['mailhost_id']);
    $mailhost_name = nullable_htmlentities($row['mailhost_name']);

    // Same expiry-urgency thresholds as the domains.php list row coloring.
    $days_until_expiry = $domain_expire ? (strtotime($domain_expire) - time()) / 86400 : null;
    if ($days_until_expiry === null) {
        $expire_badge = ['label' => 'No expiry on file', 'class' => 'bg-secondary'];
    } elseif ($days_until_expiry <= 0) {
        $expire_badge = ['label' => 'Expired', 'class' => 'bg-secondary'];
    } elseif ($days_until_expiry <= 14) {
        $expire_badge = ['label' => 'Expiring soon', 'class' => 'bg-danger'];
    } elseif ($days_until_expiry <= 90) {
        $expire_badge = ['label' => 'Renew soon', 'class' => 'bg-warning text-dark'];
    } else {
        $expire_badge = ['label' => 'Active', 'class' => 'bg-success'];
    }

    // Certificates issued for this domain - the cross-link this page adds.
    $cert_sql = mysqli_query($mysqli, "SELECT certificate_id, certificate_name, certificate_issued_by, certificate_expire
        FROM certificates
        WHERE certificate_domain_id = $domain_id AND certificate_archived_at IS NULL
        ORDER BY certificate_expire IS NULL, certificate_expire ASC");

    $history_sql = mysqli_query($mysqli, "SELECT * FROM domain_history WHERE domain_history_domain_id = $domain_id ORDER BY domain_history_modified_at DESC");
    ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $client_id ? "domains.php?client_id=$client_id" : "domains.php?scope=company" ?>">Domains</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= $domain_name ?></li>
        </ol>
    </nav>

    <div class="row">
        <div class="col-lg-8">
            <div class="card card-dark mb-3">
                <div class="card-header py-2">
                    <h3 class="card-title mt-2"><i class="fa fa-fw fa-globe me-2"></i><?= $domain_name ?></h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-light ajax-modal" data-modal-size="lg"
                            data-modal-url="modals/domain/domain_edit.php?id=<?= $domain_id ?>">
                            <i class="fas fa-fw fa-edit me-2"></i>Edit
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($domain_description) { ?>
                        <p class="text-secondary mb-3"><?= $domain_description ?></p>
                    <?php } ?>

                    <span class="badge <?= $expire_badge['class'] ?> mb-3"><?= $expire_badge['label'] ?></span>

                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-secondary" style="width:180px">Department</td>
                            <td><a href="clients.php?client_id=<?= $client_id ?>"><?= $client_name ?></a></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Expires</td>
                            <td><?= $domain_expire ? nullable_htmlentities($domain_expire) . " <small class='text-secondary'>(" . timeAgo($domain_expire) . ")</small>" : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Registered</td>
                            <td><?= $domain_registered_at ? nullable_htmlentities($domain_registered_at) . " <small class='text-secondary'>(" . timeAgo($domain_registered_at) . ")</small>" : '<span class="text-secondary">Not yet refreshed from WHOIS</span>' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Registrar</td>
                            <td><?= $registrar_name ? "<a class='ajax-modal' href='#' data-modal-url='modals/vendor/vendor_details.php?id=$registrar_id'>$registrar_name</a>" : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Web Host</td>
                            <td><?= $webhost_name ? "<a class='ajax-modal' href='#' data-modal-url='modals/vendor/vendor_details.php?id=$webhost_id'>$webhost_name</a>" : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">DNS Host</td>
                            <td><?= $dnshost_name ? "<a class='ajax-modal' href='#' data-modal-url='modals/vendor/vendor_details.php?id=$dnshost_id'>$dnshost_name</a>" : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Mail Host</td>
                            <td><?= $mailhost_name ? "<a class='ajax-modal' href='#' data-modal-url='modals/vendor/vendor_details.php?id=$mailhost_id'>$mailhost_name</a>" : '-' ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card card-dark mb-3">
                <div class="card-header py-2">
                    <h3 class="card-title mt-2"><i class="fa fa-fw fa-search-plus me-2"></i>WHOIS &amp; DNS Records</h3>
                </div>
                <div class="card-body">
                    <?php if (!$domain_registered_at && !$domain_ip && !$domain_name_servers) { ?>
                        <p class="text-secondary mb-0">Not refreshed from WHOIS yet - the daily cron picks up one domain per run, so this fills in over time.</p>
                    <?php } else { ?>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-secondary" style="width:180px">IP Address(es)</td>
                            <td><?= $domain_ip ? "<code>" . nl2br($domain_ip) . "</code>" : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Name Servers</td>
                            <td><?= $domain_name_servers ? "<code>" . nl2br($domain_name_servers) . "</code>" : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">MX Records</td>
                            <td><?= $domain_mail_servers ? "<code>" . nl2br($domain_mail_servers) . "</code>" : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">TXT Records</td>
                            <td><?= $domain_txt ? "<code>" . nl2br($domain_txt) . "</code>" : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">WHOIS Registrar</td>
                            <td><?= $domain_registrar_name ?: '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Domain Status</td>
                            <td><?= $domain_status ? "<code>" . nl2br($domain_status) . "</code>" : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">DNSSEC</td>
                            <td><?= $domain_dnssec ?: '-' ?></td>
                        </tr>
                    </table>
                    <?php if ($domain_raw_whois) { ?>
                        <button class="btn btn-sm btn-outline-secondary mt-3" type="button" data-bs-toggle="collapse" data-bs-target="#rawWhois">
                            <i class="fas fa-fw fa-code me-1"></i>Show raw WHOIS
                        </button>
                        <div class="collapse mt-2" id="rawWhois">
                            <pre class="bg-light p-2 border rounded" style="max-height:300px;overflow:auto;white-space:pre-wrap;"><?= $domain_raw_whois ?></pre>
                        </div>
                    <?php } ?>
                    <?php } ?>
                </div>
            </div>

            <?php if ($domain_notes) { ?>
            <div class="card card-dark mb-3">
                <div class="card-header py-2"><h3 class="card-title mt-2"><i class="fa fa-fw fa-sticky-note me-2"></i>Notes</h3></div>
                <div class="card-body"><?= nl2br($domain_notes) ?></div>
            </div>
            <?php } ?>

            <div class="card card-dark mb-3">
                <div class="card-header py-2"><h3 class="card-title mt-2"><i class="fa fa-fw fa-history me-2"></i>History</h3></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr><th>Date</th><th>Field</th><th>Before</th><th>After</th></tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($history_sql) == 0) { ?>
                                    <tr><td colspan="4" class="text-center text-secondary">No changes logged yet.</td></tr>
                                <?php } else {
                                    while ($h = mysqli_fetch_assoc($history_sql)) { ?>
                                    <tr>
                                        <td><?= nullable_htmlentities($h['domain_history_modified_at']) ?></td>
                                        <td><?= nullable_htmlentities($h['domain_history_column']) ?></td>
                                        <td><?= nullable_htmlentities($h['domain_history_old_value']) ?></td>
                                        <td><?= nullable_htmlentities($h['domain_history_new_value']) ?></td>
                                    </tr>
                                <?php } } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-dark mb-3">
                <div class="card-header py-2">
                    <h3 class="card-title mt-2"><i class="fa fa-fw fa-lock me-2"></i>Certificates</h3>
                </div>
                <div class="card-body p-0">
                    <?php if (mysqli_num_rows($cert_sql) == 0) { ?>
                        <p class="text-secondary p-3 mb-0">No certificates on file for this domain.</p>
                    <?php } else { ?>
                        <table class="table table-sm table-striped mb-0">
                            <tbody>
                                <?php while ($c = mysqli_fetch_assoc($cert_sql)) {
                                    $c_days = $c['certificate_expire'] ? (strtotime($c['certificate_expire']) - time()) / 86400 : null;
                                    $c_class = $c_days === null ? 'text-secondary' : ($c_days <= 0 ? 'text-secondary' : ($c_days <= 14 ? 'text-danger' : ($c_days <= 90 ? 'text-warning' : '')));
                                    ?>
                                <tr>
                                    <td>
                                        <a href="certificate_details.php?id=<?= intval($c['certificate_id']) ?>"><?= nullable_htmlentities($c['certificate_name']) ?></a>
                                        <div class="small text-secondary"><?= nullable_htmlentities($c['certificate_issued_by']) ?: '-' ?></div>
                                    </td>
                                    <td class="text-end <?= $c_class ?>"><?= $c['certificate_expire'] ? nullable_htmlentities($c['certificate_expire']) : '-' ?></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

    <?php
}

require_once "../includes/footer.php";

<?php

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND certificate_client_id = $client_id";
} else {
    require_once "includes/inc_client_overview_all.php";
    $client_query = '';
}

enforceUserPermission('module_support');

$certificate_id = intval($_GET['id'] ?? 0);

$sql = mysqli_query($mysqli, "SELECT certificates.*, clients.client_name,
    d.domain_id AS linked_domain_id, d.domain_name AS linked_domain_name
    FROM certificates
    LEFT JOIN clients ON client_id = certificate_client_id
    LEFT JOIN domains d ON d.domain_id = certificates.certificate_domain_id
    WHERE certificate_id = $certificate_id
    $client_query
    LIMIT 1");

if (mysqli_num_rows($sql) == 0) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='javascript:history.back()'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
} else {
    $row = mysqli_fetch_assoc($sql);

    $client_id = intval($row['certificate_client_id']);
    enforceClientAccess($client_id);

    $client_name = nullable_htmlentities($row['client_name']);
    $certificate_name = nullable_htmlentities($row['certificate_name']);
    $certificate_description = nullable_htmlentities($row['certificate_description']);
    $certificate_domain = nullable_htmlentities($row['certificate_domain']);
    $certificate_domain_id = intval($row['certificate_domain_id']);
    $linked_domain_name = nullable_htmlentities($row['linked_domain_name']);
    $certificate_issued_by = nullable_htmlentities($row['certificate_issued_by']);
    $certificate_public_key = nullable_htmlentities($row['certificate_public_key']);
    $certificate_notes = nullable_htmlentities($row['certificate_notes']);
    $certificate_expire = $row['certificate_expire'];
    $certificate_created_at = nullable_htmlentities($row['certificate_created_at']);

    $days_until_expiry = $certificate_expire ? (strtotime($certificate_expire) - time()) / 86400 : null;
    if ($days_until_expiry === null) {
        $expire_badge = ['label' => 'No expiry on file', 'class' => 'bg-secondary'];
    } elseif ($days_until_expiry <= 0) {
        $expire_badge = ['label' => 'Expired', 'class' => 'bg-secondary'];
    } elseif ($days_until_expiry <= 1) {
        $expire_badge = ['label' => 'Expiring today', 'class' => 'bg-danger'];
    } elseif ($days_until_expiry <= 7) {
        $expire_badge = ['label' => 'Renew soon', 'class' => 'bg-warning text-dark'];
    } else {
        $expire_badge = ['label' => 'Active', 'class' => 'bg-success'];
    }

    // Sibling certificates on the same domain - the cross-link this page adds.
    $siblings_sql = null;
    if ($certificate_domain_id > 0) {
        $siblings_sql = mysqli_query($mysqli, "SELECT certificate_id, certificate_name, certificate_issued_by, certificate_expire
            FROM certificates
            WHERE certificate_domain_id = $certificate_domain_id AND certificate_id != $certificate_id AND certificate_archived_at IS NULL
            ORDER BY certificate_expire IS NULL, certificate_expire ASC");
    }

    $history_sql = mysqli_query($mysqli, "SELECT * FROM certificate_history WHERE certificate_history_certificate_id = $certificate_id ORDER BY certificate_history_modified_at DESC");
    ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $client_id ? "certificates.php?client_id=$client_id" : "certificates.php?scope=company" ?>">Certificates</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= $certificate_name ?></li>
        </ol>
    </nav>

    <div class="row">
        <div class="col-lg-8">
            <div class="card card-dark mb-3">
                <div class="card-header py-2">
                    <h3 class="card-title mt-2"><i class="fa fa-fw fa-lock me-2"></i><?= $certificate_name ?></h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-light ajax-modal"
                            data-modal-url="modals/certificate/certificate_edit.php?id=<?= $certificate_id ?>">
                            <i class="fas fa-fw fa-edit me-2"></i>Edit
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($certificate_description) { ?>
                        <p class="text-secondary mb-3"><?= $certificate_description ?></p>
                    <?php } ?>

                    <span class="badge <?= $expire_badge['class'] ?> mb-3"><?= $expire_badge['label'] ?></span>

                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-secondary" style="width:180px">Department</td>
                            <td><a href="clients.php?client_id=<?= $client_id ?>"><?= $client_name ?></a></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Domain</td>
                            <td>
                                <?php if ($certificate_domain_id > 0 && $linked_domain_name) { ?>
                                    <a href="domain_details.php?id=<?= $certificate_domain_id ?>"><?= $linked_domain_name ?></a>
                                <?php } elseif ($certificate_domain) { ?>
                                    <?= $certificate_domain ?> <span class="text-secondary small">(not linked to a Domain record)</span>
                                <?php } else { ?>
                                    -
                                <?php } ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Issued By</td>
                            <td><?= $certificate_issued_by ?: '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Expires</td>
                            <td><?= $certificate_expire ? nullable_htmlentities($certificate_expire) . " <small class='text-secondary'>(" . timeAgo($certificate_expire) . ")</small>" : '-' ?></td>
                        </tr>
                        <tr>
                            <td class="text-secondary">Added</td>
                            <td><?= $certificate_created_at ?: '-' ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php if ($certificate_public_key) { ?>
            <div class="card card-dark mb-3">
                <div class="card-header py-2"><h3 class="card-title mt-2"><i class="fa fa-fw fa-key me-2"></i>Public Key</h3></div>
                <div class="card-body">
                    <pre class="bg-light p-2 border rounded mb-0" style="max-height:300px;overflow:auto;white-space:pre-wrap;"><?= $certificate_public_key ?></pre>
                </div>
            </div>
            <?php } ?>

            <?php if ($certificate_notes) { ?>
            <div class="card card-dark mb-3">
                <div class="card-header py-2"><h3 class="card-title mt-2"><i class="fa fa-fw fa-sticky-note me-2"></i>Notes</h3></div>
                <div class="card-body"><?= nl2br($certificate_notes) ?></div>
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
                                        <td><?= nullable_htmlentities($h['certificate_history_modified_at']) ?></td>
                                        <td><?= nullable_htmlentities($h['certificate_history_column']) ?></td>
                                        <td><?= nullable_htmlentities($h['certificate_history_old_value']) ?></td>
                                        <td><?= nullable_htmlentities($h['certificate_history_new_value']) ?></td>
                                    </tr>
                                <?php } } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if ($certificate_domain_id > 0) { ?>
            <div class="card card-dark mb-3">
                <div class="card-header py-2">
                    <h3 class="card-title mt-2"><i class="fa fa-fw fa-globe me-2"></i>Same Domain</h3>
                </div>
                <div class="card-body p-0">
                    <div class="p-3 border-bottom">
                        <a href="domain_details.php?id=<?= $certificate_domain_id ?>"><i class="fa fa-fw fa-globe me-1"></i><?= $linked_domain_name ?></a>
                    </div>
                    <?php if (!$siblings_sql || mysqli_num_rows($siblings_sql) == 0) { ?>
                        <p class="text-secondary p-3 mb-0">No other certificates on file for this domain.</p>
                    <?php } else { ?>
                        <table class="table table-sm table-striped mb-0">
                            <tbody>
                                <?php while ($s = mysqli_fetch_assoc($siblings_sql)) { ?>
                                <tr>
                                    <td>
                                        <a href="certificate_details.php?id=<?= intval($s['certificate_id']) ?>"><?= nullable_htmlentities($s['certificate_name']) ?></a>
                                        <div class="small text-secondary"><?= nullable_htmlentities($s['certificate_issued_by']) ?: '-' ?></div>
                                    </td>
                                    <td class="text-end"><?= $s['certificate_expire'] ? nullable_htmlentities($s['certificate_expire']) : '-' ?></td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>

    <?php
}

require_once "../includes/footer.php";

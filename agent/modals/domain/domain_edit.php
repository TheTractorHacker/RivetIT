<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$domain_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM domains WHERE domain_id = $domain_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$domain_name = nullable_htmlentities($row['domain_name']);
$domain_description = nullable_htmlentities($row['domain_description']);
$domain_expire = nullable_htmlentities($row['domain_expire']);
// WHOIS-derived data (functions.php's getDomainRecords(), refreshed by
// cron/domain_refresher.php) - read-only, same as domain_ip/name_servers/mail_servers/
// txt/raw_whois below; nothing here is a user-editable form field.
$domain_registered_at = nullable_htmlentities($row['domain_registered_at']);
$domain_registered_ago = timeAgo($row['domain_registered_at']);
$domain_registrar_name = nullable_htmlentities($row['domain_registrar_name']);
$domain_status = nullable_htmlentities($row['domain_status']);
$domain_dnssec = nullable_htmlentities($row['domain_dnssec']);
$domain_registrar = intval($row['domain_registrar']);
$domain_webhost = intval($row['domain_webhost']);
$domain_dnshost = intval($row['domain_dnshost']);
$domain_mailhost = intval($row['domain_mailhost']);
$domain_ip = nullable_htmlentities($row['domain_ip']);
$domain_name_servers = nullable_htmlentities($row['domain_name_servers']);
$domain_mail_servers = nullable_htmlentities($row['domain_mail_servers']);
$domain_txt = nullable_htmlentities($row['domain_txt']);
$domain_raw_whois = nullable_htmlentities($row['domain_raw_whois']);
$domain_notes = nullable_htmlentities($row['domain_notes']);
$domain_created_at = nullable_htmlentities($row['domain_created_at']);
$domain_archived_at = nullable_htmlentities($row['domain_archived_at']);
$client_id = intval($row['domain_client_id']);

$history_sql = mysqli_query($mysqli, "SELECT * FROM domain_history WHERE domain_history_domain_id = $domain_id");

enforceClientAccess();

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-globe me-2"></i>Editing domain: <span class="text-bold"><?php echo $domain_name; ?></span></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="domain_id" value="<?php echo $domain_id; ?>">

    <div class="modal-body">

        <ul class="nav nav-pills nav-justified mb-3">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="pill" href="#pills-overview<?php echo $domain_id; ?>">Overview</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-records<?php echo $domain_id; ?>">Records</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pillsEditNotes<?php echo $domain_id; ?>">Notes</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pillsEditHistory<?php echo $domain_id; ?>">History</a>
            </li>
        </ul>

        <hr>

        <div class="tab-content" <?php if (lookupUserPermission('module_support') <= 1) { echo 'inert'; } ?>>

            <div class="tab-pane fade show active" id="pills-overview<?php echo $domain_id; ?>">

                <div class="form-group">
                    <label>Domain Name <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-globe"></i></span>
                        </div>
                        <input type="text" class="form-control" name="name" placeholder="Domain name example.com" maxlength="200" value="<?php echo $domain_name; ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-align-left"></i></span>
                        </div>
                        <input type="text" class="form-control" name="description" placeholder="Short Description" value="<?php echo $domain_description; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <?php
                    /* Registrar/Webhost/DNSHost/Mailhost dropdowns below include CENTRAL
                       vendors (vendor_client_id = 0) as well as this domain's own
                       department's vendors - not vendor_client_id = $client_id alone, which
                       is what these queries used to say. A shared registrar or DNS provider
                       used across many departments (Cloudflare, GoDaddy, etc.) is exactly
                       what "Central" vendor scope exists for (agent/vendors.php's own
                       ?client_id=0 view), and excluding it meant these dropdowns held
                       nothing for any department that had only central vendors on file -
                       which, combined with Vendors being unreachable from the nav until
                       today, was the whole of why editing a domain's hosting looked like a
                       read-only view: the selects rendered with "- Select Vendor -" and
                       nothing else to pick. */
                    ?>
                    <label>Domain Registrar</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-server"></i></span>
                        </div>
                        <select class="form-control select2" name="registrar">
                            <option value="">Select Vendor</option>
                            <?php
                            $vendor_sql = mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_archived_at IS NULL AND (vendor_client_id = $client_id OR vendor_client_id = 0) ORDER BY (vendor_client_id = 0), vendor_name ASC");
                                while ($row = mysqli_fetch_assoc($vendor_sql)) {
                                    $vendor_id = $row['vendor_id'];
                                    $vendor_name = $row['vendor_name'];
                                ?>
                                <option <?php if ($domain_registrar == $vendor_id) { echo "selected"; } ?> value="<?php echo $vendor_id; ?>"><?php echo $vendor_name; ?></option>
                            <?php
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Webhost</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-server"></i></span>
                        </div>
                        <select class="form-control select2" name="webhost">
                            <option value="">Select Vendor</option>
                            <?php
                            $vendor_sql = mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_archived_at IS NULL AND (vendor_client_id = $client_id OR vendor_client_id = 0) ORDER BY (vendor_client_id = 0), vendor_name ASC");
                                while ($row = mysqli_fetch_assoc($vendor_sql)) {
                                    $vendor_id = $row['vendor_id'];
                                    $vendor_name = $row['vendor_name'];
                                ?>
                                <option <?php if ($domain_webhost == $vendor_id) { echo "selected"; } ?> value="<?php echo $vendor_id; ?>"><?php echo $vendor_name; ?></option>
                            <?php
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>DNS Host</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-server"></i></span>
                        </div>
                        <select class="form-control select2" name="dnshost">
                            <option value="">Select Vendor</option>
                            <?php
                            $vendor_sql = mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_archived_at IS NULL AND (vendor_client_id = $client_id OR vendor_client_id = 0) ORDER BY (vendor_client_id = 0), vendor_name ASC");
                                while ($row = mysqli_fetch_assoc($vendor_sql)) {
                                    $vendor_id = $row['vendor_id'];
                                    $vendor_name = $row['vendor_name'];
                                ?>
                                <option <?php if ($domain_dnshost == $vendor_id) { echo "selected"; } ?> value="<?php echo $vendor_id; ?>"><?php echo $vendor_name; ?></option>
                            <?php
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Mail Host</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                        </div>
                        <select class="form-control select2" name="mailhost">
                            <option value="">Select Vendor</option>
                            <?php
                            $vendor_sql = mysqli_query($mysqli, "SELECT vendor_id, vendor_name FROM vendors WHERE vendor_archived_at IS NULL AND (vendor_client_id = $client_id OR vendor_client_id = 0) ORDER BY (vendor_client_id = 0), vendor_name ASC");
                                while ($row = mysqli_fetch_assoc($vendor_sql)) {
                                    $vendor_id = $row['vendor_id'];
                                    $vendor_name = $row['vendor_name'];
                                ?>
                                <option <?php if ($domain_mailhost == $vendor_id) { echo "selected"; } ?> value="<?php echo $vendor_id; ?>"><?php echo $vendor_name; ?></option>
                            <?php
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Expire Date</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-calendar-times"></i></span>
                        </div>
                        <input type="date" class="form-control" name="expire" max="2999-12-31" value="<?php echo $domain_expire; ?>">
                    </div>
                </div>

                <?php if ($domain_registered_at) { ?>
                <div class="form-group">
                    <!-- WHOIS-reported creation date - refreshed data, not user-editable,
                         same reasoning as the disabled Records-tab fields below. -->
                    <label>Domain Age</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-birthday-cake"></i></span>
                        </div>
                        <input type="text" class="form-control" value="Registered <?php echo $domain_registered_at; ?> (<?php echo $domain_registered_ago; ?>)" disabled>
                    </div>
                </div>
                <?php } ?>

            </div>

            <div class="tab-pane fade" id="pills-records<?php echo $domain_id; ?>">

                <div class="form-group">
                    <label>Domain IP(s)</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-project-diagram"></i></span>
                        </div>
                        <textarea class="form-control" rows="1" name="domain_ip" disabled><?php echo $domain_ip; ?></textarea>
                    </div>
                </div>

                <div class="form-group">
                    <label>Name Servers</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-crown"></i></span>
                        </div>
                        <textarea class="form-control" rows="1" name="name_servers" disabled><?php echo $domain_name_servers; ?></textarea>
                    </div>
                </div>

                <div class="form-group">
                    <label>MX Records</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-mail-bulk"></i></span>
                        </div>
                        <textarea class="form-control" rows="1" name="mail_servers" disabled><?php echo $domain_mail_servers; ?></textarea>
                    </div>
                </div>

                <div class="form-group">
                    <label>TXT Records</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-check-double"></i></span>
                        </div>
                        <textarea class="form-control" rows="1" name="txt_records" disabled><?php echo $domain_txt; ?></textarea>
                    </div>
                </div>

                <div class="form-group">
                    <!-- The WHOIS record's own reported registrar name - distinct from
                         the "Domain Registrar" vendor picked on the Overview tab, which is
                         which vendors.* row ITFlow considers the registrar. -->
                    <label>WHOIS Registrar</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-building"></i></span>
                        </div>
                        <input type="text" class="form-control" name="whois_registrar" value="<?php echo $domain_registrar_name; ?>" disabled>
                    </div>
                </div>

                <div class="form-group">
                    <label>Domain Status</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-shield-alt"></i></span>
                        </div>
                        <textarea class="form-control" rows="1" name="domain_status" disabled><?php echo $domain_status; ?></textarea>
                    </div>
                </div>

                <div class="form-group">
                    <label>DNSSEC</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span>
                        </div>
                        <input type="text" class="form-control" name="dnssec" value="<?php echo $domain_dnssec; ?>" disabled>
                    </div>
                </div>

                <div class="form-group">
                    <label>Raw WHOIS</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-search-plus"></i></span>
                        </div>
                        <textarea class="form-control" rows="6" name="raw_whois" disabled><?php echo $domain_raw_whois; ?></textarea>
                    </div>
                </div>

            </div>

            <div class="tab-pane fade" id="pillsEditNotes<?php echo $domain_id; ?>">
                <div class="form-group">
                    <textarea class="form-control" name="notes" rows="12" placeholder="Enter some notes"><?php echo $domain_notes; ?></textarea>
                </div>
            </div>

            <div class="tab-pane fade" id="pillsEditHistory<?php echo $domain_id; ?>">
                <div class="table-responsive">
                    <table class='table table-sm table-striped border table-hover'>
                        <thead class='thead-dark'>
                            <tr>
                                <th>Date</th>
                                <th>Field</th>
                                <th>Before</th>
                                <th>After</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                                while ($row = mysqli_fetch_assoc($history_sql)) {
                                $domain_modified_at = nullable_htmlentities($row['domain_history_modified_at']);
                                $domain_field = nullable_htmlentities($row['domain_history_column']);
                                $domain_before_value = nullable_htmlentities($row['domain_history_old_value']);
                                $domain_after_value = nullable_htmlentities($row['domain_history_new_value']);
                            ?>
                            <tr>
                                <td><?php echo $domain_modified_at; ?></td>
                                <td><?php echo $domain_field; ?></td>
                                <td><?php echo $domain_before_value; ?></td>
                                <td><?php echo $domain_after_value; ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_domain" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';

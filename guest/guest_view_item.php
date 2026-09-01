<?php
header('Expires: Sun, 01 Jan 2014 00:00:00 GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

require_once "includes/inc_all_guest.php";


//Initialize the HTML Purifier to prevent XSS
require "../plugins/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null); // Disable cache by setting a non-existent directory or an invalid one
$purifier_config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);

$sql = mysqli_query($mysqli, "SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");
$row = mysqli_fetch_assoc($sql);

$company_name = nullable_htmlentities($row['company_name']);
$company_address = nullable_htmlentities($row['company_address']);
$company_city = nullable_htmlentities($row['company_city']);
$company_state = nullable_htmlentities($row['company_state']);
$company_zip = nullable_htmlentities($row['company_zip']);
$company_phone_country_code = nullable_htmlentities($row['company_phone_country_code']);
$company_phone = nullable_htmlentities(formatPhoneNumber($row['company_phone'], $company_phone_country_code));
$company_email = nullable_htmlentities($row['company_email']);
$company_website = nullable_htmlentities($row['company_website']);
$company_logo = nullable_htmlentities($row['company_logo']);
$company_locale = nullable_htmlentities($row['company_locale']);
$config_invoice_footer = nullable_htmlentities($row['config_invoice_footer']);

//Set Currency Format
$currency_format = numfmt_create($company_locale, NumberFormatter::CURRENCY);

?>

<?php
if (!isset($_GET['id']) || !isset($_GET['key'])) {
    echo "<div class='alert alert-danger'>Incorrect URL.</div>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

    exit();
}

$item_id = intval($_GET['id']);
$item_key = sanitizeInput($_GET['key']);

$sql = mysqli_query($mysqli, "SELECT * FROM shared_items WHERE item_id = $item_id AND item_key = '$item_key' AND item_expire_at > NOW() LIMIT 1");
$row = mysqli_fetch_assoc($sql);

// Check we got a result
if (mysqli_num_rows($sql) !== 1 || !$row) {
    echo "<div class='alert alert-danger' >No item to view. Check with the person that sent you this link to ensure it is correct and has not expired.</div>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

    exit();
}

// Check item share is active & hasn't been viewed too many times but allow 0 views as that is consider infinite views
if ($row['item_active'] !== "1" || ($row['item_view_limit'] > 0 && $row['item_views'] >= $row['item_view_limit'])) {
    echo "<div class='alert alert-danger'>Item cannot be viewed at this time. Check with the person that sent you this link to ensure it is correct and has not expired.</div>";
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

    exit();
}

// If we got here, we have valid information

$item_type = nullable_htmlentities($row['item_type']);
$item_related_id = intval($row['item_related_id']);
$item_encrypted_credential = nullable_htmlentities($row['item_encrypted_credential']);
$item_note = nullable_htmlentities($row['item_note']);
$item_recipient = nullable_htmlentities($row['item_recipient']);
$item_views = intval($row['item_views']);
$item_view_limit = intval($row['item_view_limit']);
$item_created = nullable_htmlentities($row['item_created_at']);
$item_expire = date('Y-m-d h:i A', strtotime($row['item_expire_at']));
$client_id = intval($row['item_client_id']);

// Create in-app notification
$item_type_sql_escaped = sanitizeInput($row['item_type']);
$item_recipient_sql_escaped = sanitizeInput($row['item_recipient']);

appNotify("Share Viewed", "$item_type_sql_escaped has been viewed by $item_recipient_sql_escaped", "/agent/client_overview.php?client_id=$client_id", $client_id);

?>

<?php
    if (!empty($company_logo)) { ?>
            <img alt="<?=nullable_htmlentities($company_name)?> logo" height="40" width="80" class="img-fluid" src="<?php echo "../uploads/settings/$company_logo"; ?>">
        <?php
        } else {
            echo "<h3>$company_name</h3>";
        }
?>

<div class="card mt-2">
    <div class="card-header bg-dark">
        <div class="card-title">
            <h6><small>Secure link intended for:</small><br><strong><?php echo $item_recipient ?></strong></h6>
        </div>

        <div class="card-tools">
            <div>
                <?php echo "Viewed: <strong>$item_views</strong> Times"; ?>
            </div>
            <div>
                <?php echo "Expires: <strong>$item_expire</strong>"; ?>
            </div>
        </div>
    </div>

    <div class="card-body">


<?php
if ($item_type == "Document") {

    $doc_sql = mysqli_query($mysqli, "SELECT * FROM documents WHERE document_id = $item_related_id AND document_client_id = $client_id LIMIT 1");
    $doc_row = mysqli_fetch_assoc($doc_sql);

    if (mysqli_num_rows($doc_sql) !== 1 || !$doc_row) {
        echo "<div class='alert alert-danger'>Error retrieving document to view.</div>";
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

        exit();
    }

    $doc_title = nullable_htmlentities($doc_row['document_name']);
    $doc_title_escaped = sanitizeInput($doc_row['document_name']);
    $doc_content = $purifier->purify($doc_row['document_content']);

    echo "<h3>$doc_title</h3>";
    echo "<div class='prettyContent'>$doc_content</div>";

    // Update document view count
    $new_item_views = $item_views + 1;
    mysqli_query($mysqli, "UPDATE shared_items SET item_views = $new_item_views WHERE item_id = $item_id");

    // Logging
    $name = mysqli_real_escape_string($mysqli, $doc_title);
    logAction("Share", "View", "Viewed shared $item_type $doc_title_escaped via link", $client_id);


} elseif ($item_type == "File") {
    $file_sql = mysqli_query($mysqli, "SELECT * FROM files WHERE file_id = $item_related_id AND file_client_id = $client_id LIMIT 1");
    $file_row = mysqli_fetch_assoc($file_sql);

    if (mysqli_num_rows($file_sql) !== 1 || !$file_row) {
        echo "<div class='alert alert-danger'>Error retrieving file.</div>";
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

        exit();
    }

    $file_name = nullable_htmlentities($file_row['file_name']);

    echo "<h3>A file has been shared with you</h3>";
    if (!empty($item_note)) {
        echo "<p class='lead'>Note: <i>$item_note</i></p>";
    }
    echo "<a href='guest_download_file.php?id=$item_id&key=$item_key'>Download $file_name</a>";


} elseif ($item_type == "Credential") {

    $credential_sql = mysqli_query($mysqli, "SELECT * FROM credentials WHERE credential_id = $item_related_id AND credential_client_id = $client_id LIMIT 1");
    $credential_row = mysqli_fetch_assoc($credential_sql);
    if (mysqli_num_rows($credential_sql) !== 1 || !$credential_row) {
        echo "<div class='alert alert-danger'>Error retrieving login.</div>";
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

        exit();
    }

    $credential_id = intval($credential_row['credential_id']);
    $credential_name = nullable_htmlentities($credential_row['credential_name']);
    $credential_uri = nullable_htmlentities($credential_row['credential_uri']);
    $credential_notes = nullable_htmlentities($credential_row['credential_note']);

    // The AES key ('ek') is passed in the URL fragment (see agent/ajax.php), which is
    // never sent to the server - so it can't be read from $_GET/$_SERVER here at all.
    // Decryption happens client-side in JS after reading window.location.hash; the
    // values below are the raw encrypted blobs, base64-encoded only so they can be
    // embedded safely as HTML attributes (this is NOT the decryption key and reveals
    // nothing without 'ek').
    $enc_username_b64 = base64_encode($row['item_encrypted_username'] ?? '');
    $enc_password_b64 = base64_encode($row['item_encrypted_credential'] ?? '');
    $enc_otp_b64 = !empty($row['item_encrypted_otp']) ? base64_encode($row['item_encrypted_otp']) : '';

    ?>

    <h5><?php echo $credential_name; ?></h5>
    <table class="table col-md-3">
        <tr>
            <th>URL</th>
            <td><?php echo $credential_uri; ?></td>
        </tr>
        <tr>
            <th>Username</th>
            <td><span id="credUsername" class="js-shared-credential-field" data-enc="<?= $enc_username_b64 ?>">Decrypting&hellip;</span></td>
        </tr>
        <tr>
            <th>Password</th>
            <td><span id="credPassword" class="js-shared-credential-field" data-enc="<?= $enc_password_b64 ?>">Decrypting&hellip;</span></td>
        </tr>
        <?php if (!empty($enc_otp_b64)) { ?>
        <tr>
            <th>2FA (TOTP)</th>
            <td>
                <span class="js-show-otp" data-enc="<?= $enc_otp_b64 ?>" data-credential-id="<?= $credential_id ?>">
                    <i class="far fa-clock"></i> <span id="otp_<?= $credential_id ?>"><i>Hover..</i></span>
                </span>
            </td>
        </tr>
        <?php } ?>

    </table>

    <noscript>
        <div class="alert alert-warning mt-2">This page requires JavaScript to decrypt and display the shared login.</div>
    </noscript>
    <div id="sharedCredentialKeyMissing" class="alert alert-danger mt-2 d-none">
        Check your link - the decryption key is missing or the link was copied incorrectly. Please use the original link you were sent.
    </div>

    <script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
        (function () {
            // 'ek' lives only in the URL fragment (never transmitted to the server) -
            // see the PHP comment above for why.
            var hashParams = new URLSearchParams((window.location.hash || '').replace(/^#/, ''));
            var ek = hashParams.get('ek');

            function showKeyMissing() {
                document.getElementById('sharedCredentialKeyMissing').classList.remove('d-none');
                document.querySelectorAll('.js-shared-credential-field').forEach(function (el) {
                    el.textContent = '(unavailable)';
                });
            }

            if (!ek) {
                showKeyMissing();
                return;
            }

            function b64ToBytes(b64) {
                var bin = atob(b64);
                var bytes = new Uint8Array(bin.length);
                for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
                return bytes;
            }

            // Matches agent/ajax.php's share encryption: a raw 16-byte IV followed by
            // openssl_encrypt()'s default (base64-encoded) AES-128-CBC ciphertext, the
            // whole blob then base64-encoded again server-side purely for safe HTML
            // attribute embedding (decoded back out again here).
            function decryptField(enc, keyStr) {
                if (!enc) return Promise.resolve('');
                var combined = b64ToBytes(enc);
                var iv = combined.slice(0, 16);
                var cipherB64 = new TextDecoder().decode(combined.slice(16));
                var cipherBytes = b64ToBytes(cipherB64);
                var keyBytes = new TextEncoder().encode(keyStr);
                return crypto.subtle.importKey('raw', keyBytes, {name: 'AES-CBC'}, false, ['decrypt'])
                    .then(function (cryptoKey) {
                        return crypto.subtle.decrypt({name: 'AES-CBC', iv: iv}, cryptoKey, cipherBytes);
                    })
                    .then(function (plainBuf) {
                        return new TextDecoder().decode(plainBuf);
                    });
            }

            document.querySelectorAll('.js-shared-credential-field').forEach(function (el) {
                decryptField(el.dataset.enc, ek).then(function (plain) {
                    el.textContent = plain || '-';
                }).catch(function () {
                    el.textContent = '(decryption failed)';
                });
            });

            // Delegated listener (CSP blocks inline onmouseenter= attributes) - lazily
            // decrypts and fetches the current TOTP code the first time each OTP field
            // is hovered.
            document.addEventListener('mouseover', function (e) {
                var trigger = e.target.closest && e.target.closest('.js-show-otp');
                if (!trigger || trigger.dataset.otpLoaded) return;
                trigger.dataset.otpLoaded = '1';
                decryptField(trigger.dataset.enc, ek).then(function (secret) {
                    if (!secret) return;
                    jQuery.get(
                        '/agent/ajax.php',
                        {get_totp_token: 'true', totp_secret: secret},
                        function (data) {
                            var token = JSON.parse(data);
                            document.getElementById('otp_' + trigger.dataset.credentialId).innerText = token;
                        }
                    );
                });
            });
        })();

        function generatePassword() {
            document.getElementById("password").value = "<?php echo randomString(); ?>"
        }
    </script>


    <?php

    // Update credential view count
    $new_item_views = $item_views + 1;
    mysqli_query($mysqli, "UPDATE shared_items SET item_views = $new_item_views WHERE item_id = $item_id");

    // Logging
    $name = sanitizeInput($credential_row['credential_name']);
    logAction("Share", "View", "Viewed shared $item_type $name via link", $client_id);

}

?>

    <hr>
    <em>
        This message and any attachments are confidential and intended for the specified recipient(s) only. If you are not the intended recipient, please notify us immediately with the contact info below. Unauthorized use, disclosure, or distribution is prohibited.
    </em>

</div>
<div class="card-footer">
<?php echo "<i class='fas fa-phone fa-fw me-2'></i>$company_phone | <i class='fas fa-globe fa-fw me-2 ms-2'></i>$company_website"; ?>
</div>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';

<?php
/*
 * Client Portal
 * Password reset page
 */

header("Content-Security-Policy: default-src 'self'");

require_once '../config.php';
require_once '../functions.php';
require_once '../includes/load_global_settings.php';


if (empty($config_smtp_host)) {
    header("Location: /login.php");
    exit();
}

// Check to see if client portal is enabled
if($config_client_portal_enable == 0) {
    echo "Department Portal is Disabled";
    exit();
}

if (!isset($_SESSION)) {
    // HTTP Only cookies
    ini_set("session.cookie_httponly", true);
    if ($config_https_only) {
        // Tell client to only send cookie(s) over HTTPS
        ini_set("session.cookie_secure", true);
    }
    session_start();
}

// Set Timezone after session
require_once "../includes/inc_set_timezone.php";

$ip = sanitizeInput(getIP());
$user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT']);

// Get Company Info
$company_sql = mysqli_query($mysqli, "SELECT company_name, company_phone FROM companies WHERE company_id = 1");
$company_results = mysqli_fetch_assoc($company_sql);
$company_name = sanitizeInput($company_results['company_name']);
$company_phone = sanitizeInput(formatPhoneNumber($company_results['company_phone']));
$company_name_display = $company_results['company_name'];

// Get settings from load_global_settings.php and sanitize them
$config_ticket_from_name = sanitizeInput($config_ticket_from_name);
$config_ticket_from_email = sanitizeInput($config_ticket_from_email);
$config_mail_from_name = sanitizeInput($config_mail_from_name);
$config_mail_from_email = sanitizeInput($config_mail_from_email);
$config_base_url = sanitizeInput($config_base_url);

DEFINE("WORDING_ERROR", "Something went wrong! Your link may have expired. Please request a new password reset e-mail.");

if ($_SERVER['REQUEST_METHOD'] == "POST") {

    /*
     * Send password reset email
     */
    if (isset($_POST['password_reset_email_request'])) {

        $email = sanitizeInput($_POST['email']);

        $sql = mysqli_query($mysqli, "SELECT contact_id, contact_name, user_email, contact_client_id, user_id FROM users LEFT JOIN contacts ON user_id = contact_user_id WHERE user_email = '$email' AND user_auth_method = 'local' AND user_type = 2 AND user_status = 1 AND user_archived_at IS NULL LIMIT 1");
        $row = mysqli_fetch_assoc($sql);

        if ($row['user_email'] == $email) {
            $id = intval($row['contact_id']);
            $user_id = intval($row['user_id']);
            $name = sanitizeInput($row['contact_name']);
            $client = intval($row['contact_client_id']);

            $token = randomString(32);
            $token_hash = hash('sha256', $token);
            $url = "https://$config_base_url/client/login_reset.php?email=$email&token=$token&client=$client";
            mysqli_query($mysqli, "UPDATE users SET user_password_reset_token = '$token_hash' WHERE user_id = $user_id LIMIT 1");
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Contact', log_action = 'Modify', log_description = 'Sent a portal password reset e-mail for $email.', log_ip = '$ip', log_user_agent = '$user_agent', log_client_id = $client");

            // Send reset email
            $subject = "Password reset for $company_name Department Portal";
            $body = "Hello $name,<br><br>Someone (probably you) has requested a new password for your account on $company_name\'s Department Portal. <br><br><b>Please <a href=\'$url\'>click here</a> to reset your password.</b> <br><br>Alternatively, copy and paste this URL into your browser:<br> $url<br><br><i>If you didn\'t request this change, you can safely ignore this email.</i><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

            $data = [
                [
                    'from' => $config_mail_from_email,
                    'from_name' => $config_mail_from_name,
                    'recipient' => $email,
                    'recipient_name' => $name,
                    'subject' => $subject,
                    'body' => $body
                ]
            ];
            $mail = addToMailQueue($data);

            // Error handling
            if ($mail !== true) {
                mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Mail', notification = 'Failed to send email to $email'");
                mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Mail', log_action = 'Error', log_description = 'Failed to send email to $email regarding $subject. $mail'");
            }
            //End Mail IF
        }

        $_SESSION['login_message'] = "If your account exists, a reset link is on it's way! Please allow a few minutes for it to reach you.";

    /*
     * Link is being used - Perform password reset
     */
    } elseif (isset($_POST['password_reset_set_password'])) {

        if (!isset($_POST['new_password']) || !isset($_POST['email']) || !isset($_POST['token']) || !isset($_POST['client'])) {
            $_SESSION['login_message'] = WORDING_ERROR;
        }

        $token = sanitizeInput($_POST['token']);
        $token_hash = mysqli_real_escape_string($mysqli, hash('sha256', $token));
        $email = sanitizeInput($_POST['email']);
        $client = intval($_POST['client']);

        // Query user
        $sql = mysqli_query($mysqli, "SELECT * FROM users LEFT JOIN contacts ON user_id = contact_user_id WHERE user_email = '$email' AND user_password_reset_token = '$token_hash' AND contact_client_id = $client AND user_auth_method = 'local' AND user_type = 2 AND user_status = 1 AND user_archived_at IS NULL LIMIT 1");
        $user_row = mysqli_fetch_assoc($sql);
        $contact_id = intval($user_row['contact_id']);
        $user_id = intval($user_row['user_id']);
        $name = sanitizeInput($user_row['contact_name']);

        // Ensure the token is correct
        if ($user_row && hash_equals((string)$user_row['user_password_reset_token'], hash('sha256', $token))) {

            // Set password, invalidate token, logging
            $password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            mysqli_query($mysqli, "UPDATE users SET user_password = '$password', user_password_reset_token = NULL WHERE user_id = $user_id LIMIT 1");
            mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Contact User', log_action = 'Modify', log_description = 'Reset portal password for $email.', log_ip = '$ip', log_user_agent = '$user_agent', log_client_id = $client, log_user_id = $user_id");

            // Send confirmation email
            $subject = "Password reset confirmation for $company_name Department Portal";
            $body = "Hello $name,<br><br>Your password for your account on $company_name\'s Department Portal was successfully reset. You should be all set! <br><br><b>If you didn\'t reset your password, please get in touch ASAP.</b><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";


            $data = [
                [
                    'from' => $config_mail_from_email,
                    'from_name' => $config_mail_from_name,
                    'recipient' => $email,
                    'recipient_name' => $name,
                    'subject' => $subject,
                    'body' => $body
                ]
            ];

            $mail = addToMailQueue($data);

            // Error handling
            if ($mail !== true) {
                mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Mail', notification = 'Failed to send email to $email'");
                mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'Mail', log_action = 'Error', log_description = 'Failed to send email to $email regarding $subject. $mail'");
            }

            // Redirect to login page
            $_SESSION['login_message'] = "Password reset successfully!";
            header("Location: /login.php");
            exit();

        } else {
            $_SESSION['login_message'] = WORDING_ERROR;
        }

    }

}

?>

<!DOCTYPE html>
<?php
/* ---------------------------------------------------------------------------
   TABLER CENTRED-PAGE SHELL (client portal password reset).

   This page used AdminLTE 4's .login-page / .login-box / .login-card-body /
   .login-box-msg for full-viewport centring. NO first-party CSS ever provided
   those, so removing adminlte.min.css without a replacement would have left the
   card unstyled at the top left. Tabler's centred-page pattern replaces them:

       body.d-flex.flex-column
         > div.page.page-center.min-vh-100   <- .page is display:flex/column,
                                                .page-center adds
                                                justify-content:center, and
                                                .min-vh-100 supplies the viewport
                                                height to centre within (Tabler's
                                                .page uses min-height:100%, which
                                                needs an explicit height chain
                                                this standalone page lacks).
           > div.container.container-tight   <- narrow centred column

   *** WHY THIS PAGE USES CLASSES ONLY, NEVER INLINE CSS ***
   Line 7 of this file sends `Content-Security-Policy: default-src 'self'` with
   no style-src of its own, so style-src falls back to default-src = 'self'.
   That forbids BOTH <style> blocks and style="" attributes on this page (unlike
   login.php, which explicitly allows 'unsafe-inline' for styles). Every rule
   below therefore has to come from a linked stylesheet, which is why this page
   accepts Tabler's stock .container-tight width instead of the 400px override
   login.php applies in its own <style> block. Do not add inline CSS here - it
   will be silently dropped by the browser, and the layout will look broken only
   in production where the header is actually sent.

   The reset logic above (token verification, hash_equals, mail queue, logging)
   and the two form branches below are untouched - this is a shell swap.
   .btn-block and .input-group-append are self-hosted in css/itflow_bs5_bridge.css
   (lines ~248 and ~262), not AdminLTE, so the inputs and buttons need no edit.
   --------------------------------------------------------------------------- */
?>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo nullable_htmlentities($company_name_display); ?> | Password Reset</title>

    <!-- Tell the browser to be responsive to screen width -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">

    <!--
    Favicon
    If Fav Icon exists else use the default one
    -->
    <?php if(file_exists('../uploads/favicon.ico')) { ?>
        <link rel="icon" type="image/x-icon" href="../uploads/favicon.ico">
    <?php } ?>

    <!-- Core stack: Tabler 1.5 (vendored, self-contained - zero @font-face, and
         all 28 url() refs are inline data: SVGs. That self-containment is what
         makes it usable under this page's strict default-src 'self' policy,
         which permits no external font or image host at all).
         Tabler bundles its own Bootstrap 5 build, so
         plugins/bootstrap5/css/bootstrap.min.css and
         plugins/adminlte4/css/adminlte.min.css are both gone. -->
    <link rel="stylesheet" href="../plugins/tabler/css/tabler.min.css">

    <!-- Theme: BS5 bridge (self-hosted components + app shims) THEN the custom
         theme THEN the design layer. -->
    <link rel="stylesheet" href="../css/itflow_bs5_bridge.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_bs5_bridge.css') ?>">
    <link rel="stylesheet" href="../css/itflow_custom.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_custom.css') ?>">
    <link rel="stylesheet" href="../css/itflow_design.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_design.css') ?>">

    <!-- Token seam: maps this app's --if-* / --color-* tokens onto Tabler's --tblr-*. -->
    <link rel="stylesheet" href="../css/itflow.bind-tabler.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow.bind-tabler.css') ?>">

</head>

<body class="d-flex flex-column">
<div class="page page-center min-vh-100">
    <div class="container container-tight py-4">

        <div class="text-center mb-4">
            <div class="h2 mb-1"><b><?php echo nullable_htmlentities($company_name_display); ?></b></div>
            <div class="text-muted">Password Reset</div>
        </div>

        <div class="card card-md">
            <div class="card-body">

            <form method="post">

                <?php
                /*
                 * Password reset form
                 */
                if (isset($_GET['token']) && isset($_GET['email']) && isset($_GET['client'])) {

                    $token = sanitizeInput($_GET['token']);
                    $email = sanitizeInput($_GET['email']);
                    $client = intval($_GET['client']);

                    $token_hash_get = mysqli_real_escape_string($mysqli, hash('sha256', $token));
                    $sql = mysqli_query($mysqli, "SELECT * FROM users LEFT JOIN contacts ON user_id = contact_user_id WHERE user_email = '$email' AND user_password_reset_token = '$token_hash_get' AND contact_client_id = $client LIMIT 1");
                    $user_row = mysqli_fetch_assoc($sql);

                    // Sanity check
                    if ($user_row && hash_equals((string)$user_row['user_password_reset_token'], hash('sha256', $token))) { ?>

                        <div class="input-group mb-3">
                            <input type="password" class="form-control" placeholder="New Password" name="new_password" required minlength="8">
                            <div class="input-group-append">
                                <div class="input-group-text">
                                    <span class="fas fa-lock"></span>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" name="token" value="<?php echo nullable_htmlentities($token); ?>">
                        <input type="hidden" name="email" value="<?php echo nullable_htmlentities($email); ?>">
                        <input type="hidden" name="client" value="<?php echo $client; ?>">

                        <button type="submit" class="btn btn-success btn-block mb-3" name="password_reset_set_password">Reset password</button>


                    <?php } else {

                        $_SESSION['login_message'] = WORDING_ERROR;

                    }


                    /*
                     * Else: Just show the form to request a reset token email
                     */
                } else { ?>

                    <div class="input-group mb-3">
                        <input type="email" class="form-control" placeholder="Registered Department Email" name="email" required autofocus>
                        <div class="input-group-append">
                            <div class="input-group-text">
                                <span class="fas fa-envelope"></span>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success btn-block mb-3" name="password_reset_email_request">Reset my password</button>

                <?php }
                ?>

            </form>

            <?php
            /* Was <p class="login-box-msg text-danger">. .login-box-msg was an
               AdminLTE class (display:block; margin:0; padding:0 20px 20px;
               text-align:center) with no first-party replacement, so it is
               expressed with Bootstrap utilities instead. Same look, no
               dependency on adminlte.min.css. */
            ?>
            <p class="text-center text-danger">
                <?php
                // Show feedback from session
                if (!empty($_SESSION['login_message'])) {
                    echo nullable_htmlentities($_SESSION['login_message']);
                    unset($_SESSION['login_message']);
                }
                ?>
            </p>

            <a href="/login.php">Back to login</a>


            </div>
            <!-- /.card-body -->

        </div>
        <!-- /.card -->

    </div>
    <!-- /.container-tight -->
</div>
<!-- /.page.page-center -->

<!-- jQuery -->
<script src="../plugins/jquery/jquery.min.js"></script>

<!-- Bootstrap 5 (bundle includes Popper) -->
<script src="../plugins/bootstrap5/js/bootstrap.bundle.min.js"></script>

<?php
/* plugins/adminlte4/js/adminlte.min.js is GONE. It only ever exported
   CardWidget, DirectChat, FullScreen, Layout, PushMenu and Treeview - none of
   which exist on a password-reset page - and nothing here ever called the
   adminlte.* API. Its CSS is replaced by Tabler above. */
?>

<!-- Prevents resubmit on refresh or back -->
<script src="../js/login_prevent_resubmit.js"></script>

</body>
</html>

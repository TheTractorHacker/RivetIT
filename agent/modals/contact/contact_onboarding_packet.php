<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_client', 1);
enforceUserPermission('module_credential', 2);

// Two modes, resolved from database rows only (the department never comes from the request):
//   ticket mode  (?ticket_id=): a new hire with no contact yet, in the ticket's department. The name is typed in; the
//                ticket's contact is usually the manager who asked, so it only prefills the Manager field.
//   contact mode (?id=, from the person's page): that contact, read-only.
$ticket_id = intval($_GET['ticket_id'] ?? 0);
$contact_id = $ticket_id > 0 ? 0 : intval($_GET['id'] ?? 0);
if ($ticket_id > 0) {
    enforceUserPermission('module_support', 1);
}
$ctx = \ITFlow\Onboarding\PacketContext::resolve($mysqli, $contact_id, $ticket_id, (int) $session_user_id, (bool) ($session_is_admin ?? false));
if ($ctx === null) {
    // Unknown, archived, department-less or department-denied: one shape.
    http_response_code(404);
    exit;
}
$client_id = $ctx->clientId;
enforceClientAccess($client_id);

$ticket_mode = $ctx->ticket !== null;
$contact_name = nullable_htmlentities($ctx->personName);
$client_name = nullable_htmlentities($ctx->clientName);
$manager_prefill = $ticket_mode ? nullable_htmlentities($ctx->ticketContactName) : '';
$ticket_label = $ticket_mode ? nullable_htmlentities($ctx->ticket['prefix'] . $ctx->ticket['number']) : '';
// Same gate as the ticket page's Upload Attachment: Support edit access and an open ticket.
$attach_ok = $ticket_mode && !$ctx->ticket['closed'] && lookupUserPermission('module_support') >= 2;
$max_software = 25;

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-file-pdf me-2"></i>Onboarding Packet</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="onboarding_packet_pdf.php" method="post" autocomplete="off" id="obpk-form">
    <input type="hidden" name="csrf_token" value="<?= nullable_htmlentities($_SESSION['csrf_token'] ?? '') ?>">
    <?php if ($ticket_mode) { ?>
    <input type="hidden" name="ticket_id" value="<?= intval($ctx->ticket['id']) ?>">
    <?php } else { ?>
    <input type="hidden" name="contact_id" value="<?= intval($ctx->contactId) ?>">
    <?php } ?>

    <div class="modal-body">

        <div class="alert alert-info">
            <i class="fa fa-fw fa-info-circle me-1"></i>
            The PDF downloads; passwords are not stored unless Save to Logins is ticked. Delete the PDF after printing.
            <?php if ($ticket_mode) { ?>
            Logins saved from a ticket go to the department (not to a contact), named after the new hire.
            <?php } ?>
        </div>

        <div class="row">
            <div class="form-group col-md-6">
                <label>Person</label>
                <?php if ($ticket_mode) { ?>
                <input type="text" class="form-control" name="person_name" maxlength="200" required autocomplete="off" placeholder="New hire's name">
                <?php } else { ?>
                <input type="text" class="form-control" value="<?= $contact_name ?>" readonly>
                <?php } ?>
            </div>
            <div class="form-group col-md-6">
                <label>Department</label>
                <input type="text" class="form-control" value="<?= $client_name ?>" readonly>
            </div>
        </div>
        <div class="row">
            <div class="form-group col-md-6">
                <label>Start date</label>
                <input type="text" class="form-control" name="start_date" maxlength="200" placeholder="e.g. 2026-12-01">
            </div>
            <div class="form-group col-md-6">
                <label>Manager</label>
                <input type="text" class="form-control" name="manager_name" maxlength="200" placeholder="Manager name" value="<?= $manager_prefill ?>">
            </div>
        </div>

        <h6 class="mt-2"><i class="fa fa-fw fa-desktop me-2"></i>Computer</h6>
        <div class="row">
            <div class="form-group col-md-4">
                <label>Name</label>
                <input type="text" class="form-control" name="computer[name]" maxlength="200" placeholder="Computer / hostname">
            </div>
            <div class="form-group col-md-4">
                <label>Username</label>
                <input type="text" class="form-control" name="computer[username]" maxlength="200" autocomplete="off">
            </div>
            <div class="form-group col-md-4">
                <label>Password</label>
                <div class="input-group">
                    <input type="password" class="form-control" name="computer[password]" maxlength="500" autocomplete="new-password">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-default obpk-eye" title="Show / hide password"><i class="fa fa-fw fa-eye"></i></button>
                    </div>
                    <div class="input-group-append">
                        <button type="button" class="btn btn-default obpk-gen" title="Generate password"><i class="fa fa-fw fa-question"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="form-group col-md-8">
                <label>Note</label>
                <input type="text" class="form-control" name="computer[note]" maxlength="200">
            </div>
            <div class="form-group col-md-4 d-flex flex-column justify-content-end">
                <input type="hidden" name="computer[change]" value="0">
                <label class="mb-1"><input type="checkbox" name="computer[change]" value="1" checked> Change on first sign-in</label>
                <input type="hidden" name="computer[save]" value="0">
                <label class="mb-1"><input type="checkbox" name="computer[save]" value="1"> Save to Logins</label>
            </div>
        </div>

        <h6 class="mt-2"><i class="fa fa-fw fa-envelope me-2"></i>Email</h6>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="email_same" id="obpk-email-same" value="1">
            <label class="form-check-label" for="obpk-email-same">Same username and password as the computer</label>
        </div>
        <div class="row">
            <div class="form-group col-md-4">
                <label>Address / name</label>
                <input type="text" class="form-control" name="email[name]" maxlength="200" placeholder="Email address">
            </div>
            <div class="form-group col-md-4">
                <label>Username</label>
                <input type="text" class="form-control obpk-email-cred" name="email[username]" maxlength="200" autocomplete="off">
            </div>
            <div class="form-group col-md-4">
                <label>Password</label>
                <div class="input-group">
                    <input type="password" class="form-control obpk-email-cred" name="email[password]" maxlength="500" autocomplete="new-password">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-default obpk-eye obpk-email-cred" title="Show / hide password"><i class="fa fa-fw fa-eye"></i></button>
                    </div>
                    <div class="input-group-append">
                        <button type="button" class="btn btn-default obpk-gen obpk-email-cred" title="Generate password"><i class="fa fa-fw fa-question"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="form-group col-md-4">
                <label>Link</label>
                <input type="text" class="form-control" name="email[uri]" maxlength="200" placeholder="Webmail URL">
            </div>
            <div class="form-group col-md-4">
                <label>Note</label>
                <input type="text" class="form-control" name="email[note]" maxlength="200">
            </div>
            <div class="form-group col-md-4 d-flex flex-column justify-content-end">
                <input type="hidden" name="email[change]" value="0">
                <label class="mb-1"><input type="checkbox" name="email[change]" value="1" checked> Change on first sign-in</label>
                <input type="hidden" name="email[save]" value="0">
                <label class="mb-1"><input type="checkbox" name="email[save]" value="1"> Save to Logins</label>
            </div>
        </div>

        <h6 class="mt-2"><i class="fa fa-fw fa-th-large me-2"></i>Software</h6>
        <div id="obpk-software-rows"></div>
        <button type="button" class="btn btn-sm btn-secondary" id="obpk-add-software"><i class="fa fa-plus me-1"></i>Add software</button>
        <small class="text-muted ms-2">Up to <?= $max_software ?> lines. Blank lines are ignored.</small>

        <?php if ($attach_ok) { ?>
        <div class="border rounded p-2 mt-3" id="obpk-ticket-attach">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" name="attach_ticket" id="obpk-attach-ticket" value="1" checked>
                <label class="form-check-label" for="obpk-attach-ticket">Attach a copy to this ticket (<?= $ticket_label ?>)</label>
            </div>
            <div class="form-check ms-3">
                <input type="checkbox" class="form-check-input" name="attach_passwords" id="obpk-attach-passwords" value="1">
                <label class="form-check-label" for="obpk-attach-passwords">Include passwords in the attached copy</label>
            </div>
            <div class="alert alert-warning mt-2 mb-2 d-none" id="obpk-attach-pw-warning" role="alert"><i class="fa fa-fw fa-exclamation-triangle me-1"></i>The attached file is stored on the server and can be opened by anyone with its link (no sign-in needed), by technicians who can view tickets but not Credentials, and by every primary or technical portal contact of this client. Passwords in it cannot be taken back later by changing the Login.</div>
            <small class="text-muted d-block">The copy hides passwords by default; usernames, links and notes are not hidden.</small>
        </div>
        <?php } ?>

    </div>
    <div class="modal-footer">
        <small class="text-success me-auto d-none" id="obpk-done-note"><i class="fa fa-fw fa-check me-1"></i>Packet generated. Close this window to start another, to avoid saving duplicate Logins.</small>
        <button type="submit" class="btn btn-primary text-bold"><i class="fa fa-file-pdf me-2"></i>Generate PDF</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Close</button>
    </div>
</form>

<template id="obpk-software-template">
    <div class="border rounded p-2 mb-2 obpk-software-row">
        <div class="row">
            <div class="form-group col-md-3">
                <label>Software</label>
                <input type="text" class="form-control" name="software[__I__][name]" maxlength="200" placeholder="e.g. Microsoft 365">
            </div>
            <div class="form-group col-md-3">
                <label>Username</label>
                <input type="text" class="form-control" name="software[__I__][username]" maxlength="200" autocomplete="off">
            </div>
            <div class="form-group col-md-3">
                <label>Password</label>
                <div class="input-group">
                    <input type="password" class="form-control" name="software[__I__][password]" maxlength="500" autocomplete="new-password">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-default obpk-eye" title="Show / hide password"><i class="fa fa-fw fa-eye"></i></button>
                    </div>
                    <div class="input-group-append">
                        <button type="button" class="btn btn-default obpk-gen" title="Generate password"><i class="fa fa-fw fa-question"></i></button>
                    </div>
                </div>
            </div>
            <div class="form-group col-md-3">
                <label>Link</label>
                <input type="text" class="form-control" name="software[__I__][uri]" maxlength="200">
            </div>
        </div>
        <div class="row">
            <div class="form-group col-md-5">
                <label>Note</label>
                <input type="text" class="form-control" name="software[__I__][note]" maxlength="200">
            </div>
            <div class="form-group col-md-5 d-flex flex-column justify-content-end">
                <input type="hidden" name="software[__I__][change]" value="0">
                <label class="mb-1"><input type="checkbox" name="software[__I__][change]" value="1" checked> Change on first sign-in</label>
                <input type="hidden" name="software[__I__][save]" value="0">
                <label class="mb-1"><input type="checkbox" name="software[__I__][save]" value="1"> Save to Logins</label>
            </div>
            <div class="form-group col-md-2 d-flex flex-column justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-danger obpk-remove"><i class="fa fa-times me-1"></i>Remove</button>
            </div>
        </div>
    </div>
</template>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
    (function () {
        var MAX = <?= $max_software ?>;
        var scope = (document.currentScript && document.currentScript.closest('.modal')) || document;
        var form = scope.querySelector('#obpk-form');
        if (!form) { return; }
        var rows = form.querySelector('#obpk-software-rows');
        var tpl = document.getElementById('obpk-software-template');
        var addBtn = form.querySelector('#obpk-add-software');
        var next = 0;

        function refresh() {
            addBtn.disabled = rows.children.length >= MAX;
        }
        function addRow() {
            if (rows.children.length >= MAX) { return; }
            var html = tpl.innerHTML.replace(/__I__/g, String(next++));
            var wrap = document.createElement('div');
            wrap.innerHTML = html;
            rows.appendChild(wrap.firstElementChild);
            refresh();
        }
        addBtn.addEventListener('click', addRow);

        // Delegated: covers dynamically added rows. Same endpoint credential_add.php's generator uses.
        form.addEventListener('click', function (e) {
            var rm = e.target.closest('.obpk-remove');
            if (rm) { rm.closest('.obpk-software-row').remove(); refresh(); return; }
            var eye = e.target.closest('.obpk-eye');
            if (eye && !eye.disabled) {
                var pi = eye.closest('.input-group').querySelector('input');
                pi.type = pi.type === 'password' ? 'text' : 'password';
                return;
            }
            var gen = e.target.closest('.obpk-gen');
            if (gen && !gen.disabled) {
                var group = gen.closest('.input-group');
                var input = group.querySelector('input');
                var old = group.parentNode.querySelector('.obpk-gen-err');
                if (old) { old.remove(); }
                // ajax.php answers application/json, so jQuery hands over the already-parsed string: use it as is,
                // never parse it a second time. dataType 'json' also turns a non-JSON reply into a failure.
                jQuery.get('ajax.php', { get_readable_pass: 'true' }, function (data) {
                    if (typeof data === 'string' && data !== '') { input.value = data; input.type = 'text'; }
                }, 'json').done(function (data) {
                    if (typeof data !== 'string' || data === '') { showGenErr(group); }
                    checkSave();
                }).fail(function () { showGenErr(group); });
            }
        });
        function showGenErr(group) {
            var m = document.createElement('small');
            m.className = 'text-danger obpk-gen-err';
            m.textContent = 'Could not generate a password';
            group.parentNode.appendChild(m);
        }

        // Save to Logins needs a password on that line (the server enforces this too).
        function checkSave() {
            form.querySelectorAll('input[type=checkbox][name$="[save]"]').forEach(function (cb) {
                var pw = null;
                form.querySelectorAll('input[type=password], input[type=text]').forEach(function (el) {
                    if (el.name === cb.name.replace(/\[save\]$/, '[password]')) { pw = el; }
                });
                if (!pw) { return; }
                var needs = cb.checked && !pw.disabled && pw.value.trim() === '';
                pw.setCustomValidity(needs ? 'Enter or generate a password to save this line to Logins.' : '');
            });
        }
        // Double-submit guard. Disabling a control before the browser serialises the form drops its value,
        // so the lock runs on a zero-delay timer, after the submit event has been serialised. Only reached when
        // validity passed (a failed validity check never fires 'submit').
        var submitBtn = form.querySelector('button[type=submit]');
        var doneNote = form.querySelector('#obpk-done-note');
        form.addEventListener('submit', function () {
            var savedBoxes = Array.prototype.filter.call(form.querySelectorAll('input[type=checkbox][name$="[save]"]'), function (cb) { return cb.checked; });
            setTimeout(function () {
                if (submitBtn) { submitBtn.disabled = true; }
                savedBoxes.forEach(function (cb) {
                    cb.checked = false;
                    cb.disabled = true;
                    var lbl = cb.closest('label');
                    if (lbl) { lbl.appendChild(document.createTextNode(' - Saved to Logins, won\'t save again')); }
                });
                if (doneNote) { doneNote.classList.remove('d-none'); }
            }, 0);
        });
        form.addEventListener('input', checkSave);
        form.addEventListener('change', checkSave);

        // "Same as computer": the endpoint ignores the email username/password then; disable them.
        var same = form.querySelector('#obpk-email-same');
        function syncSame() {
            form.querySelectorAll('.obpk-email-cred').forEach(function (el) { el.disabled = same.checked; });
            checkSave();
        }
        same.addEventListener('change', syncSame);
        syncSame();

        // "Include passwords" only means something while "Attach a copy" is ticked (the server ignores it otherwise).
        var attach = form.querySelector('#obpk-attach-ticket');
        var attachPw = form.querySelector('#obpk-attach-passwords');
        function syncAttach() {
            if (!attach || !attachPw) { return; }
            attachPw.disabled = !attach.checked;
            if (!attach.checked) { attachPw.checked = false; }
            var warn = form.querySelector('#obpk-attach-pw-warning');
            if (warn) { warn.classList.toggle('d-none', !(attach.checked && attachPw.checked)); }
        }
        if (attachPw) { attachPw.addEventListener('change', syncAttach); }
        if (attach) { attach.addEventListener('change', syncAttach); }
        syncAttach();
        refresh();
    })();
</script>

<?php

require_once '../../../includes/modal_footer.php';

<?php

require_once '../../../includes/modal_header.php';

ob_start();

$departments = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
// Contacts that do not have a login yet; anyone who already has one is promoted from the list's Edit button.
$people = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_email, contact_title, contact_client_id
    FROM contacts
    WHERE contact_archived_at IS NULL AND contact_client_id > 0
    AND NOT EXISTS (SELECT 1 FROM users WHERE users.user_id = contacts.contact_user_id AND users.user_type = 2)
    ORDER BY contact_name ASC");

?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-user-plus me-2"></i>New Department Login</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">

        <p class="text-muted small mb-3">Fields marked <strong class="text-danger">*</strong> are required.</p>

        <div class="form-group">
            <label for="pu_add_department">Department <strong class="text-danger">*</strong></label>
            <select class="form-control" id="pu_add_department" name="client_id" required>
                <option value="">Select a department</option>
                <?php while ($d = mysqli_fetch_assoc($departments)) { ?>
                    <option value="<?= intval($d['client_id']) ?>"><?= nullable_htmlentities($d['client_name']) ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group">
            <label for="pu_add_person">Person</label>
            <select class="form-control" id="pu_add_person" name="contact_id">
                <option value="0" data-dept="">New person</option>
                <?php while ($p = mysqli_fetch_assoc($people)) { ?>
                    <option value="<?= intval($p['contact_id']) ?>"
                        data-dept="<?= intval($p['contact_client_id']) ?>"
                        data-name="<?= nullable_htmlentities($p['contact_name']) ?>"
                        data-email="<?= nullable_htmlentities($p['contact_email']) ?>"
                        data-title="<?= nullable_htmlentities($p['contact_title']) ?>"><?= nullable_htmlentities($p['contact_name']) ?></option>
                <?php } ?>
            </select>
            <small class="form-text text-muted">Pick someone already in the department, or add a new person.</small>
        </div>

        <div class="form-group">
            <label for="pu_add_name">Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" id="pu_add_name" name="name" maxlength="200" required>
        </div>

        <div class="form-group">
            <label for="pu_add_email">Email <strong class="text-danger">*</strong></label>
            <input type="email" class="form-control" id="pu_add_email" name="email" maxlength="200" required>
            <small class="form-text text-muted">Also used as the sign-in username.</small>
        </div>

        <div class="form-group">
            <label for="pu_add_title">Title</label>
            <input type="text" class="form-control" id="pu_add_title" name="title" maxlength="200">
        </div>

        <div class="form-group">
            <label for="pu_add_role">Role <strong class="text-danger">*</strong></label>
            <select class="form-control" id="pu_add_role" name="portal_role" required>
                <option value="supervisor">Supervisor - sees the people who report to them</option>
                <option value="manager">Manager - sees the whole department</option>
            </select>
        </div>

        <div class="form-group">
            <label for="pu_add_password">Password <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <input type="password" class="form-control" data-toggle="password" name="password" id="pu_add_password" autocomplete="new-password" minlength="8" required>
                <span class="input-group-text" title="Show password"><i class="fa fa-fw fa-eye"></i></span>
                <button type="button" class="btn btn-outline-secondary js-portal-generate" title="Generate a random password" aria-label="Generate a random password"><i class="fa fa-fw fa-dice"></i></button>
            </div>
            <small class="form-text text-muted">Minimum 8 characters. Hand it over separately; nothing is e-mailed.</small>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_portal_user" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var dept = document.getElementById('pu_add_department');
    var person = document.getElementById('pu_add_person');
    var name = document.getElementById('pu_add_name');
    var email = document.getElementById('pu_add_email');
    var title = document.getElementById('pu_add_title');
    if (!dept || !person) { return; }

    function filterPeople() {
        var d = dept.value;
        Array.prototype.forEach.call(person.options, function (o) {
            var show = o.value === '0' || (d !== '' && o.getAttribute('data-dept') === d);
            o.hidden = !show;
            o.disabled = !show;
        });
        if (person.selectedOptions[0] && person.selectedOptions[0].disabled) { person.value = '0'; fill(); }
    }
    function fill() {
        var o = person.selectedOptions[0];
        if (!o || o.value === '0') { return; }
        name.value = o.getAttribute('data-name') || '';
        email.value = o.getAttribute('data-email') || '';
        title.value = o.getAttribute('data-title') || '';
    }
    dept.addEventListener('change', filterPeople);
    person.addEventListener('change', fill);
    filterPeople();

    if (!window.rivetPortalUserModalWired) {
        window.rivetPortalUserModalWired = true;
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-portal-generate');
            if (!btn) { return; }
            var input = btn.closest('.input-group').querySelector('input[name="password"], input[name="new_password"]');
            if (input) {
                jQuery.get("/agent/ajax.php", { get_readable_pass: 'true' }, function (data) { input.value = JSON.parse(data); });
            }
        });
    }
})();
</script>

<?php
require_once "../../../includes/modal_footer.php";

<?php
require_once "includes/inc_all_admin.php";

$it = \ITFlow\Company\ItDepartment::load($mysqli);
$col_ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM `companies` LIKE 'company_it_name'")) > 0;

?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-fw fa-headset me-2"></i>IT Department Details</h3>
    </div>
    <div class="card-body">
        <?php if (!$col_ready) { ?>
            <div class="alert alert-warning">Run the database update (Administration &gt; Update) before saving these details.</div>
        <?php } ?>
        <p class="text-muted">Who people contact for IT help. The onboarding packet's "Need help?" box shows these instead of the company details when any of them is filled in. Company Details stay as they are for invoices and quotes.</p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Name</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-headset"></i></span></div>
                            <input type="text" class="form-control" name="name" maxlength="100" placeholder="e.g. IT Help Desk" value="<?= nullable_htmlentities($it['name']) ?>">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Phone</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-phone"></i></span></div>
                            <input type="text" class="form-control" name="phone" maxlength="40" placeholder="e.g. (555) 010-0100 or ext. 4357" value="<?= nullable_htmlentities($it['phone']) ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Email</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span></div>
                            <input type="email" class="form-control" name="email" maxlength="200" placeholder="it@yourcompany.com" value="<?= nullable_htmlentities($it['email']) ?>">
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Hours <span class="text-muted fw-normal small">(optional)</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span></div>
                            <input type="text" class="form-control" name="hours" maxlength="200" placeholder="e.g. Mon-Fri 7am-5pm" value="<?= nullable_htmlentities($it['hours']) ?>">
                        </div>
                    </div>
                </div>
            </div>
            <button type="submit" name="edit_it_department" class="btn btn-primary text-bold" <?= $col_ready ? '' : 'disabled' ?>><i class="fa fa-check me-2"></i>Save</button>
        </form>
    </div>
</div>

<?php require_once "../includes/footer.php";

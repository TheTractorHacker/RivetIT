<?php
require_once "includes/inc_all_admin.php";
 ?>

    <div class="card">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-project-diagram me-2"></i>Project Settings</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                <p class="text-muted">Every new project gets an ID made of the prefix and the next number, for example <code>PRJ-8</code>.</p>

                <div class="form-group">
                    <label>Project Prefix</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-barcode"></i></span>
                        </div>
                        <input type="text" class="form-control" name="config_project_prefix" placeholder="Project Prefix" value="<?php echo nullable_htmlentities($config_project_prefix); ?>" required>
                    </div>
                    <small class="form-text text-muted">Letters shown before the number on every project.</small>
                </div>

                <div class="form-group">
                    <label>Next Number</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-barcode"></i></span>
                        </div>
                        <input type="number" min="1" class="form-control" name="config_project_next_number" placeholder="Next Project Number" value="<?php echo intval($config_project_next_number); ?>" required>
                    </div>
                </div>

                <hr>

                <button type="submit" name="edit_project_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

            </form>
        </div>
    </div>

<?php
require_once "../includes/footer.php";

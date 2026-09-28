<?php
require_once "includes/inc_all_admin.php";
 ?>

    <div class="card card-dark">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-satellite-dish me-2"></i>Telemetry</h3>
        </div>
        <div class="card-body">

            <p class="text-center">Installation ID: <strong><?php echo $installation_id; ?></strong></p>

            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label>Telemetry</label>
                    <p><i>If you can't measure it, you can't improve it. <?= htmlspecialchars(APP_NAME) ?> is built on <?= htmlspecialchars(APP_UPSTREAM_NAME) ?>, and telemetry is sent to the upstream <?= htmlspecialchars(APP_UPSTREAM_NAME) ?> project (telemetry.itflow.org), not to <?= htmlspecialchars(APP_NAME) ?>. Please consider turning it on to give that project valuable insights on how its code is used - so it can be improved for everyone.</i></p>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-broadcast-tower"></i></span>
                        </div>
                        <select class="form-control" name="config_telemetry">
                            <option <?php if ($config_telemetry == "0") { echo "selected"; } ?> value = "0">Disabled</option>
                            <option <?php if ($config_telemetry == "1") { echo "selected"; } ?> value = "1">Basic</option>
                            <option <?php if ($config_telemetry == "2") { echo "selected"; } ?> value = "2">Detailed</option>
                        </select>
                    </div>
                    <small class="form-text">We respect your privacy. <a href="https://docs.itflow.org/telemetry" target="_blank" rel="noopener">Click here <i class="fas fa-external-link-alt"></i></a> for the upstream <?= htmlspecialchars(APP_UPSTREAM_NAME) ?> documentation on the information it gathers. </small>
                </div>

                <hr>

                <button type="submit" name="edit_telemetry_settings" class="btn btn-primary text-bold float-end"><i class="fas fa-check me-2"></i>Save</button>

            </form>
        </div>
    </div>

<?php
require_once "../includes/footer.php";


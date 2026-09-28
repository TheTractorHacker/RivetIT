<?php
require_once "includes/inc_all_admin.php";
 ?>

    <div class="card card-dark">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-satellite-dish me-2"></i>Telemetry</h3>
        </div>
        <div class="card-body">

            <p class="text-center">Installation ID: <strong><?php echo $installation_id; ?></strong></p>

            <div class="alert alert-secondary mb-0">
                <i class="fas fa-fw fa-check-circle me-2"></i><?= htmlspecialchars(APP_NAME) ?> does not collect or send telemetry. Nothing about this installation is ever sent anywhere.
                (Inherited from <?= htmlspecialchars(APP_UPSTREAM_NAME) ?>, whose optional telemetry service this fork does not use.)
            </div>

        </div>
    </div>

<?php
require_once "../includes/footer.php";

<?php
require_once "includes/inc_all_admin.php";

enforceUserPermission('module_client', 2);

$tpl = \ITFlow\Onboarding\PacketTemplate::load($mysqli);
$col_ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM `settings` LIKE 'config_onboarding_packet_template'")) > 0;
require_once dirname(__DIR__) . '/includes/theme_accent.php';
$accent = itflow_theme_accent_hex($config_theme ?? '', $config_theme_accent_custom ?? null);

?>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-pdf me-2"></i>Onboarding Packet</h3>
    </div>
    <div class="card-body">
        <?php if (!$col_ready) { ?>
            <div class="alert alert-warning">Run the database update (Administration &gt; Update) to edit this template. Until then the built-in wording is used.</div>
        <?php } ?>
        <p class="text-muted">The wording of the new-hire sign-in packet (PDF) that agents generate from a ticket or a person. Leave a field blank to use the built-in text. Plain text only.
            Placeholders: <code>{first_name}</code> <code>{name}</code> <code>{department}</code> <code>{company}</code>.</p>
        <p class="small">
            <i class="fas fa-fw fa-palette me-1"></i>Colours follow your company accent
            <?php if ($accent !== '') { ?><span class="badge" style="background:<?= nullable_htmlentities($accent) ?>;color:#fff;"><?= nullable_htmlentities($accent) ?></span><?php } else { ?>(none set, so the default navy is used)<?php } ?>.
            Change it under <a href="settings_appearance.php">Appearance</a>.
        </p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <?php foreach (\ITFlow\Onboarding\PacketTemplate::FIELDS as $key => [$label, $default, $max, $rows]) { ?>
                <?php if ($key === 'it_name') { ?>
                    <hr>
                    <h5 class="mb-1"><i class="fas fa-fw fa-headset me-2"></i>IT department contact</h5>
                    <p class="text-muted small">Shown in the packet's help box instead of the company details. Leave all three blank to show the company name, phone and email (Company Details).</p>
                <?php } ?>
                <div class="mb-3">
                    <label class="form-label" for="opt-<?= $key ?>"><?= nullable_htmlentities($label) ?></label>
                    <?php if ($rows > 1) { ?>
                        <textarea class="form-control" id="opt-<?= $key ?>" name="<?= $key ?>" rows="<?= $rows ?>" maxlength="<?= $max ?>"><?= nullable_htmlentities($tpl[$key]) ?></textarea>
                    <?php } else { ?>
                        <input type="text" class="form-control" id="opt-<?= $key ?>" name="<?= $key ?>" maxlength="<?= $max ?>" value="<?= nullable_htmlentities($tpl[$key]) ?>">
                    <?php } ?>
                    <?php if ($default !== '') { ?><div class="form-text">Default: <?= nullable_htmlentities($default) ?></div><?php } ?>
                </div>
            <?php } ?>
            <button type="submit" name="save_onboarding_packet_template" class="btn btn-primary" <?= $col_ready ? '' : 'disabled' ?>><i class="fas fa-check me-2"></i>Save</button>
            <button type="submit" name="reset_onboarding_packet_template" class="btn btn-outline-secondary ms-2 confirm-link" data-confirm="Restore the built-in wording?" <?= $col_ready ? '' : 'disabled' ?>><i class="fas fa-undo me-2"></i>Restore defaults</button>
        </form>
    </div>
</div>

<?php require_once "../includes/footer.php";

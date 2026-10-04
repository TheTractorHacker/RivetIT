<?php
/*
 * Field Mapping card for the Integrations page. Set $fm_providers (a list of 'odoo' / 'microsoft' / 'google')
 * before including it: the Odoo tab shows Odoo's mappings, the Directory Sync tab shows Microsoft and Google.
 * Saving posts only the rows shown (save_field_mapping updates each posted row), so each tab saves its own.
 * Needs $directory_field_rows and $directory_field_target_labels from admin/settings_integrations.php.
 */
?>
<!-- ─── Field Mapping: what each provider's fields write to, and whether they do ─── -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-random me-2"></i>Field Mapping</h3>
        </div>
        <div class="card-body p-0">
            <p class="text-muted small px-3 pt-3 mb-2">
                What each provider's field is written into on a synced contact, and whether it's synced at all.
                A field left "— Not mapped —" is never written. Fields marked below as also used for
                matching/identity (email, or Google's org unit path) are always used for that regardless of this
                setting - this only controls whether they ALSO get written into the contact field you pick.
            </p>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <?php foreach (array_intersect_key([
                    'odoo'      => ['Odoo', 'fas fa-cogs'],
                    'microsoft' => ['Microsoft 365 / Entra ID', 'fab fa-microsoft'],
                    'google'    => ['Google Workspace', 'fab fa-google'],
                ], array_flip($fm_providers)) as $fm_provider => [$fm_label, $fm_icon]): ?>
                <h4 class="px-3 pt-2 pb-1 mb-0" style="font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#8590a5;">
                    <i class="<?= $fm_icon ?> fa-fw me-1"></i><?= htmlspecialchars($fm_label) ?>
                </h4>
                <div class="table-responsive">
                <table class="table table-sm table-borderless mb-0">
                    <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                        <tr>
                            <th class="ps-3">Source Field</th>
                            <th style="min-width:220px;">Maps To</th>
                            <th class="text-center" style="width:80px;">Enabled</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($directory_field_rows[$fm_provider] as $fm_i => $fm_row):
                        $fm_key = $fm_provider . '_' . $fm_i;
                    ?>
                        <tr>
                            <td class="ps-3 small">
                                <?= nullable_htmlentities($fm_row['label']) ?>
                                <input type="hidden" name="mapping[<?= htmlspecialchars($fm_key) ?>][provider]" value="<?= htmlspecialchars($fm_provider) ?>">
                                <input type="hidden" name="mapping[<?= htmlspecialchars($fm_key) ?>][source_field]" value="<?= htmlspecialchars($fm_row['source_field']) ?>">
                            </td>
                            <td>
                                <select class="form-control form-control-sm" name="mapping[<?= htmlspecialchars($fm_key) ?>][target_field]">
                                    <option value="">— Not mapped —</option>
                                    <?php foreach ($directory_field_target_labels as $fm_target => $fm_target_label): ?>
                                        <option value="<?= htmlspecialchars($fm_target) ?>" <?= $fm_row['target_field'] === $fm_target ? 'selected' : '' ?>><?= htmlspecialchars($fm_target_label) ?> (<?= htmlspecialchars($fm_target) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-flex justify-content-center mb-0">
                                    <input type="checkbox" class="form-check-input" name="mapping[<?= htmlspecialchars($fm_key) ?>][enabled]" value="1" <?= $fm_row['enabled'] ? 'checked' : '' ?>>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endforeach; ?>

                <div class="card-footer py-3 px-3">
                    <button type="submit" name="save_field_mapping" class="btn btn-primary btn-sm">
                        <i class="fas fa-check me-1"></i>Save Field Mappings
                    </button>
                </div>
            </form>
        </div>
    </div>


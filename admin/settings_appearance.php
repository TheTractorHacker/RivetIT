<?php
require_once "includes/inc_all_admin.php";

// Preset accent name -> hex. Defined in header.php (included above); guard in case that changes.
if (empty($theme_accent_presets) || !is_array($theme_accent_presets)) {
    $theme_accent_presets = [
        'teal'    => '#0D9488',
        'blue'    => '#2563EB',
        'indigo'  => '#4F46E5',
        'purple'  => '#7C3AED',
        'green'   => '#16A34A',
        'red'     => '#DC2626',
        'orange'  => '#EA580C',
        'pink'    => '#DB2777',
        'cyan'    => '#0891B2',
        'yellow'  => '#D97706',
        'lime'    => '#65A30D',
        'fuchsia' => '#C026D3',
        'navy'    => '#1E3A8A',
        'maroon'  => '#9F1239',
        'gray'    => '#475569',
    ];
}

$current_custom_accent = nullable_htmlentities($config_theme_accent_custom);
$current_radius_px = $config_theme_card_radius ? intval($config_theme_card_radius) : '';
// A sensible default for the color picker when no custom accent is stored
$picker_default = (!empty($config_theme_accent_custom) && preg_match('/^#[0-9A-Fa-f]{6}$/', $config_theme_accent_custom))
    ? strtoupper($config_theme_accent_custom)
    : (isset($theme_accent_presets[$config_theme]) ? $theme_accent_presets[$config_theme] : '#0D9488');

?>

<style nonce="<?php echo $csp_nonce; ?>">
    .accent-swatch-grid { display: flex; flex-wrap: wrap; gap: .75rem; }
    .accent-swatch { position: relative; }
    .accent-swatch input { position: absolute; opacity: 0; width: 0; height: 0; }
    .accent-swatch label {
        display: flex; flex-direction: column; align-items: center; gap: .3rem;
        cursor: pointer; margin: 0; width: 78px; font-size: .75rem; text-transform: capitalize;
    }
    .accent-swatch .dot {
        width: 40px; height: 40px; border-radius: 50%;
        border: 3px solid transparent; box-shadow: 0 0 0 1px rgba(0,0,0,.12);
        transition: transform .12s ease, border-color .12s ease;
    }
    .accent-swatch input:checked + label .dot {
        border-color: #fff;
        box-shadow: 0 0 0 2px var(--color-accent), 0 2px 6px rgba(0,0,0,.25);
        transform: scale(1.06);
    }
    .accent-swatch input:focus-visible + label .dot { outline: 2px solid var(--color-accent); outline-offset: 2px; }
</style>

<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-palette me-2"></i>Appearance</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label class="d-block">Accent Color</label>
                <small class="text-secondary d-block mb-2">Pick a preset accent. This recolors buttons, links, badges and the sidebar throughout the app.</small>
                <div class="accent-swatch-grid">
                    <?php foreach ($theme_accent_presets as $preset_name => $preset_hex) { ?>
                        <div class="accent-swatch">
                            <input type="radio" id="accent_<?php echo $preset_name; ?>" name="config_theme" value="<?php echo $preset_name; ?>" <?php if ($config_theme == $preset_name) { echo "checked"; } ?>>
                            <label for="accent_<?php echo $preset_name; ?>">
                                <span class="dot" style="background-color: <?php echo $preset_hex; ?>;"></span>
                                <?php echo $preset_name; ?>
                            </label>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <hr>

            <div class="form-group">
                <label>Custom Accent Color <small class="text-secondary">(optional &mdash; overrides the preset above)</small></label>
                <div class="input-group" style="max-width: 320px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text p-1">
                            <input type="color" id="custom_accent_picker" value="<?php echo $picker_default; ?>" style="width: 38px; height: 32px; border: none; background: none; padding: 0; cursor: pointer;">
                        </span>
                    </div>
                    <input type="text" class="form-control" id="custom_accent_hex" name="config_theme_accent_custom" placeholder="#0D9488" pattern="^#[0-9A-Fa-f]{6}$" title="Enter a 6-digit hex colour, e.g. #0D9488" value="<?php echo $current_custom_accent; ?>">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-outline-secondary" id="custom_accent_clear" title="Clear custom colour"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <small class="text-secondary">Leave blank to use the selected preset.</small>
            </div>

            <hr>

            <div class="form-group">
                <label>Card Corner Radius <small class="text-secondary">(optional &mdash; default 14px)</small></label>
                <div class="input-group" style="max-width: 220px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fas fa-fw fa-border-style"></i></span>
                    </div>
                    <input type="number" min="0" max="40" class="form-control" name="config_theme_card_radius" placeholder="14" value="<?php echo $current_radius_px; ?>">
                    <div class="input-group-append">
                        <span class="input-group-text">px</span>
                    </div>
                </div>
                <small class="text-secondary">Leave blank to keep the default card rounding.</small>
            </div>

            <hr>

            <div class="form-group">
                <label>Dark Mode</label>
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_theme_dark_default" <?php if ($config_theme_dark_default == 1) { echo "checked"; } ?> value="1" id="darkModeDefaultSwitch">
                    <label class="form-check-label" for="darkModeDefaultSwitch">Default to dark mode company-wide <small class="text-secondary">(individual users can still opt into dark mode from their own Preferences)</small></label>
                </div>
            </div>

            <hr>

            <button type="submit" name="edit_appearance_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

        </form>
    </div>
</div>

<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-image me-2"></i>Company Logo</h3>
    </div>
    <div class="card-body">
        <p class="mb-2">Your company logo is managed on the Company Details page and appears on invoices, quotes and the client portal.</p>
        <a href="/admin/settings_company.php" class="btn btn-outline-secondary"><i class="fas fa-briefcase me-2"></i>Manage Company Logo</a>
    </div>
</div>

<script nonce="<?php echo $csp_nonce; ?>">
(function () {
    var picker = document.getElementById('custom_accent_picker');
    var hex = document.getElementById('custom_accent_hex');
    var clearBtn = document.getElementById('custom_accent_clear');

    // Picker -> text field
    picker.addEventListener('input', function () {
        hex.value = picker.value.toUpperCase();
    });

    // Text field -> picker (only when it's a valid hex)
    hex.addEventListener('input', function () {
        if (/^#[0-9A-Fa-f]{6}$/.test(hex.value)) {
            picker.value = hex.value;
        }
    });

    // Clear the custom colour so the preset takes over
    clearBtn.addEventListener('click', function () {
        hex.value = '';
        hex.focus();
    });
})();
</script>

<?php
require_once "../includes/footer.php";

<?php
require_once "includes/inc_all_admin.php";

/*
 * Legacy AdminLTE accent names, one entry per colour. 'yellow' used to appear
 * twice (index 12 and 14), which emitted two #customRadioyellow inputs and two
 * label[for=customRadioyellow] - so the second, right-hand yellow swatch was a
 * dead control that silently drove the first yellow radio.
 */
$theme_colors_array = array (
    'lightblue',
    'blue',
    'cyan',
    'green',
    'olive',
    'teal',
    'red',
    'maroon',
    'pink',
    'purple',
    'indigo',
    'fuchsia',
    'yellow',
    'orange',
    'black',
    'navy',
    'gray'
);

?>

<style nonce="<?php echo $csp_nonce; ?>">
    /* Theme swatch grid.
       The radio used to sit in the .form-check gutter of a text-centred .col-4,
       which left it ~150px from the circle it belonged to - closer to the wrong
       swatch than to its own. Here the input is visually hidden and the whole
       tile IS the label, so control and colour are one object.
       Colours still come from the .text-<name> utilities (Bootstrap/Tabler plus
       the AdminLTE names restored in css/itflow.shim-adminlte.css) and are read
       off the dot with currentColor, so no palette value is duplicated here.
       Same shape as the accent grid on admin/settings_appearance.php. */
    .theme-swatch-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(7.5rem, 1fr)); gap: .75rem; }
    .theme-swatch { position: relative; }
    .theme-swatch > input { position: absolute; opacity: 0; width: 0; height: 0; }
    .theme-swatch > label {
        display: flex; flex-direction: column; align-items: center; gap: .6rem;
        margin: 0; padding: .9rem .5rem; cursor: pointer;
        border: 1px solid var(--if-border); border-radius: var(--if-radius-sm);
        color: var(--if-muted); font-size: .8125rem; text-transform: capitalize;
        transition: border-color .12s ease, box-shadow .12s ease, color .12s ease;
    }
    .theme-swatch > label:hover { border-color: var(--if-border-strong); color: var(--if-ink); }
    .theme-swatch .theme-dot {
        width: 2.5rem; height: 2.5rem; border-radius: 50%;
        background: currentColor;
        /* Outer hairline, not inset: keeps the black swatch readable in dark mode. */
        box-shadow: 0 0 0 1px var(--if-border-strong);
    }
    .theme-swatch > input:checked + label {
        border-color: var(--if-primary); box-shadow: 0 0 0 1px var(--if-primary);
        color: var(--if-ink); font-weight: 600;
    }
    .theme-swatch > input:focus-visible + label { outline: 2px solid var(--if-primary); outline-offset: 2px; }

    /* Favicon card: bound the preview and the upload field instead of letting a
       raw widget float in a full-width row. */
    .favicon-preview {
        display: inline-flex; align-items: center; justify-content: center;
        width: 4rem; height: 4rem; border: 1px solid var(--if-border);
        border-radius: var(--if-radius-sm); background: var(--if-bg);
    }
    .favicon-preview img { max-width: 2rem; max-height: 2rem; }
    .favicon-field { max-width: 24rem; }
</style>

<!-- Plain .card, not the legacy AdminLTE .card-dark. css/itflow_custom.css records
     that the class is otherwise inert, and on this page it only did harm: it squared
     off the card's bottom two corners (`.card.card-dark` carries the header's
     `border-radius: … 0 0 !important`) and out-ranked the design layer's own
     .form-control colours, so every field on the page painted white in dark mode. -->
<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-paint-brush me-2"></i>Theme</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <label class="form-label d-block mb-1">Select a Theme</label>
            <small class="text-muted d-block mb-3">Sets the accent colour used for buttons, links, badges and the sidebar. Picking one saves straight away.</small>

            <div class="theme-swatch-grid">

                <?php

                foreach ($theme_colors_array as $theme_color) {

                    ?>

                    <div class="theme-swatch">
                        <input class="auto-submit-select" type="radio" id="customRadio<?php echo $theme_color; ?>" name="edit_theme_settings" value="<?php echo $theme_color; ?>" <?php if ($config_theme == $theme_color) { echo "checked"; } ?>>
                        <label for="customRadio<?php echo $theme_color; ?>">
                            <span class="theme-dot text-<?php echo $theme_color; ?>"></span>
                            <?php echo $theme_color; ?>
                        </label>
                    </div>

                <?php } ?>

            </div>

        </form>
    </div>
</div>

<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-image me-2"></i>Favicon</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="favicon-preview mb-3">
                <img src="<?php if(file_exists("../uploads/favicon.ico")) { echo "../uploads/favicon.ico"; } else { echo "../favicon.ico"; } ?>" alt="Current favicon">
            </div>

            <div class="form-group favicon-field">
                <label class="form-label" for="favicon_file">Replace icon</label>
                <!-- .form-control, not the BS4-era .form-control-file: BS5 dropped that
                     class and nothing in css/ or Tabler defines it, so the input rendered
                     as raw OS chrome with no border, background or padding. -->
                <input type="file" class="form-control" id="favicon_file" name="file" accept=".ico">
                <small class="form-text text-muted">A square .ico file. Shown in the browser tab and in bookmarks.</small>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                <button type="submit" name="edit_favicon_settings" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Upload Icon</button>
                <?php if(file_exists("../uploads/favicon.ico")) { ?>
                    <a href="post.php?reset_favicon&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-outline-danger confirm-link"><i class="fas fa-redo-alt me-2"></i>Reset Favicon</a>
                <?php } ?>
            </div>
        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";

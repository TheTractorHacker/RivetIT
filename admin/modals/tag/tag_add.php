<?php

require_once '../../../includes/modal_header.php';

$tag_color_palette = tagColorPalette();

$type_display = '';

if (isset($_GET['type'])) {
    $type = intval($_GET['type']);

    if ($type === 1) {
        $type_display = "Client";
    } elseif($type === 2) {
        $type_display = "Location";
    } elseif ($type === 3) {
        $type_display = "Contact";
    } elseif ($type === 4) {
        $type_display = "Credential";
    } elseif ($type === 5) {
        $type_display = "Asset";
    } elseif ($type === 6) {
        $type_display = "Ticket";
    }
}
ob_start();

?>

<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-tag me-2"></i>New <strong><?= $type_display ?></strong> Tag</h5>
    <button type="button" class="close" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="type" value="<?php echo $type; ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="Tag name" maxlength="200" required autofocus>
            </div>
        </div>

        <?php if (isset($_GET['type'])) { ?>

        <input type="hidden" name="type" value="<?= $type ?>">

        <?php } else { ?>

        <div class="form-group">
            <label>Type <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-th"></i></span>
                </div>
                <select class="form-control select2" name="type" required>
                    <option value="">- Type -</option>
                    <option value="1">Client Tag</option>
                    <option value="2">Location Tag</option>
                    <option value="3">Contact Tag</option>
                    <option value="4">Credential Tag</option>
                    <option value="5">Asset Tag</option>
                    <option value="6">Ticket Tag</option>
                </select>
            </div>
        </div>

        <?php } ?>

        <div class="form-group">
            <label>Color <strong class="text-danger">*</strong></label>
            <input type="hidden" name="color" id="tag_color_value" value="<?= $tag_color_palette[0] ?>" required>
            <div class="tag-color-palette">
                <?php foreach ($tag_color_palette as $i => $hex) { ?>
                <span class="tag-color-swatch<?= $i === 0 ? ' selected' : '' ?>" data-color="<?= $hex ?>" style="background-color: <?= $hex ?>;"></span>
                <?php } ?>
                <label class="tag-color-swatch tag-color-custom" title="Custom color">
                    <i class="fa fa-fw fa-eye-dropper"></i>
                    <input type="color" id="tag_color_custom">
                </label>
            </div>
        </div>

        <style>
        .tag-color-palette { display: flex; flex-wrap: wrap; gap: 8px; }
        .tag-color-swatch { width: 30px; height: 30px; border-radius: 4px; cursor: pointer; border: 2px solid transparent; box-sizing: border-box; display: inline-flex; align-items: center; justify-content: center; color: #6c757d; }
        .tag-color-swatch.selected { border-color: #343a40; box-shadow: 0 0 0 2px #fff inset; }
        .tag-color-custom { border: 2px dashed #aaa; position: relative; overflow: hidden; }
        .tag-color-custom input[type=color] { position: absolute; top: -4px; left: -4px; width: 40px; height: 40px; opacity: 0; cursor: pointer; }
        </style>

        <script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
        (function(){
            var swatches = document.querySelectorAll('.tag-color-swatch[data-color]');
            var hiddenInput = document.getElementById('tag_color_value');
            var customInput = document.getElementById('tag_color_custom');
            var customLabel = document.querySelector('.tag-color-custom');

            swatches.forEach(function(s){
                s.addEventListener('click', function(){
                    swatches.forEach(function(o){ o.classList.remove('selected'); });
                    customLabel.classList.remove('selected');
                    s.classList.add('selected');
                    hiddenInput.value = s.dataset.color;
                });
            });

            customInput.addEventListener('input', function(){
                swatches.forEach(function(o){ o.classList.remove('selected'); });
                customLabel.classList.add('selected');
                customLabel.style.backgroundColor = customInput.value;
                hiddenInput.value = customInput.value;
            });
        })();
        </script>

        <div class="form-group">
            <label>Icon</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-image"></i></span>
                </div>
                <input type="text" class="form-control" name="icon" placeholder="Icon ex handshake">
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_tag" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create Tag</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';

<?php

require_once '../../../includes/modal_header.php';

$tag_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM tags WHERE tag_id = $tag_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$tag_name = nullable_htmlentities($row['tag_name']);
$tag_type = intval($row['tag_type']);
$tag_color = nullable_htmlentities($row['tag_color']);
$tag_icon = nullable_htmlentities($row['tag_icon']);

$tag_color_palette = tagColorPalette();
$tag_color_is_custom = !in_array(strtolower($tag_color), array_map('strtolower', $tag_color_palette));

if ($tag_type == 1) {
    $tag_type_display = "Client";
} elseif ( $tag_type == 2) {
    $tag_type_display = "Location";
} elseif ( $tag_type == 3) {
    $tag_type_display = "Contact";
} elseif ( $tag_type == 4) {
    $tag_type_display = "Credential";
 } elseif ( $tag_type == 5) {
    $tag_type_display = "Asset";
} elseif ( $tag_type == 6) {
    $tag_type_display = "Ticket";
} else {
    $tag_type_display = "Unknown";
}

ob_start();
?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-tag me-2"></i><?= $tag_type_display ?> Tag: <strong><?php echo $tag_name; ?></strong></h5>
    <button type="button" class="close" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="tag_id" value="<?php echo $tag_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="name" maxlength="200" value="<?php echo $tag_name; ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Color <strong class="text-danger">*</strong></label>
            <input type="hidden" name="color" id="tag_color_value" value="<?= $tag_color ?>" required>
            <div class="tag-color-palette">
                <?php foreach ($tag_color_palette as $i => $hex) { ?>
                <span class="tag-color-swatch<?= (!$tag_color_is_custom && strtolower($hex) === strtolower($tag_color)) ? ' selected' : '' ?>" data-color="<?= $hex ?>" style="background-color: <?= $hex ?>;"></span>
                <?php } ?>
                <label class="tag-color-swatch tag-color-custom<?= $tag_color_is_custom ? ' selected' : '' ?>" title="Custom color" style="<?= $tag_color_is_custom ? 'background-color: ' . $tag_color . ';' : '' ?>">
                    <i class="fa fa-fw fa-eye-dropper"></i>
                    <input type="color" id="tag_color_custom" value="<?= $tag_color_is_custom ? $tag_color : $tag_color_palette[0] ?>">
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
                <input type="text" class="form-control" name="icon" placeholder="Icon ex handshake" value="<?php echo $tag_icon; ?>">
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_tag" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';

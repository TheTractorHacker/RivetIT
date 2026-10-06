<?php

/*
 * Visual Font Awesome icon picker (RivetCore\Ui\IconCatalog).
 *
 * iconPickerField('icon', $current, 'fa-filter') renders a button showing the current icon, plus a panel (search,
 * category chips, icon grid, custom-class box) that sits in normal flow under it - so it is never clipped by a
 * .modal-body. The behaviour lives in js/icon_picker.js (loaded once by includes/footer.php, bound by event
 * delegation so AJAX-loaded modals work). The ~47 KB catalog is NOT embedded here: the script fetches it once per page
 * from /modals/icon_catalog.php (versioned URL, cacheable), so a form stays light.
 *
 * The submitted value is a canonical 'fa-xxx' class or '' (the handler falls back to its own default). Handlers MUST
 * re-validate with \RivetCore\Ui\IconCatalog::normalize() - the client check is a convenience only.
 */

use RivetCore\Ui\IconCatalog;

if (!function_exists('iconPickerField')) {
    /**
     * @param string      $name    form field name
     * @param string      $value   currently stored value ('fa-fire', 'fas fa-fire', bare 'fire' or '' are all accepted)
     * @param string      $default icon shown / used when nothing is chosen
     * @param string|null $id      DOM id prefix (defaults to a unique one)
     */
    function iconPickerField(string $name, string $value, string $default = 'fa-filter', ?string $id = null): void
    {
        static $seq = 0;
        $seq++;
        $id = $id ?? 'icon-picker-' . $seq;
        // Keep a stored hostile/odd value out of the markup: normalise to a safe class, or '' = "default".
        $current = IconCatalog::normalize($value, '');
        $default = IconCatalog::normalize($default, 'fa-filter');
        $shown = $current !== '' ? $current : $default;
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $url = '/modals/icon_catalog.php?v=' . IconCatalog::VERSION;
        ?>
        <div class="icon-picker" data-icon-picker data-default="<?= $h($default) ?>" data-catalog-url="<?= $h($url) ?>">
            <input type="hidden" name="<?= $h($name) ?>" id="<?= $h($id) ?>" value="<?= $h($current) ?>" data-icon-value>
            <button type="button" class="icon-picker__button" id="<?= $h($id) ?>-button" aria-haspopup="dialog" aria-expanded="false" aria-controls="<?= $h($id) ?>-panel">
                <span class="icon-picker__preview" aria-hidden="true"><i class="fas <?= $h($shown) ?>" data-icon-preview></i></span>
                <span class="icon-picker__label">
                    <span class="icon-picker__class" data-icon-name><?= $h($shown) ?></span>
                    <small class="icon-picker__hint">Choose icon</small>
                </span>
                <i class="fas fa-chevron-down icon-picker__caret" aria-hidden="true"></i>
            </button>
            <div class="icon-picker__panel" id="<?= $h($id) ?>-panel" role="dialog" aria-label="Choose an icon" hidden>
                <input type="search" class="form-control form-control-sm icon-picker__search" placeholder="Search icons (e.g. fire, server, user)" aria-label="Search icons" autocomplete="off" data-icon-search>
                <div class="icon-picker__cats" role="group" aria-label="Icon categories" data-icon-cats></div>
                <div class="icon-picker__grid" role="listbox" aria-label="Icons" data-icon-grid><span class="icon-picker__empty">Loading icons...</span></div>
                <div class="icon-picker__status" aria-live="polite" data-icon-status></div>
                <div class="icon-picker__custom">
                    <label class="form-label small mb-1" for="<?= $h($id) ?>-custom">Custom Font Awesome class</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="<?= $h($id) ?>-custom" maxlength="50" placeholder="e.g. fa-fire or fas fa-fire" autocomplete="off" spellcheck="false" value="<?= $h($current) ?>" data-icon-custom>
                        <button type="button" class="btn btn-outline-secondary" data-icon-use>Use</button>
                        <button type="button" class="btn btn-outline-secondary" data-icon-reset title="Use the default icon (<?= $h($default) ?>)">Default</button>
                    </div>
                    <div class="icon-picker__error text-danger small mt-1" data-icon-error hidden>Not a valid Font Awesome class.</div>
                </div>
            </div>
        </div>
        <?php
    }
}

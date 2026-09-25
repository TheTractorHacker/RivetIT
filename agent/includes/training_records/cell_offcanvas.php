<?php
defined('TRAINING_PAGE') || exit;

/* The "who is missing" drill-down panel (Bootstrap offcanvas, spec §0 #8). Filled by
   agent/js/training_reports.js from report_cell_people with DOM nodes only. */
?>
<div class="offcanvas offcanvas-end trr-offcanvas" tabindex="-1" id="trr-cell-panel" aria-labelledby="trr-cell-title">
    <div class="offcanvas-header">
        <div>
            <h2 class="offcanvas-title h5 mb-0" id="trr-cell-title">Who is missing</h2>
            <p class="trr-muted mb-0 small" id="trr-cell-sub"></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" id="trr-cell-body" aria-live="polite"></div>
</div>

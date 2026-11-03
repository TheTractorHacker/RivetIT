<?php
/*
 * Shared helpers for the department documentation list pages (domains,
 * certificates, licenses ...): a visible expiry status chip, and the one tightening
 * of the gap the old filter-bar <hr> leaves above every table.
 */

/**
 * Expiry status chip: EXPIRED / "12d left" / "In 5 months" so a row's risk is
 * readable without parsing a date. Returns '' for an empty date.
 * $warn_days is the amber window; <= $crit_days turns red.
 */
function orgExpiryChip($date, $warn_days = 45, $crit_days = 14) {
    if (empty($date)) {
        return '';
    }
    $ts = strtotime($date);
    if (!$ts) {
        return '';
    }
    $days = (int) floor(($ts - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0) {
        $cls = 'bg-red text-white';
        $icon = 'fa-times-circle';
        $txt = 'Expired ' . abs($days) . 'd ago';
    } elseif ($days <= $crit_days) {
        $cls = 'bg-red-lt';
        $icon = 'fa-exclamation-triangle';
        $txt = $days === 0 ? 'Expires today' : $days . 'd left';
    } elseif ($days <= $warn_days) {
        $cls = 'bg-yellow-lt';
        $icon = 'fa-hourglass-half';
        $txt = $days . 'd left';
    } else {
        $cls = 'bg-green-lt';
        $icon = 'fa-check';
        $txt = 'OK';
    }
    return '<span class="badge ' . $cls . ' text-nowrap ms-1"><i class="fas ' . $icon . ' me-1"></i>' . $txt . '</span>';
}

/** Style block tightening the list-page filter/table gap. Echo once per page. */
function orgListStyle() {
    global $csp_nonce;
    echo '<style nonce="' . htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) . '">'
       . '.card-body > form ~ hr{margin:.5rem 0 .25rem}'
       . '.card-body > hr ~ form .table-responsive>.table{margin-top:0}'
       . '</style>';
}

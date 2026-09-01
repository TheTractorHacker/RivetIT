<?php

require_once "includes/inc_all.php";

// Perms
enforceUserPermission('module_sales');

// Client-scoping snippet (mirror of $access_permission_query but for opportunity_client_id).
// Users restricted to certain clients only see those clients' opportunities plus unassigned ones.
$opp_access_snippet = '';
if (!empty($client_access_string) && empty($session_is_admin)) {
    $opp_access_snippet = "AND (opportunity_client_id IN ($client_access_string) OR opportunity_client_id IS NULL)";
}

$stages = getOpportunityStages();

// Build the stage columns
$columns = array();
foreach ($stages as $stage_name => $stage_prob) {
    $columns[$stage_name] = array(
        'name'          => $stage_name,
        'probability'   => $stage_prob,
        'opportunities' => array(),
    );
}

// Header stats over open (non-archived) opportunities
$stats = mysqli_fetch_assoc(mysqli_query(
    $mysqli,
    "SELECT
        COALESCE(SUM(CASE WHEN opportunity_status = 'open' THEN opportunity_amount ELSE 0 END), 0) AS pipeline_value,
        COALESCE(SUM(CASE WHEN opportunity_status = 'open' THEN opportunity_amount * opportunity_probability / 100 ELSE 0 END), 0) AS weighted_forecast,
        COALESCE(SUM(CASE WHEN opportunity_stage = 'Won'
            AND MONTH(COALESCE(opportunity_updated_at, opportunity_created_at)) = MONTH(CURRENT_DATE)
            AND YEAR(COALESCE(opportunity_updated_at, opportunity_created_at)) = YEAR(CURRENT_DATE)
            THEN opportunity_amount ELSE 0 END), 0) AS won_this_month
     FROM opportunities
     WHERE opportunity_archived_at IS NULL $opp_access_snippet"
));

$pipeline_value = floatval($stats['pipeline_value']);
$weighted_forecast = floatval($stats['weighted_forecast']);
$won_this_month = floatval($stats['won_this_month']);

// Fetch opportunities
$sql = mysqli_query(
    $mysqli,
    "SELECT opportunities.*, clients.client_name, users.user_name
     FROM opportunities
     LEFT JOIN clients ON opportunity_client_id = client_id
     LEFT JOIN users ON opportunity_owner = user_id
     WHERE opportunity_archived_at IS NULL $opp_access_snippet
     ORDER BY opportunity_amount DESC, opportunity_id DESC"
);

while ($row = mysqli_fetch_assoc($sql)) {
    $stage = $row['opportunity_stage'];
    if (!isset($columns[$stage])) {
        // Unknown / legacy stage -> bucket into Qualification column
        $stage = 'Qualification';
    }
    $columns[$stage]['opportunities'][] = $row;
}

?>

<link rel="stylesheet" href="css/ticket_kanban.css">
<style>
    /* Pipeline board reuses the kanban card/column styling but needs its own flex container */
    #pipeline-board {
        display: flex;
        overflow-x: auto;
        box-sizing: border-box;
        min-width: 400px;
        padding-bottom: 8px;
    }
    #pipeline-board .kanban-column {
        min-height: 200px;
        max-height: none;
    }
    #pipeline-board .kanban-status {
        min-height: 120px;
    }
    /* css/ticket_kanban.css hardcodes flat AdminLTE-3-era greys (#f4f4f4 column,
       #f9f9f9 status well, #fff/#ddd task cards) that clash with the light/dark
       design tokens and go flat white-on-white in dark mode. Re-skin this page's
       board with --if-* tokens instead of touching the shared stylesheet (other
       kanban boards also use it). */
    #pipeline-board .kanban-column {
        background: var(--if-bg);
        border: 1px solid var(--if-border);
    }
    #pipeline-board .kanban-status {
        background-color: var(--if-surface);
    }
    #pipeline-board .task {
        background: var(--if-surface);
        border: 1px solid var(--if-border);
        box-shadow: var(--if-shadow);
        color: var(--if-ink);
    }
</style>

<div class="row mb-3">
    <div class="col-md-4">
        <div class="card card-dark mb-0">
            <div class="card-body py-3">
                <span class="text-muted small text-uppercase fw-bold"><i class="fas fa-fw fa-chart-line me-1"></i>Pipeline Value</span>
                <h3 class="mb-0 mt-1"><?php echo numfmt_format_currency($currency_format, $pipeline_value, "$session_company_currency"); ?></h3>
                <small class="text-muted">Open opportunities</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-dark mb-0">
            <div class="card-body py-3">
                <span class="text-muted small text-uppercase fw-bold"><i class="fas fa-fw fa-balance-scale me-1"></i>Weighted Forecast</span>
                <h3 class="mb-0 mt-1"><?php echo numfmt_format_currency($currency_format, $weighted_forecast, "$session_company_currency"); ?></h3>
                <small class="text-muted">Amount &times; probability</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card card-dark mb-0">
            <div class="card-body py-3">
                <span class="text-muted small text-uppercase fw-bold"><i class="fas fa-fw fa-trophy me-1"></i>Won This Month</span>
                <h3 class="mb-0 mt-1 text-success"><?php echo numfmt_format_currency($currency_format, $won_this_month, "$session_company_currency"); ?></h3>
                <small class="text-muted"><?php echo date('F Y'); ?></small>
            </div>
        </div>
    </div>
</div>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-stream me-2"></i>Sales Pipeline</h3>
        <div class="card-tools">
            <a href="opportunities.php" class="btn btn-secondary btn-sm me-1"><i class="fas fa-fw fa-list me-1"></i>List View</a>
            <?php if (lookupUserPermission("module_sales") >= 2) { ?>
                <button type="button" class="btn btn-primary btn-sm ajax-modal" data-modal-url="modals/opportunity/opportunity_add.php"><i class="fas fa-plus"></i><span class="d-none d-lg-inline ms-2">New Opportunity</span></button>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">

        <div class="container-fluid" id="pipeline-board">
            <?php foreach ($columns as $stage_name => $column) {
                $stage_color = opportunityStageColor($stage_name);

                // Column total
                $col_total = 0;
                foreach ($column['opportunities'] as $opp) {
                    $col_total += floatval($opp['opportunity_amount']);
                }
                ?>
                <div class="kanban-column card card-dark" data-stage="<?php echo htmlspecialchars($stage_name); ?>">
                    <h6 class="panel-title">
                        <span class="badge badge-<?php echo $stage_color; ?> me-1"><?php echo htmlspecialchars($stage_name); ?></span>
                        <span class="float-end text-muted"><?php echo numfmt_format_currency($currency_format, $col_total, "$session_company_currency"); ?></span>
                    </h6>

                    <div class="kanban-status" data-stage="<?php echo htmlspecialchars($stage_name); ?>">
                        <?php foreach ($column['opportunities'] as $opp) {
                            $opp_id = intval($opp['opportunity_id']);
                            $opp_name = nullable_htmlentities($opp['opportunity_name']);
                            $opp_client = nullable_htmlentities($opp['client_name']);
                            $opp_amount = floatval($opp['opportunity_amount']);
                            $opp_prob = intval($opp['opportunity_probability']);
                            $opp_close = nullable_htmlentities($opp['opportunity_close_date']);
                            $opp_owner = nullable_htmlentities($opp['user_name']);
                            ?>
                            <div class="task grab-cursor js-open-opportunity"
                                 data-opportunity-id="<?php echo $opp_id; ?>"
                                 data-stage="<?php echo htmlspecialchars($stage_name); ?>">

                                <div class="btn btn-light drag-handle-class" style="display:none;">
                                    <i class="drag-handle-class fas fa-bars"></i>
                                </div>

                                <b><?php echo $opp_name; ?></b><br>

                                <?php if ($opp_client) { ?>
                                    <i class="fa fa-fw fa-users text-secondary me-1"></i><?php echo $opp_client; ?><br>
                                <?php } ?>

                                <span class="badge text-bg-success"><?php echo numfmt_format_currency($currency_format, $opp_amount, "$session_company_currency"); ?></span>
                                <span class="badge text-bg-light"><?php echo $opp_prob; ?>%</span>
                                <br>

                                <?php if ($opp_close) { ?>
                                    <i class="fa fa-fw fa-calendar-check text-secondary me-1"></i><span class="text-muted small"><?php echo $opp_close; ?></span><br>
                                <?php } ?>

                                <?php if ($opp_owner) { ?>
                                    <i class="fas fa-fw fa-user-tie text-secondary me-1"></i><span class="text-muted small"><?php echo $opp_owner; ?></span>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>

    </div>
</div>

<?php
echo "<script nonce=\"" . htmlspecialchars($csp_nonce ?? '') . "\">";
echo "const PIPELINE_CSRF_TOKEN = " . json_encode($_SESSION['csrf_token']) . ";";
echo "</script>";
?>

<script src="../plugins/SortableJS/Sortable.min.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
    // A tiny modal loader so double-click opens the edit modal via the standard ajax-modal path
    window.loadModal = window.loadModal || function (url) {
        var trigger = document.createElement('a');
        trigger.className = 'ajax-modal';
        trigger.setAttribute('data-modal-url', url);
        trigger.style.display = 'none';
        document.body.appendChild(trigger);
        trigger.click();
        setTimeout(function () { trigger.remove(); }, 500);
    };

    function isTouchDevice() {
        return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    }

    document.addEventListener('dblclick', function (e) {
        var card = e.target.closest('.js-open-opportunity');
        if (card) { loadModal('modals/opportunity/opportunity_edit.php?id=' + card.dataset.opportunityId); }
    });

    document.querySelectorAll('#pipeline-board .kanban-status').forEach(function (col) {
        new Sortable(col, {
            group: 'opportunities',
            animation: 150,
            handle: isTouchDevice() ? '.drag-handle-class' : undefined,
            onEnd: function (evt) {
                var target = evt.to;
                var movedEl = evt.item;
                var newStage = target.getAttribute('data-stage');
                var oldStage = movedEl.getAttribute('data-stage');
                var opportunityId = movedEl.getAttribute('data-opportunity-id');

                if (newStage === oldStage) {
                    return; // reorder within same column, no persistence needed
                }

                movedEl.setAttribute('data-stage', newStage);

                jQuery.post('ajax.php', {
                    update_opportunity_stage: true,
                    csrf_token: PIPELINE_CSRF_TOKEN,
                    opportunity_id: opportunityId,
                    stage: newStage
                }).done(function () {
                    // Reload so header totals + column totals + win/loss status reflect the move
                    window.location.reload();
                }).fail(function (xhr) {
                    console.error('Error updating opportunity stage:', xhr.responseText);
                    movedEl.setAttribute('data-stage', oldStage);
                    alert('Could not move opportunity. Please refresh and try again.');
                });
            }
        });
    });

    if (isTouchDevice()) {
        document.querySelectorAll('.drag-handle-class').forEach(function (el) {
            el.style.display = 'inline';
        });
    }
});
</script>

<?php require_once "../includes/footer.php"; ?>

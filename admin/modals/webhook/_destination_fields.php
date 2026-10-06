<?php
// Destination type and routing filters shared by the add and edit webhook modals.
// Expects $wh (the webhooks row) when editing, or null when adding. Only Slack and Teams destinations use the filters.
$dest_type = \ITFlow\Webhooks\ChatFormatter::normalizeType($wh['webhook_type'] ?? '');
$dest_min  = (string) ($wh['webhook_min_priority'] ?? '');
$dest_clients = array_map('intval', array_filter(explode(',', (string) ($wh['webhook_client_ids'] ?? ''))));
?>
<div class="form-group">
    <label>Destination type</label>
    <select class="form-control" name="webhook_type">
        <option value="generic" <?= $dest_type === 'generic' ? 'selected' : '' ?>>Generic webhook (signed JSON)</option>
        <option value="slack" <?= $dest_type === 'slack' ? 'selected' : '' ?>>Slack (Incoming Webhook)</option>
        <option value="teams" <?= $dest_type === 'teams' ? 'selected' : '' ?>>Microsoft Teams (Workflows webhook)</option>
    </select>
    <small class="text-secondary">For Slack and Teams, paste the webhook URL above: it is stored encrypted and never shown again. A Slack Signing Secret is optional (interactive buttons); Teams cards are one-way. See docs/SLACK_TEAMS_SETUP.md.</small>
</div>

<div class="form-group">
    <label>Minimum ticket priority <small class="text-secondary">(Slack / Teams only)</small></label>
    <select class="form-control" name="webhook_min_priority">
        <option value="">Any priority</option>
        <?php foreach (array_keys(\ITFlow\Webhooks\ChatFormatter::PRIORITIES) as $pr) { ?>
        <option value="<?= htmlspecialchars($pr) ?>" <?= $dest_min === $pr ? 'selected' : '' ?>><?= htmlspecialchars($pr) ?> and above</option>
        <?php } ?>
    </select>
</div>

<div class="form-group">
    <label>Only these clients <small class="text-secondary">(Slack / Teams only; none selected = all clients)</small></label>
    <select class="form-control" name="webhook_client_ids[]" multiple size="5">
        <?php
        $dest_sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC LIMIT 500");
        while ($dest_sql && ($dc = mysqli_fetch_assoc($dest_sql))) { ?>
        <option value="<?= intval($dc['client_id']) ?>" <?= in_array((int) $dc['client_id'], $dest_clients, true) ? 'selected' : '' ?>><?= nullable_htmlentities($dc['client_name']) ?></option>
        <?php } ?>
    </select>
</div>

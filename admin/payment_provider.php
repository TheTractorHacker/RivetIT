<?php

// Default Column Sortby Filter
$sort = "payment_provider_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

$sql = mysqli_query($mysqli, "SELECT * FROM payment_providers
    LEFT JOIN accounts ON payment_provider_account = account_id
    LEFT JOIN vendors ON payment_provider_expense_vendor = vendor_id
    LEFT JOIN categories ON payment_provider_expense_category = category_id
    ORDER BY $sort $order"
);

$num_rows = mysqli_num_rows($sql);

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-credit-card me-2"></i>Payment Providers</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/payment_provider/payment_provider_add.php"><i class="fas fa-plus me-2"></i>Add Provider</button>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows == 0) { echo "d-none"; } ?>">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=payment_provider_name&order=<?php echo $disp; ?>">
                            Provider <?php if ($sort == 'payment_provider_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=account_name&order=<?php echo $disp; ?>">
                            Expense / Income Account <?php if ($sort == 'account_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=payment_provider_threshold&order=<?php echo $disp; ?>">
                            Threshold <?php if ($sort == 'payment_provider_threshold') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=vendor_name&order=<?php echo $disp; ?>">
                            Expense Vendor <?php if ($sort == 'vendor_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=category_name&order=<?php echo $disp; ?>">
                            Expense Category <?php if ($sort == 'category_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark">Expensed Fee</a>
                    </th>
                    <th class="text-center">
                        <a class="text-dark">Saved Payment Methods</a>
                    </th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php

                while ($row = mysqli_fetch_assoc($sql)) {
                    $provider_id = intval($row['payment_provider_id']);
                    $provider_name = nullable_htmlentities($row['payment_provider_name']);
                    $provider_description = nullable_htmlentities($row['payment_provider_description']);
                    $account_name = nullable_htmlentities($row['account_name']);
                    $threshold = floatval($row['payment_provider_threshold']);
                    $vendor_name = nullable_htmlentities($row['vendor_name'] ?? "Expense Disabled");
                    $category = nullable_htmlentities($row['category_name']);
                    $percent_fee = floatval($row['payment_provider_expense_percentage_fee']) * 100;
                    $flat_fee = floatval($row['payment_provider_expense_flat_fee']);

                    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('saved_payment_id') AS saved_payment_count FROM client_saved_payment_methods WHERE saved_payment_provider_id = $provider_id"));
                    $saved_payment_count = intval($row['saved_payment_count']);

                    ?>
                    <tr>
                        <td>
                            <a class="text-dark text-bold ajax-modal" href="#"
                                data-modal-url="modals/payment_provider/payment_provider_edit.php?id=<?= $provider_id ?>">
                                <?php echo $provider_name; ?>
                            </a>
                            <span class="text-secondary"><?php echo $provider_description; ?></span>
                        </td>
                        <td><?php echo $account_name; ?></td>
                        <td><?php echo numfmt_format_currency($currency_format, $threshold, $session_company_currency); ?></td>
                        <td><?php echo $vendor_name; ?></td>
                        <td><?php echo $category; ?></td>
                        <td><?php echo $percent_fee; ?>% + <?php echo numfmt_format_currency($currency_format, $flat_fee, $session_company_currency); ?></td>
                        <td class="text-center">
                            <a class="badge text-bg-dark rounded-pill p-2" href="saved_payment_method.php"><?= $saved_payment_count ?></a>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#"
                                        data-modal-url="modals/payment_provider/payment_provider_edit.php?id=<?= $provider_id ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?delete_payment_provider=<?= $provider_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-trash me-2"></i><strong>Delete Provider and</strong>
                                        <ul class="text-xs">
                                            <li>Related Recurring Payments</li>
                                            <li>Related Saved cards</li>
                                            <li>Client Provider Relations</li>
                                        </ul>
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <?php

                }

                if ($num_rows == 0) {
                    echo "<h3 class='text-secondary mt-3' style='text-align: center'>No Records Here</h3>";
                }

                ?>

                </tbody>
            </table>

        </div>
    </div>
</div>

<?php

$sql_map_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL AND client_lead = 0 ORDER BY client_name ASC");
$map_clients = [];
while ($c = mysqli_fetch_assoc($sql_map_clients)) { $map_clients[] = $c; }

$sql_map_providers = mysqli_query($mysqli, "SELECT payment_provider_id, payment_provider_name FROM payment_providers ORDER BY payment_provider_name ASC");
$map_providers = [];
while ($p = mysqli_fetch_assoc($sql_map_providers)) { $map_providers[] = $p; }

// "$client_id:$provider_id" => Stripe customer id
$customer_links = [];
$sql_cpp = mysqli_query($mysqli, "SELECT client_id, payment_provider_id, payment_provider_client FROM client_payment_provider");
while ($row = mysqli_fetch_assoc($sql_cpp)) {
    $customer_links[$row['client_id'] . ':' . $row['payment_provider_id']] = $row['payment_provider_client'];
}

// "$client_id:$provider_id" => array of saved-method display labels
$saved_methods = [];
$sql_spm = mysqli_query($mysqli, "SELECT saved_payment_client_id, saved_payment_provider_id, saved_payment_type, saved_payment_description, saved_payment_provider_method FROM client_saved_payment_methods ORDER BY saved_payment_created_at ASC");
while ($row = mysqli_fetch_assoc($sql_spm)) {
    $key = $row['saved_payment_client_id'] . ':' . $row['saved_payment_provider_id'];
    $desc = nullable_htmlentities($row['saved_payment_description']);
    $label = $desc !== '' ? $desc : (nullable_htmlentities($row['saved_payment_type'] ?: 'card') . ' (' . nullable_htmlentities($row['saved_payment_provider_method']) . ')');
    $saved_methods[$key][] = $label;
}

?>

<div class="card card-dark mt-3">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-people-arrows me-2"></i>Customer Mapping</h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($map_providers)): ?>
            <p class="text-secondary text-center py-4 mb-0">Add a payment provider above to see client mappings here.</p>
        <?php elseif (empty($map_clients)): ?>
            <p class="text-secondary text-center py-4 mb-0">No active clients.</p>
        <?php else: ?>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover mb-0">
                <thead class="text-dark">
                <tr>
                    <th>Client</th>
                    <?php if (count($map_providers) > 1) { echo "<th>Provider</th>"; } ?>
                    <th>Linked Customer</th>
                    <th>Saved Payment Method(s)</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($map_clients as $c):
                    $cid = intval($c['client_id']);
                    $cname = nullable_htmlentities($c['client_name']);
                    foreach ($map_providers as $p):
                        $pid = intval($p['payment_provider_id']);
                        $pname = nullable_htmlentities($p['payment_provider_name']);
                        $key = "$cid:$pid";
                        $customer_id = $customer_links[$key] ?? null;
                ?>
                <tr>
                    <td><?= $cname ?></td>
                    <?php if (count($map_providers) > 1): ?><td><?= $pname ?></td><?php endif; ?>
                    <td>
                        <?php if ($customer_id): ?>
                            <span class="badge text-bg-success me-1"><i class="fas fa-check-circle me-1"></i>Mapped</span>
                            <span class="text-secondary small"><?= nullable_htmlentities($customer_id) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary"><i class="fas fa-unlink me-1"></i>Not mapped</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($saved_methods[$key])): ?>
                            <?php foreach ($saved_methods[$key] as $label): ?>
                                <span class="badge text-bg-info me-1 mb-1"><?= $label ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-secondary">&mdash;</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";

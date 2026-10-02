<?php
$sql = mysqli_query($mysqli, "SELECT p.*, COALESCE(m.ai_model_count, 0) AS ai_model_count
    FROM ai_providers p
    LEFT JOIN (
        SELECT ai_model_ai_provider_id, COUNT(*) AS ai_model_count
        FROM ai_models GROUP BY ai_model_ai_provider_id
    ) m ON m.ai_model_ai_provider_id = p.ai_provider_id
    ORDER BY p.ai_provider_name ASC");
$num_rows = mysqli_num_rows($sql);
?>

<div class="card" id="providers">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-robot me-2"></i>AI Providers</h3>
        <div class="card-tools">
            <a class="btn btn-outline-secondary me-2" href="ai_model.php"><i class="fas fa-list me-2"></i>Manage Models</a>
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/ai/ai_provider_add.php"><i class="fas fa-plus me-2"></i>Add Provider</button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">Connect a provider, then configure its models and prompts.</p>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows == 0) { echo "d-none"; } ?>">
                <tr>
                    <th>
                        Provider
                    </th>
                    <th>
                        URL
                    </th>
                    <th>
                        Key
                    </th>
                    <th class="text-center">
                        Models
                    </th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php

                while ($row = mysqli_fetch_assoc($sql)) {
                    $provider_id = intval($row['ai_provider_id']);
                    $provider_name = nullable_htmlentities($row['ai_provider_name']);
                    $url = nullable_htmlentities($row['ai_provider_api_url']);
                    $key_is_set = !empty($row['ai_provider_api_key']);

                    $ai_model_count = intval($row['ai_model_count']);

                    ?>
                    <tr>
                        <td>
                            <a class="text-dark text-bold ajax-modal" href="#"
                                data-modal-url="modals/ai/ai_provider_edit.php?id=<?= $provider_id ?>">
                                <?php echo $provider_name; ?>
                            </a>
                        </td>
                        <td><?php echo $url; ?></td>
                        <td><?php echo $key_is_set ? '<span class="badge text-bg-success">Set</span>' : '<span class="badge text-bg-secondary">Not set</span>'; ?></td>
                        <td class="text-center">
                            <a class="badge text-bg-dark rounded-pill p-2" href="ai_model.php"><?= $ai_model_count ?></a>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#"
                                        data-modal-url="modals/ai/ai_provider_edit.php?id=<?= $provider_id ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?delete_ai_provider=<?php echo $provider_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-trash me-2"></i>Delete
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>

                    <?php

                }

                if ($num_rows == 0) {
                    echo '<tr><td colspan="5" class="text-center text-muted py-4">No AI providers configured yet. Add one to enable AI features.</td></tr>';
                }

                ?>

                </tbody>
            </table>

        </div>
    </div>
</div>

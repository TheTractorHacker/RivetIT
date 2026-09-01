<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_credential');

$client_id = intval($_GET['client_id'] ?? 0);
$current_folder_id = intval($_GET['current_folder_id'] ?? 0);

enforceClientAccess();

// Selected IDs from JS (may be empty array)
$credential_ids = array_map('intval', $_GET['credential_ids'] ?? []);

$total = count($credential_ids);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fa fa-fw fa-exchange-alt me-2"></i>
        Move <strong><?= $total ?></strong> Credential<?= $total === 1 ? '' : 's' ?>
    </h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <?php foreach ($credential_ids as $id): ?>
        <input type="hidden" name="credential_ids[]" value="<?= $id ?>">
    <?php endforeach; ?>

    <div class="modal-body">

        <div class="form-group">
            <label>Target Folder</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-folder"></i></span>
                </div>
                <select class="form-control select2" name="bulk_folder_id">
                    <option value="0">/</option>
                    <?php
                    // Credential folders only (folder_location = 2)
                    $sql_all_folders = mysqli_query(
                        $mysqli,
                        "SELECT folder_id, folder_name, parent_folder
                         FROM folders
                         WHERE folder_client_id = $client_id
                         AND folder_location = 2
                         ORDER BY folder_name ASC"
                    );

                    $folders = [];

                    while ($row = mysqli_fetch_assoc($sql_all_folders)) {
                        $folders[$row['folder_id']] = [
                            'folder_id'    => (int)$row['folder_id'],
                            'folder_name'  => nullable_htmlentities($row['folder_name']),
                            'parent_folder'=> (int)$row['parent_folder'],
                            'children'     => []
                        ];
                    }

                    // Build hierarchy
                    foreach ($folders as $id => &$folder) {
                        if ($folder['parent_folder'] != 0 && isset($folders[$folder['parent_folder']])) {
                            $folders[$folder['parent_folder']]['children'][] = &$folder;
                        }
                    }
                    unset($folder);

                    $root_folders = [];
                    foreach ($folders as $id => $folder) {
                        if ($folder['parent_folder'] == 0) {
                            $root_folders[] = $folder;
                        }
                    }

                    $stack = [];
                    foreach (array_reverse($root_folders) as $folder) {
                        $stack[] = ['folder' => $folder, 'level' => 0];
                    }

                    while (!empty($stack)) {
                        $node   = array_pop($stack);
                        $folder = $node['folder'];
                        $level  = $node['level'];

                        $indentation = str_repeat('&nbsp;', $level * 4);

                        $selected = ($folder['folder_id'] === $current_folder_id) ? 'selected' : '';

                        echo "<option value=\"{$folder['folder_id']}\" $selected>$indentation{$folder['folder_name']}</option>";

                        if (!empty($folder['children'])) {
                            foreach (array_reverse($folder['children']) as $child) {
                                $stack[] = ['folder' => $child, 'level' => $level + 1];
                            }
                        }
                    }
                    ?>
                </select>
            </div>
        </div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="bulk_move_credentials" class="btn btn-primary text-bold">
            <i class="fa fa-check me-2"></i>Move Credentials
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
            <i class="fa fa-times me-2"></i>Cancel
        </button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';

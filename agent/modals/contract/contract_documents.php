<?php
require_once '../../../includes/modal_header.php';
enforceUserPermission('module_contracts');

$contract_id = intval($_GET['contract_id']);

$contract = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT contract_name, contract_client_id FROM contracts WHERE contract_id = $contract_id LIMIT 1"
));
if (!$contract) { echo '<div class="p-3 text-danger">Contract not found.</div>'; require_once '../../../includes/modal_footer.php'; exit; }

$client_id   = intval($contract['contract_client_id']);
$contract_name = nullable_htmlentities($contract['contract_name']);

if ($client_id) enforceClientAccess();

$docs = mysqli_query($mysqli,
    "SELECT d.*, u.user_name FROM contract_documents d
     LEFT JOIN users u ON d.doc_uploaded_by = u.user_id
     WHERE d.doc_contract_id = $contract_id
     ORDER BY d.doc_uploaded_at DESC"
);

ob_start();
?>
<div class="modal-header bg-dark text-white">
    <div>
        <h5 class="modal-title mb-0"><i class="fas fa-fw fa-file-pdf me-2"></i>Documents</h5>
        <small class="text-muted"><?= $contract_name ?></small>
    </div>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>

<div class="modal-body p-0">

    <!-- Upload form -->
    <?php if (lookupUserPermission('module_contracts') >= 2): ?>
    <div class="p-3 border-bottom">
        <form action="post.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="contract_id" value="<?= $contract_id ?>">
            <div class="input-group">
                <div class="custom-file">
                    <input type="file" class="custom-file-input" id="doc_upload" name="contract_document"
                           accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.txt" required>
                    <label class="custom-file-label" for="doc_upload">Choose file…</label>
                </div>
                <div class="input-group-append">
                    <button class="btn btn-primary" type="submit" name="upload_contract_document">
                        <i class="fas fa-upload me-1"></i>Upload
                    </button>
                </div>
            </div>
            <small class="text-muted">PDF, Word, images, text — max 20 MB</small>
        </form>
    </div>
    <?php endif; ?>

    <!-- Document list -->
    <?php $count = mysqli_num_rows($docs);
    if ($count === 0): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-folder-open fa-3x mb-3 d-block text-secondary"></i>
            No documents uploaded yet.
        </div>
    <?php else: ?>
    <table class="table table-sm table-borderless table-hover mb-0">
        <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
            <tr>
                <th class="ps-3" style="width:32px;"></th>
                <th>File</th>
                <th>Size</th>
                <th>Uploaded by</th>
                <th>Date</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php while ($doc = mysqli_fetch_assoc($docs)):
            $doc_id   = intval($doc['doc_id']);
            $fname    = nullable_htmlentities($doc['doc_original_name']);
            $uploader = nullable_htmlentities($doc['user_name'] ?? 'System');
            $date     = nullable_htmlentities($doc['doc_uploaded_at']);
            $ago      = timeAgo($doc['doc_uploaded_at']);
            $size     = $doc['doc_size'] >= 1048576
                ? round($doc['doc_size'] / 1048576, 1) . ' MB'
                : round($doc['doc_size'] / 1024, 0) . ' KB';
            $mime = $doc['doc_mime_type'];
            $icon = str_contains($mime, 'pdf') ? 'file-pdf text-danger'
                : (str_contains($mime, 'word') || str_contains($fname, '.doc') ? 'file-word text-primary'
                : (str_contains($mime, 'image') ? 'file-image text-success'
                : 'file-alt text-secondary'));
        ?>
            <tr>
                <td class="ps-3 text-center"><i class="fas fa-<?= $icon ?>"></i></td>
                <td class="small fw-bold"><?= $fname ?></td>
                <td class="text-muted small"><?= $size ?></td>
                <td class="text-muted small"><?= $uploader ?></td>
                <td class="text-muted small" title="<?= $date ?>"><?= $ago ?></td>
                <td class="pe-3 text-end text-nowrap">
                    <a href="post.php?serve_contract_document=<?= $doc_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                       class="btn btn-xs btn-outline-primary" title="Download" target="_blank">
                        <i class="fas fa-download"></i>
                    </a>
                    <?php if (lookupUserPermission('module_contracts') >= 2): ?>
                    <a href="post.php?delete_contract_document=<?= $doc_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                       class="btn btn-xs btn-outline-danger ms-1 js-confirm-native" title="Delete"
                       data-confirm-message="Delete <?= htmlspecialchars($fname, ENT_QUOTES) ?>?">
                        <i class="fas fa-trash"></i>
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="modal-footer py-2 text-muted small">
    <?= $count ?> document<?= $count !== 1 ? 's' : '' ?>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
// Show filename in the custom file input label
document.getElementById('doc_upload')?.addEventListener('change', function() {
    var label = this.nextElementSibling;
    label.textContent = this.files[0] ? this.files[0].name : 'Choose file…';
});
</script>

<?php require_once '../../../includes/modal_footer.php'; ?>

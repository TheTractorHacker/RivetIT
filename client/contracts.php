<?php
require_once "includes/inc_all.php";

if ($session_contact_primary == 0 && !$session_contact_is_technical_contact) {
    header("Location: post.php?logout");
    exit();
}

$docs_by_contract = [];
$contracts_sql = mysqli_query($mysqli,
    "SELECT c.contract_id, c.contract_name, c.contract_type, c.contract_status,
            c.contract_start_date, c.contract_end_date
     FROM contracts c
     WHERE c.contract_client_id = $session_client_id
       AND c.contract_archived_at IS NULL
     ORDER BY c.contract_name ASC"
);
while ($cr = mysqli_fetch_assoc($contracts_sql)) {
    $cid = intval($cr['contract_id']);
    $doc_sql = mysqli_query($mysqli,
        "SELECT doc_id, doc_original_name, doc_mime_type, doc_size, doc_uploaded_at
         FROM contract_documents WHERE doc_contract_id = $cid ORDER BY doc_uploaded_at DESC"
    );
    $docs = [];
    while ($d = mysqli_fetch_assoc($doc_sql)) $docs[] = $d;
    $docs_by_contract[] = array_merge($cr, ['docs' => $docs]);
}
?>
<div class="container-fluid mt-3">
<div class="card">
    <div class="card-header">
        <h4 class="mb-0"><i class="fas fa-file-contract me-2"></i>Contracts &amp; Documents</h4>
    </div>
    <div class="card-body p-0">
        <?php if (empty($docs_by_contract)): ?>
            <p class="text-muted text-center py-4 mb-0">No contracts on file.</p>
        <?php else: ?>
        <div class="accordion" id="contractAccordion">
        <?php foreach ($docs_by_contract as $i => $cr):
            $cid      = intval($cr['contract_id']);
            $cname    = nullable_htmlentities($cr['contract_name']);
            $ctype    = nullable_htmlentities($cr['contract_type']);
            $cstatus  = nullable_htmlentities($cr['contract_status']);
            $cstart   = $cr['contract_start_date'] ? date('M j, Y', strtotime($cr['contract_start_date'])) : '—';
            $cend     = $cr['contract_end_date']   ? date('M j, Y', strtotime($cr['contract_end_date']))   : '—';
            $badge    = match($cstatus) { 'Active'=>'success','Pending'=>'warning','Expired'=>'secondary',default=>'danger' };
            $doc_count = count($cr['docs']);
        ?>
            <div class="card mb-2 border">
                <div class="card-header py-2" id="ch<?= $cid ?>" style="cursor:pointer;"
                     data-bs-toggle="collapse" data-bs-target="#cc<?= $cid ?>" aria-expanded="<?= $i===0?'true':'false' ?>">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-file-contract me-3 text-primary"></i>
                        <div class="flex-grow-1">
                            <strong><?= $cname ?></strong>
                            <span class="text-muted small ms-2"><?= $ctype ?></span>
                        </div>
                        <span class="badge badge-<?= $badge ?> me-3"><?= $cstatus ?></span>
                        <span class="text-muted small me-3"><?= $cstart ?> – <?= $cend ?></span>
                        <span class="badge text-bg-secondary"><?= $doc_count ?> doc<?= $doc_count!==1?'s':'' ?></span>
                        <i class="fas fa-chevron-down ms-3 text-muted" style="font-size:12px;"></i>
                    </div>
                </div>
                <div id="cc<?= $cid ?>" class="collapse <?= $i===0?'show':'' ?>" data-bs-parent="#contractAccordion">
                    <div class="card-body p-0">
                        <?php if (empty($cr['docs'])): ?>
                            <p class="text-muted text-center py-3 mb-0 small">No documents uploaded for this contract.</p>
                        <?php else: ?>
                        <table class="table table-sm table-borderless table-hover mb-0">
                            <tbody>
                            <?php foreach ($cr['docs'] as $doc):
                                $doc_id = intval($doc['doc_id']);
                                $fname  = nullable_htmlentities($doc['doc_original_name']);
                                $mime   = $doc['doc_mime_type'];
                                $size   = $doc['doc_size'] >= 1048576
                                    ? round($doc['doc_size']/1048576,1).' MB'
                                    : round($doc['doc_size']/1024,0).' KB';
                                $date   = date('M j, Y', strtotime($doc['doc_uploaded_at']));
                                $icon   = str_contains($mime,'pdf') ? 'file-pdf text-danger'
                                    : (str_contains($mime,'word') ? 'file-word text-primary'
                                    : (str_contains($mime,'image') ? 'file-image text-success'
                                    : 'file-alt text-secondary'));
                            ?>
                                <tr>
                                    <td class="ps-3" style="width:36px;"><i class="fas fa-<?= $icon ?>"></i></td>
                                    <td><strong class="small"><?= $fname ?></strong></td>
                                    <td class="text-muted small"><?= $size ?></td>
                                    <td class="text-muted small"><?= $date ?></td>
                                    <td class="pe-3 text-end">
                                        <a href="post.php?client_serve_contract_doc=<?= $doc_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                                           class="btn btn-sm btn-outline-primary" target="_blank">
                                            <i class="fas fa-download me-1"></i>View / Download
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php require_once "includes/footer.php"; ?>

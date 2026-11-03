<?php
require_once "includes/inc_all_admin.php";

// Administrator copy of the public /trust.php page, with the per-control detail and notes.
$page = new \ITFlow\Security\TrustPage(dirname(__DIR__));
?>
<style nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">.card-body table th:nth-child(2),.card-body table td:nth-child(2){white-space:nowrap}.card-body table th:nth-child(1),.card-body table td:nth-child(1){white-space:nowrap}</style>
<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-shield-alt mr-2"></i>Security and trust</h3>
        <div class="card-tools"><a class="btn btn-sm btn-outline-secondary" href="/trust.php" target="_blank" rel="noopener">Public page</a></div>
    </div>
    <div class="card-body">
        <?php echo $page->html(defined('APP_VERSION') ? APP_VERSION : '', true); ?>
    </div>
</div>
<?php $page_index_selector = '.card-body h2'; require __DIR__ . '/includes/page_index.php'; ?>
<?php require_once "../includes/footer.php"; ?>

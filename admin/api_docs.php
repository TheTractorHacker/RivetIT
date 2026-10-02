<?php
require_once 'includes/inc_all_admin.php';
?>
<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-code me-2"></i>API Documentation</h3>
        <div class="card-tools">
            <a href="/api/v1/openapi" download="rivetit-openapi.yaml" class="btn btn-secondary btn-sm">
                <i class="fas fa-file-code me-1"></i>OpenAPI spec
            </a>
            <a href="/api/v1/docs" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                <i class="fas fa-external-link-alt me-1"></i>Open full reference
            </a>
        </div>
    </div>
    <div class="card-body p-2 p-md-3">
        <p class="text-muted mb-2">Search for an endpoint, then choose Python, PHP, C/Libcurl, or another client above its request example. Manage credentials in <a href="/admin/api_keys.php">API Keys</a>.</p>
        <iframe src="/api/v1/docs" title="RivetIT API reference" style="display:block;width:100%;height:min(82vh,1000px);min-height:620px;border:1px solid var(--bs-border-color);border-radius:.4rem;background:#fff"></iframe>
    </div>
</div>
<?php require_once '../includes/footer.php'; ?>

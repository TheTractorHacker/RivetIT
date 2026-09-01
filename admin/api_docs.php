<?php

require_once "includes/inc_all_admin.php";
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/openapi_functions.php';

$spec        = parseOpenApiSpec($_SERVER['DOCUMENT_ROOT'] . '/api/v1/openapi.yaml');
$api_title   = $spec['title'];
$api_version = $spec['version'];
$endpoints   = $spec['endpoints'];
$tag_order   = $spec['tag_order'];
$by_tag      = $spec['by_tag'];

$method_class = [
    'GET'    => 'get',
    'POST'   => 'post',
    'PUT'    => 'put',
    'PATCH'  => 'put',
    'DELETE' => 'delete',
];

$slug = fn($tag) => 'tag-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($tag));

?>
<style>
  /* Scoped to the API Docs page. Reuses the app's own tokens (css/itflow_custom.css,
     css/itflow_design.css) so light/dark theme, radius and shadow stay consistent
     with the rest of the admin area - this isn't a separate visual language, just
     a denser, reference-manual layout for a page with 90+ rows to scan. */
  .api-docs-shell { --api-get: #0a7d3f; --api-get-bg: #e6f4ec;
    --api-post: #1b4fd8; --api-post-bg: #e6ecfd;
    --api-put: #a8600a; --api-put-bg: #fbf0df;
    --api-delete: #c1341a; --api-delete-bg: #fbe7e3; }
  :root[data-bs-theme="dark"] .api-docs-shell { --api-get: #57d98a; --api-get-bg: #16311f;
    --api-post: #8fb0ff; --api-post-bg: #1a2340;
    --api-put: #e0b25f; --api-put-bg: #352a13;
    --api-delete: #f08a75; --api-delete-bg: #3a1f19; }

  /* AdminLTE sets `.sidebar-expand-*.layout-fixed .app-main{overflow:auto}`
     (adminlte.min.css) at (0,0,3,0) specificity intending .app-main to be the
     scrolling viewport under a fixed sidebar/topbar - but nothing constrains
     its height to the viewport (html itself is also overflow:visible/scrollable),
     so .app-main's scrollHeight==clientHeight and it never actually scrolls;
     <html> does instead. position:sticky still computes against the nearest
     overflow!=visible ancestor regardless of whether that ancestor truly
     scrolls, so .api-toc silently behaved as static and scrolled away instead
     of sticking (confirmed: getBoundingClientRect().top went to -4136px after
     scrolling 4500px). The !important is deliberate and scoped to only this
     page (:has(.api-docs-shell)) - beating AdminLTE's 3-class selector cleanly
     would require duplicating all 6 sidebar-expand-{sm,md,lg,xl,xxl,''} variants,
     which is more fragile than one documented, narrowly-targeted override.
     Must reset BOTH axes: per the CSS overflow spec, if overflow-x and
     overflow-y are set to different values and neither is visible, "visible"
     on just one axis computes back to auto anyway - confirmed via CDP
     getMatchedStylesForNode that overflow-y:visible!important was winning the
     cascade yet getComputedStyle() still reported auto, because the
     3-class rule's overflow-x:auto was still in effect on the other axis. */
  .app-main:has(.api-docs-shell) { overflow: visible !important; }
  .api-toc { position: sticky; top: 1rem; max-height: calc(100vh - 6rem); overflow-y: auto; }
  .api-toc a {
    display: flex; justify-content: space-between; gap: .5rem;
    padding: .38rem .7rem; border-radius: var(--if-radius-sm); font-size: .85rem;
    color: var(--if-ink); text-decoration: none;
  }
  .api-toc a:hover { background: var(--if-bg); }
  .api-toc a .cnt { color: var(--if-muted); font-family: var(--if-mono); font-size: .78rem; }
  .api-toc-legend { font-size: .74rem; color: var(--if-muted); border-top: 1px solid var(--if-border); margin-top: .6rem; padding-top: .6rem; }
  .api-toc-legend .api-verb { margin-right: .25rem; }

  .api-searchbar {
    position: sticky; top: 0; z-index: 2; background: var(--if-bg);
    padding: .85rem 0 .7rem; margin-bottom: .25rem;
  }
  .api-searchbar .form-control:focus { border-color: var(--color-accent); box-shadow: 0 0 0 3px var(--color-accent-soft); }
  .api-count { white-space: nowrap; color: var(--if-muted); font-size: .85rem; }

  /* .card:not(.card-outline) in itflow_custom.css resets border-top-* to `none`
     at (0,0,2,0) specificity - a plain .api-section{border-top:...} rule (0,0,1,0)
     loses to it regardless of source order. Use AdminLTE's own card-outline
     variant hook instead (--lte-card-variant-bg, see adminlte.min.css's
     `.card.card-outline{border-top:3px solid var(--lte-card-variant-bg)}`) rather
     than fighting the cascade - the api-section div also carries card-outline. */
  .api-section { --lte-card-variant-bg: var(--color-accent); margin-bottom: 1.1rem; }
  .api-section .card-header { display: flex; align-items: baseline; gap: .5rem; }
  .api-section .card-header h5 { margin: 0; }
  .api-section .card-header .cnt { color: var(--if-muted); font-size: .8rem; }

  .api-row { display: flex; align-items: baseline; gap: .7rem; padding: .55rem .25rem; border-top: 1px solid var(--if-border); flex-wrap: wrap; }
  .api-row:first-child { border-top: none; }
  .api-verb {
    display: inline-block; min-width: 56px; text-align: center; flex-shrink: 0;
    font-family: var(--if-mono); font-size: .68rem; font-weight: 700; letter-spacing: .04em;
    padding: .18rem 0; border-radius: 6px;
  }
  .api-verb.get { color: var(--api-get); background: var(--api-get-bg); }
  .api-verb.post { color: var(--api-post); background: var(--api-post-bg); }
  .api-verb.put { color: var(--api-put); background: var(--api-put-bg); }
  .api-verb.delete { color: var(--api-delete); background: var(--api-delete-bg); }
  .api-path { font-family: var(--if-mono); font-size: .86rem; word-break: break-all; }
  .api-copy { border: 0; background: none; color: var(--if-muted); padding: 0 0 0 .3rem; cursor: pointer; }
  .api-copy:hover { color: var(--color-accent); }
  .api-summary { flex-basis: 100%; color: var(--if-muted); font-size: .83rem; margin-left: 66px; }
  .api-row.api-hidden, .api-section.api-hidden { display: none; }

  .api-empty { display: none; padding: 2.5rem 1rem; text-align: center; color: var(--if-muted); }
  .api-empty.api-visible { display: block; }

  @media (max-width: 767px) {
    .api-toc { position: static; max-height: none; margin-bottom: 0; }
    .api-searchbar { position: static; }
  }
</style>

<div class="card card-dark api-docs-shell">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-code me-2"></i>API Documentation</h3>
        <div class="card-tools">
            <a href="/api/v1/openapi.yaml" target="_blank" class="btn btn-secondary btn-sm">
                <i class="fas fa-file-code me-1"></i>Raw OpenAPI Spec
            </a>
            <a href="/api/v1/docs" target="_blank" class="btn btn-secondary btn-sm">
                <i class="fas fa-external-link-alt me-1"></i>Public Reference Page
            </a>
        </div>
    </div>

    <div class="card-body">
        <p class="text-muted mb-0">
            <?= nullable_htmlentities($api_title) ?><?= $api_version !== '' ? ' &middot; v' . nullable_htmlentities($api_version) : '' ?>
            &mdash; every live endpoint of the REST API used by the ITFlow mobile app, ITPanel Pro, and other integrations.
            Base path: <code>/api/v1</code>. Import <a href="/api/v1/openapi.yaml" target="_blank"><code>openapi.yaml</code></a>
            into Swagger UI, Postman, or Insomnia to explore/test interactively.
        </p>
        <hr>
        <div class="row">
            <div class="col-md-6 mb-3 mb-md-0">
                <h5><i class="fas fa-fw fa-key text-secondary me-1"></i>Authentication</h5>
                <ul class="mb-0 ps-3">
                    <li><code>Authorization: Bearer &lt;token&gt;</code> &mdash; a user token from <code>POST /api/v1/auth</code></li>
                    <li><code>X-Api-Key: &lt;key&gt;</code> &mdash; a legacy instance API key (see <a href="/admin/api_keys.php">API Keys</a>; denied on <code>/credentials</code> and <code>/me</code>)</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h5><i class="fas fa-fw fa-info-circle text-secondary me-1"></i>Conventions</h5>
                <ul class="mb-0 ps-3">
                    <li>Errors return <code>{"error": "..."}</code></li>
                    <li>Lists return <code>{"data": [...], "total": N}</code></li>
                    <li>Creates return <code>{"id": N}</code>; simple mutations return <code>{"ok": true}</code></li>
                    <li>Rate limit: 300 requests/60s per caller (<code>429</code> with <code>Retry-After</code>)</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($endpoints): ?>
<div class="row api-docs-shell">
    <div class="col-12 col-md-3 order-2 order-md-1 mb-3 mb-md-0">
        <div class="api-toc">
            <?php foreach ($tag_order as $tag): if (empty($by_tag[$tag])) continue; ?>
            <a href="#<?= $slug($tag) ?>" data-toc-for="<?= $slug($tag) ?>">
                <span><?= nullable_htmlentities($tag) ?></span>
                <span class="cnt"><?= count($by_tag[$tag]) ?></span>
            </a>
            <?php endforeach; ?>
            <div class="api-toc-legend">
                <span class="api-verb get">GET</span>read
                &nbsp;<span class="api-verb post">POST</span>create
                &nbsp;<span class="api-verb put">PUT</span>update
                &nbsp;<span class="api-verb delete">DELETE</span>remove
            </div>
        </div>
    </div>

    <div class="col-12 col-md-9 order-1 order-md-2">
        <div class="api-searchbar d-flex align-items-center" style="gap:.75rem;">
            <div class="input-group">
                <span class="input-group-text bg-transparent"><i class="fa fa-search text-secondary"></i></span>
                <input type="search" id="apiDocsSearch" class="form-control" placeholder="Filter by path, summary, or method&hellip;">
            </div>
            <span class="api-count"><span id="apiDocsCount"><?= count($endpoints) ?></span> / <?= count($endpoints) ?> endpoints</span>
        </div>

        <?php foreach ($tag_order as $tag): if (empty($by_tag[$tag])) continue; ?>
        <div class="card card-outline api-section" id="<?= $slug($tag) ?>">
            <div class="card-header py-2">
                <h5><?= nullable_htmlentities($tag) ?></h5>
                <span class="cnt">(<?= count($by_tag[$tag]) ?>)</span>
            </div>
            <div class="card-body py-2">
                <?php foreach ($by_tag[$tag] as $ep):
                    $cls  = $method_class[$ep['method']] ?? 'get';
                    $path = $ep['path'];
                ?>
                <div class="api-row" data-search="<?= nullable_htmlentities(strtolower($ep['method'] . ' ' . $path . ' ' . $ep['summary'])) ?>">
                    <span class="api-verb <?= $cls ?>"><?= nullable_htmlentities($ep['method']) ?></span>
                    <span class="api-path"><?= nullable_htmlentities($path) ?></span>
                    <button type="button" class="api-copy clipboardjs" data-clipboard-text="<?= nullable_htmlentities($path) ?>" title="Copy path">
                        <i class="far fa-copy"></i>
                    </button>
                    <?php if ($ep['summary'] !== ''): ?>
                    <span class="api-summary"><?= nullable_htmlentities($ep['summary']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="api-empty" id="apiDocsEmpty">
            <i class="fas fa-fw fa-search fa-2x mb-2"></i>
            <p class="mb-0">No endpoints match "<span id="apiDocsEmptyQuery"></span>". Try a different path, summary word, or method.</p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body">
        <p class="text-danger mb-0">Could not read <code>api/v1/openapi.yaml</code>.</p>
    </div>
</div>
<?php endif; ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var input = document.getElementById('apiDocsSearch');
    if (!input) return;
    var rows     = Array.prototype.slice.call(document.querySelectorAll('.api-row'));
    var total    = rows.length;
    var countEl  = document.getElementById('apiDocsCount');
    var emptyEl  = document.getElementById('apiDocsEmpty');
    var emptyQEl = document.getElementById('apiDocsEmptyQuery');

    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var terms = q.split(/\s+/).filter(Boolean);
        var visible = 0;

        document.querySelectorAll('.api-section').forEach(function (section) {
            var secRows = section.querySelectorAll('.api-row');
            var secVisible = 0;
            secRows.forEach(function (row) {
                var hay = row.getAttribute('data-search') || '';
                var match = terms.every(function (t) { return hay.indexOf(t) !== -1; });
                row.classList.toggle('api-hidden', !match);
                if (match) secVisible++;
            });
            section.classList.toggle('api-hidden', secVisible === 0);
            var tocLink = document.querySelector('[data-toc-for="' + section.id + '"]');
            if (tocLink) {
                var cnt = tocLink.querySelector('.cnt');
                if (cnt) cnt.textContent = secVisible;
            }
            visible += secVisible;
        });

        countEl.textContent = visible;
        emptyEl.classList.toggle('api-visible', visible === 0);
        emptyQEl.textContent = input.value.trim();
    });
})();
</script>

<?php require_once "../includes/footer.php"; ?>

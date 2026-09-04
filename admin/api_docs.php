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
  /* ================================================================
     DESIGN NOTE: this page's audience is developers integrating
     against the API (mobile app, ITPanel Pro, RMM scripts) - people
     who read references like this in a dark editor/terminal already.
     Styling it as another light Bootstrap dashboard card (the
     previous two passes) never had a shot at feeling considered to
     that audience, no matter how much the spacing was refined.

     So: the reference itself is a fixed-dark, monospace "console"
     panel with its OWN identity, independent of the app's own
     light/dark toggle - the way a code editor's terminal pane keeps
     its own palette regardless of the editor's theme. The header
     card above it stays in the app's normal theme; only the console
     commits to something else. Sharp corners (no border-radius) are
     deliberate too - real terminals don't have rounded corners, and
     it lets the sticky prompt bar sit flush against the panel edge
     instead of visually detaching from a rounded corner as it scrolls.

     Method colors are ANSI-terminal-inspired and semantic (GET/POST/
     PUT/DELETE each mean something specific), not one arbitrary neon
     accent - deliberately different from the generic "dark bg, one
     bright accent" AI-design default.
     ================================================================ */
  .api-console {
    --c-bg: #0b1014;
    --c-surface: #11181d;
    --c-border: #1f2b31;
    --c-ink: #d8e2e4;
    --c-muted: #6b8087;
    --c-accent: #5eead4;
    --c-get: #7ee787;
    --c-post: #79c0ff;
    --c-put: #f2cc60;
    --c-delete: #ff7b72;

    background: var(--c-bg);
    border: 1px solid var(--c-border);
    font-family: var(--if-mono);
    color: var(--c-ink);
    display: flex;
    align-items: stretch;
    /* NOT overflow:hidden - same trap as the .app-main fix below: any
       overflow != visible here would make .api-console itself the nearest
       "scrolling ancestor" for the sticky TOC/prompt-bar's positioning math,
       breaking them exactly like .app-main did. Sharp corners (no
       border-radius) mean nothing needs clipping anyway. */
  }
  .api-console a { color: var(--c-post); }
  .api-console a:focus-visible,
  .api-console button:focus-visible,
  .api-console input:focus-visible {
    outline: 2px solid var(--c-accent); outline-offset: 1px;
  }

  /* AdminLTE sets `.sidebar-expand-*.layout-fixed .app-main{overflow:auto}`
     (adminlte.min.css) at (0,0,3,0) specificity intending .app-main to be the
     scrolling viewport under a fixed sidebar/topbar - but nothing constrains
     its height to the viewport (html itself is also overflow:visible/scrollable),
     so .app-main's scrollHeight==clientHeight and it never actually scrolls;
     <html> does instead. position:sticky still computes against the nearest
     overflow!=visible ancestor regardless of whether that ancestor truly
     scrolls, so a sticky sidebar/prompt bar would silently behave as static
     and scroll away instead of sticking. The !important is deliberate and
     scoped to only this page (:has(.api-docs-shell)) - beating AdminLTE's
     3-class selector cleanly would require duplicating all 6
     sidebar-expand-{sm,md,lg,xl,xxl,''} variants. Must reset BOTH overflow
     axes: if x/y differ and neither is visible, "visible" on just one axis
     computes back to auto (confirmed via CDP getMatchedStylesForNode). */
  .app-main:has(.api-docs-shell) { overflow: visible !important; }

  .api-toc-col {
    flex: 0 0 240px; border-right: 1px solid var(--c-border);
    background: var(--c-surface);
  }
  .api-toc { position: sticky; top: 1rem; max-height: calc(100vh - 6rem); overflow-y: auto; padding: .9rem 0; }
  .api-toc a {
    display: flex; justify-content: space-between; gap: .5rem;
    padding: .3rem .9rem; font-size: .78rem; text-decoration: none; color: var(--c-ink);
  }
  .api-toc a:hover { background: rgba(94, 234, 212, .07); color: var(--c-accent); }
  .api-toc a .cnt { color: var(--c-muted); }
  .api-toc-legend {
    font-size: .68rem; color: var(--c-muted); border-top: 1px solid var(--c-border);
    margin: .7rem .9rem 0; padding-top: .6rem; line-height: 1.9;
  }
  .api-toc-legend .api-verb { display: inline-block; min-width: 3.2em; margin-right: .4em; }

  .api-main-col { flex: 1 1 auto; min-width: 0; }

  .api-prompt-bar {
    position: sticky; top: 0; z-index: 2; background: var(--c-surface);
    border-bottom: 1px solid var(--c-border);
    display: flex; align-items: center; gap: .6rem; padding: .8rem 1.1rem;
  }
  .api-prompt-label { color: var(--c-accent); font-weight: 600; font-size: .85rem; white-space: nowrap; user-select: none; }
  .api-prompt-bar input[type="search"] {
    flex: 1; min-width: 0; background: transparent; border: 0; outline: 0;
    color: var(--c-ink); font-family: var(--if-mono); font-size: .85rem; caret-color: var(--c-accent);
  }
  .api-prompt-bar input[type="search"]::placeholder { color: var(--c-muted); }
  .api-prompt-bar input[type="search"]::-webkit-search-cancel-button { filter: invert(60%); cursor: pointer; }
  .api-count { white-space: nowrap; color: var(--c-muted); font-size: .78rem; }

  .api-section-heading {
    font-family: var(--if-mono); text-transform: uppercase; letter-spacing: .1em;
    font-size: .72rem; font-weight: 700; color: var(--c-accent);
    padding: 1.2rem 1.1rem .55rem; margin: 0;
    border-top: 1px solid var(--c-border);
  }
  .api-main-col > .api-section:first-child .api-section-heading { border-top: 0; }
  .api-section-heading .cnt { color: var(--c-muted); font-weight: 500; letter-spacing: 0; text-transform: none; margin-left: .5rem; }

  .api-path-heading {
    display: flex; align-items: center; gap: .5rem; padding: .55rem 1.1rem .15rem;
    font-size: .86rem; font-weight: 600; color: var(--c-ink);
  }
  .api-copy { border: 0; background: none; color: var(--c-muted); padding: 0; cursor: pointer; font-size: .78rem; line-height: 1; }
  .api-copy:hover { color: var(--c-accent); }

  .api-row { display: flex; align-items: baseline; gap: .7rem; padding: .3rem 1.1rem .45rem 2.5rem; flex-wrap: wrap; }
  .api-verb { font-weight: 700; font-size: .74rem; min-width: 3.4em; flex-shrink: 0; }
  .api-verb.get { color: var(--c-get); }
  .api-verb.post { color: var(--c-post); }
  .api-verb.put { color: var(--c-put); }
  .api-verb.delete { color: var(--c-delete); }
  .api-op-body { min-width: 0; }
  .api-summary { color: var(--c-ink); font-family: var(--if-sans); font-size: .84rem; }
  .api-meta { margin-top: .2rem; font-size: .72rem; color: var(--c-muted); }
  .api-meta .api-flag::before { content: "\00b7"; margin-right: .3em; }
  .api-meta .api-flag { margin-right: .8em; }
  .api-meta .api-flag-public { color: var(--c-accent); }
  .api-meta .api-param-req { color: var(--c-ink); }
  .api-row.api-hidden, .api-path-group.api-hidden, .api-section.api-hidden { display: none; }

  .api-empty { display: none; padding: 3rem 1.5rem; text-align: center; color: var(--c-muted); font-family: var(--if-sans); }
  .api-empty.api-visible { display: block; }
  .api-empty code { color: var(--c-accent); background: none; }

  @media (max-width: 767px) {
    .api-console { flex-direction: column; }
    .api-toc-col { flex: 0 0 auto; border-right: 0; border-top: 1px solid var(--c-border); order: 2; }
    .api-toc { position: static; max-height: none; }
    .api-main-col { order: 1; }
  }

  @media (prefers-reduced-motion: reduce) { .api-console * { transition: none !important; } }
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
            &mdash; every live endpoint of the REST API used by the ITFlow Internal IT mobile app, ITPanel Pro, and other integrations.
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
<div class="api-console api-docs-shell mt-3">
    <div class="api-toc-col">
        <div class="api-toc">
            <?php foreach ($tag_order as $tag): if (empty($by_tag[$tag])) continue; ?>
            <a href="#<?= $slug($tag) ?>" data-toc-for="<?= $slug($tag) ?>">
                <span><?= nullable_htmlentities($tag) ?></span>
                <span class="cnt"><?= count($by_tag[$tag]) ?></span>
            </a>
            <?php endforeach; ?>
            <div class="api-toc-legend">
                <div><span class="api-verb get">GET</span>read</div>
                <div><span class="api-verb post">POST</span>create</div>
                <div><span class="api-verb put">PUT</span>update</div>
                <div><span class="api-verb delete">DELETE</span>remove</div>
            </div>
        </div>
    </div>

    <div class="api-main-col">
        <div class="api-prompt-bar">
            <span class="api-prompt-label">api-docs&nbsp;$</span>
            <input type="search" id="apiDocsSearch" placeholder="grep path, summary, or method&hellip;" autocomplete="off" spellcheck="false">
            <span class="api-count"><span id="apiDocsCount"><?= count($endpoints) ?></span>/<?= count($endpoints) ?></span>
        </div>

        <?php foreach ($tag_order as $tag): if (empty($by_tag[$tag])) continue; ?>
        <section class="api-section" id="<?= $slug($tag) ?>">
            <h4 class="api-section-heading">
                <?= nullable_htmlentities($tag) ?>
                <span class="cnt" data-total="<?= count($by_tag[$tag]) ?>">(<?= count($by_tag[$tag]) ?>)</span>
            </h4>
            <?php
            // Group this tag's endpoints by path (they're already contiguous
            // per-path in file order, since one YAML path key holds all its
            // methods together) so the URL is shown once per resource.
            $groups = [];
            foreach ($by_tag[$tag] as $ep) {
                $groups[$ep['path']][] = $ep;
            }
            foreach ($groups as $path => $ops): ?>
                <div class="api-path-group" data-search="<?= nullable_htmlentities(strtolower($path)) ?>">
                    <div class="api-path-heading">
                        <span><?= nullable_htmlentities($path) ?></span>
                        <button type="button" class="api-copy clipboardjs" data-clipboard-text="<?= nullable_htmlentities($path) ?>" title="Copy path">
                            <i class="far fa-copy"></i>
                        </button>
                    </div>
                    <?php foreach ($ops as $ep):
                        $cls = $method_class[$ep['method']] ?? 'get';
                        $query_params = array_values(array_filter($ep['params'], fn($p) => $p['in'] === 'query'));
                    ?>
                    <div class="api-row" data-search="<?= nullable_htmlentities(strtolower($ep['method'] . ' ' . $path . ' ' . $ep['summary'])) ?>">
                        <span class="api-verb <?= $cls ?>"><?= nullable_htmlentities($ep['method']) ?></span>
                        <div class="api-op-body">
                            <?php if ($ep['summary'] !== ''): ?>
                            <div class="api-summary"><?= nullable_htmlentities($ep['summary']) ?></div>
                            <?php endif; ?>
                            <?php if ($ep['no_auth'] || $ep['has_body'] || $query_params): ?>
                            <div class="api-meta">
                                <?php if ($ep['no_auth']): ?><span class="api-flag api-flag-public">public</span><?php endif; ?>
                                <?php if ($ep['has_body']): ?><span class="api-flag">body required</span><?php endif; ?>
                                <?php if ($query_params): ?>
                                <span class="api-flag">query:
                                <?php
                                $parts = [];
                                foreach ($query_params as $p) {
                                    $parts[] = $p['required']
                                        ? '<span class="api-param-req">' . nullable_htmlentities($p['name']) . '*</span>'
                                        : nullable_htmlentities($p['name']);
                                }
                                echo implode(', ', $parts);
                                ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>

        <div class="api-empty" id="apiDocsEmpty">
            <p class="mb-0">no matches for <code id="apiDocsEmptyQuery"></code></p>
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
            var secVisible = 0;
            section.querySelectorAll('.api-path-group').forEach(function (group) {
                var groupVisible = 0;
                group.querySelectorAll('.api-row').forEach(function (row) {
                    var hay = row.getAttribute('data-search') || '';
                    var match = terms.every(function (t) { return hay.indexOf(t) !== -1; });
                    row.classList.toggle('api-hidden', !match);
                    if (match) groupVisible++;
                });
                group.classList.toggle('api-hidden', groupVisible === 0);
                secVisible += groupVisible;
            });
            section.classList.toggle('api-hidden', secVisible === 0);
            var tocLink = document.querySelector('[data-toc-for="' + section.id + '"]');
            if (tocLink) {
                var tocCnt = tocLink.querySelector('.cnt');
                if (tocCnt) tocCnt.textContent = secVisible;
            }
            var headerCnt = section.querySelector('.api-section-heading .cnt');
            if (headerCnt) {
                var total = headerCnt.getAttribute('data-total');
                headerCnt.textContent = (q === '') ? '(' + total + ')' : '(' + secVisible + ' / ' + total + ')';
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

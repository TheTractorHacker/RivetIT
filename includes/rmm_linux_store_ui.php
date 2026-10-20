<?php

/*
 * The "Linux files hosted on this server" section of the Agent binaries card (RivetIT 26.10.64): what is stored, a manual upload, make current, delete.
 * The rules (what is accepted, where it lives) are in includes/rmm_linux_store.php. Administrator pages only; every form carries the CSRF token.
 */

require_once __DIR__ . '/rmm_linux_store.php';

function rivetLxAdminSection($mysqli, string $csrf): string
{
    $h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (!rivetLxReady($mysqli)) {
        return '<hr><h5 class="h6" id="linux-files">Linux files hosted on this server</h5><p class="small text-muted">Run the database update (Administration &gt; Updates) to turn this on.</p>';
    }
    $rows = rivetLxList($mysqli);
    $human = static fn (int $n): string => \RivetCore\Rmm\Binaries\BinaryStore::human($n);
    $o = '<hr><h5 class="h6" id="linux-files">Linux files hosted on this server</h5>'
        . '<p class="small text-muted">The Linux install command downloads <code>install-linux.sh</code> and the agent <strong>from this server</strong> (nothing to upload or unpack on the machine). They come from a local build (Agent updates &gt; Local build) or from <strong>Agent updates &gt; Publish</strong>, or you can add them here: <code>install-linux.sh</code>, <code>rivetit-agent-linux-amd64</code>, <code>rivetit-agent-linux-arm64</code> and optionally the <code>.tar.gz</code>. '
        . 'Each file is checked (a 64-bit Linux program for the CPU in its name; a small shell script that starts with <code>#!</code>; a gzip file) and stored outside the web root. The file name decides what it is, so keep the release names.</p>'
        . '<form action="post.php" method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-3" autocomplete="off"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '">'
        . '<div class="col-lg-4"><label class="form-label small" for="lx_files">Linux files</label><input type="file" class="form-control form-control-sm" id="lx_files" name="linux_files[]" multiple required></div>'
        . '<div class="col-lg-2"><label class="form-label small" for="lx_v">Version</label><input class="form-control form-control-sm" id="lx_v" name="version" placeholder="1.2.0" maxlength="40" required></div>'
        . '<div class="col-lg-2"><label class="form-label small" for="lx_ring">Ring</label><select class="form-select form-select-sm" id="lx_ring" name="release_ring"><option value="">None</option><option value="pilot">Pilot</option><option value="stable">Stable</option></select></div>'
        . '<div class="col-lg-1"><label class="form-label small" for="lx_pct">Rollout %</label><input class="form-control form-control-sm" id="lx_pct" name="rollout_pct" type="number" min="0" max="100" value="100"></div>'
        . '<div class="col-lg-2"><div class="form-check"><input type="checkbox" class="form-check-input" id="lx_cur" name="activate" value="1" checked><label class="form-check-label small" for="lx_cur">Make current</label></div></div>'
        . '<div class="col-lg-1"><button class="btn btn-sm btn-primary w-100" name="upload_linux_files" value="1">Upload</button></div></form>'
        . '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Linux files hosted on this server</caption>'
        . '<thead><tr><th scope="col">File</th><th scope="col">Version</th><th scope="col">Size</th><th scope="col">SHA-256</th><th scope="col">From</th><th scope="col">Ring</th><th scope="col">State</th><th scope="col"></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $state = !(int) $r['active'] ? '<span class="badge bg-secondary">Inactive</span>' : ((int) $r['is_current'] ? '<span class="badge bg-success">Current</span>' : '<span class="badge bg-info text-dark">Available</span>');
        $act = '<form action="post.php" method="post" class="d-inline-flex gap-1"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="file_id" value="' . (int) $r['file_id'] . '">';
        if (!(int) $r['is_current']) {
            $act .= '<button class="btn btn-xs btn-outline-primary" name="linux_file_action" value="make_current">Make current</button>'
                . '<button class="btn btn-xs btn-outline-danger" name="linux_file_action" value="delete" data-ea-confirm="Delete this file from the server? A command that pins this version stops working.">Delete</button>';
        }
        $o .= '<tr><td class="small font-monospace">' . $h($r['name']) . '</td><td>' . $h($r['version']) . '</td><td>' . $h($human((int) $r['size_bytes'])) . '</td><td class="small font-monospace text-break">' . $h($r['sha256']) . '</td>'
            . '<td class="small">' . $h(['build_host' => 'Local build', 'github' => 'GitHub release', 'upload' => 'Upload'][$r['source']] ?? $r['source']) . ($r['user_name'] ? ' (' . $h($r['user_name']) . ')' : '') . '</td>'
            . '<td class="small">' . ($r['release_ring'] ? $h($r['release_ring']) . ' ' . (int) $r['rollout_pct'] . '%' : '<span class="text-muted">none</span>') . '</td><td>' . $state . '</td><td class="text-nowrap">' . $act . '</form></td></tr>';
    }
    if ($rows === []) {
        $o .= '<tr><td colspan="8" class="text-muted">No Linux file is hosted yet. The Linux install command cannot be created until <code>install-linux.sh</code> and the agent for the CPU are here. Publish a release under Agent updates, or upload them above.</td></tr>';
    }

    return $o . '</tbody></table></div>';
}

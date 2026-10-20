<?php
/**
 * RivetIT 26.10.56: the tabs of Administration > Endpoint agent (admin/settings_endpoint_agent.php).
 *
 * Pure rendering. The page prints each card into the bucket of its tab; rivetEaRenderTabs() wraps the buckets in an accessible tab list
 * (js/rmm_ea_tabs.js adds the behaviour: arrow keys, the #hash, the hidden field that brings a Save back to its tab). Without JavaScript every
 * pane is shown one after the other, which is the old single page. No card, form or field name is changed here.
 */

/** The tabs in display order: key => [label, icon]. */
function rivetEaTabList(): array
{
    return [
        'overview'  => ['Overview', 'tachometer-alt'],
        'updates'   => ['Agent updates', 'cloud-download-alt'],
        'checks'    => ['Checks', 'heartbeat'],
        'remote'    => ['Remote and Mesh', 'network-wired'],
        'inventory' => ['Inventory and logs', 'clipboard-list'],
        'patching'  => ['Patching', 'band-aid'],
        'software'  => ['Software deployment', 'box-open'],
        'hypervisor' => ['Hypervisors', 'server'],
        'security'  => ['Security and policy', 'shield-alt'],
    ];
}

/** Attach every input, select and textarea of $html to the form with this id (so a control can sit in another tab than its form). */
function rivetEaFormAttr(string $html, string $formId): string
{
    return (string) preg_replace('/<(input|select|textarea)\b(?![^>]*\bform=)/i', '<$1 form="' . $formId . '"', $html);
}

/** True when at least one enrollment token can still be used. */
function rivetEaHasActiveToken(array $tokens): bool
{
    foreach ($tokens as $t) {
        if (($t['state'] ?? '') === 'active') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,string> $buckets tab key => HTML ('top' is printed above the tabs)
 * @param array<string,string> $dots    tab key => the reason a tab needs attention (empty: no dot)
 * @param callable             $h       HTML escaper
 */
function rivetEaRenderTabs(array $buckets, array $dots, callable $h): string
{
    $tabs = rivetEaTabList();
    $o = (string) ($buckets['top'] ?? '');
    $o .= '<div id="ea-tabs" class="mb-3"><ul class="nav nav-tabs flex-nowrap overflow-auto position-relative" id="ea-tablist" role="tablist" aria-label="Endpoint agent settings" style="white-space:nowrap">';
    $first = true;
    foreach ($tabs as $k => [$label, $icon]) {
        $dot = (string) ($dots[$k] ?? '');
        $o .= '<li class="nav-item" role="presentation"><button type="button" class="nav-link' . ($first ? ' active' : '') . '" id="ea-tab-' . $k . '" role="tab" data-ea-tab="' . $k . '" aria-controls="tab-' . $k . '" aria-selected="' . ($first ? 'true' : 'false') . '"' . ($first ? '' : ' tabindex="-1"') . '>'
            . '<i class="fas fa-fw fa-' . $icon . ' me-1" aria-hidden="true"></i>' . $h($label)
            . ($dot !== '' ? ' <span class="ea-tab-dot badge rounded-pill bg-warning p-1 ms-1" title="' . $h($dot) . '"><span class="visually-hidden">Needs attention: ' . $h($dot) . '</span></span>' : '')
            . '</button></li>';
        $first = false;
    }
    $o .= '</ul><div class="tab-content pt-3">';
    $first = true;
    foreach ($tabs as $k => [$label]) {
        $html = (string) preg_replace('/(<form\b[^>]*\bmethod="post"[^>]*>)/i', '$1<input type="hidden" name="ea_tab" value="' . $k . '">', (string) ($buckets[$k] ?? ''));
        $o .= '<div class="tab-pane ea-pane' . ($first ? ' active show' : '') . '" id="tab-' . $k . '" role="tabpanel" aria-labelledby="ea-tab-' . $k . '" tabindex="0">' . $html . '</div>';
        $first = false;
    }

    return $o . '</div></div>'
        . '<script src="/js/rmm_ea_tabs.js?v=' . (int) @filemtime(dirname(__DIR__) . '/js/rmm_ea_tabs.js') . '" defer></script>';
}

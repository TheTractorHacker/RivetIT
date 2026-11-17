<?php

declare(strict_types=1);

/**
 * RivetIT HTML policy for HTMLPurifier configs (security finding IT-WEB-1).
 *
 * HTMLPurifier's default Attr.AllowedClasses is null = "any class". The app has document-wide click/behaviour hooks keyed on CSS
 * classes (.ajax-modal, .confirm-link, .js-*), so user-supplied HTML that keeps `class` can drive app behaviour inside an agent's session.
 *
 *   'strict' - ticket replies, notes, mail, search snippets: NO classes survive.
 *   'rich'   - knowledge base and documents: only the formatting classes the editor emits and the interactive-KB hooks (.ikb*).
 *
 * data-* attributes are not in HTMLPurifier's HTML 4/XHTML 1.0 schema, so they are dropped unless a module (InteractiveBlocks: data-ikb*) registers them.
 * Call before `new HTMLPurifier($config)`.
 */
function rivet_html_policy_apply(HTMLPurifier_Config $config, string $mode = 'strict'): void
{
    if ($mode === 'rich') {
        $config->set('Attr.AllowedClasses', [
            'ikb', 'ikb-part', 'ikb-title', 'ikb-label', 'ikb-body', 'ikb-node', 'ikb-copy',
            'table', 'table-bordered', 'table-striped', 'table-sm', 'text-center', 'text-start', 'text-end',
            'img-fluid', 'blockquote', 'lead',
        ]);
        return;
    }
    $config->set('Attr.AllowedClasses', []);
}

<?php

// Shared parser for api/v1/openapi.yaml, used by both the public API reference
// (api/v1/docs.php) and the admin-side API Docs page (admin/api_docs.php).
// PHP here has no yaml extension, hence the small hand-rolled line scanner -
// the spec's own formatting is fixed and regular, so this stays simple. Keep
// both callers in sync with this single parser rather than re-scanning the
// spec independently, so the two pages can't drift out of agreement.
function parseOpenApiSpec(string $spec_path): array {
    $raw = is_readable($spec_path) ? file($spec_path, FILE_IGNORE_NEW_LINES) : [];

    $api_title   = 'ITFlow API v1';
    $api_version = '';
    $endpoints   = [];          // [ ['path'=>, 'method'=>, 'summary'=>, 'tag'=>], ... ]
    $tag_order   = [];          // tag names in first-seen order

    $in_paths = false;
    $cur_path = null;
    $cur_idx  = null;           // index into $endpoints for the current path+method
    $http_methods = ['get', 'post', 'put', 'delete', 'patch'];

    foreach ($raw as $line) {
        // Grab a couple of info fields (2-space indent, appear once).
        if ($api_version === '' && preg_match('/^  version:\s*(.+)$/', $line, $m)) {
            $api_version = trim($m[1]);
        }
        if (preg_match('/^  title:\s*(.+)$/', $line, $m)) {
            $api_title = trim($m[1]);
        }

        if (preg_match('/^paths:\s*$/', $line)) { $in_paths = true; continue; }
        if (!$in_paths) continue;
        // A new top-level key (column 0, non-space) ends the paths block.
        if (preg_match('/^\S/', $line)) break;

        // Path key: exactly two leading spaces then a "/...:".
        if (preg_match('#^  (/[^:]*):\s*$#', $line, $m)) {
            $cur_path = $m[1];
            $cur_idx  = null;
            continue;
        }
        // HTTP method key: four leading spaces.
        if ($cur_path !== null && preg_match('/^    ([a-z]+):\s*$/', $line, $m) && in_array($m[1], $http_methods, true)) {
            $endpoints[] = ['path' => $cur_path, 'method' => strtoupper($m[1]), 'summary' => '', 'tag' => 'Other'];
            $cur_idx = array_key_last($endpoints);
            continue;
        }
        // summary / tags: six leading spaces, belong to the current method.
        if ($cur_idx !== null && preg_match('/^      summary:\s*(.+)$/', $line, $m)) {
            $endpoints[$cur_idx]['summary'] = trim($m[1]);
            continue;
        }
        if ($cur_idx !== null && preg_match('/^      tags:\s*\[([^\]]*)\]/', $line, $m)) {
            $tag = trim(explode(',', $m[1])[0]);
            if ($tag !== '') {
                $endpoints[$cur_idx]['tag'] = $tag;
                if (!in_array($tag, $tag_order, true)) $tag_order[] = $tag;
            }
        }
    }

    // Group endpoints by tag, preserving first-seen tag order.
    $by_tag = [];
    foreach ($endpoints as $ep) {
        $by_tag[$ep['tag']][] = $ep;
    }
    if (!in_array('Other', $tag_order, true) && isset($by_tag['Other'])) $tag_order[] = 'Other';

    return [
        'title'     => $api_title,
        'version'   => $api_version,
        'endpoints' => $endpoints,
        'tag_order' => $tag_order,
        'by_tag'    => $by_tag,
    ];
}

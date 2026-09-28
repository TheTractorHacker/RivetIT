<?php

require_once __DIR__ . '/branding.php'; // APP_NAME for the default API title

// Shared parser for api/v1/openapi.yaml, used by both the public API reference
// (api/v1/docs.php) and the admin-side API Docs page (admin/api_docs.php).
// PHP here has no yaml extension, hence the small hand-rolled line scanner -
// the spec's own formatting is fixed and regular, so this stays simple. Keep
// both callers in sync with this single parser rather than re-scanning the
// spec independently, so the two pages can't drift out of agreement.
//
// This intentionally does NOT resolve requestBody/response $ref schemas (that
// would mean walking arbitrarily-nested `components: schemas:` objects, a real
// YAML-parser-shaped problem) - it sticks to the flat, single-line-per-item
// shapes the spec actually uses for parameters, which stay easy to scan
// reliably: path/query params, whether auth is overridden off, and whether a
// request body is expected.
function parseOpenApiSpec(string $spec_path): array {
    $raw = is_readable($spec_path) ? file($spec_path, FILE_IGNORE_NEW_LINES) : [];

    $api_title   = APP_NAME . ' API v1';
    $api_version = '';
    $endpoints   = [];          // [ ['path','method','summary','tag','params','no_auth','has_body'], ... ]
    $tag_order   = [];          // tag names in first-seen order
    $http_methods = ['get', 'post', 'put', 'delete', 'patch'];

    // ---- Pass 1: components.parameters lookup (IdPath, PageParam, ...) so
    // per-operation `{$ref: '#/components/parameters/X'}` lines can resolve to
    // a real name/location instead of just showing "ref". ----
    $param_defs = [];
    $in_component_params = false;
    $cur_param_name = null;
    foreach ($raw as $line) {
        if (preg_match('/^  parameters:\s*$/', $line)) { $in_component_params = true; continue; }
        if (!$in_component_params) continue;
        if (preg_match('/^  \S/', $line)) { $in_component_params = false; continue; } // next column-2 key ends the block
        if (preg_match('/^    (\w+):\s*$/', $line, $m)) {
            $cur_param_name = $m[1];
            $param_defs[$cur_param_name] = ['name' => '', 'in' => '', 'required' => false];
            continue;
        }
        if ($cur_param_name === null) continue;
        if (preg_match('/^      name:\s*(\S+)/', $line, $m)) $param_defs[$cur_param_name]['name'] = trim($m[1]);
        if (preg_match('/^      in:\s*(\S+)/', $line, $m)) $param_defs[$cur_param_name]['in'] = trim($m[1]);
        if (preg_match('/^      required:\s*true/', $line)) $param_defs[$cur_param_name]['required'] = true;
    }

    // ---- Pass 2: the paths: block itself. ----
    $in_paths = false;
    $cur_path = null;
    $cur_idx  = null;           // index into $endpoints for the current path+method
    $in_op_params = false;

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
            $in_op_params = false;
            continue;
        }
        // HTTP method key: four leading spaces.
        if ($cur_path !== null && preg_match('/^    ([a-z]+):\s*$/', $line, $m) && in_array($m[1], $http_methods, true)) {
            $endpoints[] = [
                'path' => $cur_path, 'method' => strtoupper($m[1]), 'summary' => '', 'tag' => 'Other',
                'params' => [], 'no_auth' => false, 'has_body' => false,
            ];
            $cur_idx = array_key_last($endpoints);
            $in_op_params = false;
            continue;
        }
        if ($cur_idx === null) continue;

        // Parameters: a `parameters:` line, then zero or more single-line flow-
        // style list items (`- {...}` or `- {$ref: '...'}`) until a line that
        // isn't one of those (ends the list - could be `responses:`, `security:`,
        // the next path, etc).
        if (preg_match('/^      parameters:\s*$/', $line)) { $in_op_params = true; continue; }
        if ($in_op_params) {
            if (preg_match('/^        - \{(.*)\}\s*$/', $line, $m)) {
                $inner = $m[1];
                if (preg_match('/\$ref:\s*[\'"]#\/components\/parameters\/(\w+)[\'"]/', $inner, $rm) && isset($param_defs[$rm[1]])) {
                    $endpoints[$cur_idx]['params'][] = $param_defs[$rm[1]];
                } else {
                    $p = ['name' => '', 'in' => '', 'required' => false];
                    if (preg_match('/\bname:\s*([A-Za-z0-9_]+)/', $inner, $nm)) $p['name'] = $nm[1];
                    if (preg_match('/\bin:\s*([A-Za-z0-9_]+)/', $inner, $im)) $p['in'] = $im[1];
                    if (preg_match('/\brequired:\s*true/', $inner)) $p['required'] = true;
                    if ($p['name'] !== '') $endpoints[$cur_idx]['params'][] = $p;
                }
                continue;
            }
            $in_op_params = false; // fall through - this line is something else
        }

        // No-auth override (security: [] on an otherwise bearer/key-gated API).
        if (preg_match('/^      security:\s*\[\]\s*$/', $line)) {
            $endpoints[$cur_idx]['no_auth'] = true;
            continue;
        }
        // Request body presence (don't try to resolve its schema - just flag it).
        if (preg_match('/^      requestBody:\s*$/', $line)) {
            $endpoints[$cur_idx]['has_body'] = true;
            continue;
        }

        // summary / tags: six leading spaces, belong to the current method.
        if (preg_match('/^      summary:\s*(.+)$/', $line, $m)) {
            $endpoints[$cur_idx]['summary'] = trim($m[1]);
            continue;
        }
        if (preg_match('/^      tags:\s*\[([^\]]*)\]/', $line, $m)) {
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

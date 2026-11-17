<?php

declare(strict_types=1);

/**
 * IT-WEB-1: stamps every <script> in a pop-up's JSON {"content": "..."} response with the per-response token
 * (see includes/modal_header.php and js/ajax_modal.js). Any data-modal-script attribute already in the content is removed first,
 * so markup cannot bring its own. Output that is not a modal JSON document is returned untouched.
 */
function rivet_modal_stamp_scripts(string $out, string $token): string
{
    $data = json_decode($out, true);
    if (!is_array($data) || !isset($data['content']) || !is_string($data['content'])) {
        return $out;
    }
    $data['content'] = preg_replace_callback('/<script\b([^>]*)>/i', static function (array $m) use ($token): string {
        $attrs = preg_replace('/\sdata-modal-script\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $m[1]);
        return '<script data-modal-script="' . $token . '"' . $attrs . '>';
    }, $data['content']);
    $json = json_encode($data);
    return $json === false ? $out : $json;
}

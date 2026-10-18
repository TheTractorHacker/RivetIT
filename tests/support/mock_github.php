<?php

/*
 * A stand-in for api.github.com and github.com, for the tests of the Agent updates card (and the real-asset run). Router for `php -S`; nothing here touches the network.
 *
 *   MOCK_GH_DIR=<dir> php -S 127.0.0.1:<port> tests/support/mock_github.php
 *
 * <dir>/releases.json     the body of GET /repos/{owner}/{repo}/releases (any JSON; the tests write it)
 * <dir>/scenario.json     {"mode": "...", ...} switches the behaviour at run time (the tests rewrite it between requests):
 *                           api_status (int), api_headers (map), api_raw (string, sent as the body instead of releases.json), api_etag (string; honoured with If-None-Match),
 *                           api_pages (list of JSON bodies; page n is served with a Link: rel="next" header), api_page_link_evil (a next link that points elsewhere),
 *                           asset_mode: normal | evil_redirect | redirect_loop | redirect_private | redirect_http | redirect_userinfo | huge | slow | status_404 | tamper
 *                           evil_location (what evil_redirect points to)
 * <dir>/assets/<tag>/<name>   the files served for GET /<owner>/<repo>/releases/download/<tag>/<name> (through a /cdn/ redirect in normal mode, like GitHub)
 * <dir>/requests.log      one line per request: "<METHOD> <path> <status>" (the tests count them)
 */

$dir = rtrim((string) getenv('MOCK_GH_DIR'), '/');
if ($dir === '' || !is_dir($dir)) {
    http_response_code(500);
    echo 'MOCK_GH_DIR is not set';

    return true;
}
$scenario = is_file("$dir/scenario.json") ? (json_decode((string) file_get_contents("$dir/scenario.json"), true) ?: []) : [];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$log = static function (int $status) use ($dir, $method, $path): void {
    file_put_contents("$dir/requests.log", "$method $path $status\n", FILE_APPEND | LOCK_EX);
};
$reply = static function (int $status, string $body, array $headers = []) use ($log): bool {
    http_response_code($status);
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    $log($status);
    echo $body;

    return true;
};

// ---- the API
if (preg_match('~^/repos/([^/]+)/([^/]+)/releases$~', $path, $m) === 1) {
    $status = (int) ($scenario['api_status'] ?? 200);
    $headers = ['Content-Type' => 'application/json'] + (array) ($scenario['api_headers'] ?? []);
    if ($status !== 200) {
        return $reply($status, (string) ($scenario['api_raw'] ?? json_encode(['message' => 'API rate limit exceeded'])), $headers);
    }
    $etag = (string) ($scenario['api_etag'] ?? '');
    if ($etag !== '') {
        $headers['ETag'] = $etag;
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            return $reply(304, '', $headers);
        }
    }
    if (isset($scenario['api_raw'])) {
        return $reply(200, (string) $scenario['api_raw'], $headers);
    }
    $pages = $scenario['api_pages'] ?? null;
    if (is_array($pages)) {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $base = 'http://' . $_SERVER['HTTP_HOST'] . $path;
        if ($page < count($pages)) {
            $headers['Link'] = '<' . (!empty($scenario['api_page_link_evil']) ? (string) $scenario['api_page_link_evil'] : $base . '?per_page=100&page=' . ($page + 1)) . '>; rel="next"';
        }

        return $reply(200, json_encode($pages[$page - 1] ?? []), $headers);
    }

    return $reply(200, (string) @file_get_contents("$dir/releases.json"), $headers);
}

// ---- the asset, first hop (github.com): redirects to the "CDN"
if (preg_match('~^/([^/]+)/([^/]+)/releases/download/([^/]+)/([^/]+)$~', $path, $m) === 1) {
    $tag = rawurldecode($m[3]);
    $name = rawurldecode($m[4]);
    $mode = (string) ($scenario['asset_mode'] ?? 'normal');
    if (isset($scenario['only']) && $scenario['only'] !== $name) {
        $mode = 'normal';   // the odd behaviour is for ONE file, so that its checksum files can still be fetched
    }
    $self = 'http://' . $_SERVER['HTTP_HOST'];
    switch ($mode) {
        case 'evil_redirect':
            return $reply(302, '', ['Location' => (string) ($scenario['evil_location'] ?? 'https://evil.example.test/' . rawurlencode($name))]);
        case 'redirect_private':
            return $reply(302, '', ['Location' => 'https://127.0.0.1/' . rawurlencode($name)]);
        case 'redirect_http':
            return $reply(302, '', ['Location' => 'http://objects.githubusercontent.com/' . rawurlencode($name)]);
        case 'redirect_userinfo':
            return $reply(302, '', ['Location' => 'https://objects.githubusercontent.com@evil.example.test/' . rawurlencode($name)]);
        case 'redirect_loop':
            return $reply(302, '', ['Location' => $self . $path]);
        case 'status_404':
            return $reply(404, 'Not Found');
    }

    return $reply(302, '', ['Location' => $self . '/cdn/' . rawurlencode($tag) . '/' . rawurlencode($name) . '?sig=x']);
}

// ---- the CDN: the bytes
if (preg_match('~^/cdn/([^/]+)/([^/]+)$~', $path, $m) === 1) {
    $tag = rawurldecode($m[1]);
    $name = rawurldecode($m[2]);
    $file = "$dir/assets/$tag/$name";
    $mode = (string) ($scenario['asset_mode'] ?? 'normal');
    if (isset($scenario['only']) && $scenario['only'] !== $name) {
        $mode = 'normal';
    }
    if (!is_file($file) || str_contains($tag . $name, '..')) {
        return $reply(404, 'Not Found');
    }
    if ($mode === 'huge') {
        header('Content-Length: ' . (200 * 1048576));
        $log(200);
        http_response_code(200);
        echo str_repeat('A', 4096);

        return true;
    }
    $bytes = (string) file_get_contents($file);
    if ($mode === 'tamper' && ($scenario['tamper'] ?? '') === $name) {
        $bytes[strlen($bytes) - 1] = $bytes[strlen($bytes) - 1] === 'X' ? 'Y' : 'X';
    }
    if ($mode === 'slow') {
        usleep((int) (($scenario['sleep_s'] ?? 3) * 1000000));
    }

    return $reply(200, $bytes, ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) strlen($bytes)]);
}

return $reply(404, 'Not Found');

<?php

namespace ITFlow\Oidc;

/**
 * The few HTML pages the provider shows in the browser: a refusal and the "sign in again" prompt. Both are plain pages with fixed text.
 * Nothing from the request is ever put on them - not the client name, redirect URI, state, nonce, error text or reason code - so a crafted
 * authorization URL cannot make this site display attacker-chosen content. (The reason is in the audit log, with a reference number the
 * person can quote.)
 */
final class OidcPages
{
    /** Security headers for every page this provider renders: no framing, no referrer, never cached, no scripts at all. */
    public static function headers(): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        header('Content-Type: text/html; charset=utf-8');
    }

    /** A page for a refusal. The reason is in the audit log (Administration > Single sign-on provider), never on the page. */
    public static function refusal(int $status, string $headline, string $message): never
    {
        self::headers();
        http_response_code($status);
        echo self::shell($headline, '<p>' . self::h($message) . '</p><p class="muted">Nothing was shared with the other application. If you think this is a mistake, ask your administrator.</p>');
        exit;
    }

    /**
     * The "sign in again" prompt. $csrf is the session CSRF token; $who is the signed-in person's display name (from the database, escaped here).
     * A POST destroys the session and sends the person to the sign-in page, which brings them straight back to /oidc/authorize.
     */
    public static function reauth(string $csrf, string $who = ''): never
    {
        self::headers();
        http_response_code(200);
        $lock = '<svg class="ico" viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
        $body = '<p>Quality asks for a recent sign-in before it opens. Sign in again and you will go straight to Quality; nothing else changes.</p>'
            . ($who !== '' ? '<p class="who">Signed in as <strong>' . self::h($who) . '</strong></p>' : '')
            . '<form method="post" action="/oidc/authorize"><input type="hidden" name="csrf_token" value="' . self::h($csrf) . '">'
            . '<input type="hidden" name="action" value="reauth"><div class="row"><button class="go" type="submit">Sign in again</button>'
            . '<a class="alt" href="/">Cancel</a></div></form>'
            . '<p class="muted">You will be taken to the sign-in page and brought back here automatically.</p>';
        echo self::shell('Confirm it is you', $body, $lock);
        exit;
    }

    public static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function shell(string $headline, string $body, string $icon = ''): string
    {
        $app = defined('APP_NAME') ? (string) APP_NAME : 'RivetIT';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . self::h($app) . ' - sign-in</title><style>'
            . ':root{--bg:#f3f5f8;--fg:#1c2330;--muted:#5d6879;--card:#fff;--line:#d9dee7;--accent:#0d9488;--accent-fg:#fff}'
            . '@media (prefers-color-scheme:dark){:root{--bg:#10141b;--fg:#e6eaf1;--muted:#9aa5b6;--card:#181e28;--line:#2a3342;--accent:#2dd4bf;--accent-fg:#06201d}}'
            . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:16px}'
            . '.card{max-width:32rem;margin:8vh auto;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:24px}'
            . 'h1{font-size:1.3rem;margin:0 0 .75rem;display:flex;align-items:center;gap:.6rem}.ico{color:var(--accent);flex:none}.who{color:var(--muted)}.alt{margin-left:14px;color:var(--muted);text-decoration:underline}.row{display:flex;align-items:center;flex-wrap:wrap}p{margin:.5rem 0}.muted{color:var(--muted);font-size:.9rem}.row{margin-top:1.2rem}'
            . 'button{font:inherit;cursor:pointer;border-radius:8px;padding:10px 18px;border:1px solid var(--accent);background:var(--accent);color:var(--accent-fg);font-weight:600}'
            . '</style></head><body><main class="card"><h1>' . self::h($headline) . '</h1>' . $body . '</main></body></html>';
    }
}

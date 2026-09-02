<?php

namespace ITFlow\Knowledge;

/**
 * Master plan Section 14.4 - secure credential references in KB articles.
 * Convention: an article body may contain the literal token "[[credential:123]]"
 * where 123 is a credentials.credential_id. This class only swaps that token,
 * once an article's already-purified HTML is about to be displayed, for a
 * "Reveal linked credential" trigger - it never looks the credential up, never
 * decrypts anything, and the token itself is never rewritten in storage (the
 * DB row and every kb_article_versions snapshot keep the raw "[[credential:123]]"
 * text forever, only the rendered-for-display copy changes). Clicking the
 * trigger opens agent/modals/credential/credential_view.php via the app's
 * existing ajax-modal mechanism, which re-checks module_credential permission
 * and enforceClientAccess() itself - this class has no opinion on who is
 * allowed to see the secret.
 */
class CredentialReferenceRenderer
{
    private const TOKEN_PATTERN = '/\[\[credential:(\d+)\]\]/i';

    public function render(string $html): string
    {
        return preg_replace_callback(
            self::TOKEN_PATTERN,
            fn (array $m) => $this->badge((int) $m[1]),
            $html
        );
    }

    public function containsReference(string $html): bool
    {
        return preg_match(self::TOKEN_PATTERN, $html) === 1;
    }

    private function badge(int $credentialId): string
    {
        return '<a href="#" class="btn btn-sm btn-outline-secondary kb-credential-reveal ajax-modal" '
            . 'data-modal-url="modals/credential/credential_view.php?id=' . $credentialId . '">'
            . '<i class="fas fa-fw fa-key me-1"></i>Reveal linked credential</a>';
    }
}

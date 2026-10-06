<?php

namespace ITFlow\Knowledge;

/**
 * Master plan Section 14.4 - secure credential references in KB articles. An article body may contain the
 * literal token "[[credential:123]]"; this swaps it, once the article's already-purified HTML is about to be
 * displayed, for a "Reveal linked credential" trigger. It never looks the credential up or decrypts anything,
 * and the stored text keeps the raw token. Clicking the trigger opens agent/modals/credential/credential_view.php
 * through the ajax-modal mechanism, which re-checks module_credential permission and enforceClientAccess().
 *
 * Edition subclass (not deprecated) of RivetCore\Knowledge\CredentialReferenceRenderer; the reveal control is RivetIT's UI.
 */
class CredentialReferenceRenderer extends \RivetCore\Knowledge\CredentialReferenceRenderer
{
    public function __construct()
    {
        parent::__construct(static fn (int $credentialId): string =>
            '<a href="#" class="btn btn-sm btn-outline-secondary kb-credential-reveal ajax-modal" '
            . 'data-modal-url="modals/credential/credential_view.php?id=' . $credentialId . '">'
            . '<i class="fas fa-fw fa-key me-1"></i>Reveal linked credential</a>');
    }
}

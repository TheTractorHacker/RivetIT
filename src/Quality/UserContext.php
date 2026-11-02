<?php

namespace ITFlow\Quality;

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Oidc\KeyStore;
use ITFlow\Oidc\QualityAccessPolicy;

/**
 * The per-user authorization context that travels with every employee-facing request to the QMS (X-Rivet-User-Context).
 *
 * The service token says WHICH SYSTEM is calling. This says FOR WHOM, and the QMS must authorize the request against that person with its own rules;
 * a service token alone never reads or writes a person's items (the client refuses to send a /me or /quality request without one, and the QMS must
 * refuse one that arrives without one). It is an ES256 JWT signed with the same key the SSO provider publishes in /oidc/jwks, so the QMS needs no
 * second trust relationship, and it is short lived (60 seconds) and single purpose: header typ "rivetit-user-context+jwt", a distinct audience, so
 * it can never be mistaken for an ID token or an access token.
 *
 * Claims: iss (the issuer, from the configured base URL), aud (the QMS base address), sub (Odoo employee id, or "rivetit:<contact id>" when the
 * person is not linked), rivetit_id, odoo_employee_id (when linked), qms_id (when the QMS told us), dept (RivetIT department id and Odoo department id),
 * roles ("employee", plus "manager" for a department manager), scp (what this request may do), iat, nbf, exp, jti, cid (the correlation id).
 * No name, e-mail, phone or PIN.
 */
final class UserContext
{
    public const TYP = 'rivetit-user-context+jwt';
    public const TTL = 60;

    /** @param array<string,mixed> $claims */
    private function __construct(public readonly array $claims, private readonly string $jwt)
    {
    }

    public function jwt(): string
    {
        return $this->jwt;
    }

    public function rivetitId(): int
    {
        return (int) ($this->claims['rivetit_id'] ?? 0);
    }

    /**
     * Builds the context for a department person, after re-checking on the server that this person may use Quality right now.
     *
     * @param list<string> $scopes what the request will do, e.g. ['tasks:read'] or ['documents:acknowledge']
     * @throws QmsException (CONTEXT) when the person is not eligible: nothing is signed for them
     */
    public static function forContact(\mysqli $db, int $contactId, string $audience, array $scopes, string $correlationId): self
    {
        $access = QualityConfig::access($db, $contactId);
        if (!$access['ok']) {
            throw new QmsException('This person may not use Quality (' . $access['reason'] . ').', QmsException::CONTEXT, 0, $correlationId);
        }
        $elig = QualityAccessPolicy::eligible($contactId, $db);
        if (!$elig['ok']) {
            throw new QmsException('This person may not use Quality (' . $elig['reason'] . ').', QmsException::CONTEXT, 0, $correlationId);
        }
        $subject = QualityAccessPolicy::subject($db, $contactId);
        $issuer = IntegrationAuth::issuer();
        if ($subject === null || $issuer === 'https://') {
            throw new QmsException('The server base URL is not set, so no user context can be issued.', QmsException::CONTEXT, 0, $correlationId);
        }
        $odooEmployee = null;
        $st = $db->prepare('SELECT MIN(odoo_employee_id) AS o FROM contact_odoo_links WHERE contact_id = ?');
        $st->bind_param('i', $contactId);
        $st->execute();
        $o = $st->get_result()->fetch_assoc();
        $st->close();
        if ($o && $o['o'] !== null && (int) $o['o'] > 0) {
            $odooEmployee = (string) (int) $o['o'];
        }
        $clientId = (int) $access['client_id'];
        $odooDept = null;
        $st = $db->prepare('SELECT MIN(odoo_department_id) AS d FROM client_odoo_links WHERE client_id = ?');
        $ok = $st !== false;
        if ($ok) {
            $st->bind_param('i', $clientId);
            $st->execute();
            $d = $st->get_result()->fetch_assoc();
            $st->close();
            $odooDept = ($d && $d['d'] !== null && (int) $d['d'] > 0) ? (string) (int) $d['d'] : null;
        }
        $qmsId = null;
        $st = $db->prepare("SELECT qm_qms_id FROM quality_id_map WHERE qm_entity = 'employee' AND qm_rivetit_id = ?");
        $st->bind_param('i', $contactId);
        $st->execute();
        $m = $st->get_result()->fetch_assoc();
        $st->close();
        if ($m) {
            $qmsId = (string) $m['qm_qms_id'];
        }
        $now = time();
        $claims = [
            'iss' => $issuer,
            'aud' => $audience,
            'sub' => $subject['sub'],
            'rivetit_id' => (string) $contactId,
            'odoo_employee_id' => $odooEmployee,
            'qms_id' => $qmsId,
            'dept' => ['rivetit_id' => (string) $clientId, 'odoo_id' => $odooDept],
            'roles' => $access['manager'] ? ['employee', 'manager'] : ['employee'],
            'scp' => array_values(array_unique($scopes)),
            'iat' => $now, 'nbf' => $now - 5, 'exp' => $now + self::TTL,
            'jti' => bin2hex(random_bytes(12)),
            'cid' => $correlationId,
        ];
        $key = KeyStore::activeSigningKey($db);
        $jwt = \Firebase\JWT\JWT::encode($claims, $key['private_pem'], KeyStore::ALG, $key['kid'], ['typ' => self::TYP]);
        return new self($claims, $jwt);
    }
}

<?php
declare(strict_types=1);
/* IT-INJ-8: portal Entra SSO binds on (tenant id, object id), not on the UPN string. */
require __DIR__ . '/secauthz_bootstrap.php';
require SECAUTHZ_ROOT . '/includes/portal_entra.php';
sec_reset_fixture();
$TA = 'aaaaaaaa-0000-4000-8000-000000000001'; $OA = 'bbbbbbbb-0000-4000-8000-000000000001';   // victim's real tenant + object
$TX = 'cccccccc-0000-4000-8000-000000000002'; $OX = 'dddddddd-0000-4000-8000-000000000002';   // attacker tenant + object
$mk = fn(int $id, string $email, ?string $iss, ?string $sub) => $q("INSERT INTO users SET user_id = $id, user_name = 'u$id', user_email = '$email', user_password = 'x', user_type = 2, user_status = 1, user_auth_method = 'azure', user_oidc_issuer = " . ($iss ? "'$iss'" : 'NULL') . ", user_oidc_subject = " . ($sub ? "'$sub'" : 'NULL'));
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'V', contact_email = 'victim@cust.example', contact_client_id = 1, contact_user_id = 31");
$q("INSERT INTO contacts SET contact_id = 2, contact_name = 'L', contact_email = 'legacy@cust.example', contact_client_id = 1, contact_user_id = 32");
$q("INSERT INTO contacts SET contact_id = 3, contact_name = 'P', contact_email = 'pinned@cust.example', contact_client_id = 1, contact_user_id = 33");
$mk(31, 'victim@cust.example', portalEntraIssuer($TA), $OA);   // bound
$mk(32, 'legacy@cust.example', null, null);                    // linked before this fix: UPN-only
$mk(33, 'pinned@cust.example', portalEntraIssuer($TA), null);  // tenant pinned by admin, object not yet bound

// --- the old behaviour (client/login_microsoft.php before the fix), kept as the documented "before": UPN string match
$old = (string) shell_exec('cd ' . escapeshellarg(SECAUTHZ_ROOT) . ' && git show origin/beta:client/login_microsoft.php 2>/dev/null');
$legacy_hit = null;
if (preg_match('/\$sql = mysqli_query\(\$mysqli, "(SELECT \* FROM users.*?)"\n/s', $old, $m)) {
    $upn = 'victim@cust.example';   // attacker tenant X issues a token whose UPN equals the victim's email
    $res = mysqli_query($mysqli, str_replace(["'\$upn'"], ["'$upn'"], $m[1]));
    $legacy_hit = $res ? mysqli_fetch_assoc($res) : null;
}
echo "INFO  old UPN-only lookup for a token from another tenant returned user_id " . ($legacy_hit['user_id'] ?? 'none (git history unavailable)') . "\n";

// --- id_token validation
$tok = function (array $o = []) use ($TA, $OA) {
    $c = array_merge(['tid' => $TA, 'oid' => $OA, 'aud' => 'client-1', 'iss' => "https://login.microsoftonline.com/$TA/v2.0", 'nonce' => 'n1', 'exp' => time() + 600], $o);
    return 'h.' . rtrim(strtr(base64_encode(json_encode($c)), '+/', '-_'), '=') . '.s';
};
$ok(portalEntraClaims($tok(), 'client-1', 'n1') === ['tid' => $TA, 'oid' => $OA], 'claims: a well-formed token yields tenant id + object id');
$ok(portalEntraClaims($tok(['aud' => 'other']), 'client-1', 'n1') === null, 'claims: wrong audience refused');
$ok(portalEntraClaims($tok(['iss' => "https://login.microsoftonline.com/$TX/v2.0"]), 'client-1', 'n1') === null, 'claims: issuer that does not match the tid claim refused');
$ok(portalEntraClaims($tok(['nonce' => 'x']), 'client-1', 'n1') === null && portalEntraClaims($tok(), 'client-1', '') === null, 'claims: wrong / missing nonce refused');
$ok(portalEntraClaims($tok(['exp' => time() - 5]), 'client-1', 'n1') === null, 'claims: expired token refused');
$ok(portalEntraClaims($tok(['tid' => '9188040d-6c67-4c5b-b112-36a304b66dad', 'iss' => 'https://login.microsoftonline.com/9188040d-6c67-4c5b-b112-36a304b66dad/v2.0']), 'client-1', 'n1') === null, 'claims: consumer (personal) tenant refused');
$ok(portalEntraClaims('garbage', 'client-1', 'n1') === null && portalEntraClaims($tok(['oid' => 'not-a-guid']), 'client-1', 'n1') === null, 'claims: malformed token / oid refused');

// --- resolving the login
$r = portalEntraResolveLogin($mysqli, $TX, $OX, 'victim@cust.example');
$ok($r === null, 'resolve: token from ANOTHER tenant with a UPN equal to the victim\'s email does not sign in as the victim');
$r = portalEntraResolveLogin($mysqli, $TA, $OA, 'whatever@else.example');
$ok(($r['user_id'] ?? 0) == 31, 'resolve: the bound tenant + object signs in (UPN not consulted)');
$ok(portalEntraResolveLogin($mysqli, $TA, $OX, 'victim@cust.example') === null, 'resolve: right tenant, different object id, same UPN: refused');
$ok(portalEntraResolveLogin($mysqli, $TX, $OX, 'legacy@cust.example') === null && portalEntraResolveLogin($mysqli, $TA, $OX, 'legacy@cust.example') === null, 'resolve: an unlinked (legacy) login is refused until an admin sets its tenant (re-link)');
$ok(portalEntraResolveLogin($mysqli, $TX, $OX, 'pinned@cust.example') === null && $one("SELECT user_oidc_subject FROM users WHERE user_id = 33") === null, 'resolve: tenant-pinned login is not bound by another tenant');
$r = portalEntraResolveLogin($mysqli, $TA, 'eeeeeeee-0000-4000-8000-000000000003', 'pinned@cust.example');
$ok(($r['user_id'] ?? 0) == 33 && $one("SELECT user_oidc_subject FROM users WHERE user_id = 33") === 'eeeeeeee-0000-4000-8000-000000000003', 'resolve: first sign-in from the pinned tenant binds the object id');
$ok(portalEntraResolveLogin($mysqli, $TA, 'ffffffff-0000-4000-8000-000000000004', 'pinned@cust.example') === null, 'resolve: once bound, a different object (same tenant, same UPN) is refused');
$q("UPDATE users SET user_status = 0 WHERE user_id = 31");
$ok(portalEntraResolveLogin($mysqli, $TA, $OA, 'victim@cust.example') === null, 'resolve: disabled login refused');

// --- wiring
$s = (string) file_get_contents(SECAUTHZ_ROOT . '/client/login_microsoft.php');
$ok(str_contains($s, 'portalEntraResolveLogin(') && !preg_match('/WHERE user_email = \'\$upn\'/', $s) && str_contains($s, 'portalEntraClaims(') && !str_contains($s, "== session_id()"), 'login_microsoft.php uses claims + bound identity, no UPN lookup, no session-id state');
exit($T_fails ? 1 : 0);

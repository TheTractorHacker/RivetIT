<?php
// Minimal WebAuthn / FIDO2 helper — no external dependencies
// Supports ES256 (ECDSA P-256) and RS256 (RSA-PKCS1-SHA256)

// ── Base64url ─────────────────────────────────────────────────────────────────

function wa_b64u_decode(string $s): string {
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

function wa_b64u_encode(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

// ── Minimal CBOR decoder ──────────────────────────────────────────────────────

function wa_cbor(string $data): mixed {
    $off = 0;
    return _wa_cbor($data, $off);
}

function _wa_cbor(string &$d, int &$o): mixed {
    if ($o >= strlen($d)) throw new RuntimeException('CBOR: end of stream');
    $b = ord($d[$o++]);
    $M = ($b >> 5) & 7;
    $I = $b & 0x1f;

    if ($I < 24)       $v = $I;
    elseif ($I === 24) $v = ord($d[$o++]);
    elseif ($I === 25) { $v = (ord($d[$o]) << 8) | ord($d[$o + 1]); $o += 2; }
    elseif ($I === 26) { $v = unpack('N', substr($d, $o, 4))[1]; $o += 4; }
    elseif ($I === 27) {
        $hi = unpack('N', substr($d, $o, 4))[1];
        $lo = unpack('N', substr($d, $o + 4, 4))[1];
        $v = ($hi << 32) | $lo; $o += 8;
    } else throw new RuntimeException("CBOR: bad additional info $I");

    switch ($M) {
        case 0: return $v;
        case 1: return -1 - $v;
        case 2: $s = substr($d, $o, $v); $o += $v; return $s;
        case 3: $s = substr($d, $o, $v); $o += $v; return $s;
        case 4: $a = []; for ($i = 0; $i < $v; $i++) $a[] = _wa_cbor($d, $o); return $a;
        case 5:
            $m = [];
            for ($i = 0; $i < $v; $i++) { $k = _wa_cbor($d, $o); $m[$k] = _wa_cbor($d, $o); }
            return $m;
        case 6: _wa_cbor($d, $o); return _wa_cbor($d, $o); // skip tag, decode value
        case 7:
            if ($I === 20) return false;
            if ($I === 21) return true;
            if ($I === 22) return null;
            if ($I === 26) { $f = unpack('G', substr($d, $o, 4))[1]; $o += 4; return $f; }
            if ($I === 27) { $f = unpack('E', substr($d, $o, 8))[1]; $o += 8; return $f; }
            throw new RuntimeException("CBOR: unknown simple type $I");
    }
    throw new RuntimeException("CBOR: unreachable");
}

// ── AuthData binary parser ────────────────────────────────────────────────────

function wa_parse_authdata(string $ad): array {
    if (strlen($ad) < 37) throw new RuntimeException('AuthData too short');
    $o = 0;
    $rpHash    = substr($ad, $o, 32); $o += 32;
    $flags     = ord($ad[$o++]);
    $signCount = unpack('N', substr($ad, $o, 4))[1]; $o += 4;

    $result = [
        'rpIdHash'  => $rpHash,
        'UP'        => (bool)($flags & 0x01),
        'UV'        => (bool)($flags & 0x04),
        'AT'        => (bool)($flags & 0x40),
        'signCount' => $signCount,
    ];

    if ($result['AT'] && strlen($ad) > $o) {
        $aaguid  = bin2hex(substr($ad, $o, 16)); $o += 16;
        $cidLen  = unpack('n', substr($ad, $o, 2))[1]; $o += 2;
        $credId  = substr($ad, $o, $cidLen); $o += $cidLen;
        $cose    = wa_cbor(substr($ad, $o));
        $result['aaguid']  = $aaguid;
        $result['credId']  = $credId;
        $result['coseKey'] = $cose;
    }
    return $result;
}

// ── COSE public key → PEM ─────────────────────────────────────────────────────

function wa_cose_to_pem(array $k): string {
    $kty = $k[1] ?? null;

    if ($kty === 2) {
        // EC P-256 (kty=2, alg=-7, crv=1)
        $x = $k[-2] ?? '';
        $y = $k[-3] ?? '';
        // AlgorithmIdentifier for EC P-256
        $oid = hex2bin('301306072a8648ce3d020106082a8648ce3d030107');
        $pt  = "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT)
                      . str_pad($y, 32, "\x00", STR_PAD_LEFT);
        $bs  = "\x03" . _wa_derlen(strlen($pt) + 1) . "\x00" . $pt;
        $der = "\x30" . _wa_derlen(strlen($oid) + strlen($bs)) . $oid . $bs;

    } elseif ($kty === 3) {
        // RSA (kty=3, alg=-257)
        $n = $k[-1] ?? '';
        $e = $k[-2] ?? '';
        if ($n !== '' && ord($n[0]) >= 0x80) $n = "\x00" . $n;
        if ($e !== '' && ord($e[0]) >= 0x80) $e = "\x00" . $e;
        $nDer = "\x02" . _wa_derlen(strlen($n)) . $n;
        $eDer = "\x02" . _wa_derlen(strlen($e)) . $e;
        $seq  = "\x30" . _wa_derlen(strlen($nDer) + strlen($eDer)) . $nDer . $eDer;
        $oid  = hex2bin('300d06092a864886f70d0101010500');
        $bs   = "\x03" . _wa_derlen(strlen($seq) + 1) . "\x00" . $seq;
        $der  = "\x30" . _wa_derlen(strlen($oid) + strlen($bs)) . $oid . $bs;

    } else {
        throw new RuntimeException("Unsupported COSE key type: $kty");
    }
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function _wa_derlen(int $n): string {
    if ($n < 0x80)  return chr($n);
    if ($n < 0x100) return "\x81" . chr($n);
    return "\x82" . chr($n >> 8) . chr($n & 0xff);
}

// ── RP helpers ────────────────────────────────────────────────────────────────

function wa_rp_id(): string {
    global $config_base_url;
    if (!empty($config_base_url)) {
        $host = parse_url('https://' . ltrim($config_base_url, '/'), PHP_URL_HOST) ?: $config_base_url;
        return preg_replace('/:\d+$/', '', $host);
    }
    return preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
}

function wa_origin(): string {
    global $config_base_url, $config_https_only;
    if (!empty($config_base_url)) {
        $scheme = (!empty($config_https_only)) ? 'https' : 'http';
        return $scheme . '://' . ltrim($config_base_url, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// ── Registration verification ──────────────────────────────────────────────────
// Returns ['credId' => base64url, 'pubKeyPem' => string, 'signCount' => int, 'aaguid' => string]
// Throws on any verification failure.

function wa_verify_registration(
    string $clientDataJSON_b64u,
    string $attestationObject_b64u,
    string $expectedChallenge_b64u
): array {
    $clientDataJSON     = wa_b64u_decode($clientDataJSON_b64u);
    $attestationObject  = wa_b64u_decode($attestationObject_b64u);

    // 1. Parse clientDataJSON
    $clientData = json_decode($clientDataJSON, true);
    if (!$clientData) throw new RuntimeException('Invalid clientDataJSON');
    if (($clientData['type'] ?? '') !== 'webauthn.create') {
        throw new RuntimeException('Wrong clientData type');
    }
    if (!hash_equals(wa_b64u_decode($clientData['challenge']), wa_b64u_decode($expectedChallenge_b64u))) {
        throw new RuntimeException('Challenge mismatch');
    }
    $origin = wa_origin();
    if (($clientData['origin'] ?? '') !== $origin) {
        throw new RuntimeException("Origin mismatch: got '{$clientData['origin']}', expected '$origin'");
    }

    // 2. Parse attestation object
    $attObj  = wa_cbor($attestationObject);
    $authData = $attObj['authData'] ?? '';
    if (!$authData) throw new RuntimeException('Missing authData in attestation');

    // 3. Parse authData
    $ad = wa_parse_authdata($authData);
    $expectedRpHash = hash('sha256', wa_rp_id(), true);
    if (!hash_equals($ad['rpIdHash'], $expectedRpHash)) {
        throw new RuntimeException('RP ID hash mismatch');
    }
    if (!$ad['UP']) throw new RuntimeException('User Present flag not set');
    if (!isset($ad['credId'], $ad['coseKey'])) {
        throw new RuntimeException('Missing credential data in authData');
    }

    // 4. Convert key
    $pubKeyPem = wa_cose_to_pem($ad['coseKey']);

    return [
        'credId'     => wa_b64u_encode($ad['credId']),
        'pubKeyPem'  => $pubKeyPem,
        'signCount'  => $ad['signCount'],
        'aaguid'     => $ad['aaguid'] ?? '',
    ];
}

// ── Authentication verification ───────────────────────────────────────────────
// Verifies the assertion. Returns new sign count.
// Throws on any verification failure.

function wa_verify_assertion(
    string $credentialId_b64u,
    string $clientDataJSON_b64u,
    string $authenticatorData_b64u,
    string $signature_b64u,
    string $storedPubKeyPem,
    int    $storedSignCount,
    string $expectedChallenge_b64u,
    array  $allowedOrigins = [],
    bool   $requireUV = true
): int {
    $clientDataJSON    = wa_b64u_decode($clientDataJSON_b64u);
    $authenticatorData = wa_b64u_decode($authenticatorData_b64u);
    $signature         = wa_b64u_decode($signature_b64u);

    // 1. Parse and verify clientDataJSON
    $clientData = json_decode($clientDataJSON, true);
    if (!$clientData) throw new RuntimeException('Invalid clientDataJSON');
    if (($clientData['type'] ?? '') !== 'webauthn.get') {
        throw new RuntimeException('Wrong clientData type');
    }
    if (!hash_equals(wa_b64u_decode($clientData['challenge']), wa_b64u_decode($expectedChallenge_b64u))) {
        throw new RuntimeException('Challenge mismatch');
    }
    $origin = wa_origin();
    $requestOrigin = $clientData['origin'] ?? '';
    $allOrigins = array_merge([$origin], $allowedOrigins);
    if (!in_array($requestOrigin, $allOrigins, true)) {
        throw new RuntimeException("Origin mismatch: got '$requestOrigin'");
    }

    // 2. Parse authenticatorData
    $ad = wa_parse_authdata($authenticatorData);
    $expectedRpHash = hash('sha256', wa_rp_id(), true);
    if (!hash_equals($ad['rpIdHash'], $expectedRpHash)) {
        throw new RuntimeException('RP ID hash mismatch');
    }
    if (!$ad['UP']) throw new RuntimeException('User Present flag not set');
    if ($requireUV && !$ad['UV']) throw new RuntimeException('User Verified flag not set');

    // 3. Verify signature
    $clientDataHash = hash('sha256', $clientDataJSON, true);
    $signedData     = $authenticatorData . $clientDataHash;

    $pubKey = openssl_pkey_get_public($storedPubKeyPem);
    if (!$pubKey) throw new RuntimeException('Could not load stored public key');

    $keyDetails = openssl_pkey_get_details($pubKey);
    $keyType    = $keyDetails['type'] ?? -1;

    if ($keyType === OPENSSL_KEYTYPE_EC) {
        $algo = OPENSSL_ALGO_SHA256;
    } elseif ($keyType === OPENSSL_KEYTYPE_RSA) {
        $algo = OPENSSL_ALGO_SHA256;
    } else {
        throw new RuntimeException("Unsupported key type: $keyType");
    }

    $verified = openssl_verify($signedData, $signature, $pubKey, $algo);
    if ($verified !== 1) {
        throw new RuntimeException('Signature verification failed');
    }

    // 4. Sign count check (0,0 is allowed for platform authenticators that don't track)
    $newCount = $ad['signCount'];
    if ($storedSignCount !== 0 && $newCount !== 0 && $newCount <= $storedSignCount) {
        throw new RuntimeException('Sign count did not increase — possible cloned authenticator');
    }

    return $newCount;
}

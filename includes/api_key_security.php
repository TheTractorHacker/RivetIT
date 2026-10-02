<?php

/** Parse an IP allowlist. Invalid entries must never silently broaden access. */
function rivetitApiKeyNetworks(string $input): array {
    if (strlen($input) > 8192) {
        throw new InvalidArgumentException('Keep the IP allowlist within 8192 characters.');
    }
    $networks = preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY);
    if (trim($input) !== '' && empty($networks)) {
        throw new InvalidArgumentException('Enter an IP address or CIDR network, or leave the field blank.');
    }
    if (count($networks) > 100) {
        throw new InvalidArgumentException('Use at most 100 IP addresses or networks.');
    }
    foreach ($networks as $network) {
        $parts = explode('/', $network);
        $address = $parts[0];
        $packed = @inet_pton($address);
        if ($packed === false || count($parts) > 2) {
            throw new InvalidArgumentException('Enter valid IPv4 or IPv6 addresses or CIDR networks.');
        }
        if (isset($parts[1]) && (!ctype_digit($parts[1]) || intval($parts[1]) > strlen($packed) * 8)) {
            throw new InvalidArgumentException('Enter a valid CIDR prefix length.');
        }
    }
    return array_values(array_unique($networks));
}

function rivetitApiKeyIpAllowed(string $allowlist, string $ip): bool {
    if (trim($allowlist) === '') return true;
    $candidate = @inet_pton($ip);
    if ($candidate === false) return false;
    try {
        $networks = rivetitApiKeyNetworks($allowlist);
    } catch (InvalidArgumentException $e) {
        return false;
    }
    foreach ($networks as $network) {
        $parts = explode('/', $network);
        $address = inet_pton($parts[0]);
        if (strlen($address) !== strlen($candidate)) continue;
        $bits = isset($parts[1]) ? intval($parts[1]) : strlen($address) * 8;
        $bytes = intdiv($bits, 8);
        if (substr($candidate, 0, $bytes) !== substr($address, 0, $bytes)) continue;
        $remainder = $bits % 8;
        if ($remainder === 0 || ((ord($candidate[$bytes]) ^ ord($address[$bytes])) & (255 << (8 - $remainder))) === 0) {
            return true;
        }
    }
    return false;
}

/** Legacy delete.php endpoints use POST; they still need explicit delete scope. */
function rivetitApiKeyRequestAllowed(array $key, string $method, bool $legacy_delete = false): bool {
    $permission = $key['api_key_permission'] ?? 'write';
    if (!in_array($permission, ['read', 'write'], true)) return false;
    if ($method === 'GET') return true;
    if ($permission !== 'write') return false;
    if ($method === 'DELETE' || $legacy_delete) {
        return !empty($key['api_key_allow_delete'] ?? 1);
    }
    return in_array($method, ['POST', 'PUT', 'PATCH'], true);
}

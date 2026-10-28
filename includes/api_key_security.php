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

/**
 * Department scope of a legacy API key.
 *
 * Storage: api_keys.api_key_client_id plus api_key_clients rows.
 *   0   and no rows  -> every department (null)
 *   > 0              -> exactly that department (the single-department shape older code already understands)
 *   < 0 (-1)         -> several departments, listed in api_key_clients. -1 matches no real department on purpose, so
 *                       any consumer that only looks at api_key_client_id treats the key as restricted, never as unrestricted.
 * Returns null for "all departments", otherwise a list of department ids (an empty list means no department at all).
 * Fail-closed: a key flagged 0 that nevertheless has rows is limited to those rows; a < 0 key with no rows reaches nothing.
 *
 * @return list<int>|null
 */
function rivetitApiKeyClientIds(mysqli $db, int $key_id, int $key_client_id): ?array {
    $ids = [];
    if ($key_id > 0) {
        $res = mysqli_query($db, "SELECT client_id FROM api_key_clients WHERE api_key_id = " . $key_id);
        while ($res && ($r = mysqli_fetch_row($res))) {
            $ids[] = (int) $r[0];
        }
    }
    if ($key_client_id > 0) {
        return [$key_client_id];
    }
    if ($key_client_id < 0) {
        return array_values(array_unique($ids));
    }
    return $ids === [] ? null : array_values(array_unique($ids));
}

/** Human text for the key's departments, e.g. "All departments", "Acme", "Acme, Beta (+2 more)". */
function rivetitApiKeyDepartmentLabel(mysqli $db, int $key_id, int $key_client_id, int $show = 3): string {
    $ids = rivetitApiKeyClientIds($db, $key_id, $key_client_id);
    if ($ids === null) {
        return 'All departments';
    }
    if ($ids === []) {
        return 'No departments';
    }
    $names = [];
    $res = mysqli_query($db, "SELECT client_name FROM clients WHERE client_id IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY client_name");
    while ($res && ($r = mysqli_fetch_row($res))) {
        $names[] = (string) $r[0];
    }
    $more = count($ids) - $show;
    return implode(', ', array_slice($names, 0, $show)) . ($more > 0 ? " (+$more more)" : '');
}

/** Validates a posted department selection. Returns the clean id list (existing, non-archived clients only) or null when invalid. @return list<int>|null */
function rivetitApiKeyValidateClientSelection(mysqli $db, array $posted, int $max = 200): ?array {
    $ids = [];
    foreach ($posted as $v) {
        if (!is_scalar($v) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $v)) {
            return null;
        }
        $ids[(int) $v] = (int) $v;
    }
    $ids = array_values($ids);
    if ($ids === [] || count($ids) > $max) {
        return null;
    }
    $res = mysqli_query($db, "SELECT COUNT(*) FROM clients WHERE client_archived_at IS NULL AND client_id IN (" . implode(',', $ids) . ")");
    $row = $res ? mysqli_fetch_row($res) : null;
    return ($row && (int) $row[0] === count($ids)) ? $ids : null;
}

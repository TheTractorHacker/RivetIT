<?php
declare(strict_types=1);

namespace ITFlow\Onboarding;

/**
 * Saves the packet lines the user ticked "Save to Logins" on as ordinary Credentials, through the
 * same path as agent/post/credential.php (encryptCredentialEntry, then VaultV3::finalizeCredential).
 * All-or-nothing: one transaction; any failure rolls back and rethrows. Never logs or echoes secrets.
 */
final class PacketSaver
{
    private const DEFAULT_NAMES = ['computer' => 'Computer', 'email' => 'Email', 'software' => 'Software'];

    public const MAX_PASSWORD_BYTES = 1000;

    /** Throws \InvalidArgumentException (user-safe) if any line to be saved cannot be stored; touches nothing. */
    public static function preflight(PacketRequest $req): void
    {
        foreach ($req->lines as $i => $l) {
            if ($l['save'] && strlen($l['password']) > self::MAX_PASSWORD_BYTES) {
                $label = $l['name'] !== '' ? $l['name'] : (self::DEFAULT_NAMES[$l['kind']] ?? 'Login');
                throw new \InvalidArgumentException($label . ': password too long to save.');
            }
        }
    }

    /** Same cleaning as credential_model.php (encoding fix, strip tags, trim) without the SQL escape: we bind parameters. */
    private static function clean(string $v): string
    {
        if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
        }
        return trim(strip_tags($v));
    }

    /** credentials.credential_name is varchar(200). */
    public const MAX_NAME = 200;

    /**
     * The saved Login's name: the line's name (or Computer/Email/Software), prefixed "<person> - " when $person is
     * given (ticket mode, where the Login is not linked to a contact and must still say whose it is). The line name
     * is never cut; only the person prefix is shortened to keep the whole within MAX_NAME.
     */
    public static function credentialName(string $lineName, string $kind, string $person = ''): string
    {
        $name = self::clean($lineName) !== '' ? self::clean($lineName) : (self::DEFAULT_NAMES[$kind] ?? 'Login');
        try {
            $person = PacketRequest::cleanName(self::clean($person));
        } catch (\InvalidArgumentException $e) {
            $person = '';
        }
        if ($person === '') {
            return $name;
        }
        $room = self::MAX_NAME - 3 - mb_strlen($name, 'UTF-8');
        if ($room <= 0) {
            return $name;
        }
        return rtrim(mb_substr($person, 0, $room, 'UTF-8')) . ' - ' . $name;
    }

    /**
     * @param callable(\mysqli):void|null $inTransaction runs after the Logins are inserted and before COMMIT, inside the
     *        same transaction (the endpoint stores the ticket attachment there). If it throws, nothing is committed.
     * @param string $personPrefix ticket mode: the new hire's name, prefixed to each Login's name (see credentialName)
     * @param bool|null $committed set true the moment COMMIT succeeds (callers must not undo side effects after that)
     * @return list<array{id:int,name:string,client_id:int}> the credentials saved (count() is the number saved); never includes secrets
     */
    public static function save(\mysqli $db, PacketRequest $req, int $clientId, int $contactId, ?callable $inTransaction = null, string $personPrefix = '', ?bool &$committed = null): array
    {
        $lines = array_values(array_filter($req->lines, static fn(array $l): bool => $l['save']));
        if (!$lines && $inTransaction === null) {
            return [];
        }

        if ($lines) {
            self::preflight($req);
            if (\ITFlow\Crypto\VaultV3::sessionMaster() === null) {
                throw new \RuntimeException('No session encryption key; refusing to store an undecryptable password.');
            }
        }

        $committed = false;
        $db->begin_transaction();
        try {
            $saved = [];
            if ($lines) {
                $stmt = $db->prepare(
                    "INSERT INTO credentials SET credential_type = 'Login', credential_name = ?, credential_description = '', credential_uri = ?, credential_uri_2 = '',"
                    . ' credential_username = ?, credential_password = ?, credential_otp_secret = \'\', credential_note = ?, credential_favorite = 0,'
                    . ' credential_folder_id = 0, credential_contact_id = ?, credential_asset_id = 0, credential_client_id = ?, credential_rotation_due_at = NULL'
                );
                foreach ($lines as $l) {
                    $name = self::credentialName($l['name'], $l['kind'], $personPrefix);
                    $uri = self::clean($l['uri']);
                    $username = \encryptCredentialEntry($l['username']);
                    $password = \encryptCredentialEntry($l['password']);
                    $note = self::clean($l['note']);
                    if (!empty($l['also_email'])) {
                        $note = trim($note . ' Also the email login: ' . self::clean((string) $l['also_email']));
                    }
                    $stmt->bind_param('sssssii', $name, $uri, $username, $password, $note, $contactId, $clientId);
                    if (!$stmt->execute()) {
                        throw new \RuntimeException('Could not save a login.');
                    }
                    $id = (int) $db->insert_id;
                    \ITFlow\Crypto\VaultV3::finalizeCredential($db, $id);
                    $saved[] = ['id' => $id, 'name' => $name, 'client_id' => $clientId];
                }
                $stmt->close();
            }
            if ($inTransaction !== null) {
                $inTransaction($db);
            }
            if (!$db->commit()) {
                throw new \RuntimeException('Could not commit.');
            }
            $committed = true;
            return $saved;
        } catch (\Throwable $e) {
            if (!$committed) {
                $db->rollback();
            }
            throw $e;
        }
    }
}

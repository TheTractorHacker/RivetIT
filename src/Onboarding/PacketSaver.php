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

    /** @return int number of credentials saved */
    public static function save(\mysqli $db, PacketRequest $req, int $clientId, int $contactId): int
    {
        $lines = array_values(array_filter($req->lines, static fn(array $l): bool => $l['save']));
        if (!$lines) {
            return 0;
        }

        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                "INSERT INTO credentials SET credential_type = 'Login', credential_name = ?, credential_description = '', credential_uri = ?, credential_uri_2 = '',"
                . ' credential_username = ?, credential_password = ?, credential_otp_secret = \'\', credential_note = ?, credential_favorite = 0,'
                . ' credential_folder_id = 0, credential_contact_id = ?, credential_asset_id = 0, credential_client_id = ?, credential_rotation_due_at = NULL'
            );
            $saved = 0;
            foreach ($lines as $l) {
                $name = $l['name'] !== '' ? $l['name'] : (self::DEFAULT_NAMES[$l['kind']] ?? 'Login');
                $uri = $l['uri'];
                $username = \encryptCredentialEntry($l['username']);
                $password = \encryptCredentialEntry($l['password']);
                $note = $l['note'];
                $stmt->bind_param('sssssii', $name, $uri, $username, $password, $note, $contactId, $clientId);
                if (!$stmt->execute()) {
                    throw new \RuntimeException('Could not save a login.');
                }
                $id = (int) $db->insert_id;
                \ITFlow\Crypto\VaultV3::finalizeCredential($db, $id);
                $saved++;
            }
            $stmt->close();
            $db->commit();
            return $saved;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}

<?php

declare(strict_types=1);

namespace ITFlow\Integrations\ManageEngine;

use ITFlow\Assets\AssetAssignmentService;

/**
 * MDM user -> RivetIT contact.
 *
 * ManageEngine's device list carries the device's user as user.user_name and user.user_email (the only user fields the mapper reads and
 * stores). A user is identified by the lower-cased e-mail address; with no address, by "name:" + the lower-cased user name. (The list
 * response also has a user id in the documented shape, but the link tables do not store it, so it cannot be a key here.)
 *
 *   refresh()        records every user seen on a linked or unmatched device in mdm_user_contact_map and auto-maps an unmapped user when
 *                    EXACTLY ONE non-archived contact has that e-mail (exact, case-insensitive). More than one, or none: "Needs mapping".
 *                    A manual choice (source 'manual', mapped or ignored) is never changed by it.
 *   assignForLink()  sets the linked asset's assigned contact from the mapped contact. Only when the owner switched "Assign contact from
 *                    MDM user" on; only into an EMPTY asset_contact_id unless "overwrite" is on; only when the contact belongs to the
 *                    asset's department (an asset with no department takes any contact); history through AssetAssignmentService.
 */
final class MdmUserContactMapper
{
    public function __construct(private \mysqli $db, private int $integrationId)
    {
    }

    /** The mapping key of a device user, or '' when the device has no user. */
    public static function userKey(?string $email, ?string $name): string
    {
        $email = strtolower(trim((string) $email));
        if ($email !== '' && str_contains($email, '@')) {
            return mb_substr($email, 0, 200);
        }
        $name = strtolower(trim((string) $name));

        return $name !== '' ? mb_substr('name:' . $name, 0, 200) : '';
    }

    /** @return array{users:int,new:int,auto_mapped:int} */
    public function refresh(): array
    {
        $out = ['users' => 0, 'new' => 0, 'auto_mapped' => 0];
        $seen = [];
        foreach (['asset_mdm_links', 'mdm_unmatched_devices'] as $table) {
            $res = $this->db->query("SELECT user_name, user_email FROM $table WHERE integration_id = {$this->integrationId} AND (COALESCE(user_email, '') <> '' OR COALESCE(user_name, '') <> '') GROUP BY user_name, user_email");
            while ($res && ($r = $res->fetch_assoc())) {
                $key = self::userKey($r['user_email'], $r['user_name']);
                if ($key !== '' && !isset($seen[$key])) {
                    $seen[$key] = ['name' => trim((string) $r['user_name']), 'email' => trim((string) $r['user_email'])];
                }
            }
        }
        $out['users'] = count($seen);
        foreach ($seen as $key => $u) {
            $stmt = $this->db->prepare('INSERT INTO mdm_user_contact_map (integration_id, user_key, user_name, user_email) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_name = VALUES(user_name), user_email = VALUES(user_email)');
            $stmt->bind_param('isss', $this->integrationId, $key, $u['name'], $u['email']);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                $out['new']++;
            }
            $stmt->close();
        }
        // An auto-mapping whose contact has since been archived or deleted goes back to "Needs mapping".
        $this->db->query("UPDATE mdm_user_contact_map m LEFT JOIN contacts c ON c.contact_id = m.contact_id SET m.contact_id = NULL, m.source = ''
            WHERE m.integration_id = {$this->integrationId} AND m.source = 'auto' AND m.contact_id IS NOT NULL AND (c.contact_id IS NULL OR c.contact_archived_at IS NOT NULL)");

        $res = $this->db->query("SELECT id, user_key, user_email FROM mdm_user_contact_map WHERE integration_id = {$this->integrationId} AND contact_id IS NULL AND ignored = 0 AND source <> 'manual' AND user_email LIKE '%@%'");
        while ($res && ($r = $res->fetch_assoc())) {
            $cid = $this->uniqueContactByEmail((string) $r['user_email'], $this->departmentsOfUser((string) $r['user_key']));
            if ($cid > 0) {
                $this->db->query("UPDATE mdm_user_contact_map SET contact_id = $cid, source = 'auto' WHERE id = " . (int) $r['id'] . ' AND contact_id IS NULL');
                $out['auto_mapped']++;
            }
        }

        return $out;
    }

    /**
     * The one contact with this e-mail, or 0. With several matches the user's device departments narrow it down (still must be exactly one).
     *
     * @param list<int> $departments departments of the user's linked assets (empty = no preference)
     */
    public function uniqueContactByEmail(string $email, array $departments = []): int
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return 0;
        }
        $stmt = $this->db->prepare('SELECT contact_id, contact_client_id FROM contacts WHERE contact_archived_at IS NULL AND LOWER(TRIM(contact_email)) = ? LIMIT 20');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($rows) === 1) {
            return (int) $rows[0]['contact_id'];
        }
        if (count($rows) > 1 && $departments !== []) {
            $narrow = array_values(array_filter($rows, static fn (array $r): bool => in_array((int) $r['contact_client_id'], $departments, true)));
            if (count($narrow) === 1) {
                return (int) $narrow[0]['contact_id'];
            }
        }

        return 0;
    }

    /**
     * Departments (client ids, > 0) of the live assets this user's linked devices belong to.
     *
     * @return list<int>
     */
    public function departmentsOfUser(string $userKey): array
    {
        $ids = [];
        $res = $this->db->query("SELECT DISTINCT a.asset_client_id, l.user_email, l.user_name FROM asset_mdm_links l JOIN assets a ON a.asset_id = l.asset_id
            WHERE l.integration_id = {$this->integrationId} AND a.asset_archived_at IS NULL AND a.asset_client_id > 0");
        while ($res && ($r = $res->fetch_assoc())) {
            if (self::userKey($r['user_email'], $r['user_name']) === $userKey) {
                $ids[(int) $r['asset_client_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * May this contact be chosen for this user? The contact must exist and be live; and when the user's devices sit in one or more
     * departments, the contact must belong to one of them. Caller still checks the person's own access to the contact's department.
     *
     * @return array{ok:bool,message:string,client_id:int}
     */
    public function validateChoice(string $userKey, int $contactId): array
    {
        $stmt = $this->db->prepare('SELECT contact_client_id, contact_archived_at FROM contacts WHERE contact_id = ?');
        $stmt->bind_param('i', $contactId);
        $stmt->execute();
        $c = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$c || $c['contact_archived_at'] !== null) {
            return ['ok' => false, 'message' => 'That contact does not exist or is archived.', 'client_id' => 0];
        }
        $clientId = (int) $c['contact_client_id'];
        $deps = $this->departmentsOfUser($userKey);
        if ($deps !== [] && !in_array($clientId, $deps, true)) {
            return ['ok' => false, 'message' => "That contact is not in the department of this user's device. Pick a contact from the same department.", 'client_id' => $clientId];
        }

        return ['ok' => true, 'message' => '', 'client_id' => $clientId];
    }

    /** Record a person's choice: a contact, or ignore, or back to "needs mapping" (both null/false). Always source 'manual'. */
    public function setChoice(int $entryId, ?int $contactId, bool $ignored, int $byUserId): bool
    {
        $cid = $contactId !== null && $contactId > 0 ? $contactId : null;
        $ign = $ignored ? 1 : 0;
        $stmt = $this->db->prepare("UPDATE mdm_user_contact_map SET contact_id = ?, ignored = ?, source = 'manual', mapped_by = ? WHERE id = ? AND integration_id = ?");
        $stmt->bind_param('iiiii', $cid, $ign, $byUserId, $entryId, $this->integrationId);
        $stmt->execute();
        $ok = $stmt->affected_rows >= 0 && $stmt->errno === 0;
        $stmt->close();

        return $ok;
    }

    /** @return array<string,mixed>|null */
    public function entry(int $entryId): ?array
    {
        $r = $this->db->query("SELECT * FROM mdm_user_contact_map WHERE id = $entryId AND integration_id = {$this->integrationId}");

        return $r ? ($r->fetch_assoc() ?: null) : null;
    }

    /**
     * What assigning this link's mapped contact would do (no write): status is one of
     * assign | unchanged | disabled | no_user | no_mapping | ignored | contact_gone | other_department | kept_existing | no_asset.
     *
     * @param array<string,mixed> $settings
     * @return array{status:string,asset_id:int,contact_id:int,current_contact_id:int,asset_client_id:int}
     */
    public function evaluate(int $linkId, array $settings): array
    {
        $none = static fn (string $s, int $a = 0, int $c = 0, int $cur = 0, int $cl = 0): array => ['status' => $s, 'asset_id' => $a, 'contact_id' => $c, 'current_contact_id' => $cur, 'asset_client_id' => $cl];
        $res = $this->db->query("SELECT l.asset_id, l.user_name, l.user_email, a.asset_contact_id, a.asset_client_id, a.asset_archived_at FROM asset_mdm_links l
            JOIN assets a ON a.asset_id = l.asset_id WHERE l.id = $linkId AND l.integration_id = {$this->integrationId}");
        $l = $res ? $res->fetch_assoc() : null;
        if (!$l || $l['asset_archived_at'] !== null) {
            return $none('no_asset');
        }
        $aid = (int) $l['asset_id'];
        $cur = (int) $l['asset_contact_id'];
        $acl = (int) $l['asset_client_id'];
        if (empty($settings['assign_contact'])) {
            return $none('disabled', $aid, 0, $cur, $acl);
        }
        $key = self::userKey($l['user_email'], $l['user_name']);
        if ($key === '') {
            return $none('no_user', $aid, 0, $cur, $acl);
        }
        $stmt = $this->db->prepare('SELECT contact_id, ignored FROM mdm_user_contact_map WHERE integration_id = ? AND user_key = ?');
        $stmt->bind_param('is', $this->integrationId, $key);
        $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$m || ((int) $m['ignored'] === 0 && $m['contact_id'] === null)) {
            return $none('no_mapping', $aid, 0, $cur, $acl);
        }
        if ((int) $m['ignored'] === 1) {
            return $none('ignored', $aid, 0, $cur, $acl);
        }
        $cid = (int) $m['contact_id'];
        $cr = $this->db->query("SELECT contact_client_id, contact_archived_at FROM contacts WHERE contact_id = $cid");
        $c = $cr ? $cr->fetch_assoc() : null;
        if (!$c || $c['contact_archived_at'] !== null) {
            return $none('contact_gone', $aid, $cid, $cur, $acl);
        }
        if ($acl > 0 && (int) $c['contact_client_id'] !== $acl) {
            return $none('other_department', $aid, $cid, $cur, $acl);
        }
        if ($cur === $cid) {
            return $none('unchanged', $aid, $cid, $cur, $acl);
        }
        if ($cur !== 0 && empty($settings['assign_contact_overwrite'])) {
            return $none('kept_existing', $aid, $cid, $cur, $acl);
        }

        return $none('assign', $aid, $cid, $cur, $acl);
    }

    /**
     * Assign (when evaluate() says "assign"). Never writes otherwise. History goes through AssetAssignmentService like a manual change.
     *
     * @param array<string,mixed> $settings
     * @return array{status:string,asset_id:int,contact_id:int,current_contact_id:int,asset_client_id:int}
     */
    public function assignForLink(int $linkId, array $settings, int $byUserId): array
    {
        $e = $this->evaluate($linkId, $settings);
        if ($e['status'] !== 'assign') {
            return $e;
        }
        $aid = $e['asset_id'];
        $cid = $e['contact_id'];
        // Same guard as the evaluation: an overwrite=off run still only fills an empty column, even if it changed since evaluate().
        $guard = !empty($settings['assign_contact_overwrite']) ? '' : ' AND asset_contact_id = 0';
        $stmt = $this->db->prepare("UPDATE assets SET asset_contact_id = ? WHERE asset_id = ? AND asset_archived_at IS NULL$guard");
        $stmt->bind_param('ii', $cid, $aid);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$changed) {
            $e['status'] = 'kept_existing';

            return $e;
        }
        (new AssetAssignmentService($this->db))->recordChangeIfNeeded($aid, $cid, $byUserId > 0 ? $byUserId : null);
        $desc = 'ManageEngine MDM set the assigned contact from the device user (contact #' . $cid . ($e['current_contact_id'] > 0 ? ', replacing contact #' . $e['current_contact_id'] : ', it was empty') . ')';
        $stmt = $this->db->prepare("INSERT INTO asset_history (asset_history_status, asset_history_description, asset_history_asset_id) VALUES ('Updated', ?, ?)");
        $stmt->bind_param('si', $desc, $aid);
        $stmt->execute();
        $stmt->close();
        try {
            \ITFlow\Audit\AuditService::record('integration.manageengine_mdm.asset_contact_assign', $byUserId > 0 ? $byUserId : null, 'asset', $aid, 'success', $desc,
                ['contact_id' => $cid, 'previous_contact_id' => $e['current_contact_id']]);
        } catch (\Throwable $ex) {
            // the asset history row above is the record
        }

        return $e;
    }

    /**
     * Run assignForLink over every live link. Returns counts per status.
     *
     * @param array<string,mixed> $settings
     * @return array<string,int>
     */
    public function assignAll(array $settings, int $byUserId, bool $dryRun = false): array
    {
        $counts = [];
        $res = $this->db->query("SELECT l.id FROM asset_mdm_links l JOIN assets a ON a.asset_id = l.asset_id WHERE l.integration_id = {$this->integrationId} AND a.asset_archived_at IS NULL");
        $ids = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $ids[] = (int) $r['id'];
        }
        foreach ($ids as $id) {
            $e = $dryRun ? $this->evaluate($id, $settings) : $this->assignForLink($id, $settings, $byUserId);
            $counts[$e['status']] = ($counts[$e['status']] ?? 0) + 1;
        }

        return $counts;
    }
}

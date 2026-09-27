<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Integrations\Odoo\OdooAuthException;
use ITFlow\Integrations\Odoo\OdooConnectorInterface;
use ITFlow\Training\Automation\AutomationSettings;
use ITFlow\Training\Automation\Notify;
use ITFlow\Training\Automation\Recipients;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Upstream\LearnerGateway;
use ITFlow\Training\Upstream\RecordsGateway;
use ITFlow\Training\Upstream\Schema;

/**
 * The Odoo write-back run (spec §3.4 "PushService::run() flow", S5/S8), called by
 * cron/training_worker.php --task=odoo every 10 minutes. OFF by default: with the switch off (or
 * before the 2.6.96 tables exist) it returns at once, silently - no stamp, no output, no Odoo call.
 *
 * Guards before any Odoo call, each of which pauses the run (stamped on the settings row, one
 * admin notification a day): an enabled integration exists; it is https; it is the target an admin
 * confirmed (A22 pinning); the stored discovery belongs to it and offers resume lines with the
 * chosen type; on a production target, the Phase 2 employee-link check ran after that discovery.
 * Per row: the contact must be linked, the link must not be flagged, and the Odoo employee's name
 * must match the contact's (unless an admin confirmed the link) - otherwise the row is held.
 *
 * No database transaction is open during any network call. At-least-once delivery without
 * duplicates comes from the marker search before every create. Closes (voids) only write the line
 * the create made, checked by id and employee.
 */
final class PushService
{
    public const ACTION_URL = '/admin/settings_training.php#odoo-writeback';
    public const NOTIFY_TYPE = 'Training Odoo';

    private const TRANSIENT_BREAKER = 3;
    private const PERMANENT_BREAKER = 5;
    private const PAUSE_RELEASE_S = 1800;
    private const WAIT_FOR_CREATE_S = 600;

    private ?array $adminsCache = null;

    public function __construct(
        private readonly Ctx $c,
        private readonly ?OdooConnectorInterface $connector = null,
        private readonly ?Target $target = null,
        private readonly ?Notify $notify = null,
    ) {
    }

    /**
     * @param array $opts limit (25), budget_s (150), dry_run (false)
     * @return array{line:string, pushed:int, failed:int, dead:int, held:int, paused:?string, queued?:array}
     */
    public function run(array $opts): array
    {
        $db = $this->c->db;
        $limit = max(1, min(200, (int) ($opts['limit'] ?? 25)));
        $budget = max(5, (int) ($opts['budget_s'] ?? 150));
        $dry = !empty($opts['dry_run']);
        $out = ['line' => '', 'pushed' => 0, 'failed' => 0, 'dead' => 0, 'held' => 0, 'paused' => null];

        // 1. Switched off (or not installed): silent.
        if (!Schema::has($db, Schema::P5)) {
            $out['paused'] = 'disabled';
            return $out;
        }
        $s = AutomationSettings::loadWorker($db);
        if (empty($s['ready']) || (int) ($s['tauto_odoo_push_enabled'] ?? 0) !== 1) {
            $out['paused'] = 'disabled';
            return $out;
        }

        // 2-3. Target pinning and discovery binding.
        $t = $this->target ?? Target::current($db);
        $disc = is_string($s['tauto_odoo_discovery_json'] ?? null) ? json_decode($s['tauto_odoo_discovery_json'], true) : null;
        $why = self::pauseReason($t, $s, is_array($disc) ? $disc : null, (new RecordsGateway($db))->recordsSettings());
        if ($why !== null) {
            return $this->pause($out, $why, 'odoo_paused', $dry);
        }
        /** @var Target $t */
        $now = Clock::nowUtc();
        $records = new RecordsGateway($db);
        $learner = Schema::has($db, Schema::P3_AWARDS) ? new LearnerGateway($db) : null;
        $repo = new OutboxRepo($db);
        $inst8 = Marker::inst8For($db);
        $scanner = new OutboxScanner($db, $records, $learner, $repo, $inst8);

        // 4. Queue what the listener missed (dry run: count only, no writes, no network).
        if ($dry) {
            $q = $scanner->scan($t, $s, $now, 200, true);
            $due = count($repo->upcoming($t->key, 200));
            $out['queued'] = $q;
            $out['line'] = sprintf('odoo: dry run, would queue %d new, %d close, %d achievement; %d already waiting', $q['creates'], $q['closes'], $q['awards'], $due);
            return $out;
        }
        $q = $scanner->scan($t, $s, $now, 200);
        $out['queued'] = $q;

        // 5. Push the due rows.
        $rows = $repo->claim($t->key, $limit, $now);
        if ($rows) {
            $this->pushRows($rows, $t, $s, is_array($disc) ? $disc : [], $records, $learner, $repo, $out, $budget);
        }

        // 6-7. Stamp; tell admins about rows that died.
        $queued = $q['creates'] + $q['closes'] + $q['awards'];
        $summary = sprintf('pushed %d, failed %d, dead %d, held %d', $out['pushed'], $out['failed'], $out['dead'], $out['held']);
        if ($out['paused'] === null) {
            if ($rows || $queued) {
                $out['line'] = 'odoo: ' . $summary . ($queued ? sprintf(' (queued %d new, %d close, %d achievement)', $q['creates'], $q['closes'], $q['awards']) : '');
            }
            $this->stamp(['tauto_odoo_last_run_at_utc' => Clock::nowUtc(), 'tauto_odoo_last_result' => 'ok ' . $summary, 'tauto_odoo_paused_reason' => null]);
        } else {
            $out['line'] = 'odoo: paused (' . $out['paused'] . '); ' . $summary;
            $this->stamp(['tauto_odoo_last_run_at_utc' => Clock::nowUtc(), 'tauto_odoo_last_result' => (string) Text::clip('paused ' . $out['paused'] . '; ' . $summary, 255),
                'tauto_odoo_paused_reason' => (string) Text::clip($out['paused'], 255)]);
        }
        if ($out['dead'] > 0) {
            $this->notifyAdmins('odoo_dead', $out['dead'] . ' training record' . ($out['dead'] === 1 ? '' : 's') . ' could not be sent to Odoo. See Admin > Training > Odoo write-back.', ['dead' => $out['dead']]);
        }
        return $out;
    }

    /**
     * Why pushing must pause before any row is touched, or null. Public for the admin card, which
     * shows the same reason the worker would stamp.
     *
     * @param array $records RecordsGateway::recordsSettings()
     */
    public static function pauseReason(?Target $t, array $s, ?array $disc, array $records): ?string
    {
        if ($t === null) {
            return 'no_integration';
        }
        if (!$t->https) {
            return 'not_https';
        }
        if ((string) ($s['tauto_odoo_target_key'] ?? '') === '' || !hash_equals((string) $s['tauto_odoo_target_key'], $t->key)) {
            return 'target_changed';
        }
        $typeId = (int) ($s['tauto_odoo_resume_type_id'] ?? 0);
        $typeIds = array_map(static fn($x) => (int) ($x['id'] ?? 0), (array) ($disc['resume']['types'] ?? []));
        if ($disc === null || ($disc['target']['key'] ?? null) !== $t->key || ($s['tauto_odoo_mode'] ?? 'resume') !== 'resume'
            || empty($disc['resume']['available']) || $typeId < 1 || !in_array($typeId, $typeIds, true)) {
            return 'rediscover';
        }
        $awardType = (int) ($s['tauto_odoo_award_type_id'] ?? 0);
        if ($awardType > 0 && !in_array($awardType, $typeIds, true)) {
            return 'rediscover';
        }
        if (OutboxScanner::sinceUtc($s['tauto_odoo_push_since'] ?? null) === null) {
            return 'not_configured';
        }
        if (!$t->looksStaging) {
            // A production target must have been confirmed with its employee links checked after the Odoo check
            // it was confirmed on (spec §1.4 #6). A later "Check Odoo" (a discovery newer than the confirmation)
            // does not pause sending; the next save asks for a new link check.
            $checked = $records['link_checked_at_utc'] ?? null;
            $discAt = (string) ($disc['checked_at_utc'] ?? '9999');
            $confirmedAt = (string) ($s['tauto_odoo_target_confirmed_at_utc'] ?? '');
            if ($confirmedAt === '' || $discAt <= $confirmedAt) {
                if (!is_string($checked) || $checked === '' || $checked < $discAt) {
                    return 'links_unchecked';
                }
            }
        }
        return null;
    }

    /** Plain words for a pause reason (admin card and notifications). */
    public static function describePause(string $reason): string
    {
        $base = explode(':', $reason, 2);
        return match ($base[0]) {
            'disabled' => 'Write-back is switched off.',
            'no_integration' => 'No enabled Odoo integration is configured.',
            'not_https' => 'The Odoo address is not https://, so the key would travel unencrypted.',
            'target_changed' => 'The Odoo connection now points at a different Odoo than the one confirmed here. Check Odoo and save again.',
            'rediscover' => 'The last Odoo check does not match the current Odoo or the chosen line type. Run Check Odoo and save again.',
            'not_configured' => 'The "send records recorded on or after" date is not set. Save the Odoo settings again.',
            'links_unchecked' => 'The employee links were not checked against this Odoo after it was checked here. Run Check now under Employee links (Odoo).',
            'auth' => 'Odoo refused the write-back key (HTTP 401/403). Check the key under Integrations.',
            'config' => 'Odoo answered in a way that means a setup problem' . (isset($base[1]) ? ':' . $base[1] : '.'),
            default => $reason,
        };
    }

    // -----------------------------------------------------------------------------------------

    private function pushRows(array $rows, Target $t, array $s, array $disc, RecordsGateway $records, ?LearnerGateway $learner, OutboxRepo $repo, array &$out, int $budget): void
    {
        $db = $this->c->db;
        $pusher = new Pusher($this->connector ?? $t->connector(), $disc, $s);
        $company = $this->companyName();
        $start = microtime(true);
        $rest = static fn(int $from): array => array_map(static fn($r) => (int) $r['todoo_id'], array_slice($rows, $from));
        $names = [];
        $linkHeld = 0;
        $changed = 0;
        $consecT = 0;
        $consecP = 0;
        $n = count($rows);

        for ($i = 0; $i < $n; $i++) {
            $row = $rows[$i];
            $id = (int) $row['todoo_id'];
            if (microtime(true) - $start > $budget) {
                $repo->release($rest($i), 0, Clock::nowUtc());
                break;
            }
            $isClose = $row['todoo_action'] === 'close';
            $isAward = $row['todoo_source_type'] === 'award';
            try {
                // 1. Source.
                $dto = $isAward ? $learner?->awardForPush((int) $row['todoo_source_id']) : $records->completionForPush((int) $row['todoo_source_id']);
                if ($dto === null) {
                    $this->fail($repo, $id, 'permanent', 'source_missing: the ITFlow record no longer exists', $out);
                    continue;
                }
                $payload = $isAward ? PayloadBuilder::award($dto, (string) $row['todoo_marker'], $company)
                                    : PayloadBuilder::completion($dto, (string) $row['todoo_marker'], $company);

                if ($isClose) {
                    // 5. Close ordering: only after the create went through.
                    $createRow = $repo->findCreate($t->key, (string) $row['todoo_source_type'], (int) $row['todoo_source_id']);
                    $cs = $createRow['todoo_status'] ?? null;
                    if (in_array($cs, ['pending', 'failed', 'running'], true)) {
                        $repo->release([$id], self::WAIT_FOR_CREATE_S, Clock::nowUtc());
                        continue;
                    }
                    if ($cs !== 'done') {
                        $this->fail($repo, $id, 'policy', 'create_not_sent: the line was never created in Odoo', $out);
                        continue;
                    }
                    if (($payload['voided_on'] ?? null) === null) {
                        $this->fail($repo, $id, 'policy', 'not_voided: the record is not voided', $out);
                        continue;
                    }
                    $res = $pusher->push($row, $payload, (int) $createRow['todoo_odoo_employee_id'], $createRow);
                    $repo->done($id, $res['model'], $res['res_id'], (int) $createRow['todoo_odoo_employee_id'], $payload, Clock::nowUtc());
                    $out['pushed']++;
                    $consecT = $consecP = 0;
                    continue;
                }

                // Policy at push time: document kind, opted-out course, achievements switched off.
                if (!$isAward && ($dto['course_kind'] ?? 'training') !== 'training') {
                    $this->fail($repo, $id, 'policy', 'document_kind: acknowledgments are not sent to Odoo', $out);
                    continue;
                }
                if (!$isAward && !OutboxScanner::coursePushed($db, (int) $dto['course_id'])) {
                    $this->fail($repo, $id, 'policy', 'course_opted_out: this course is not sent to Odoo', $out);
                    continue;
                }
                if ($isAward && (empty($s['tauto_odoo_push_awards']) || !OutboxScanner::achievementPushed($db, (int) $dto['achievement_id']))) {
                    $this->fail($repo, $id, 'policy', 'achievement_not_sent: this achievement is not sent to Odoo', $out);
                    continue;
                }

                // 2. Voided before it was pushed: a lost-response create may already exist. Only a line this
                //    integration created counts (Pusher::ownLines); a line someone else planted with the marker is ignored.
                if (!$isAward && ($payload['voided_on'] ?? null) !== null) {
                    $linked = (int) ($records->linkState((int) $row['todoo_contact_id'], $t->integrationId)['odoo_employee_id'] ?? 0);
                    $hits = $pusher->ownLines((string) $row['todoo_marker'], $linked);
                    if ($hits) {
                        $repo->done($id, 'hr.resume.line', $hits[0]['id'], $hits[0]['employee_id'], $payload, Clock::nowUtc());
                        $out['pushed']++;
                    } else {
                        $this->fail($repo, $id, 'policy', 'voided_before_push: voided before it reached Odoo', $out);
                    }
                    $consecT = $consecP = 0;
                    continue;
                }

                // 3. Link.
                $link = $records->linkState((int) $row['todoo_contact_id'], $t->integrationId);
                $eid = (int) ($link['odoo_employee_id'] ?? 0);
                if ($eid < 1) {
                    $this->hold($repo, $id, 'No Odoo employee linked', $out);
                    continue;
                }
                if (in_array($link['state'] ?? '', ['repointed', 'mismatch', 'missing'], true) && empty($link['confirmed'])) {
                    $this->hold($repo, $id, 'Employee link flagged by the link check: ' . $link['state'], $out);
                    $linkHeld++;
                    continue;
                }

                // 4. Name check (cached per employee for this run).
                if (!array_key_exists($eid, $names)) {
                    $names[$eid] = $pusher->employee($eid);
                }
                $emp = $names[$eid];
                if ($emp === null) {
                    $this->hold($repo, $id, 'Odoo employee #' . $eid . ' not found', $out);
                    $linkHeld++;
                    continue;
                }
                if (!self::sameName($emp['name'], $link) && empty($link['confirmed'])) {
                    $this->hold($repo, $id, 'Odoo employee name "' . Text::clip($emp['name'], 100) . '" differs from "' . Text::clip((string) $link['contact_name'], 100) . '"', $out);
                    $linkHeld++;
                    continue;
                }

                // 6. Push.
                $res = $pusher->push($row, $payload, $eid, null);
                $repo->done($id, $res['model'], $res['res_id'], $eid, $payload, Clock::nowUtc());
                $out['pushed']++;
                $consecT = $consecP = 0;
            } catch (\DomainException $e) {
                // A line this integration created with this marker is on another employee (the contact was
                // re-linked after a lost create response): never adopted, never duplicated.
                $this->fail($repo, $id, 'permanent', 'employee_changed: the ITFlow line with this reference is on another Odoo employee', $out);
                $changed++;
                $consecP++;
            } catch (\Throwable $e) {
                $class = ErrorClass::of($e);
                $msg = $e->getMessage();
                if ($class === 'auth_candidate') {
                    try {
                        $keyOk = $pusher->probe();
                    } catch (OdooAuthException) {
                        $repo->release($rest($i), self::PAUSE_RELEASE_S, Clock::nowUtc(), 'auth', $msg);
                        $out['paused'] = 'auth';
                        $this->notifyAdmins('odoo_auth', 'Odoo refused the training write-back key (HTTP 401/403). Records wait until the key works again; check it under Integrations.', []);
                        break;
                    }
                    $class = $keyOk ? 'permanent' : 'transient';
                    $msg = $keyOk ? 'Odoo denied access to this record: ' . $msg : $msg;
                }
                if ($class === 'config') {
                    $repo->release($rest($i), self::PAUSE_RELEASE_S, Clock::nowUtc(), 'config', $msg);
                    $out['paused'] = (string) Text::clip('config: ' . $msg, 255);
                    $this->notifyAdmins('odoo_config', (string) Text::clip('Odoo write-back paused: ' . $msg, 900), []);
                    break;
                }
                if ($class === 'transient') {
                    $this->fail($repo, $id, 'transient', $msg, $out);
                    $consecP = 0;
                    if (++$consecT >= self::TRANSIENT_BREAKER) {
                        $repo->release($rest($i + 1), self::WAIT_FOR_CREATE_S, Clock::nowUtc());
                        break;
                    }
                    continue;
                }
                $this->fail($repo, $id, $class === 'policy' ? 'policy' : 'permanent', $msg, $out);
                $consecT = 0;
                if ($class !== 'policy' && ++$consecP >= self::PERMANENT_BREAKER) {
                    $repo->release($rest($i + 1), self::WAIT_FOR_CREATE_S, Clock::nowUtc());
                    $this->notifyAdmins('odoo_dead', 'Odoo is rejecting every training record; check the Odoo write-back card under Admin > Training.', ['dead' => $out['dead']]);
                    break;
                }
            }
        }

        if ($linkHeld > 0 || $changed > 0) {
            $parts = [];
            if ($linkHeld > 0) {
                $parts[] = $linkHeld . ' training record' . ($linkHeld === 1 ? '' : 's') . ' wait: the Odoo employee link is flagged or the Odoo employee differs. Run Check now under Employee links (Odoo).';
            }
            if ($changed > 0) {
                $parts[] = $changed . ' record' . ($changed === 1 ? '' : 's') . ' already have an Odoo line on another employee and were not sent.';
            }
            $this->notifyAdmins('odoo_link', implode(' ', $parts), ['held' => $linkHeld, 'changed' => $changed]);
        }
    }

    private function fail(OutboxRepo $repo, int $id, string $class, string $msg, array &$out): void
    {
        $status = $repo->fail($id, $class, $msg, Clock::nowUtc());
        if ($status === 'dead') {
            $out['dead']++;
        } elseif ($status === 'failed') {
            $out['failed']++;
        }
    }

    private function hold(OutboxRepo $repo, int $id, string $msg, array &$out): void
    {
        $repo->fail($id, 'hold', $msg, Clock::nowUtc());
        $out['held']++;
    }

    private function pause(array $out, string $reason, string $kind, bool $dry): array
    {
        $out['paused'] = $reason;
        $out['line'] = 'odoo: paused (' . $reason . ')';
        if ($dry) {
            return $out;
        }
        $this->stamp(['tauto_odoo_paused_reason' => $reason, 'tauto_odoo_last_run_at_utc' => Clock::nowUtc(), 'tauto_odoo_last_result' => 'paused ' . $reason]);
        $this->notifyAdmins($kind, 'Odoo write-back paused: ' . self::describePause($reason), []);
        return $out;
    }

    private function stamp(array $values): void
    {
        try {
            AutomationSettings::stamp($this->c->db, $values);
        } catch (\Throwable $e) {
            error_log('Training Odoo write-back: stamp failed: ' . $e->getMessage());
        }
    }

    /** Notify::once to every administrator (one per kind per day), each in its own try/catch. */
    private function notifyAdmins(string $kind, string $text, array $counts): void
    {
        try {
            $this->adminsCache ??= array_values(array_filter(Recipients::withLevel($this->c->db, 3), static fn($r) => !empty($r['is_admin'])));
        } catch (\Throwable $e) {
            error_log('Training Odoo write-back: recipients failed: ' . $e->getMessage());
            return;
        }
        $notify = $this->notify ?? new Notify($this->c->db);
        $today = Clock::todayLocal();
        foreach ($this->adminsCache as $r) {
            try {
                $notify->once((int) $r['user_id'], $today, $kind, self::NOTIFY_TYPE, $text, self::ACTION_URL, $counts);
            } catch (\Throwable $e) {
                error_log('Training Odoo write-back: notify failed for user #' . (int) $r['user_id'] . ': ' . $e->getMessage());
            }
        }
    }

    private function companyName(): string
    {
        try {
            $r = Db::one($this->c->db, 'SELECT company_name FROM companies WHERE company_id = 1');
            return (string) Text::clip((string) ($r['company_name'] ?? ''), 120);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Does the Odoo employee's name still match this person? Compared with the Phase 2 link rule
     * (RecordsGateway::nameKey: lowercase, accents removed, spaces collapsed) against the ITFlow contact
     * name, or against the Odoo name Phase 2 last confirmed for this link (the directory sync may have
     * given the contact a different spelling since).
     */
    public static function sameName(string $odooName, array $link): bool
    {
        $k = RecordsGateway::nameKey($odooName);
        if ($k === '') {
            return false;
        }
        foreach ([$link['contact_name'] ?? null, $link['odoo_name'] ?? null] as $n) {
            if (is_string($n) && $n !== '' && RecordsGateway::nameKey($n) === $k) {
                return true;
            }
        }
        return false;
    }
}

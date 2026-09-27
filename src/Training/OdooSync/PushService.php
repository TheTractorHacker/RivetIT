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
 *
 * Targets (2.6.97, Targets): each record goes to every target switched on - résumé line, certification
 * skill, HR note - as its own outbox row, so every target has its own attempts, backoff and breakers (a
 * target Odoo keeps refusing stops only that target for the run), its own idempotency and its own close on
 * void. The guards above (pinning, link check, per-row link and name check, course opt-out, training kind
 * only) apply to every target alike. Auth and setup failures still pause the whole run: the key and the
 * endpoint are shared.
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
        $out = ['line' => '', 'pushed' => 0, 'failed' => 0, 'dead' => 0, 'held' => 0, 'paused' => null,
                'by_target' => array_fill_keys(Targets::MODES, ['pushed' => 0, 'failed' => 0, 'dead' => 0, 'held' => 0])];

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

        // 5. Push the due rows (creates only for the targets switched on; closes for every target).
        $rows = $repo->claim($t->key, $limit, $now, Targets::enabled($s));
        if ($rows) {
            $this->pushRows($rows, $t, $s, is_array($disc) ? $disc : [], $records, $learner, $repo, $out, $budget);
        }

        // 6-7. Stamp; tell admins about rows that died.
        $queued = $q['creates'] + $q['closes'] + $q['awards'];
        $summary = sprintf('pushed %d, failed %d, dead %d, held %d', $out['pushed'], $out['failed'], $out['dead'], $out['held']);
        $stored = $summary . self::targetSuffix($out['by_target'], Targets::enabled($s));
        if ($out['paused'] === null) {
            if ($rows || $queued) {
                $out['line'] = 'odoo: ' . $summary . ($queued ? sprintf(' (queued %d new, %d close, %d achievement)', $q['creates'], $q['closes'], $q['awards']) : '');
            }
            $this->stamp(['tauto_odoo_last_run_at_utc' => Clock::nowUtc(), 'tauto_odoo_last_result' => (string) Text::clip('ok ' . $stored, 255), 'tauto_odoo_paused_reason' => null]);
        } else {
            $out['line'] = 'odoo: paused (' . $out['paused'] . '); ' . $summary;
            $this->stamp(['tauto_odoo_last_run_at_utc' => Clock::nowUtc(), 'tauto_odoo_last_result' => (string) Text::clip('paused ' . $out['paused'] . '; ' . $stored, 255),
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
        $modes = Targets::enabled($s);
        if ($modes === []) {
            return 'no_target';
        }
        if ($disc === null || ($disc['target']['key'] ?? null) !== $t->key) {
            return 'rediscover';
        }
        if (in_array('resume', $modes, true)) {
            $typeId = (int) ($s['tauto_odoo_resume_type_id'] ?? 0);
            $typeIds = array_map(static fn($x) => (int) ($x['id'] ?? 0), (array) ($disc['resume']['types'] ?? []));
            if (empty($disc['resume']['available']) || $typeId < 1 || !in_array($typeId, $typeIds, true)) {
                return 'rediscover';
            }
            $awardType = (int) ($s['tauto_odoo_award_type_id'] ?? 0);
            if ($awardType > 0 && !in_array($awardType, $typeIds, true)) {
                return 'rediscover';
            }
        }
        if (in_array('skill', $modes, true) && self::skillConfig($s, $disc) === null) {
            return 'rediscover';
        }
        if (in_array('note', $modes, true) && empty($disc['note']['available'])) {
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
            'rediscover' => 'The last Odoo check does not match the current Odoo or the chosen settings (résumé line type, certification type and level, or HR notes). Run Check Odoo and save again.',
            'no_target' => 'No Odoo target is switched on (résumé line, certification skill or HR note). Choose at least one and save.',
            'not_configured' => 'The "send records recorded on or after" date is not set. Save the Odoo settings again.',
            'links_unchecked' => 'The employee links were not checked against this Odoo after it was checked here. Run Check now under Employee links (Odoo).',
            'auth' => 'Odoo refused the write-back key (HTTP 401/403). Check the key under Integrations.',
            'config' => 'Odoo answered in a way that means a setup problem' . (isset($base[1]) ? ':' . $base[1] : '.'),
            default => $reason,
        };
    }

    /**
     * The certification settings when they match the stored discovery: the chosen type is a certification type
     * Odoo reported, the level belongs to it. @return array{type_id:int, level_id:int, skills:array<int,string>}|null
     */
    public static function skillConfig(array $s, ?array $disc): ?array
    {
        $type = (int) ($s['tauto_odoo_skill_type_id'] ?? 0);
        $level = (int) ($s['tauto_odoo_skill_level_id'] ?? 0);
        if ($disc === null || empty($disc['skill']['available']) || $type < 1 || $level < 1) {
            return null;
        }
        $types = array_map(static fn($x) => (int) ($x['id'] ?? 0), (array) ($disc['skill']['cert_types'] ?? []));
        if (!in_array($type, $types, true)) {
            return null;
        }
        $ok = false;
        foreach ((array) ($disc['skill']['levels'] ?? []) as $l) {
            if ((int) ($l['id'] ?? 0) === $level && (int) ($l['type_id'] ?? 0) === $type) {
                $ok = true;
            }
        }
        if (!$ok) {
            return null;
        }
        $skills = [];
        foreach ((array) ($disc['skill']['skills'] ?? []) as $k) {
            if ((int) ($k['type_id'] ?? 0) === $type && (int) ($k['id'] ?? 0) > 0) {
                $skills[(int) $k['id']] = (string) ($k['name'] ?? '');
            }
        }
        return ['type_id' => $type, 'level_id' => $level, 'skills' => $skills];
    }

    /**
     * "; resume P/F/D/H; skill …; note …" after the totals (pushed/failed/dead/held per target), for the targets that
     * are on or did something - '' when only the résumé line is on (the Phase 5 result stays as it was).
     */
    public static function targetSuffix(array $byTarget, array $enabled): string
    {
        $active = [];
        foreach (Targets::MODES as $m) {
            $c = $byTarget[$m] ?? ['pushed' => 0, 'failed' => 0, 'dead' => 0, 'held' => 0];
            if (in_array($m, $enabled, true) || $c['pushed'] + $c['failed'] + $c['dead'] + $c['held'] > 0) {
                $active[$m] = $c;
            }
        }
        if ($active === [] || array_keys($active) === ['resume']) {
            return '';
        }
        $parts = [];
        foreach ($active as $m => $c) {
            $parts[] = sprintf('%s %d/%d/%d/%d', $m, $c['pushed'], $c['failed'], $c['dead'], $c['held']);
        }
        return '; ' . implode('; ', $parts);
    }

    // -----------------------------------------------------------------------------------------

    private function pushRows(array $rows, Target $t, array $s, array $disc, RecordsGateway $records, ?LearnerGateway $learner, OutboxRepo $repo, array &$out, int $budget): void
    {
        $db = $this->c->db;
        $pusher = new Pusher($this->connector ?? $t->connector(), $disc, $s);
        $company = $this->companyName();
        $skillCfg = self::skillConfig($s, $disc);
        $start = microtime(true);
        $rest = static fn(int $from): array => array_map(static fn($r) => (int) $r['todoo_id'], array_slice($rows, $from));
        // The rest of one target's rows (its breaker tripped): the other targets carry on.
        $restOf = static fn(int $from, string $mode): array => array_map(static fn($r) => (int) $r['todoo_id'],
            array_values(array_filter(array_slice($rows, $from), static fn($r) => $r['todoo_mode'] === $mode)));
        $names = [];
        $linkHeld = 0;
        $changed = 0;
        $consecT = array_fill_keys(Targets::MODES, 0);
        $consecP = array_fill_keys(Targets::MODES, 0);
        $stopped = [];
        $n = count($rows);

        for ($i = 0; $i < $n; $i++) {
            $row = $rows[$i];
            $id = (int) $row['todoo_id'];
            $mode = Targets::valid((string) $row['todoo_mode']) ? (string) $row['todoo_mode'] : 'resume';
            if (isset($stopped[$mode])) {
                continue;   // released when this target's breaker tripped
            }
            if (microtime(true) - $start > $budget) {
                $repo->release($rest($i), 0, Clock::nowUtc());
                break;
            }
            $isClose = $row['todoo_action'] === 'close';
            $isAward = $row['todoo_source_type'] === 'award';
            $sourceId = (int) $row['todoo_source_id'];
            try {
                // 1. Source.
                $dto = $isAward ? $learner?->awardForPush($sourceId) : $records->completionForPush($sourceId);
                if ($dto === null) {
                    $this->fail($repo, $id, 'permanent', 'source_missing: the ITFlow record no longer exists', $out, $mode);
                    continue;
                }
                $payload = $isAward ? PayloadBuilder::award($dto, (string) $row['todoo_marker'], $company)
                                    : PayloadBuilder::completion($dto, (string) $row['todoo_marker'], $company);

                if ($isClose) {
                    // 5. Close ordering: only after this target's create went through.
                    $createRow = $repo->findCreate($t->key, (string) $row['todoo_source_type'], $sourceId, $mode);
                    $cs = $createRow['todoo_status'] ?? null;
                    if (in_array($cs, ['pending', 'failed', 'running'], true)) {
                        $repo->release([$id], self::WAIT_FOR_CREATE_S, Clock::nowUtc());
                        continue;
                    }
                    if ($cs !== 'done') {
                        $this->fail($repo, $id, 'policy', 'create_not_sent: the ' . Targets::label($mode) . ' was never created in Odoo', $out, $mode);
                        continue;
                    }
                    if (($payload['voided_on'] ?? null) === null) {
                        $this->fail($repo, $id, 'policy', 'not_voided: the record is not voided', $out, $mode);
                        continue;
                    }
                    $res = $pusher->push($row, $payload, (int) $createRow['todoo_odoo_employee_id'], $createRow);
                    $repo->done($id, $res['model'], $res['res_id'], (int) $createRow['todoo_odoo_employee_id'], $payload, Clock::nowUtc());
                    $this->pushed($out, $mode);
                    $consecT[$mode] = $consecP[$mode] = 0;
                    continue;
                }

                // Policy at push time: document kind, opted-out course, achievements switched off.
                if (!$isAward && ($dto['course_kind'] ?? 'training') !== 'training') {
                    $this->fail($repo, $id, 'policy', 'document_kind: acknowledgments are not sent to Odoo', $out, $mode);
                    continue;
                }
                if (!$isAward && !OutboxScanner::coursePushed($db, (int) $dto['course_id'])) {
                    $this->fail($repo, $id, 'policy', 'course_opted_out: this course is not sent to Odoo', $out, $mode);
                    continue;
                }
                if ($isAward && (empty($s['tauto_odoo_push_awards']) || !OutboxScanner::achievementPushed($db, (int) $dto['achievement_id']))) {
                    $this->fail($repo, $id, 'policy', 'achievement_not_sent: this achievement is not sent to Odoo', $out, $mode);
                    continue;
                }

                // Certification: the course's (achievement's) Odoo skill on this target, in the chosen certification type.
                $opts = [];
                if ($mode === 'skill') {
                    $what = $isAward ? 'achievement "' . Text::clip((string) $dto['achievement_name'], 80) . '"' : 'course "' . Text::clip((string) $dto['course_name'], 80) . '"';
                    $skillId = OutboxScanner::skillFor($db, $isAward ? 'achievement' : 'course', $isAward ? (int) $dto['achievement_id'] : (int) $dto['course_id'], $t->key);
                    if ($skillId === null) {
                        $this->fail($repo, $id, 'policy', 'not_mapped: no Odoo certification skill is mapped to the ' . $what . ' on this Odoo. Map one under Send to Odoo, then Retry.', $out, $mode);
                        continue;
                    }
                    if ($skillCfg === null || !isset($skillCfg['skills'][$skillId])) {
                        $this->fail($repo, $id, 'policy', 'skill_not_in_type: the Odoo skill #' . $skillId . ' mapped to the ' . $what
                            . ' is not in the chosen certification type (as of the last Check Odoo). Map a skill of that type, then Retry.', $out, $mode);
                        continue;
                    }
                    $type = (string) $row['todoo_source_type'];
                    $opts = ['skill_id' => $skillId, 'level_id' => $skillCfg['level_id'], 'type_id' => $skillCfg['type_id'],
                             'holder' => static fn(int $odooId): ?array => $repo->holderOf($t->key, 'skill', $odooId, $type, $sourceId)];
                }

                // 2. Voided before it was pushed: a lost-response create may already exist. Only a record this
                //    integration created counts (Pusher::existing); one someone else planted is ignored.
                if (!$isAward && ($payload['voided_on'] ?? null) !== null) {
                    $linked = (int) ($records->linkState((int) $row['todoo_contact_id'], $t->integrationId)['odoo_employee_id'] ?? 0);
                    $hit = $pusher->existing($row, $payload, $linked, $opts);
                    if ($hit !== null) {
                        $repo->done($id, Targets::MODELS[$mode], $hit['id'], $hit['employee_id'], $payload, Clock::nowUtc());
                        $this->pushed($out, $mode);
                    } else {
                        $this->fail($repo, $id, 'policy', 'voided_before_push: voided before it reached Odoo', $out, $mode);
                    }
                    $consecT[$mode] = $consecP[$mode] = 0;
                    continue;
                }

                // 3. Link.
                $link = $records->linkState((int) $row['todoo_contact_id'], $t->integrationId);
                $eid = (int) ($link['odoo_employee_id'] ?? 0);
                if ($eid < 1) {
                    $this->hold($repo, $id, 'No Odoo employee linked', $out, $mode);
                    continue;
                }
                if (in_array($link['state'] ?? '', ['repointed', 'mismatch', 'missing'], true) && empty($link['confirmed'])) {
                    $this->hold($repo, $id, 'Employee link flagged by the link check: ' . $link['state'], $out, $mode);
                    $linkHeld++;
                    continue;
                }

                // 4. Name check (cached per employee for this run).
                if (!array_key_exists($eid, $names)) {
                    $names[$eid] = $pusher->employee($eid);
                }
                $emp = $names[$eid];
                if ($emp === null) {
                    $this->hold($repo, $id, 'Odoo employee #' . $eid . ' not found', $out, $mode);
                    $linkHeld++;
                    continue;
                }
                if (!self::sameName($emp['name'], $link) && empty($link['confirmed'])) {
                    $this->hold($repo, $id, 'Odoo employee name "' . Text::clip($emp['name'], 100) . '" differs from "' . Text::clip((string) $link['contact_name'], 100) . '"', $out, $mode);
                    $linkHeld++;
                    continue;
                }

                // 6. Push.
                $res = $pusher->push($row, $payload, $eid, null, $opts);
                if ($mode === 'skill') {
                    $payload['odoo_skill'] = ['skill_id' => $opts['skill_id'], 'level_id' => $opts['level_id'], 'type_id' => $opts['type_id']];
                }
                $repo->done($id, $res['model'], $res['res_id'], $eid, $payload, Clock::nowUtc());
                $this->pushed($out, $mode);
                $consecT[$mode] = $consecP[$mode] = 0;
            } catch (PushException $e) {
                if ($e->errorClass === 'wait') {
                    // Another ITFlow record holds the identical certification and its void is on its way: try again later.
                    $repo->release([$id], self::WAIT_FOR_CREATE_S, Clock::nowUtc(), 'transient', $e->getMessage());
                    continue;
                }
                if ($this->failed($e, $e->errorClass, $e->getMessage(), $pusher, $repo, $id, $i, $mode, $rest, $restOf, $out, $consecT, $consecP, $stopped)) {
                    break;
                }
            } catch (\DomainException $e) {
                // A record this integration created with this marker is on another employee (the contact was
                // re-linked after a lost create response): never adopted, never duplicated.
                $this->fail($repo, $id, 'permanent', 'employee_changed: the ITFlow ' . Targets::label($mode) . ' with this reference is on another Odoo employee', $out, $mode);
                $changed++;
                $consecP[$mode]++;
            } catch (\Throwable $e) {
                if ($this->failed($e, ErrorClass::of($e), $e->getMessage(), $pusher, $repo, $id, $i, $mode, $rest, $restOf, $out, $consecT, $consecP, $stopped)) {
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

    /**
     * One failed push, sorted by class. Returns true when the whole run must stop (auth, config). A target whose
     * transient or permanent breaker trips stops alone: its remaining rows are released, the others go on.
     */
    private function failed(\Throwable $e, string $class, string $msg, Pusher $pusher, OutboxRepo $repo, int $id, int $i, string $mode,
                            \Closure $rest, \Closure $restOf, array &$out, array &$consecT, array &$consecP, array &$stopped): bool
    {
        if ($class === 'auth_candidate') {
            try {
                $keyOk = $pusher->probe();
            } catch (OdooAuthException) {
                $repo->release($rest($i), self::PAUSE_RELEASE_S, Clock::nowUtc(), 'auth', $msg);
                $out['paused'] = 'auth';
                $this->notifyAdmins('odoo_auth', 'Odoo refused the training write-back key (HTTP 401/403). Records wait until the key works again; check it under Integrations.', []);
                return true;
            }
            $class = $keyOk ? 'permanent' : 'transient';
            $msg = $keyOk ? 'Odoo denied access to this record: ' . $msg : $msg;
        }
        if ($class === 'config') {
            $repo->release($rest($i), self::PAUSE_RELEASE_S, Clock::nowUtc(), 'config', $msg);
            $out['paused'] = (string) Text::clip('config: ' . $msg, 255);
            $this->notifyAdmins('odoo_config', (string) Text::clip('Odoo write-back paused: ' . $msg, 900), []);
            return true;
        }
        if ($class === 'transient') {
            $this->fail($repo, $id, 'transient', $msg, $out, $mode);
            $consecP[$mode] = 0;
            if (++$consecT[$mode] >= self::TRANSIENT_BREAKER) {
                $repo->release($restOf($i + 1, $mode), self::WAIT_FOR_CREATE_S, Clock::nowUtc());
                $stopped[$mode] = true;
            }
            return false;
        }
        $this->fail($repo, $id, $class === 'policy' ? 'policy' : 'permanent', $msg, $out, $mode);
        $consecT[$mode] = 0;
        if ($class !== 'policy' && ++$consecP[$mode] >= self::PERMANENT_BREAKER) {
            $repo->release($restOf($i + 1, $mode), self::WAIT_FOR_CREATE_S, Clock::nowUtc());
            $stopped[$mode] = true;
            $this->notifyAdmins('odoo_dead', 'Odoo is rejecting every training record sent as ' . (Targets::PHRASE[$mode] ?? $mode)
                . '; check the Odoo write-back card under Admin > Training.', ['dead' => $out['dead'], 'target' => $mode]);
        }
        return false;
    }

    private function pushed(array &$out, string $mode): void
    {
        $out['pushed']++;
        $out['by_target'][$mode]['pushed']++;
    }

    private function fail(OutboxRepo $repo, int $id, string $class, string $msg, array &$out, string $mode = 'resume'): void
    {
        $status = $repo->fail($id, $class, $msg, Clock::nowUtc());
        if ($status === 'dead') {
            $out['dead']++;
            $out['by_target'][$mode]['dead']++;
        } elseif ($status === 'failed') {
            $out['failed']++;
            $out['by_target'][$mode]['failed']++;
        }
    }

    private function hold(OutboxRepo $repo, int $id, string $msg, array &$out, string $mode = 'resume'): void
    {
        $repo->fail($id, 'hold', $msg, Clock::nowUtc());
        $out['held']++;
        $out['by_target'][$mode]['held']++;
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

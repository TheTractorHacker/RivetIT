<?php

namespace ITFlow\Training\Reminders;

use ITFlow\Training\Automation\AutomationSettings;
use ITFlow\Training\Automation\Notify;
use ITFlow\Training\Automation\Recipients;
use ITFlow\Training\Automation\WorkerCtx;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Upstream\ComplianceGateway;
use ITFlow\Training\Upstream\Links;
use ITFlow\Training\Upstream\P2;
use ITFlow\Training\Upstream\PeopleScope;
use ITFlow\Training\Upstream\Schema;

/**
 * S4: the daily in-app Training digest (spec §3.5, §1.4 #9). In-app only - Automation\Notify is
 * the one way out; there is no mail code here (spec §0).
 *
 *   1  Preconditions: reminders switched on (tauto_reminders_enabled, OFF by default), today's ISO
 *      weekday in tauto_reminder_weekdays, P2 compliance + scope available.
 *   2  One ComplianceGateway::departmentCounts() for every department (P2's pair statuses; nothing
 *      is recomputed here).
 *   3  Every active agent with Training >= 1 (Automation\Recipients, from DB levels - never the
 *      session), each in its own try/catch so one failure never stops the others:
 *        scope = P2's fail-closed Scope for THAT user (Upstream\PeopleScope over WorkerCtx::forUser):
 *        no department rows = no digest; admins and Training 3 see every department.
 *        digest     -> Notify::once(kind 'digest', type 'Training Digest', the Training overview)
 *        Training 3 / admins also get the escalation (departments with people overdue more than
 *        tauto_escalate_after_days) -> Notify::once(kind 'escalation', 'Training Escalation', the overdue report).
 *   Notify::once dedupes per user, day and kind (training_reminder_log), so a second run on the
 *   same day sends nothing new, and a failed send removes its row so it is retried.
 *
 * Dry run computes everything and writes nothing: no log rows, no notifications. The admin
 * preview (?preview=reminders on the Training settings page) passes $ignoreSchedule so it can show
 * what today's digests would say even while reminders are off or today is not a reminder day.
 */
final class ReminderService
{
    public function __construct(
        private readonly Ctx $system,
        private readonly Notify $notify,
        private readonly ?ComplianceGateway $compliance = null,
    ) {
    }

    /**
     * @return array{state:string, sent:int, skipped:int, line:string,
     *               users:list<array{user_id:int, name:string, level:int, is_admin:bool, digest:?string, escalation:?string,
     *                                sent:bool, note:?string}>}
     *   state: ok | disabled | not_today | unavailable. `sent` counts notifications sent (0 on a dry run);
     *   `skipped` counts people who got nothing (no department access, nothing to report, already sent today, or an error).
     */
    public function run(string $todayLocal, bool $dryRun, bool $ignoreSchedule = false): array
    {
        $db = $this->system->db;
        $out = ['state' => 'ok', 'sent' => 0, 'skipped' => 0, 'line' => '', 'users' => []];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $todayLocal) || strtotime($todayLocal) === false) {
            throw new \InvalidArgumentException('ReminderService::run: today must be Y-m-d');
        }
        if (!Schema::has($db, ['training_automation', 'training_reminder_log'])) {
            return self::finish($out, 'unavailable', 'reminders: not installed');
        }
        $s = AutomationSettings::loadWorker($db);
        if (!$ignoreSchedule) {
            if ((int) ($s['tauto_reminders_enabled'] ?? 0) !== 1) {
                return self::finish($out, 'disabled', '');
            }
            if (!in_array((int) date('N', strtotime($todayLocal)), self::weekdays((string) ($s['tauto_reminder_weekdays'] ?? '')), true)) {
                return self::finish($out, 'not_today', 'reminders: not a reminder day');
            }
        }
        if (!P2::has($db, 'compliance') || !P2::has($db, 'scope')) {
            return self::finish($out, 'unavailable', 'reminders: Training compliance is not available');
        }

        $escalateDays = self::escalateDays($s['tauto_escalate_after_days'] ?? 14);
        $rows = ($this->compliance ?? new ComplianceGateway($db))->departmentCounts($this->system, $escalateDays);

        foreach (Recipients::withLevel($db, 1) as $who) {
            $uid = (int) $who['user_id'];
            $isAdmin = (bool) $who['is_admin'];
            $level = (int) $who['level'];
            $u = ['user_id' => $uid, 'name' => (string) $who['name'], 'level' => $level, 'is_admin' => $isAdmin,
                  'digest' => null, 'escalation' => null, 'sent' => false, 'note' => null];
            try {
                $scope = PeopleScope::forCtx(WorkerCtx::forUser($db, $this->system->baseUrl, $uid, $isAdmin, $level));
                if ($scope->isNone()) {
                    $u['note'] = 'No department access';
                    $out['skipped']++;
                    $out['users'][] = $u;
                    continue;
                }
                $mine = array_values(array_filter($rows, static fn($r) => $scope->allows((int) $r['client_id'])));
                $u['digest'] = DigestBuilder::digestText($mine, $escalateDays);
                if ($isAdmin || $level >= 3) {
                    $u['escalation'] = DigestBuilder::escalationText($mine, $escalateDays);
                }
                if ($u['digest'] === null && $u['escalation'] === null) {
                    $u['note'] = 'Nothing to report';
                    $out['skipped']++;
                    $out['users'][] = $u;
                    continue;
                }

                if ($dryRun) {
                    $already = $this->alreadySent($uid, $todayLocal);
                    if (($u['digest'] === null || in_array('digest', $already, true))
                        && ($u['escalation'] === null || in_array('escalation', $already, true))) {
                        $u['note'] = 'Already sent today';
                        $out['skipped']++;
                    }
                    $out['users'][] = $u;
                    continue;
                }

                $n = 0;
                if ($u['digest'] !== null
                    && $this->notify->once($uid, $todayLocal, 'digest', 'Training Digest', $u['digest'], Links::overview(), self::counts($mine))) {
                    $n++;
                }
                if ($u['escalation'] !== null) {
                    $esc = array_values(array_filter($mine, static fn($r) => (int) ($r['escalated'] ?? 0) > 0));
                    if ($this->notify->once($uid, $todayLocal, 'escalation', 'Training Escalation', $u['escalation'], Links::overdue(), self::counts($esc))) {
                        $n++;
                    }
                }
                $u['sent'] = $n > 0;
                $out['sent'] += $n;
                if ($n === 0) {
                    $u['note'] = 'Already sent today';
                    $out['skipped']++;
                }
            } catch (\Throwable $e) {
                error_log('Training reminders: user ' . $uid . ': ' . get_class($e) . ': ' . $e->getMessage());
                $u['note'] = 'Could not be prepared (see the server error log)';
                $out['skipped']++;
            }
            $out['users'][] = $u;
        }

        $line = $dryRun
            ? sprintf('reminders (dry run): %d would get a digest, %d skipped', count($out['users']) - $out['skipped'], $out['skipped'])
            : sprintf('reminders: sent %d, skipped %d', $out['sent'], $out['skipped']);
        return self::finish($out, 'ok', $line);
    }

    /** "1,2,3,4,5" -> [1,2,3,4,5]: ISO weekdays 1 (Monday) .. 7 (Sunday), unknown values dropped. */
    public static function weekdays(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $d) {
            $d = trim($d);
            if (preg_match('/^[1-7]$/D', $d) === 1) {
                $out[(int) $d] = (int) $d;
            }
        }
        ksort($out);
        return array_values($out);
    }

    public static function escalateDays(mixed $v): int
    {
        return max(1, min(180, (int) $v));
    }

    // ------------------------------------------------------------------------------------------

    private static function finish(array $out, string $state, string $line): array
    {
        $out['state'] = $state;
        $out['line'] = $line;
        return $out;
    }

    /** @return array{departments:int, overdue:int, escalated:int, due_soon:int, renewal:int} */
    private static function counts(array $rows): array
    {
        $c = ['departments' => count($rows), 'overdue' => 0, 'escalated' => 0, 'due_soon' => 0, 'renewal' => 0];
        foreach ($rows as $r) {
            foreach (['overdue', 'escalated', 'due_soon', 'renewal'] as $k) {
                $c[$k] += max(0, (int) ($r[$k] ?? 0));
            }
        }
        return $c;
    }

    /** Kinds already logged for this user today (the preview's "Already sent today"). @return list<string> */
    private function alreadySent(int $userId, string $todayLocal): array
    {
        return array_column(Db::all($this->system->db, "SELECT trem_kind FROM training_reminder_log
            WHERE trem_user_id = ? AND trem_date = ? AND trem_kind IN ('digest', 'escalation')", 'is', [$userId, $todayLocal]), 'trem_kind');
    }
}

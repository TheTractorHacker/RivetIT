<?php

namespace ITFlow\Training\Reminders;

use ITFlow\Training\Automation\Notify;
use ITFlow\Training\Automation\Recipients;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Media\OEmbedClient;
use ITFlow\Training\Media\VideoLink;
use ITFlow\Training\Publish\RevisionRepository;
use ITFlow\Training\Upstream\Links;
use ITFlow\Training\Upstream\Schema;

/**
 * S6: a daily look at the external (YouTube / Vimeo) videos that published courses use, so an
 * author hears about a video that went private or was deleted before employees hit it on the
 * kiosk (spec §3.5, §1.4 #13).
 *
 * Read-only towards Phase 1: the videos come from each non-archived course's CURRENT revision
 * (Publish\RevisionRepository), checked through Media\OEmbedClient / SafeHttp only (host
 * allowlist, public IPs, no redirects; URLs rebuilt from validated ids by VideoLink). It NEVER
 * writes training_video_checks: Phase 1 treats a failed check there as blocking republishing, so
 * one flaky answer would stop an author. This watch keeps its own state in training_video_watch
 * and only alerts; publishing already re-checks videos itself (VideoRecheck).
 *
 * Per video (deduped by provider, id, privacy hash; oldest check first, never-checked first):
 *   'error' (network, odd answer)  -> nothing is written: a transient problem is not evidence
 *   ok                              -> status ok, streak 0, first bad cleared
 *   private / not_found / embed_disabled / live, or a Vimeo length that moved more than
 *   max(2 s, 2 %) from the published one ('duration_changed')
 *                                   -> the streak goes up only when the last counted bad check is
 *                                      at least MIN_GAP_S (1 h) old, so two checks a minute apart
 *                                      count once
 * An alert needs a streak of 2 (two bad answers at least an hour apart) and goes out once per bad
 * spell: to the course's responsible person (when they are an active Training user) and to
 * Training 3 / admins, one Notify 'video' message per person per day listing that person's videos.
 * A video whose alert reached nobody (everyone already had a video message today) stays pending
 * and is alerted on the next run.
 *
 * Runs are serialised with a named lock (the daily worker and Admin "Check now" can overlap), and
 * each video's row is re-read FOR UPDATE in its own tiny transaction - no network inside it.
 */
final class VideoWatch
{
    public const MIN_GAP_S = 3600;

    /** training_video_watch statuses that count as bad. */
    public const BAD = ['not_found', 'private', 'embed_disabled', 'live', 'duration_changed'];

    private const LOCK = 'training_video_watch';

    public function __construct(
        private readonly Ctx $c,
        private readonly Notify $notify,
        private readonly ?\Closure $oembed = null,   // tests: fn(array $video): array (OEmbedClient::check shape)
        private readonly ?\Closure $clock = null,    // tests: fn(): string UTC 'Y-m-d H:i:s.v'
    ) {
    }

    /**
     * @return array{state:string, checked:int, changed:int, errors:int, alerts:int, line:string,
     *               bad:list<array{provider:string, id:string, status:string, streak:int, courses:list<array{id:int, name:string}>}>}
     *   state: ok | busy (another check is running) | unavailable (tables missing) | none (no external videos).
     *   On a dry run nothing is written and nothing is sent; `bad`/`changed` say what would change.
     */
    public function run(int $max = 200, int $budgetS = 90, bool $dryRun = false): array
    {
        $db = $this->c->db;
        $out = ['state' => 'ok', 'checked' => 0, 'changed' => 0, 'errors' => 0, 'alerts' => 0, 'line' => '', 'bad' => []];
        if (!Schema::has($db, ['training_video_watch'])) {
            $out['state'] = 'unavailable';
            $out['line'] = 'video watch: not installed';
            return $out;
        }
        if (!$dryRun && !Db::lock($db, self::LOCK, 0)) {
            $out['state'] = 'busy';
            $out['line'] = 'video watch: another check is running';
            return $out;
        }
        try {
            $videos = $this->collect();
            if ($videos === []) {
                $out['state'] = 'none';
                $out['line'] = 'video watch: no external videos';
                return $out;
            }
            $rows = $this->watchRows();
            uasort($videos, static function ($a, $b) use ($rows) {
                $ta = $rows[$a['key']]['tvwatch_checked_at_utc'] ?? '';
                $tb = $rows[$b['key']]['tvwatch_checked_at_utc'] ?? '';
                return strcmp((string) $ta, (string) $tb) ?: strcmp($a['key'], $b['key']);
            });

            $deadline = microtime(true) + max(1, $budgetS);
            $max = max(0, $max);
            foreach ($videos as $key => $v) {
                if ($out['checked'] >= $max || microtime(true) >= $deadline) {
                    break;
                }
                $res = $this->check($v);
                $out['checked']++;
                $status = $this->classify($v, $res);
                if ($status === null) {
                    $out['errors']++;
                    continue;   // transient: no write
                }
                $http = isset($res['http']) && is_int($res['http']) && $res['http'] >= 0 && $res['http'] <= 999 ? $res['http'] : null;
                $dur = isset($res['duration_s']) && is_int($res['duration_s']) && $res['duration_s'] > 0 ? $res['duration_s'] : null;
                [$before, $after] = $this->record($v, $status, $http, $dur, $dryRun, $rows[$key] ?? null);
                if ($before === null || $before['tvwatch_status'] !== $after['tvwatch_status']) {
                    $out['changed']++;
                }
                $rows[$key] = $after;
            }

            // Alerts: every current video in a bad spell with a streak of 2 that has not been alerted for this spell.
            $due = [];
            foreach ($videos as $key => $v) {
                $r = $rows[$key] ?? null;
                if ($r === null || !in_array($r['tvwatch_status'], self::BAD, true)) {
                    continue;
                }
                $out['bad'][] = ['provider' => $v['provider'], 'id' => $v['id'], 'status' => (string) $r['tvwatch_status'],
                    'streak' => (int) $r['tvwatch_bad_streak'], 'courses' => array_values(array_map(
                        static fn($c) => ['id' => $c['id'], 'name' => $c['name']], $v['courses']))];
                if ((int) $r['tvwatch_bad_streak'] >= 2 && ($r['tvwatch_alerted_at_utc'] === null
                        || ($r['tvwatch_first_bad_at_utc'] !== null && $r['tvwatch_alerted_at_utc'] < $r['tvwatch_first_bad_at_utc']))) {
                    $due[$key] = $v + ['row' => $r];
                }
            }
            if ($due !== []) {
                $out['alerts'] = $this->alert($due, $dryRun);
            }
        } finally {
            if (!$dryRun) {
                try {
                    Db::unlock($db, self::LOCK);
                } catch (\Throwable) {
                    // The lock dies with the connection anyway.
                }
            }
        }
        $out['line'] = sprintf('video watch%s: checked %d, changed %d, errors %d, bad %d, alerts %d',
            $dryRun ? ' (dry run)' : '', $out['checked'], $out['changed'], $out['errors'], count($out['bad']), $out['alerts']);
        return $out;
    }

    /**
     * The settings card's view, with no network: every external video in a current revision, its
     * stored watch state and the courses (and lessons) that use it.
     *
     * @return array{available:bool, videos:int, courses:int, never_checked:int, last_checked_at_utc:?string,
     *               bad:list<array{provider:string, id:string, status:string, streak:int, first_bad_at_utc:?string,
     *                              last_bad_at_utc:?string, checked_at_utc:?string, alerted:bool,
     *                              courses:list<array{id:int, name:string, lessons:list<string>}>}>}
     */
    public function report(): array
    {
        $out = ['available' => false, 'videos' => 0, 'courses' => 0, 'never_checked' => 0, 'last_checked_at_utc' => null, 'bad' => []];
        if (!Schema::has($this->c->db, ['training_video_watch'])) {
            return $out;
        }
        $out['available'] = true;
        $videos = $this->collect();
        $rows = $this->watchRows();
        $courses = [];
        foreach ($videos as $key => $v) {
            $out['videos']++;
            foreach ($v['courses'] as $cid => $_) {
                $courses[$cid] = true;
            }
            $r = $rows[$key] ?? null;
            if ($r === null) {
                $out['never_checked']++;
                continue;
            }
            if ($out['last_checked_at_utc'] === null || $r['tvwatch_checked_at_utc'] > $out['last_checked_at_utc']) {
                $out['last_checked_at_utc'] = (string) $r['tvwatch_checked_at_utc'];
            }
            if (in_array($r['tvwatch_status'], self::BAD, true)) {
                $out['bad'][] = [
                    'provider' => $v['provider'],
                    'id' => $v['id'],
                    'status' => (string) $r['tvwatch_status'],
                    'streak' => (int) $r['tvwatch_bad_streak'],
                    'first_bad_at_utc' => $r['tvwatch_first_bad_at_utc'],
                    'last_bad_at_utc' => $r['tvwatch_last_bad_at_utc'],
                    'checked_at_utc' => $r['tvwatch_checked_at_utc'],
                    'alerted' => $r['tvwatch_alerted_at_utc'] !== null && $r['tvwatch_first_bad_at_utc'] !== null
                        && $r['tvwatch_alerted_at_utc'] >= $r['tvwatch_first_bad_at_utc'],
                    'courses' => array_values(array_map(static fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'lessons' => array_values(array_unique($c['lessons']))], $v['courses'])),
                ];
            }
        }
        $out['courses'] = count($courses);
        usort($out['bad'], static fn($a, $b) => ($b['streak'] <=> $a['streak']) ?: strcmp((string) $a['first_bad_at_utc'], (string) $b['first_bad_at_utc']));
        return $out;
    }

    /** "YouTube reports 'private'" wording for one status (also used by the settings card). */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'ok' => 'OK',
            'private' => 'private',
            'not_found' => 'not found (deleted or wrong link)',
            'embed_disabled' => 'embedding turned off',
            'live' => 'a live stream',
            'duration_changed' => 'a different length than when it was published',
            default => $status,
        };
    }

    public static function providerLabel(string $provider): string
    {
        return $provider === 'vimeo' ? 'Vimeo' : 'YouTube';
    }

    // ------------------------------------------------------------------------------------------

    /**
     * External videos in the current revision of every non-archived course.
     *
     * @return array<string, array{key:string, provider:string, id:string, hash:string, durations:list<int>,
     *                             courses:array<int, array{id:int, name:string, responsible:int, lessons:list<string>}>}>
     */
    private function collect(): array
    {
        $db = $this->c->db;
        $courses = Db::all($db, 'SELECT course_id, course_name, course_responsible_user_id, course_current_revision_id FROM training_courses
            WHERE course_archived_at IS NULL AND course_current_revision_id IS NOT NULL ORDER BY course_id');
        if ($courses === []) {
            return [];
        }
        $repo = new RevisionRepository($this->c);
        $videos = [];
        foreach ($courses as $co) {
            $cid = (int) $co['course_id'];
            try {
                $doc = $repo->get((int) $co['course_current_revision_id'])['doc'];
            } catch (\Throwable $e) {
                error_log('Training video watch: course ' . $cid . ' revision could not be read: ' . get_class($e) . ': ' . $e->getMessage());
                continue;
            }
            $default = (string) ($doc['course']['default_language'] ?? 'en');
            foreach ((array) ($doc['lessons'] ?? []) as $lesson) {
                $variants = is_array($lesson['variants'] ?? null) ? $lesson['variants'] : [];
                $lessonTitle = (string) ($variants[$default]['title'] ?? (reset($variants)['title'] ?? ''));
                foreach ($variants as $variant) {
                    $vid = is_array($variant['video'] ?? null) ? $variant['video'] : null;
                    if ($vid === null || !in_array($vid['provider'] ?? null, ['youtube', 'vimeo'], true)) {
                        continue;
                    }
                    $hash = (string) ($vid['h'] ?? '');
                    $link = VideoLink::fromParts((string) $vid['provider'], (string) ($vid['id'] ?? ''), $hash);
                    if ($link === null) {
                        continue;
                    }
                    $key = $link['provider'] . ':' . $link['id'] . ':' . $link['hash'];
                    $videos[$key] ??= ['key' => $key, 'provider' => $link['provider'], 'id' => $link['id'], 'hash' => $link['hash'],
                                       'link' => $link, 'durations' => [], 'courses' => []];
                    $d = (int) ($vid['duration_s'] ?? 0);
                    if ($d > 0 && !in_array($d, $videos[$key]['durations'], true)) {
                        $videos[$key]['durations'][] = $d;
                    }
                    $videos[$key]['courses'][$cid] ??= ['id' => $cid, 'name' => (string) $co['course_name'],
                        'responsible' => (int) ($co['course_responsible_user_id'] ?? 0), 'lessons' => []];
                    $title = (string) ($variant['title'] ?? '') !== '' && $lessonTitle === '' ? (string) $variant['title'] : $lessonTitle;
                    if ($title !== '' && !in_array($title, $videos[$key]['courses'][$cid]['lessons'], true)) {
                        $videos[$key]['courses'][$cid]['lessons'][] = $title;
                    }
                }
            }
        }
        return $videos;
    }

    /** @return array<string, array> every training_video_watch row keyed provider:id:hash */
    private function watchRows(): array
    {
        $out = [];
        foreach (Db::all($this->c->db, 'SELECT ' . self::COLS . ' FROM training_video_watch') as $r) {
            $out[$r['tvwatch_provider'] . ':' . $r['tvwatch_ext_id'] . ':' . $r['tvwatch_ext_hash']] = self::typed($r);
        }
        return $out;
    }

    private const COLS = 'tvwatch_provider, tvwatch_ext_id, tvwatch_ext_hash, tvwatch_status, tvwatch_http, tvwatch_oembed_duration_s,
        tvwatch_bad_streak, tvwatch_first_bad_at_utc, tvwatch_last_bad_at_utc, tvwatch_checked_at_utc, tvwatch_alerted_at_utc';

    private static function typed(array $r): array
    {
        $r['tvwatch_bad_streak'] = (int) $r['tvwatch_bad_streak'];
        $r['tvwatch_http'] = $r['tvwatch_http'] === null ? null : (int) $r['tvwatch_http'];
        $r['tvwatch_oembed_duration_s'] = $r['tvwatch_oembed_duration_s'] === null ? null : (int) $r['tvwatch_oembed_duration_s'];
        return $r;
    }

    private function check(array $v): array
    {
        try {
            $res = $this->oembed !== null ? ($this->oembed)($v['link']) : OEmbedClient::check($v['link'], $this->c->baseUrl);
        } catch (\Throwable $e) {
            error_log('Training video watch: ' . $v['provider'] . ' ' . $v['id'] . ': ' . get_class($e) . ': ' . $e->getMessage());
            return ['status' => 'error', 'http' => null, 'duration_s' => null];
        }
        return is_array($res) ? $res : ['status' => 'error', 'http' => null, 'duration_s' => null];
    }

    /** The status to record, or null for a transient answer that is not written. */
    private function classify(array $v, array $res): ?string
    {
        $status = (string) ($res['status'] ?? 'error');
        if ($status === 'ok') {
            $d = $res['duration_s'] ?? null;
            if ($v['provider'] === 'vimeo' && is_int($d) && $d > 0) {
                foreach ($v['durations'] as $pub) {
                    if (abs($d - $pub) > max(2, 0.02 * $pub)) {
                        return 'duration_changed';
                    }
                }
            }
            return 'ok';
        }
        return in_array($status, self::BAD, true) ? $status : null;
    }

    /**
     * Applies one answer to the video's row (FOR UPDATE in a short transaction; no network inside).
     * On a dry run the new row is computed from $known and nothing is written.
     *
     * @return array{0:?array, 1:array} the row before and after
     */
    private function record(array $v, string $status, ?int $http, ?int $dur, bool $dryRun, ?array $known): array
    {
        $now = $this->now();
        $apply = function (?array $before) use ($v, $status, $http, $dur, $now): array {
            $after = $before ?? ['tvwatch_provider' => $v['provider'], 'tvwatch_ext_id' => $v['id'], 'tvwatch_ext_hash' => $v['hash'],
                'tvwatch_status' => null, 'tvwatch_http' => null, 'tvwatch_oembed_duration_s' => null, 'tvwatch_bad_streak' => 0,
                'tvwatch_first_bad_at_utc' => null, 'tvwatch_last_bad_at_utc' => null, 'tvwatch_checked_at_utc' => $now, 'tvwatch_alerted_at_utc' => null];
            $after['tvwatch_status'] = $status;
            $after['tvwatch_http'] = $http;
            if ($dur !== null) {
                $after['tvwatch_oembed_duration_s'] = $dur;
            }
            $after['tvwatch_checked_at_utc'] = $now;
            if ($status === 'ok') {
                $after['tvwatch_bad_streak'] = 0;
                $after['tvwatch_first_bad_at_utc'] = null;
            } else {
                $streak = (int) $after['tvwatch_bad_streak'];
                $last = $after['tvwatch_last_bad_at_utc'];
                if ($streak === 0) {
                    $after['tvwatch_bad_streak'] = 1;
                    $after['tvwatch_last_bad_at_utc'] = $now;
                } elseif ($last === null || self::secondsBetween((string) $last, $now) >= self::MIN_GAP_S) {
                    $after['tvwatch_bad_streak'] = min(255, $streak + 1);
                    $after['tvwatch_last_bad_at_utc'] = $now;
                }
                $after['tvwatch_first_bad_at_utc'] ??= $now;
            }
            return $after;
        };

        if ($dryRun) {
            return [$known, $apply($known)];
        }
        $db = $this->c->db;
        return Db::tx($db, function () use ($db, $v, $apply) {
            $row = Db::one($db, 'SELECT ' . self::COLS . ' FROM training_video_watch
                WHERE tvwatch_provider = ? AND tvwatch_ext_id = ? AND tvwatch_ext_hash = ? FOR UPDATE', 'sss', [$v['provider'], $v['id'], $v['hash']]);
            $before = $row === null ? null : self::typed($row);
            $after = $apply($before);
            $vals = [$after['tvwatch_status'], $after['tvwatch_http'], $after['tvwatch_oembed_duration_s'], $after['tvwatch_bad_streak'],
                     $after['tvwatch_first_bad_at_utc'], $after['tvwatch_last_bad_at_utc'], $after['tvwatch_checked_at_utc']];
            if ($before === null) {
                Db::exec($db, 'INSERT INTO training_video_watch (tvwatch_provider, tvwatch_ext_id, tvwatch_ext_hash, tvwatch_status, tvwatch_http,
                        tvwatch_oembed_duration_s, tvwatch_bad_streak, tvwatch_first_bad_at_utc, tvwatch_last_bad_at_utc, tvwatch_checked_at_utc)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'ssssiiisss', array_merge([$v['provider'], $v['id'], $v['hash']], $vals));
            } else {
                Db::exec($db, 'UPDATE training_video_watch SET tvwatch_status = ?, tvwatch_http = ?, tvwatch_oembed_duration_s = ?, tvwatch_bad_streak = ?,
                        tvwatch_first_bad_at_utc = ?, tvwatch_last_bad_at_utc = ?, tvwatch_checked_at_utc = ?
                    WHERE tvwatch_provider = ? AND tvwatch_ext_id = ? AND tvwatch_ext_hash = ?', 'siiisss' . 'sss',
                    array_merge($vals, [$v['provider'], $v['id'], $v['hash']]));
            }
            return [$before, $after];
        });
    }

    /**
     * One message per person listing the due videos that person should hear about; marks a video
     * alerted once its message reached at least one person.
     *
     * @param array<string, array> $due
     * @return int messages sent (would be sent, on a dry run)
     */
    private function alert(array $due, bool $dryRun): int
    {
        $db = $this->c->db;
        $active = [];
        foreach (Recipients::withLevel($db, 1) as $r) {
            $active[(int) $r['user_id']] = $r;
        }
        // user id => list of due keys
        $per = [];
        foreach ($active as $uid => $r) {
            if ($r['is_admin'] || (int) $r['level'] >= 3) {
                $per[$uid] = array_keys($due);
            }
        }
        foreach ($due as $key => $v) {
            foreach ($v['courses'] as $c) {
                $uid = (int) $c['responsible'];
                if ($uid > 0 && isset($active[$uid]) && !in_array($key, $per[$uid] ?? [], true)) {
                    $per[$uid][] = $key;
                }
            }
        }
        ksort($per);
        $today = Clock::localDate($this->now());
        $sent = 0;
        $reached = [];
        foreach ($per as $uid => $keys) {
            $mine = array_intersect_key($due, array_flip($keys));
            if ($mine === []) {
                continue;
            }
            if ($dryRun) {
                $sent++;
                continue;
            }
            try {
                $mine = array_map(static fn($v) => self::ownCoursesFirst($v, $uid), $mine);
                $first = reset($mine);
                $firstCourse = reset($first['courses']);
                $ok = $this->notify->once($uid, $today, 'video', 'Training Video', self::alertText($mine),
                    Links::courseBuilder((int) $firstCourse['id']), ['videos' => count($mine)]);
            } catch (\Throwable $e) {
                error_log('Training video watch: alert to user ' . $uid . ': ' . get_class($e) . ': ' . $e->getMessage());
                $ok = false;
            }
            if ($ok) {
                $sent++;
                foreach (array_keys($mine) as $k) {
                    $reached[$k] = true;
                }
            }
        }
        if (!$dryRun) {
            $now = $this->now();
            foreach (array_keys($reached) as $key) {
                $v = $due[$key];
                Db::exec($db, 'UPDATE training_video_watch SET tvwatch_alerted_at_utc = ?
                    WHERE tvwatch_provider = ? AND tvwatch_ext_id = ? AND tvwatch_ext_hash = ?', 'ssss', [$now, $v['provider'], $v['id'], $v['hash']]);
            }
        }
        return $sent;
    }

    /** The courses $userId is responsible for come first, so their message names their course. */
    private static function ownCoursesFirst(array $v, int $userId): array
    {
        $own = array_filter($v['courses'], static fn($c) => (int) $c['responsible'] === $userId);
        $v['courses'] = $own + $v['courses'];
        return $v;
    }

    /**
     * "YouTube reports 'private' for 'Lockout intro' in LOTO Basics (checked twice). Fix the video in
     * YouTube, then play it once in the builder before republishing."
     */
    public static function alertText(array $videos): string
    {
        $items = [];
        $providers = [];
        foreach ($videos as $v) {
            $providers[self::providerLabel($v['provider'])] = true;
            $row = $v['row'] ?? [];
            $streak = (int) ($row['tvwatch_bad_streak'] ?? 2);
            $courses = array_values($v['courses']);
            $c0 = $courses[0] ?? ['name' => 'a course', 'lessons' => []];
            $lesson = (string) ($c0['lessons'][0] ?? '');
            $where = ($lesson !== '' ? "'" . self::short($lesson) . "' in " : 'a lesson in ') . self::short((string) $c0['name'])
                . (count($courses) > 1 ? ' and ' . (count($courses) - 1) . (count($courses) === 2 ? ' other course' : ' other courses') : '');
            $status = (string) ($row['tvwatch_status'] ?? '');
            $what = $status === 'duration_changed'
                ? self::providerLabel($v['provider']) . ' now reports a different length for ' . $where
                : self::providerLabel($v['provider']) . " reports '" . self::statusLabel($status) . "' for " . $where;
            $items[] = $what . ' (checked ' . ($streak === 2 ? 'twice' : $streak . ' times') . ')';
        }
        $fixIn = implode(' or ', array_keys($providers));
        if (count($items) === 1) {
            $text = $items[0] . '. Fix the video in ' . $fixIn . ', then play it once in the builder before republishing.';
        } else {
            $text = count($items) . ' training videos need attention: ' . implode('; ', $items)
                . '. Fix each video in ' . $fixIn . ', then play it once in the builder before republishing.';
        }
        return Notify::clip($text);
    }

    private static function short(string $s): string
    {
        $s = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', mb_scrub($s, 'UTF-8')));
        return mb_strlen($s, 'UTF-8') > 80 ? rtrim(mb_substr($s, 0, 79, 'UTF-8')) . '…' : $s;
    }

    private function now(): string
    {
        if ($this->clock !== null) {
            $t = (string) ($this->clock)();
            if (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{3}$/D', $t) === 1) {
                return $t;
            }
        }
        return Clock::nowUtc();
    }

    private static function secondsBetween(string $fromUtc, string $toUtc): int
    {
        $z = new \DateTimeZone('UTC');
        return (new \DateTimeImmutable($toUtc, $z))->getTimestamp() - (new \DateTimeImmutable($fromUtc, $z))->getTimestamp();
    }
}

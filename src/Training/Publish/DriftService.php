<?php

namespace ITFlow\Training\Publish;

use ITFlow\Training\Authoring\CourseTouch;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Media\KbSnapshot;
use ITFlow\Training\Quiz\BankService;
use ITFlow\Training\Quiz\BankTree;
use ITFlow\Training\Quiz\InList;

/**
 * Drift badges for the course builder header and the Versions tab (spec §3.5 DriftService).
 *
 *   has_changes  the one definition shared by the Publish button, publish_check and the Versions
 *                tab: sha256(strict build) != current revision sha (or nothing published yet).
 *                An equal sha settles the draft timestamp, so a stale "Unpublished changes"
 *                clears itself.
 *   kb           KB-imported variants whose source article changed since it was imported
 *                (draft_drift) or since the current version was published (published_drift)
 *   banks        question banks the current version draws from whose questions changed since
 *   videos       external videos whose last check failed, that are unverified, or whose
 *                verification is older than 30 days
 */
final class DriftService
{
    public function __construct(private readonly Ctx $c)
    {
    }

    public function forCourse(int $courseId): array
    {
        $db = $this->c->db;
        $build = (new RevisionBuilder($this->c))->build($courseId, true);
        $repo = new RevisionRepository($this->c);
        $current = $repo->current($courseId);
        $hasChanges = $current === null || !hash_equals($current['sha256'], $build['sha256']);
        if (!$hasChanges && $build['course']['course_archived_at'] === null) {
            Db::tx($db, function () use ($db, $courseId, $current): void {
                CourseTouch::settle($db, $courseId, $current['published_at_utc']);
            });
        }

        return [
            'has_changes' => $hasChanges,
            'draft_sha' => $build['sha256'],
            'current_sha' => $current['sha256'] ?? null,
            'current_number' => $current['number'] ?? null,
            'kb' => $this->kb($courseId, $current['doc'] ?? null),
            'banks' => $this->banks($current['doc'] ?? null),
            'videos' => $this->videos($build),
        ];
    }

    private function kb(int $courseId, ?array $publishedDoc): array
    {
        $db = $this->c->db;
        $rows = Db::all($db, 'SELECT l.lesson_id, l.lesson_uid, v.lvar_lang, v.lvar_kb_source_article_id, v.lvar_kb_source_sha256,
                v.lvar_kb_imported_at_utc FROM training_lesson_variants v JOIN training_lessons l ON l.lesson_id = v.lvar_lesson_id
            WHERE l.lesson_course_id = ? AND l.lesson_archived_at IS NULL AND v.lvar_kb_source_article_id IS NOT NULL
            ORDER BY l.lesson_id, v.lvar_lang', 'i', [$courseId]);
        $published = [];
        foreach ($publishedDoc['lessons'] ?? [] as $l) {
            foreach ($l['variants'] ?? [] as $lang => $v) {
                if (($v['kb_source'] ?? null) !== null) {
                    $published[$l['uid'] . ':' . $lang] = (string) $v['kb_source']['sha256'];
                }
            }
        }
        $live = [];
        $out = [];
        foreach ($rows as $r) {
            $aid = (int) $r['lvar_kb_source_article_id'];
            if (!array_key_exists($aid, $live)) {
                $live[$aid] = KbSnapshot::currentSha($db, $aid);
            }
            $liveSha = $live[$aid];
            $draftSha = (string) ($r['lvar_kb_source_sha256'] ?? '');
            $pubSha = $published[$r['lesson_uid'] . ':' . $r['lvar_lang']] ?? null;
            $out[] = [
                'lesson_id' => (int) $r['lesson_id'],
                'lesson_uid' => (string) $r['lesson_uid'],
                'lang' => (string) $r['lvar_lang'],
                'article_id' => $aid,
                'imported_at' => Clock::toIso($r['lvar_kb_imported_at_utc'], true),
                'missing' => $liveSha === null,
                'draft_drift' => $liveSha === null || $liveSha !== $draftSha,
                'published_drift' => $pubSha !== null && ($liveSha === null || $liveSha !== $pubSha),
            ];
        }
        return $out;
    }

    private function banks(?array $doc): array
    {
        if ($doc === null || ($doc['banks'] ?? []) === []) {
            return [];
        }
        $db = $this->c->db;
        $desc = [];
        foreach ($doc['lessons'] as $l) {
            foreach ($l['quiz']['rules'] ?? [] as $r) {
                $desc[$r['bank_uid']] = ($desc[$r['bank_uid']] ?? false) || $r['include_descendants'];
            }
        }
        $uids = array_map(static fn($b) => (string) $b['uid'], $doc['banks']);
        [$ph, $t, $p] = InList::strings($uids);
        $ids = [];
        foreach (Db::all($db, "SELECT qbank_id, qbank_uid FROM training_question_banks WHERE qbank_uid IN ($ph)", $t, $p) as $r) {
            $ids[(string) $r['qbank_uid']] = (int) $r['qbank_id'];
        }
        $tree = BankTree::load($db);
        $out = [];
        foreach ($doc['banks'] as $b) {
            $id = $ids[$b['uid']] ?? null;
            $now = $id === null ? null : BankService::poolSha($db, $tree, $id, $desc[$b['uid']] ?? false);
            $out[] = [
                'uid' => (string) $b['uid'],
                'bank_id' => $id,
                'name' => (string) $b['name'],
                'path' => (string) $b['path'],
                'archived' => $id === null || !$tree->isLive($id),
                'drift' => $now === null || $now !== $b['sha256'],
            ];
        }
        return $out;
    }

    private function videos(array $build): array
    {
        $byKey = [];
        foreach ($build['videos'] as $v) {
            $key = RevisionBuilder::videoKey($v['provider'], $v['id'], $v['hash']);
            $byKey[$key] ??= ['provider' => $v['provider'], 'id' => $v['id'], 'hash' => $v['hash'], 'lesson_ids' => []];
            if (!in_array($v['lesson_id'], $byKey[$key]['lesson_ids'], true)) {
                $byKey[$key]['lesson_ids'][] = $v['lesson_id'];
            }
        }
        $out = [];
        $now = time();
        foreach ($byKey as $key => $v) {
            $vc = $build['vchecks'][$key] ?? null;
            $verifiedIso = $vc === null ? null : Clock::toIso($vc['vcheck_verified_at_utc'], true);
            $errIso = $vc === null ? null : Clock::toIso($vc['vcheck_last_error_at_utc'], true);
            $verifiedTs = $verifiedIso === null ? null : strtotime($verifiedIso);
            $errTs = $errIso === null ? null : strtotime($errIso);
            $stale = $verifiedTs !== null && $now - $verifiedTs > PublishValidator::VIDEO_FRESH_DAYS * 86400;
            $failed = $errTs !== null && ($verifiedTs === null || $errTs > $verifiedTs);
            $status = $vc['vcheck_status'] ?? null;
            $out[] = $v + [
                'status' => $status,
                'verified_at' => $verifiedIso,
                'last_error' => $vc['vcheck_last_error'] ?? null,
                'unverified' => $verifiedTs === null,
                'stale' => $stale,
                'problem' => $verifiedTs === null || $stale || $failed || ($status !== null && $status !== 'ok'),
            ];
        }
        return $out;
    }
}

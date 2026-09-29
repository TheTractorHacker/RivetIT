<?php

namespace ITFlow\Training\Publish;

use ITFlow\Training\Core\Canonical;

/**
 * "What's changing" between two revision JSON v1 documents (or a revision and the draft
 * build), for the publish modal, the Versions tab and "Compare to draft" (spec §5.3, §5.10).
 *
 * Entities are matched by uid, so renames, moves and edits are told apart from additions and
 * removals. The result is a list of plain-language items the UI renders with textContent:
 *   {area: course|languages|outline|section|lesson|question|bank, kind: added|removed|changed|moved,
 *    uid?, label, fields?:list<string>}
 * plus counts. The diff is shown to authors (level 2+), so it may say that an answer key changed,
 * but it never contains the key itself.
 */
final class RevisionDiff
{
    public static function diff(?array $from, array $to): array
    {
        // Compare canonical forms: a decoded stored revision and a fresh build hold the same
        // data with different key order, and PHP's === on arrays is order-sensitive.
        $to = self::canonical($to);
        $from = $from === null ? null : self::canonical($from);
        $items = [];
        $lang = (string) ($to['course']['default_language'] ?? 'en');

        if ($from === null) {
            $items[] = ['area' => 'course', 'kind' => 'added', 'label' => 'First version'];
            return self::result(true, $items, $to);
        }

        // --- course -----------------------------------------------------------------------
        $fields = [];
        $fc = $from['course'] ?? [];
        $tc = $to['course'] ?? [];
        foreach (['code', 'cover_media_id', 'color', 'regulation_ref', 'sequential', 'est_minutes', 'validity_months', 'renewal_lead_days',
                     'requires_signature', 'is_qualification', 'components', 'eval_checklist', 'default_language'] as $f) {
            if (($fc[$f] ?? null) !== ($tc[$f] ?? null)) {
                $fields[] = self::courseFieldLabel($f);
            }
        }
        foreach (self::langs($fc['text'] ?? [], $tc['text'] ?? []) as $l) {
            foreach (['name' => 'name', 'summary' => 'summary', 'description_html' => 'description', 'attestation_text' => 'attestation'] as $f => $label) {
                if (($fc['text'][$l][$f] ?? null) !== ($tc['text'][$l][$f] ?? null) && isset($fc['text'][$l], $tc['text'][$l])) {
                    $fields[] = $label . ' (' . strtoupper($l) . ')';
                }
            }
        }
        if ($fields !== []) {
            $items[] = ['area' => 'course', 'kind' => 'changed', 'label' => 'Course details', 'fields' => array_values(array_unique($fields))];
        }

        // --- languages ----------------------------------------------------------------------
        $fl = $fc['languages'] ?? [];
        $tl = $tc['languages'] ?? [];
        foreach (array_diff($tl, $fl) as $l) {
            $items[] = ['area' => 'languages', 'kind' => 'added', 'uid' => $l, 'label' => 'Language ' . strtoupper($l)];
        }
        foreach (array_diff($fl, $tl) as $l) {
            $items[] = ['area' => 'languages', 'kind' => 'removed', 'uid' => $l, 'label' => 'Language ' . strtoupper($l)];
        }

        // --- sections ------------------------------------------------------------------------
        $fs = self::byUid($from['sections'] ?? []);
        $ts = self::byUid($to['sections'] ?? []);
        foreach ($ts as $uid => $s) {
            $title = (string) ($s['text'][$lang]['title'] ?? '');
            if (!isset($fs[$uid])) {
                $items[] = ['area' => 'section', 'kind' => 'added', 'uid' => $uid, 'label' => $title];
            } elseif ($fs[$uid]['text'] !== $s['text']) {
                $items[] = ['area' => 'section', 'kind' => 'changed', 'uid' => $uid, 'label' => $title, 'fields' => ['title']];
            }
        }
        foreach ($fs as $uid => $s) {
            if (!isset($ts[$uid])) {
                $items[] = ['area' => 'section', 'kind' => 'removed', 'uid' => $uid, 'label' => (string) (reset($s['text'])['title'] ?? '')];
            }
        }
        if (array_keys($fs) !== array_keys($ts) && array_diff(array_keys($fs), array_keys($ts)) === [] && array_diff(array_keys($ts), array_keys($fs)) === []) {
            $items[] = ['area' => 'outline', 'kind' => 'changed', 'label' => 'Section order'];
        }

        // --- lessons --------------------------------------------------------------------------
        $fL = self::byUid($from['lessons'] ?? []);
        $tL = self::byUid($to['lessons'] ?? []);
        foreach ($tL as $uid => $l) {
            $title = self::lessonTitle($l, $lang);
            if (!isset($fL[$uid])) {
                $items[] = ['area' => 'lesson', 'kind' => 'added', 'uid' => $uid, 'label' => $title];
                continue;
            }
            $o = $fL[$uid];
            $changes = [];
            if ($o['section_uid'] !== $l['section_uid']) {
                $items[] = ['area' => 'lesson', 'kind' => 'moved', 'uid' => $uid, 'label' => $title];
            }
            if ($o['type'] !== $l['type']) {
                $changes[] = 'type';
            }
            foreach (['required', 'requires_previous', 'duration_s', 'allow_download', 'preview_enabled', 'thumb_media_id', 'min_watch_pct', 'ack'] as $f) {
                if (($o[$f] ?? null) !== ($l[$f] ?? null)) {
                    $changes[] = 'settings';
                    break;
                }
            }
            foreach (self::langs($o['variants'] ?? [], $l['variants'] ?? []) as $vl) {
                $a = $o['variants'][$vl] ?? null;
                $b = $l['variants'][$vl] ?? null;
                if ($a === null || $b === null) {
                    $changes[] = ($b === null ? 'removed ' : 'added ') . strtoupper($vl);
                    continue;
                }
                if ($a['title'] !== $b['title']) {
                    $changes[] = 'title (' . strtoupper($vl) . ')';
                }
                if ($a['description_html'] !== $b['description_html']) {
                    $changes[] = 'description (' . strtoupper($vl) . ')';
                }
                foreach (['body_html', 'media_id', 'pages', 'caption', 'video', 'kb_source'] as $f) {
                    if (($a[$f] ?? null) !== ($b[$f] ?? null)) {
                        $changes[] = 'content (' . strtoupper($vl) . ')';
                        break;
                    }
                }
            }
            if (($o['resources'] ?? []) !== ($l['resources'] ?? [])) {
                $changes[] = 'resources';
            }
            $oq = $o['quiz'] ?? null;
            $lq = $l['quiz'] ?? null;
            if ($oq === null && $lq !== null) {
                $changes[] = 'quiz added';
            } elseif ($oq !== null && $lq === null) {
                $changes[] = 'quiz removed';
            } elseif ($oq !== null) {
                $settingsA = $oq;
                $settingsB = $lq;
                unset($settingsA['rules'], $settingsB['rules']);
                if ($settingsA !== $settingsB) {
                    $changes[] = 'quiz settings';
                }
                $rulesA = array_map(static fn($r) => [$r['uid'], $r['bank_uid'], $r['include_descendants'], $r['count']], $oq['rules']);
                $rulesB = array_map(static fn($r) => [$r['uid'], $r['bank_uid'], $r['include_descendants'], $r['count']], $lq['rules']);
                if ($rulesA !== $rulesB) {
                    $changes[] = 'question sources';
                }
                $poolA = array_merge([], ...array_map(static fn($r) => $r['pool'], $oq['rules'] ?: [['pool' => []]]));
                $poolB = array_merge([], ...array_map(static fn($r) => $r['pool'], $lq['rules'] ?: [['pool' => []]]));
                if ($poolA !== $poolB) {
                    $changes[] = 'quiz questions';
                }
            }
            if ($changes !== []) {
                $items[] = ['area' => 'lesson', 'kind' => 'changed', 'uid' => $uid, 'label' => $title, 'fields' => array_values(array_unique($changes))];
            }
        }
        foreach ($fL as $uid => $l) {
            if (!isset($tL[$uid])) {
                $items[] = ['area' => 'lesson', 'kind' => 'removed', 'uid' => $uid, 'label' => self::lessonTitle($l, (string) ($fc['default_language'] ?? $lang))];
            }
        }
        $commonFrom = array_values(array_filter($from['lesson_order'] ?? [], static fn($u) => isset($tL[$u])));
        $commonTo = array_values(array_filter($to['lesson_order'] ?? [], static fn($u) => isset($fL[$u])));
        if ($commonFrom !== $commonTo) {
            $items[] = ['area' => 'outline', 'kind' => 'changed', 'label' => 'Lesson order'];
        }

        // --- questions ------------------------------------------------------------------------
        $fq = $from['questions'] ?? [];
        $tq = $to['questions'] ?? [];
        foreach ($tq as $uid => $q) {
            $label = self::questionLabel($q, $lang);
            if (!isset($fq[$uid])) {
                $items[] = ['area' => 'question', 'kind' => 'added', 'uid' => $uid, 'label' => $label];
                continue;
            }
            $o = $fq[$uid];
            $changes = [];
            if ($o['type'] !== $q['type']) {
                $changes[] = 'type';
            }
            if ($o['points'] !== $q['points']) {
                $changes[] = 'points';
            }
            if ($o['critical'] !== $q['critical']) {
                $changes[] = 'critical';
            }
            if ($o['media_id'] !== $q['media_id']) {
                $changes[] = 'image';
            }
            $keyA = array_map(static fn($x) => [$x['uid'], $x['correct']], $o['options']);
            $keyB = array_map(static fn($x) => [$x['uid'], $x['correct']], $q['options']);
            if (array_column($keyA, 0) !== array_column($keyB, 0)) {
                $changes[] = 'answers';
            } elseif ($keyA !== $keyB) {
                $changes[] = 'correct answer';
            }
            if (array_map(static fn($x) => $x['pinned'], $o['options']) !== array_map(static fn($x) => $x['pinned'], $q['options'])
                && array_column($keyA, 0) === array_column($keyB, 0)) {
                $changes[] = 'answer order';
            }
            foreach (self::langs($o['text'] ?? [], $q['text'] ?? []) as $ql) {
                $optA = array_map(static fn($x) => $x['text'][$ql] ?? null, $o['options']);
                $optB = array_map(static fn($x) => $x['text'][$ql] ?? null, $q['options']);
                if (($o['text'][$ql] ?? null) !== ($q['text'][$ql] ?? null) || $optA !== $optB) {
                    $changes[] = 'text (' . strtoupper($ql) . ')';
                }
            }
            if ($changes !== []) {
                $items[] = ['area' => 'question', 'kind' => 'changed', 'uid' => $uid, 'label' => $label, 'fields' => array_values(array_unique($changes))];
            }
        }
        foreach ($fq as $uid => $q) {
            if (!isset($tq[$uid])) {
                $items[] = ['area' => 'question', 'kind' => 'removed', 'uid' => $uid, 'label' => self::questionLabel($q, (string) ($fc['default_language'] ?? $lang))];
            }
        }

        // --- banks (names / paths) --------------------------------------------------------
        $fb = self::byUid($from['banks'] ?? []);
        foreach (self::byUid($to['banks'] ?? []) as $uid => $b) {
            if (isset($fb[$uid]) && ($fb[$uid]['name'] !== $b['name'] || $fb[$uid]['path'] !== $b['path'])) {
                $items[] = ['area' => 'bank', 'kind' => 'changed', 'uid' => $uid, 'label' => (string) $b['path'], 'fields' => ['name']];
            }
        }

        return self::result(false, $items, $to);
    }

    private static function result(bool $first, array $items, array $to): array
    {
        $counts = ['added' => 0, 'removed' => 0, 'changed' => 0, 'moved' => 0];
        foreach ($items as $i) {
            $counts[$i['kind']]++;
        }
        return [
            'first' => $first,
            'has_changes' => $first || $items !== [],
            'items' => $items,
            'counts' => $counts,
            'totals' => [
                'lessons' => count($to['lessons'] ?? []),
                'questions' => count($to['questions'] ?? []),
                'media' => count($to['media'] ?? []),
                'languages' => $to['course']['languages'] ?? [],
            ],
        ];
    }

    private static function canonical(array $doc): array
    {
        return json_decode(Canonical::doc($doc), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array> */
    private static function byUid(array $list): array
    {
        $out = [];
        foreach ($list as $x) {
            if (isset($x['uid'])) {
                $out[(string) $x['uid']] = $x;
            }
        }
        return $out;
    }

    /** @return list<string> union of the two maps' language keys */
    private static function langs(array $a, array $b): array
    {
        $l = array_values(array_unique(array_merge(array_map('strval', array_keys($a)), array_map('strval', array_keys($b)))));
        sort($l, SORT_STRING);
        return $l;
    }

    private static function lessonTitle(array $l, string $lang): string
    {
        $v = $l['variants'][$lang] ?? (is_array($l['variants'] ?? null) && $l['variants'] !== [] ? reset($l['variants']) : null);
        $t = trim((string) ($v['title'] ?? ''));
        return $t !== '' ? $t : 'Untitled lesson';
    }

    private static function questionLabel(array $q, string $lang): string
    {
        $t = $q['text'][$lang]['q'] ?? (is_array($q['text'] ?? null) && $q['text'] !== [] ? (reset($q['text'])['q'] ?? '') : '');
        $t = trim((string) $t);
        if ($t === '') {
            return 'Untitled question';
        }
        return mb_strlen($t, 'UTF-8') > 80 ? mb_substr($t, 0, 79, 'UTF-8') . '…' : $t;
    }

    private static function courseFieldLabel(string $f): string
    {
        return match ($f) {
            'code' => 'code',
            'cover_media_id' => 'cover',
            'color' => 'color',
            'regulation_ref' => 'regulation reference',
            'sequential' => 'learning flow',
            'est_minutes' => 'estimated time',
            'validity_months', 'renewal_lead_days' => 'validity',
            'requires_signature' => 'signature',
            'is_qualification' => 'qualification',
            'components', 'eval_checklist' => 'completion rules',
            'default_language' => 'default language',
            default => $f,
        };
    }
}

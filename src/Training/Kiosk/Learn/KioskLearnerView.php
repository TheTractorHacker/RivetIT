<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Media\ArticleMediaRefs;
use ITFlow\Training\Preview\LearnerView;
use ITFlow\Training\Preview\PayloadGuard;
use ITFlow\Training\Kiosk\Core\KioskCtx;

/**
 * The player's LearnerView for the kiosk (P3 spec §3.4): Phase 1's projection of a PUBLISHED
 * revision, then re-pointed and trimmed for the kiosk:
 *   - every training media URL (/agent/training_media.php?m=N[&dl=1]) => /kiosk/media.php?m=N&r=<revision>[&dl=1],
 *     in plain fields and inside *_html (ArticleMediaRefs::rewrite);
 *   - external <a href="http…"> in *_html => <span class="kx-extlink"> (links leave the kiosk);
 *   - resources of kind 'link' removed;
 *   - every preview_enabled = false (L-17 is later: the player must not show a lesson as open
 *     that the server refuses);
 *   - content lessons with a knowledge check ('check' role) => quiz null (L-16 later);
 *   - mode 'kiosk'; endpoints point at the kiosk API (the kiosk adapter calls them itself).
 * The result passes PayloadGuard's learner_view allowlist again.
 */
final class KioskLearnerView
{
    public const ENDPOINTS = [
        'quiz_start' => '/kiosk/api.php?action=exam_start',
        'quiz_submit' => '/kiosk/api.php?action=exam_submit',
        'video_verify' => '',
    ];
    private const HTML_FIELDS = ['description_html', 'body_html', 'statement_html'];

    /**
     * @param array  $rev  RevisionCache::get() row (id, course_id, number, sha256, doc, …)
     * @param string $lang wanted language (falls back to the revision default inside LearnerView)
     */
    public static function build(KioskCtx $k, array $rev, string $lang): array
    {
        $db = $k->db();
        $doc = $rev['doc'];
        $view = LearnerView::fromBuild(['doc' => $doc, 'lesson_ids' => []], $lang, [
            'source' => 'revision',
            'course_id' => (int) $rev['course_id'],
            'revision_id' => (int) $rev['id'],
            'revision_number' => (int) $rev['number'],
            'can_verify_video' => false,
            'can_grade' => true,
            'base_url' => $k->core->baseUrl,
            'video_checks' => [],
            'media' => LearnerView::imageDims($db, $doc),
            'attestation_default' => $k->core->settings->attestationDefault ?? null,
        ]);
        $map = self::mediaUrl((int) $rev['id']);
        $view = self::rewrite($view, $map);
        $view['mode'] = 'kiosk';
        $view['endpoints'] = self::ENDPOINTS;
        foreach ($view['lessons'] as $i => $l) {
            $view['lessons'][$i]['preview_enabled'] = false;
            if ($l['type'] !== 'quiz' && is_array($l['quiz'] ?? null) && ($l['quiz']['role'] ?? '') === 'check') {
                $view['lessons'][$i]['quiz'] = null;
            }
            $res = [];
            foreach ($l['resources'] ?? [] as $r) {
                if (($r['kind'] ?? '') !== 'link') {
                    $res[] = $r;
                }
            }
            $view['lessons'][$i]['resources'] = $res;
        }
        PayloadGuard::assert($view, 'learner_view');
        return $view;
    }

    /** fn(int $mediaId, bool $download): string - the kiosk media URL for revision $revisionId. */
    public static function mediaUrl(int $revisionId): callable
    {
        return static fn(int $id, bool $dl = false): string => '/kiosk/media.php?m=' . $id . '&r=' . $revisionId . ($dl ? '&dl=1' : '');
    }

    /** Recursively re-points media URLs and neutralises external links in *_html. */
    private static function rewrite(mixed $v, callable $map, ?string $key = null): mixed
    {
        if (is_array($v)) {
            foreach ($v as $k => $item) {
                $v[$k] = self::rewrite($item, $map, is_string($k) ? $k : $key);
            }
            return $v;
        }
        if (!is_string($v)) {
            return $v;
        }
        if ($key !== null && in_array($key, self::HTML_FIELDS, true)) {
            return self::neutraliseLinks(ArticleMediaRefs::rewrite($v, static fn(int $id, bool $dl): string => $map($id, $dl)));
        }
        if (preg_match('#^/agent/training_media\.php\?m=([0-9]+)(&dl=1)?$#D', $v, $m) === 1) {
            return $map((int) $m[1], ($m[2] ?? '') !== '');
        }
        return $v;
    }

    /** External <a href="http(s)://…" or "//…"> become <span class="kx-extlink"> with the same children. */
    public static function neutraliseLinks(string $html): string
    {
        if ($html === '' || stripos($html, '<a') === false) {
            return $html;
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        try {
            $ok = $dom->loadHTML('<?xml encoding="UTF-8"?><div id="kx-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            if (!$ok) {
                return $html;
            }
            $changed = false;
            $links = [];
            foreach ($dom->getElementsByTagName('a') as $a) {
                $links[] = $a;
            }
            foreach ($links as $a) {
                $href = trim((string) $a->getAttribute('href'));
                if (preg_match('#^(https?:)?//#i', $href) !== 1 && preg_match('#^(mailto|tel|javascript|data):#i', $href) !== 1) {
                    continue;
                }
                $span = $dom->createElement('span');
                $span->setAttribute('class', 'kx-extlink');
                while ($a->firstChild !== null) {
                    $span->appendChild($a->firstChild);
                }
                $a->parentNode?->replaceChild($span, $a);
                $changed = true;
            }
            if (!$changed) {
                return $html;
            }
            $root = $dom->getElementById('kx-root');
            if ($root === null) {
                foreach ($dom->getElementsByTagName('div') as $d) {
                    if ($d->getAttribute('id') === 'kx-root') {
                        $root = $d;
                        break;
                    }
                }
            }
            if ($root === null) {
                return $html;
            }
            $out = '';
            foreach ($root->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }
}

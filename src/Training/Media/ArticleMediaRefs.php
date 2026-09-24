<?php

namespace ITFlow\Training\Media;

/**
 * Training media referenced from authored HTML: /agent/training_media.php?m=<id>[&dl=1] in an
 * <img src> or <a href> (the only two carriers ArticleSanitizer lets through).
 *
 *   extract()          ids, in document order, de-duplicated - for the revision media manifest,
 *                      the purge reference scan and LessonIssues
 *   extractDetailed()  the same with where each reference sits (img/a) and whether it is a download
 *   rewrite()          points every reference somewhere else (e.g. the Phase 3 kiosk media endpoint)
 *
 * Works on the sanitiser's output. It is tag-anchored, like src/KB/MediaUrlRewriter: it looks
 * only inside <img> / <a> start tags and tokenises their attributes left to right, so neither a
 * URL written as visible text nor one quoted inside another attribute (an alt text) is ever
 * taken for a reference or rewritten.
 */
final class ArticleMediaRefs
{
    private const TAG_RE = '/<(img|a)(?=[\s>\/])[^>]*>/i';
    private const ATTR_RE = '/\s+([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/';
    private const URL_RE = '#^/agent/training_media\.php\?m=([1-9][0-9]{0,9})(&dl=1)?$#D';

    /** @return list<int> */
    public static function extract(string $html): array
    {
        $ids = [];
        foreach (self::extractDetailed($html) as $ref) {
            $ids[$ref['id']] = true;
        }
        return array_keys($ids);
    }

    /** @return list<array{id:int, tag:string, download:bool}> */
    public static function extractDetailed(string $html): array
    {
        if ($html === '' || stripos($html, 'training_media.php') === false) {
            return [];
        }
        if (preg_match_all(self::TAG_RE, $html, $tags, PREG_SET_ORDER) === false) {
            return [];
        }
        $out = [];
        foreach ($tags as $t) {
            $tag = strtolower($t[1]);
            foreach (self::refs($t[0], $tag) as $ref) {
                $out[] = ['id' => $ref['id'], 'tag' => $tag, 'download' => $ref['download']];
            }
        }
        return $out;
    }

    /**
     * Replaces each reference with $idToUrl($id, $download) (a root-relative or absolute URL;
     * it is HTML-escaped here). Everything else in the document is left byte-identical.
     */
    public static function rewrite(string $html, callable $idToUrl): string
    {
        if ($html === '' || stripos($html, 'training_media.php') === false) {
            return $html;
        }
        $out = preg_replace_callback(self::TAG_RE, static function (array $t) use ($idToUrl): string {
            $tagHtml = $t[0];
            $refs = self::refs($tagHtml, strtolower($t[1]));
            // Replace from the end so earlier offsets stay valid.
            foreach (array_reverse($refs) as $ref) {
                $new = (string) $idToUrl($ref['id'], $ref['download']);
                $attr = $ref['name'] . '="' . htmlspecialchars($new, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
                $tagHtml = substr_replace($tagHtml, $attr, $ref['offset'], $ref['length']);
            }
            return $tagHtml;
        }, $html);
        return $out ?? $html;
    }

    /** @return list<array{id:int, download:bool, name:string, offset:int, length:int}> */
    private static function refs(string $tagHtml, string $tag): array
    {
        $want = $tag === 'img' ? 'src' : 'href';
        $nameLen = strlen($tag) + 1;   // "<img" / "<a"
        if (preg_match_all(self::ATTR_RE, $tagHtml, $attrs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $nameLen) === false) {
            return [];
        }
        $out = [];
        foreach ($attrs as $a) {
            if (strtolower($a[1][0]) !== $want) {
                continue;
            }
            $raw = null;
            foreach ([2, 3, 4] as $g) {
                if (isset($a[$g]) && $a[$g][1] >= 0) {
                    $raw = $a[$g][0];
                    break;
                }
            }
            if ($raw === null) {
                continue;
            }
            $url = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match(self::URL_RE, $url, $m) !== 1) {
                continue;
            }
            // The attribute text starts after the leading whitespace the pattern consumed.
            $full = $a[0][0];
            $lead = strlen($full) - strlen(ltrim($full));
            $out[] = ['id' => (int) $m[1], 'download' => ($m[2] ?? '') !== '', 'name' => $a[1][0],
                      'offset' => $a[0][1] + $lead, 'length' => strlen($full) - $lead];
        }
        return $out;
    }
}

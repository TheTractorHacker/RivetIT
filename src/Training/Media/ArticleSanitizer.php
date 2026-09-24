<?php

namespace ITFlow\Training\Media;

/**
 * The sanitiser for every piece of authored HTML in Training - article bodies, descriptions,
 * acknowledgment statements, course descriptions (spec §3.3, §8 "XSS"). It runs when the author
 * saves (the lesson/course services) AND again when a learner view is projected, so stored HTML
 * is never trusted on its way out.
 *
 * Stage 0 (raw input, DOM): interactive-KB embed blocks (data-ikb="embed") are removed whole -
 *   they point at KB-only sandboxed pages - and counted; other interactive blocks are flattened
 *   by stage 1 (the training purifier does not register the KB vocabulary).
 * Stage 1, HTMLPurifier: the Knowledge Base configuration (agent/kb_article.php) minus
 *   InteractiveBlocks::apply(), with URI.AllowedSchemes http/https/mailto, Attr.DefaultImageAlt
 *   '', Attr.AllowedFrameTargets ['_blank'], HTML.TargetNoreferrer, and a CLASS ALLOWLIST
 *   (ALLOWED_CLASSES) so author markup cannot borrow page chrome like position-fixed or
 *   stretched-link.
 * Stage 2, URI pass (DOM) over the purifier's output:
 *   <img src>  only /agent/training_media.php?m=<id> whose media kind is image or page; any
 *              other image - external, data:, /logout.php beacons, kb_media.php hotlinks - is removed
 *   <a href>   https:, mailto:, or /agent/training_media.php?m=<id>[&dl=1] whose kind is file or
 *              pdf; any other link is unwrapped (its text stays)
 *   CSS url(), cite, longdesc and every other URL-bearing attribute are removed
 *
 * The kind check needs the database, so callers pass $mediaKindOf (MediaStore::kindLookup());
 * without it only the URL shape is enforced.
 *
 * SIZE. HTMLPurifier's memory grows with the number of elements: measured on this box, 512 KB of
 * the densest inline markup peaks near 170 MB (ordinary paragraphs: about 22 MB). One cap,
 * MAX_HTML_BYTES, therefore applies at every entry point - the save patch (Authoring\Patch::html),
 * DOCX import and KB import - so nothing is stored that cannot be saved again or projected, and
 * purify() raises the request's memory_limit to MEMORY_FLOOR for inputs past 64 KB (only ever
 * raising it; FPM's default is 128M).
 */
final class ArticleSanitizer
{
    public const ALLOWED_CLASSES = ['table', 'table-bordered', 'table-striped', 'table-sm', 'text-start', 'text-center', 'text-end',
        'fw-bold', 'fst-italic', 'small', 'lead', 'img-fluid', 'tr-callout', 'tr-callout--info', 'tr-callout--warning', 'tr-callout--danger'];

    /** The largest authored HTML field, in bytes, anywhere in Training. */
    public const MAX_HTML_BYTES = 524288;
    /** An importer refuses source HTML larger than this before any work (the result must still fit MAX_HTML_BYTES). */
    public const MAX_SOURCE_HTML_BYTES = 1048576;
    private const MEMORY_FLOOR = 268435456;

    public const MEDIA_SRC_RE = '#^/agent/training_media\.php\?m=([1-9][0-9]{0,9})$#D';
    public const MEDIA_HREF_RE = '#^/agent/training_media\.php\?m=([1-9][0-9]{0,9})(&dl=1)?$#D';

    /** Attributes that can carry a URL; outside <img src> / <a href> they are dropped outright. */
    private const URL_ATTRS = ['src', 'href', 'cite', 'longdesc', 'background', 'action', 'formaction', 'data', 'poster', 'srcset',
        'usemap', 'codebase', 'classid', 'archive', 'profile', 'manifest', 'dynsrc', 'lowsrc', 'xlink:href', 'ping'];

    private static ?\HTMLPurifier $purifier = null;

    /**
     * @param (\Closure(int):?string)|null $mediaKindOf media id => kind (null when it does not exist)
     * @return array{html:string, removed:array{external_image:int, link:int, class:int, ikb:int}}
     */
    public static function purify(string $html, ?\Closure $mediaKindOf = null): array
    {
        $removed = ['external_image' => 0, 'link' => 0, 'class' => 0, 'ikb' => 0];
        if (trim($html) === '') {
            return ['html' => '', 'removed' => $removed];
        }
        self::ensureMemory(strlen($html));
        $pre = self::prePass($html);
        $removed['ikb'] = $pre['ikb'];
        $removed['class'] = $pre['class'];

        $clean = self::purifier()->purify($pre['html']);
        $post = self::uriPass($clean, $mediaKindOf);

        $removed['external_image'] = max(0, $pre['img'] - $post['img']) + $post['css_urls'];
        $removed['link'] = max(0, $pre['links'] - $post['links']);
        return ['html' => $post['html'], 'removed' => $removed];
    }

    /**
     * Stages 0 and 1 only - the purifier, without the URI pass. For importers that must still
     * see the source's own media URLs (KbSnapshot maps KB media before the full purify(), which
     * must still run on the result).
     *
     * @return array{html:string, removed:array{class:int, ikb:int}}
     */
    public static function purifyMarkup(string $html): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'removed' => ['class' => 0, 'ikb' => 0]];
        }
        self::ensureMemory(strlen($html));
        $pre = self::prePass($html);
        return ['html' => self::purifier()->purify($pre['html']), 'removed' => ['class' => $pre['class'], 'ikb' => $pre['ikb']]];
    }

    /** Raises memory_limit to MEMORY_FLOOR for a large input (never lowers it; -1 = unlimited stays). */
    private static function ensureMemory(int $bytes): void
    {
        if ($bytes <= 65536) {
            return;
        }
        $cur = trim((string) ini_get('memory_limit'));
        if ($cur === '' || $cur === '-1') {
            return;
        }
        $n = (int) $cur;
        $n *= match (strtolower(substr($cur, -1))) {
            'g' => 1073741824,
            'm' => 1048576,
            'k' => 1024,
            default => 1,
        };
        if ($n > 0 && $n < self::MEMORY_FLOOR) {
            @ini_set('memory_limit', (string) self::MEMORY_FLOOR);
        }
    }

    /**
     * Publish-blocking problems in stored HTML (both errors at publish, spec §3.5):
     *   article_data_uri       an image or link embedded as a data: URI (never uploaded)
     *   article_foreign_media  an image, embed or link to media that is not a training upload
     *
     * @return list<array{code:string, severity:string, message:string, count:int}>
     */
    public static function issues(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $doc = self::load($html);
        $dataUri = 0;
        $foreign = 0;
        foreach (self::elements($doc) as $el) {
            $tag = strtolower($el->tagName);
            if (in_array($tag, ['video', 'audio', 'iframe', 'embed', 'object', 'source', 'picture', 'svg', 'canvas'], true)) {
                $foreign++;
            }
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                $val = trim((string) $attr->nodeValue);
                if (in_array($name, self::URL_ATTRS, true) || $name === 'style') {
                    if (preg_match('/^\s*data:|url\(\s*[\'"]?\s*data:/i', $val) === 1) {
                        $dataUri++;
                        continue;
                    }
                }
                if ($name === 'style' && stripos($val, 'url(') !== false) {
                    $foreign++;
                } elseif ($tag === 'img' && $name === 'src' && preg_match(self::MEDIA_SRC_RE, $val) !== 1) {
                    $foreign++;
                } elseif ($tag === 'a' && $name === 'href' && !self::linkAllowed($val, null)
                          && preg_match('#(kb_media\.php|/uploads/|training_media\.php)#i', $val) === 1) {
                    $foreign++;
                }
            }
        }
        $out = [];
        if ($dataUri > 0) {
            $out[] = ['code' => 'article_data_uri', 'severity' => 'error', 'count' => $dataUri,
                      'message' => 'A picture is pasted into the text as data. Insert it again with the image button so it is uploaded.'];
        }
        if ($foreign > 0) {
            $out[] = ['code' => 'article_foreign_media', 'severity' => 'error', 'count' => $foreign,
                      'message' => 'The text shows a picture or file from outside Training. Upload it here instead.'];
        }
        return $out;
    }

    /** Words a reader reads (used for "≈ 4 min read" and the duration estimate). */
    public static function wordCount(string $html): int
    {
        if (trim($html) === '') {
            return 0;
        }
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $n = preg_match_all('/[\p{L}\p{N}]+(?:[\'’.\-][\p{L}\p{N}]+)*/u', $text);
        return $n === false ? 0 : $n;
    }

    // ---------------------------------------------------------------------------------------

    /**
     * Stage 0 on the raw input: drop embed blocks, count images, links and non-allowlisted
     * classes (for the author-facing "removed" counts). Re-serialises only when it removed
     * something, so ordinary input reaches the purifier byte-identical.
     *
     * @return array{html:string, ikb:int, img:int, links:int, class:int}
     */
    private static function prePass(string $html): array
    {
        $doc = self::load($html);
        $ikb = 0;
        foreach (self::elements($doc) as $el) {
            if ($el->parentNode !== null && strtolower(trim($el->getAttribute('data-ikb'))) === 'embed') {
                $el->parentNode->removeChild($el);
                $ikb++;
            }
        }
        $img = 0;
        $links = 0;
        $class = 0;
        foreach (self::elements($doc) as $el) {
            $tag = strtolower($el->tagName);
            if ($tag === 'img') {
                $img++;
            } elseif ($tag === 'a' && $el->hasAttribute('href')) {
                $links++;
            }
            if ($el->hasAttribute('class')) {
                foreach (preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [] as $cls) {
                    // ikb-* classes belong to flattened interactive blocks: expected, not worth a warning.
                    if ($cls !== '' && !in_array($cls, self::ALLOWED_CLASSES, true) && $cls !== 'ikb' && !str_starts_with($cls, 'ikb-')) {
                        $class++;
                    }
                }
            }
        }
        return ['html' => $ikb > 0 ? self::save($doc) : $html, 'ikb' => $ikb, 'img' => $img, 'links' => $links, 'class' => $class];
    }

    /** @return array{html:string, img:int, links:int, css_urls:int} */
    private static function uriPass(string $html, ?\Closure $kindOf): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'img' => 0, 'links' => 0, 'css_urls' => 0];
        }
        $doc = self::load($html);
        $cssUrls = 0;
        foreach (self::elements($doc) as $el) {
            if ($el->parentNode === null) {
                continue;
            }
            $tag = strtolower($el->tagName);
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                if ($name === 'style') {
                    [$style, $n] = self::stripCssUrls((string) $attr->nodeValue);
                    $cssUrls += $n;
                    if ($style === '') {
                        $el->removeAttribute($attr->nodeName);
                    } elseif ($n > 0) {
                        $el->setAttribute($attr->nodeName, $style);
                    }
                } elseif (in_array($name, self::URL_ATTRS, true) && !($tag === 'img' && $name === 'src') && !($tag === 'a' && $name === 'href')) {
                    $el->removeAttribute($attr->nodeName);
                }
            }
            if ($tag === 'img') {
                $src = trim($el->getAttribute('src'));
                if (!self::imageAllowed($src, $kindOf)) {
                    $el->parentNode->removeChild($el);
                }
            } elseif ($tag === 'a') {
                $href = trim($el->getAttribute('href'));
                if ($href === '' || !self::linkAllowed($href, $kindOf)) {
                    self::unwrap($el);
                } elseif ($el->hasAttribute('target') && $el->getAttribute('target') !== '_blank') {
                    $el->removeAttribute('target');
                }
            }
        }
        $img = 0;
        $links = 0;
        foreach (self::elements($doc) as $el) {
            $tag = strtolower($el->tagName);
            if ($tag === 'img') {
                $img++;
            } elseif ($tag === 'a' && $el->hasAttribute('href')) {
                $links++;
            }
        }
        return ['html' => self::save($doc), 'img' => $img, 'links' => $links, 'css_urls' => $cssUrls];
    }

    private static function imageAllowed(string $src, ?\Closure $kindOf): bool
    {
        if (preg_match(self::MEDIA_SRC_RE, $src, $m) !== 1) {
            return false;
        }
        if ($kindOf === null) {
            return true;
        }
        return in_array($kindOf((int) $m[1]), ['image', 'page'], true);
    }

    private static function linkAllowed(string $href, ?\Closure $kindOf): bool
    {
        if (preg_match(self::MEDIA_HREF_RE, $href, $m) === 1) {
            return $kindOf === null || in_array($kindOf((int) $m[1]), ['file', 'pdf'], true);
        }
        if (preg_match('/^mailto:[^\s<>"]+$/iD', $href) === 1) {
            return true;
        }
        if (preg_match('#^https://#i', $href) === 1) {
            $p = parse_url($href);
            return $p !== false && isset($p['host']) && $p['host'] !== '' && !isset($p['user']) && !isset($p['pass']);
        }
        return false;
    }

    /** Removes every CSS declaration that contains url(...). @return array{0:string, 1:int} */
    private static function stripCssUrls(string $style): array
    {
        if (stripos($style, 'url') === false && stripos($style, '\\') === false) {
            return [trim($style), 0];
        }
        $kept = [];
        $n = 0;
        foreach (explode(';', $style) as $decl) {
            if (trim($decl) === '') {
                continue;
            }
            // Backslash escapes can spell url( in CSS; a declaration using them is dropped too.
            if (stripos($decl, 'url') !== false || str_contains($decl, '\\')) {
                $n++;
                continue;
            }
            $kept[] = trim($decl);
        }
        return [implode('; ', $kept) . ($kept === [] ? '' : ';'), $n];
    }

    private static function unwrap(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }
        if (!class_exists('HTMLPurifier', false)) {
            require_once dirname(__DIR__, 3) . '/plugins/htmlpurifier/HTMLPurifier.standalone.php';
        }
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.DefaultImageAlt', '');
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('HTML.TargetNoopener', true);
        $config->set('Attr.AllowedClasses', self::ALLOWED_CLASSES);
        return self::$purifier = new \HTMLPurifier($config);
    }

    private static function load(string $html): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
                . mb_scrub($html, 'UTF-8') . '</body></html>', LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        return $doc;
    }

    private static function save(\DOMDocument $doc): string
    {
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        $out = '';
        foreach ($body->childNodes as $n) {
            $out .= $doc->saveHTML($n);
        }
        return $out;
    }

    /** @return list<\DOMElement> every element under <body>, in document order (a static copy) */
    private static function elements(\DOMDocument $doc): array
    {
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return [];
        }
        $out = [];
        foreach ($body->getElementsByTagName('*') as $el) {
            $out[] = $el;
        }
        return $out;
    }
}

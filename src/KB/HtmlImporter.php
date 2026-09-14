<?php

namespace ITFlow\KB;

/**
 * HTML page -> Knowledge Base article, the third importer beside DocxConverter
 * and PdfConverter.
 *
 * SAME CONTRACT AS ITS TWO SIBLINGS, deliberately:
 *
 *     HtmlImporter::convert(string $path, string $mode = 'blocks', array $options = [])
 *         : array{html, text, media, warnings, embed, title, stats}
 *
 * It never writes to disk, never touches the database, and never fetches a
 * remote resource. Images come back as bytes in memory with an opaque
 * per-conversion token in the HTML as <img src="TOKEN">; the CALLER writes them
 * and substitutes the real URL. That is what lets agent/post/kb_article.php run
 * this through the identical plumbing the DOCX and PDF importers already use.
 *
 * WHAT MAKES THIS ONE DIFFERENT FROM THE OTHER TWO
 * ------------------------------------------------
 * A .docx and a .pdf carry no structure this application has a home for beyond
 * prose. HTML does. A page that already says "these are collapsible sections",
 * "these are tabs", "this is a task list" is describing exactly the interactive
 * vocabulary in src/KB/InteractiveBlocks.php, so this importer RECOGNISES those
 * shapes and emits real blocks instead of flattening them into paragraphs.
 * HTMLPurifier 4.15.0 unwraps <details> and deletes <input> (measured; see
 * InteractiveBlocks' header), so an HTML import that did NOT do this would
 * silently lose the collapsibility and the tick boxes of every page it touched.
 *
 *   <details><summary>X</summary>Y</details> runs -> sequence/accordion
 *   Bootstrap .nav-tabs and ARIA [role=tablist] widgets -> sequence/tabs
 *   task lists (<input type=checkbox> or "[ ]" / "[x]") -> sequence/checklist
 *   a short list under a "checklist/steps/procedure/runbook/how to" heading
 *                                                        -> sequence/checklist
 *                                        or, if the heading says "step(s)", steps
 *   a run of <section>s that each open with a heading    -> sequence/steps
 *   a run of "Step 1 ... Step 2 ..." headings            -> sequence/steps
 *   <pre> / <pre><code>                                  -> <pre data-ikb="copy">
 *   everything else                                      -> ordinary article HTML
 *
 * DECISION TREES ARE NEVER IMPORTED. No HTML shape reliably encodes a branching
 * troubleshooter, and a wrong guess would land in stored content where a reader
 * would follow it. Trees are authored, full stop.
 *
 * THREAT MODEL - the input is a file a user uploaded, and this is the only
 * importer whose input format is itself an attack language.
 * ---------------------------------------------------------------------------
 * 1. XXE / entity expansion.  Any '<!ENTITY' anywhere in the bytes, and any
 *    DOCTYPE carrying an internal subset, is a HARD REJECT before the parser
 *    runs. Then, for the parse itself: the external entity loader is replaced
 *    with one that returns null, LIBXML_NONET is passed, resolveExternals and
 *    substituteEntities are false and LIBXML_NOENT is deliberately NOT passed -
 *    so there is no expansion step for a billion-laughs document to blow up.
 *    Four layers, the same four DocxConverter::loadXml() documents.
 *
 * 2. Resource fetching at import time.  NOTHING is fetched, ever. No remote
 *    image, stylesheet, script or font is retrieved; a remote <img> keeps its
 *    original URL and is reported, and every other remote reference is dropped.
 *    A page that could make the SERVER fetch a URL would be an SSRF gadget
 *    pointed at the LAN this application sits on.
 *
 * 3. Local file reading.  Also nothing. A relative <img src="images/x.png"> is
 *    DROPPED, not resolved - resolving it would mean reading a path derived
 *    from an uploaded document off the server's filesystem, which is a local
 *    file read with the attacker choosing the file. This is a deliberate
 *    departure from the design sketch, which proposed importing "same-directory
 *    relative files"; a browser uploads one file, so there is no directory to
 *    be in, and the only thing that path could ever reach is the server's own
 *    disk. data: URIs are the one image source that IS imported, because the
 *    bytes are in the document.
 *
 * 4. HTML injection.  Every text node and every attribute value is escaped with
 *    htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, UTF-8), and the emitter can
 *    only ever produce the closed literal tag set in self::ALLOWED_TAGS_NOTE
 *    with the closed attribute set href/src/alt/colspan/rowspan/class plus the
 *    data-ikb-* vocabulary, whose values are an enum, a minted key, or a bounded
 *    integer - never anything from the document. <script>, <style>, style="",
 *    every event handler, <form> and friends never reach the emitter at all.
 *    This is independent of the HTMLPurifier pass the caller runs; two locks.
 *
 * 5. Resource exhaustion.  A 2 MiB input cap, a 20,000 node cap, a depth cap of
 *    64, a 200 block cap, a 32-tab-per-widget cap with pane dedupe, a 4 MiB
 *    output cap and the DOCX importer's image budgets. Every one of them is
 *    checked, not assumed. See the constants - and MAX_TABS's comment
 *    specifically for the one that WASN'T checked until this file's own
 *    review measured a single tab widget rendering its pane thousands of
 *    times over.
 *
 * THE ESCAPE HATCH (mode 'embed')
 * ---------------------------------------------------------------------------
 * Some pages ARE the tool - a subnet calculator, a decision aid, a form that
 * computes something. Flattening one into prose destroys it. In 'embed' mode
 * this class does not convert anything: it returns the file's ORIGINAL BYTES
 * untouched in ['embed'], for the caller to store in kb_article_embeds, where
 * agent/kb_embed.php and client/kb_embed.php serve them into an opaque-origin
 * sandbox under their own Content-Security-Policy. The bytes must not be
 * modified here - that column is documented as the one place in the database
 * holding HTML no filter ever touched, and the containment is entirely in those
 * endpoints' headers.
 *
 * Because an embed's content can never be purified it can never be indexed
 * either, so 'text' comes back as the page's plain text for the caller to fold
 * into kb_articles.kb_article_content_raw, and the block the caller writes into
 * the article carries a real name and description as a TEXT NODE. Both are the
 * only things about an embed that FULLTEXT will ever see.
 */
final class HtmlImporter
{
    /** Documentation only - the emitter is a closed set of literal strings. */
    public const ALLOWED_TAGS_NOTE = 'p h1 h2 h3 h4 h5 h6 strong em u s br ul ol li table tr th td a img code pre div hr blockquote h4/h5(block furniture)';

    public const MODE_BLOCKS = 'blocks';
    public const MODE_EMBED  = 'embed';

    // ---- Budgets ---------------------------------------------------------
    //
    // MAX_INPUT_BYTES was originally 2 MiB, sized for a THEN-current 128M
    // php-fpm memory_limit. Raised to 50 MiB once the host had real headroom
    // to spend on it (16 GB RAM) - the real-world case this serves is a wiki/
    // Confluence/OneNote export with several full-resolution screenshots
    // embedded as base64 <img> data, which bloats raw file size far more than
    // it bloats actual article content (images are extracted out, not stored
    // as article HTML - see MAX_MEDIA_BYTES_TOTAL below). It is still not
    // unlimited: a DOM lives in libxml's own heap rather than in PHP's
    // allocator, so php.ini memory_limit does not bound it - only this does -
    // and agent/post/kb_article.php raises ITS OWN request's memory_limit to
    // 512M right before calling convert() for exactly this reason (a dense
    // TEXT page, not an image-heavy one, genuinely needs it - see below).
    //
    // MEASURED ON THIS BOX (PHP 8.4.25, libxml 2.9.14), max RSS from
    // /usr/bin/time, against an empty `php -r` baseline of 34.4 MB, at the
    // original 2 MiB ceiling:
    //      2,097,061 bytes /  19,006 elements     203 ms    55.6 MB  (+21 MB)
    //        205,826 bytes /  19,602 elements     126 ms    43.9 MB  (+9.5 MB)
    // Re-measured at the current 50 MiB ceiling, same box, same method, two
    // realistic shapes:
    //     51,380,261 bytes / 335,077 elements   4,216 ms   369.2 MB  - dense
    //         text/tables, the worst case for this cap. Needed
    //         agent/post/kb_article.php's request-scoped memory_limit raise
    //         to 512M to finish; at the old 128M default it fatals building
    //         the output string (libxml's own heap stays outside
    //         memory_limit, as above, but the plain PHP strings this class
    //         builds from it do not).
    //     50,333,408 bytes /      37 elements   1,432 ms   237.9 MB  - 12
    //         embedded screenshots, the realistic motivating case (a wiki/
    //         Confluence/OneNote export whose size is almost entirely
    //         base64 image data, not text). Comfortably fits even the old
    //         128M default, since the actual DOM here stays tiny.
    // A knowledge-base page exported from any wiki is far under this; a page
    // that is not is a page that wanted to be an attachment.
    private const MAX_INPUT_BYTES = 52428800;   // 50 MiB

    // The serve-side restatement of this exact number is at
    // agent/includes/kb_embed_serve.php:150 (KB_EMBED_MAX_BYTES). Both must
    // agree or a stored embed becomes a 413 nobody can explain.
    //
    // Matches MAX_INPUT_BYTES above - raised from the original 512 KiB
    // (picked at design time with no measurement behind it, unlike every
    // other budget in this file) once nothing else turned out to actually
    // cap it: kb_article_embed_untrusted_html is longtext (DB migration
    // 2.6.82 -> 2.6.83, was mediumtext), delivery is a real HTTP response
    // via iframe src= (no srcdoc/data: URI size ceiling in play), and
    // max_allowed_packet was raised to 64 MiB to clear this with room for
    // mysqli_real_escape_string() overhead on the INSERT in
    // agent/post/kb_article.php.
    private const MAX_EMBED_BYTES = 52428800;   // 50 MiB

    // Structural caps. MAX_NODES was originally 20,000 (the second half of
    // the ORIGINAL 2 MiB measurement above - 19,602 of them cost 126 ms),
    // checked immediately after the parse so nothing walks a document
    // bigger than this. Raised to 500,000 alongside MAX_INPUT_BYTES: the
    // dense-text 50 MiB fixture measured above has 335,077 elements, so
    // 500,000 keeps real headroom above the worst realistic case rather
    // than sitting flush against one measurement. Time still scales with
    // node count the way the original measurement predicted (126 ms per
    // ~19,600 nodes extrapolates to ~2.1 s at 335,077; measured 4,216 ms -
    // higher because the OUTPUT this fixture produces is also large, not
    // because the walk itself is slower than predicted). 64 is deeper than
    // any hand-written or generated page; measured against 5,000 nested
    // <div>s, the depth guard returns empty from the walk rather than
    // recursing until the stack dies (that fixture is refused as "no
    // importable content", which is the right answer for a page whose only
    // text is 5,000 levels down).
    private const MAX_NODES  = 500000;
    private const MAX_DEPTH  = 64;

    // At most this many interactive blocks per article. A page of 5,000
    // <details> elements would otherwise become 5,000 blocks, each of which the
    // render layer binds and each of which can hold a reader's saved progress.
    private const MAX_BLOCKS = 200;

    // At most this many TABS in one tabs widget. Unlike every other structural
    // cap in this file, this one does not bound the SOURCE tree (MAX_NODES
    // already does that) or the number of blocks (MAX_BLOCKS does) - it bounds
    // how many times detectTabs() renders a PANE, because a pane is looked up
    // by id and nothing stopped more than one tab from naming the same one.
    // MEASURED, PHP 8.4.25, before this cap (and before the dedupe below)
    // existed: a 234,101-byte, 18,002-element fixture - one div.nav-tabs of
    // 9,000 <a href="#p">, all naming the SAME div#p of 9,000 <p> - did not
    // finish converting in 100 s and was killed; a 461,899-byte, 16,006-element
    // fixture of the same shape took 160.1 s and produced 4,410,755 bytes of
    // output for what should have been a two-pane widget. Both are UNDER the
    // 2 MiB byte cap and the 20,000-element node cap, so neither of those caps
    // ever saw the problem: the walk that builds $tabs is O(tabs in the
    // widget), fine on its own, but rendering the shared pane once PER TAB
    // makes the real cost O(tabs x pane size) - quadratic in the fixture above,
    // since the pane grows with the tab count too. 32 is far above any
    // hand-authored or generated tab widget (real ones measured in this
    // project's own fixtures run single digits) and, combined with the dedupe
    // in detectTabs() (a pane referenced by more than one tab renders ONCE,
    // regardless of how many tabs name it), bounds the worst case to 32 renders
    // of 32 DISTINCT panes even when nothing is shared - fast by construction,
    // not by luck.
    private const MAX_TABS = 32;

    // Same numbers as DocxConverter, so a document that imports one way imports
    // the other. kb_articles.kb_article_content is a MEDIUMTEXT (16 MiB) and the
    // caller escapes the HTML twice over before it reaches the column.
    private const MAX_HTML_BYTES        = 4194304;   //  4 MiB
    private const MAX_IMAGES            = 100;
    private const MAX_MEDIA_BYTES_EACH  = 8388608;   //  8 MiB
    private const MAX_MEDIA_BYTES_TOTAL = 25165824;  // 24 MiB

    /** Plain-text feed for the FULLTEXT index. */
    private const MAX_TEXT_BYTES = 1048576;          //  1 MiB
    /** How much of an embedded page's text the caller may fold into the index. */
    private const MAX_EMBED_TEXT_BYTES = 8192;       //  8 KiB

    /**
     * A list item longer than this is prose, not a step. Used only by the
     * heading-led heuristic (rule D), which is the one mapping that guesses;
     * measured against real runbooks, real checklist items run 20-70
     * characters and the first thing that goes over 120 is always a paragraph
     * that happened to be in a list.
     */
    private const SHORT_ITEM_CHARS = 120;

    /** Longest text this class will lift out of a document to use as a label. */
    private const MAX_LABEL_CHARS = 160;

    /** Deepest nesting at which a run of siblings is still examined for blocks. */
    private const MAX_BLOCK_DEPTH = 6;

    // ---- Accepted media (identical to DocxConverter and PdfConverter) ------
    private const IMAGE_MIME_EXT = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/bmp'  => 'bmp',
    ];
    private const IMAGE_TYPES = [
        IMAGETYPE_PNG,
        IMAGETYPE_JPEG,
        IMAGETYPE_GIF,
        IMAGETYPE_WEBP,
        IMAGETYPE_BMP,
    ];

    /**
     * Elements whose CONTENT is thrown away, not unwrapped, with the count
     * reported. Everything here is either executable, presentational-only under
     * a policy that forbids it, or an element the vocabulary has no home for.
     * <noscript> is here because libxml hands its body back as TEXT, so
     * unwrapping it would paste raw markup into the article as visible prose.
     */
    private const DROP_ELEMENTS = [
        'script' => 'scripts', 'style' => 'stylesheets', 'link' => 'stylesheets',
        'meta' => null, 'base' => null, 'title' => null, 'noscript' => 'scripts',
        'template' => null, 'iframe' => 'frames', 'frame' => 'frames',
        'frameset' => 'frames', 'object' => 'frames', 'embed' => 'frames',
        'applet' => 'frames', 'canvas' => 'drawings', 'svg' => 'drawings',
        'math' => 'drawings', 'video' => 'media players', 'audio' => 'media players',
        'source' => null, 'track' => null, 'map' => null, 'area' => null,
        'form' => 'forms', 'input' => 'forms', 'select' => 'forms',
        'option' => null, 'optgroup' => null, 'textarea' => 'forms',
        'button' => 'forms', 'label' => null, 'fieldset' => null,
        'legend' => null, 'datalist' => null, 'output' => null,
        'progress' => null, 'meter' => null, 'dialog' => null, 'menu' => null,
    ];

    /** Elements that start a new line in the output; everything else is inline. */
    private const BLOCK_ELEMENTS = [
        'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'main', 'nav', 'ol', 'p',
        'pre', 'section', 'table', 'ul', 'dd', 'dt', 'li', 'tr', 'td', 'th',
        'tbody', 'thead', 'tfoot', 'caption', 'colgroup', 'col', 'summary',
        'body', 'html', 'head', 'script', 'style', 'iframe', 'noscript',
        'template', 'canvas', 'svg', 'video', 'audio', 'object', 'dialog',
        'menu', 'legend',
    ];

    /** @var array<int,array{token:string,extension:string,mime:string,bytes:string}> */
    private array $media = [];
    /** @var array<string,string> data: URI sha1 => already-issued token */
    private array $mediaByHash = [];
    /** @var array<int,string> */
    private array $warnings = [];
    /** @var array<string,int> */
    private array $dropped = [];
    /** @var array<string,int> */
    private array $stats = [];
    /** @var array<string,\DOMElement> id => element, built once per conversion */
    private array $idMap = [];
    /** @var array<int,\DOMElement> every tab strip in the document, built once */
    private array $tablists = [];

    private string $tokenPrefix = '';
    private int $mediaBytesUsed = 0;
    private int $htmlBytesUsed = 0;
    private int $blockCount = 0;
    private bool $truncated = false;
    private bool $mapBlocks = true;
    /** Set once by renderLabel() when a label had to flatten block markup. */
    private bool $labelLostStructure = false;

    /**
     * Convert an uploaded HTML file.
     *
     * @param  string $path    Absolute path to the upload (a $_FILES tmp_name).
     * @param  string $mode    self::MODE_BLOCKS or self::MODE_EMBED.
     * @param  array  $options ['map_blocks' => bool] - false leaves every list,
     *                         heading and <details> as ordinary content, for
     *                         when the recogniser guesses wrong on a page.
     * @return array{html:string,text:string,media:array,warnings:array,embed:?string,title:?string,stats:array}
     * @throws HtmlImportException on anything malformed, oversized or hostile.
     */
    public static function convert(string $path, string $mode = self::MODE_BLOCKS, array $options = []): array
    {
        return (new self())->run($path, $mode, $options);
    }

    /**
     * @return array{html:string,text:string,media:array,warnings:array,embed:?string,title:?string,stats:array}
     * @throws HtmlImportException
     */
    private function run(string $path, string $mode, array $options): array
    {
        if ($mode !== self::MODE_BLOCKS && $mode !== self::MODE_EMBED) {
            throw new HtmlImportException('That import mode is not one this server knows about.');
        }

        $this->mapBlocks   = (bool) ($options['map_blocks'] ?? true);
        $this->tokenPrefix = 'itflow-html-media-' . bin2hex(random_bytes(16)) . '-';

        $raw = $this->readUpload($path);

        // ---- The two hard rejects, on the BYTES, before any parser sees them.
        $this->refuseEntities($raw);

        $utf8 = $this->toUtf8($raw);
        $doc  = $this->parse($utf8);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            throw new HtmlImportException('That file has no readable page content in it.');
        }

        $title = $this->documentTitle($doc);
        $text  = $this->plainText($body, self::MAX_TEXT_BYTES);

        $this->scanForCagedApis($utf8, $mode);

        if ($mode === self::MODE_EMBED) {
            if (strlen($raw) > self::MAX_EMBED_BYTES) {
                throw new HtmlImportException(
                    'That page is ' . $this->megabytes(strlen($raw)) . ' and an embedded tool may be at most '
                    . $this->megabytes(self::MAX_EMBED_BYTES) . '. Import it as article content instead, or trim the page.'
                );
            }
            if (trim($text) === '' && stripos($utf8, '<script') === false) {
                throw new HtmlImportException('That page is empty - there is nothing to embed.');
            }

            /* THE BYTES GO IN A utf8mb4 COLUMN, VERBATIM. Everything else in
               this class works on the UTF-8 conversion, but an embed is stored
               exactly as uploaded - so a page saved as Windows-1252 or UTF-16
               would reach a mediumtext column as invalid UTF-8 and, under
               STRICT_TRANS_TABLES, be a mysqli ERROR rather than a truncation.
               Refused here, where the message can say what to do about it,
               rather than three layers down as "the embedded page could not be
               stored". Converting instead is not an option: rewriting the bytes
               of a document whose own <meta charset> then disagrees with them
               would break the tool in the frame. */
            if (!mb_check_encoding($raw, 'UTF-8')) {
                throw new HtmlImportException(
                    'That page is not saved as UTF-8, and an embedded tool is stored exactly as uploaded. '
                    . 'Re-save it as UTF-8 and import it again, or import it as article content instead.'
                );
            }

            $this->stats['mode'] = 'embed';
            return [
                'html'     => '',
                // The caller folds this into kb_article_content_raw; it is the
                // ONLY thing about an embed FULLTEXT will ever be able to find.
                'text'     => $this->plainText($body, self::MAX_EMBED_TEXT_BYTES),
                'media'    => [],
                'warnings' => $this->warnings,
                // ORIGINAL BYTES, byte-for-byte. See the file header.
                'embed'    => $raw,
                'title'    => $title,
                'stats'    => $this->stats,
            ];
        }

        $this->indexIds($doc);
        $this->countDroppableElements($doc);

        $html = trim($this->renderFlow($this->childArray($body), 0));

        if ($html === '') {
            throw new HtmlImportException('That page has no importable content in it - nothing was imported.');
        }

        if ($this->truncated) {
            $this->warn('The page was longer than the ' . round(self::MAX_HTML_BYTES / 1048576) . ' MB import limit and was truncated.');
        }
        if ($this->labelLostStructure) {
            $this->warn('One or more section titles contained formatting this importer cannot keep in a title (a list, a table, or similar) - only the words were kept.');
        }

        $this->stats['mode'] = 'blocks';
        $this->reportDropped();

        return [
            'html'     => $html,
            'text'     => $text,
            'media'    => array_values($this->media),
            'warnings' => $this->warnings,
            'embed'    => null,
            'title'    => $title,
            'stats'    => $this->stats,
        ];
    }

    // =====================================================================
    // Input
    // =====================================================================

    /** @throws HtmlImportException */
    private function readUpload(string $path): string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new HtmlImportException('The uploaded file could not be read.');
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            throw new HtmlImportException('That file is empty.');
        }
        // Checked BEFORE the read, so an oversized upload never becomes an
        // allocation. file_get_contents() with a length is belt and braces
        // against the file growing between the stat and the read.
        if ($size > self::MAX_INPUT_BYTES) {
            throw new HtmlImportException(
                'That page is ' . $this->megabytes($size) . '. HTML import accepts files up to '
                . round(self::MAX_INPUT_BYTES / 1048576) . ' MB - attach it to an article instead of importing it.'
            );
        }

        $raw = file_get_contents($path, false, null, 0, self::MAX_INPUT_BYTES + 1);
        if ($raw === false || $raw === '') {
            throw new HtmlImportException('The uploaded file could not be read.');
        }
        if (strlen($raw) > self::MAX_INPUT_BYTES) {
            throw new HtmlImportException(
                'That page is larger than the ' . round(self::MAX_INPUT_BYTES / 1048576) . ' MB HTML import limit.'
            );
        }

        return $raw;
    }

    /**
     * Layer 4 of the XXE defence, and the only one that runs before libxml sees
     * a byte: no entity declaration, and no DOCTYPE internal subset.
     *
     * A plain '<!DOCTYPE html>' is EXPECTED here and is fine - unlike
     * WordprocessingML, an HTML file legitimately has one. What is refused is a
     * DOCTYPE carrying a '[...]' internal subset (the only place an HTML
     * document can declare entities) and the string '<!ENTITY' anywhere at all.
     * That is what a billion-laughs file is made of, so it never reaches the
     * parser and the expansion limits libxml may or may not have been compiled
     * with stop being something we depend on.
     *
     * @throws HtmlImportException
     */
    private function refuseEntities(string $raw): void
    {
        if (preg_match('/<!\s*ENTITY/i', $raw) === 1) {
            throw new HtmlImportException(
                'That page declares XML entities and was rejected (this is how entity-expansion attacks are written).'
            );
        }

        if (preg_match('/<!\s*DOCTYPE[^>\[]*\[/i', $raw) === 1) {
            throw new HtmlImportException(
                'That page carries a document type declaration with an internal subset and was rejected '
                . '(this is how entity-expansion and external-entity attacks are written).'
            );
        }
    }

    /**
     * Get the bytes into UTF-8, then into pure ASCII with numeric references.
     *
     * WHY BOTH. libxml's HTML parser assumes ISO-8859-1 for a document with no
     * declared encoding, and this application's KB is full of em-dashes and
     * accented names. Measured on this host (PHP 8.4.25 / libxml 2.9.14) with a
     * UTF-8 page whose title is "Cafe - Unicode" in real punctuation:
     *     loaded raw                  -> "CafÃ© â€” Ãœnicode"   (mojibake)
     *     mb_encode_numericentity     -> "Cafe - Unicode"       (correct)
     * The numeric-entity form is used rather than the '<?xml encoding>' prefix
     * hack because it does not depend on the parser honouring a processing
     * instruction it is not required to honour, and it leaves the document's own
     * <head> - and therefore its <title> - exactly where it was.
     */
    private function toUtf8(string $raw): string
    {
        $charset = null;

        // A BOM outranks any declaration.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
            $charset = 'UTF-8';
        } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $charset = str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $raw = substr($raw, 2);
        }

        // Only the head of the file may declare an encoding, and only the first
        // declaration counts - which is also what browsers do.
        if ($charset === null) {
            $head = substr($raw, 0, 4096);
            if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9_:.\-]+)/i', $head, $m) === 1) {
                $charset = $m[1];
            }
        }

        if ($charset !== null && strcasecmp($charset, 'UTF-8') !== 0 && strcasecmp($charset, 'utf8') !== 0) {
            $known = array_map('strtolower', mb_list_encodings());
            if (in_array(strtolower($charset), $known, true)) {
                $converted = @mb_convert_encoding($raw, 'UTF-8', $charset);
                if (is_string($converted) && $converted !== '') {
                    $raw = $converted;
                }
            } else {
                $this->warn('That page declared an encoding this server does not know (' . preg_replace('/[^A-Za-z0-9_.:-]/', '', substr($charset, 0, 32)) . '); it was read as UTF-8.');
            }
        }

        // Whatever happened above, the string must be valid UTF-8 from here on:
        // mb_encode_numericentity() on invalid bytes produces garbage, and the
        // escaper downstream would substitute them one at a time.
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $repaired = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            $raw = is_string($repaired) && $repaired !== '' ? $repaired : mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        }

        return $raw;
    }

    /**
     * Parse, with external entities comprehensively disabled.
     *
     * The four layers are DocxConverter::loadXml()'s four, minus the "no
     * DOCTYPE at all" rule (an HTML file is allowed one) and plus the internal
     * subset refusal in refuseEntities() above:
     *   1. libxml_set_external_entity_loader(fn () => null) - every external
     *      fetch goes through that callback, and null makes it fail. Restored
     *      in a finally so global state is never left changed.
     *   2. LIBXML_NONET - libxml refuses a network-backed resource even if a
     *      loader were reached.
     *   3. resolveExternals = false, substituteEntities = false, and
     *      LIBXML_NOENT deliberately NOT passed (it is a misnomer: it ENABLES
     *      substitution). With no substitution there is no expansion step.
     *   4. refuseEntities() already ran on the bytes.
     *
     * @throws HtmlImportException
     */
    private function parse(string $utf8): \DOMDocument
    {
        $previousLoader = libxml_get_external_entity_loader();
        $previousErrors = libxml_use_internal_errors(true);

        try {
            libxml_set_external_entity_loader(static fn () => null);

            $doc = new \DOMDocument();
            $doc->resolveExternals   = false;
            $doc->substituteEntities = false;
            $doc->validateOnParse    = false;
            $doc->preserveWhiteSpace = true;
            $doc->formatOutput       = false;

            $ascii = mb_encode_numericentity($utf8, [0x80, 0x10FFFF, 0, 0x10FFFF], 'UTF-8');

            $ok = $doc->loadHTML($ascii, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

            if ($ok === false || $doc->documentElement === null) {
                throw new HtmlImportException('That file could not be read as an HTML page.');
            }

            // The node cap, enforced immediately after the parse and before any
            // walk. The parse itself is bounded by MAX_INPUT_BYTES; this bounds
            // everything that comes after it.
            $nodes = $doc->getElementsByTagName('*')->length;
            if ($nodes > self::MAX_NODES) {
                throw new HtmlImportException(
                    'That page contains ' . $nodes . ' elements, more than the ' . self::MAX_NODES
                    . ' this importer will process. Split it, or attach it to an article instead.'
                );
            }
            $this->stats['elements'] = $nodes;

            return $doc;
        } finally {
            libxml_clear_errors();
            libxml_set_external_entity_loader($previousLoader);
            libxml_use_internal_errors($previousErrors);
        }
    }

    /**
     * Warn about the APIs that DO NOT WORK inside the escape hatch.
     *
     * agent/kb_embed.php serves an embed into an OPAQUE ORIGIN. There,
     * document.cookie, localStorage and sessionStorage do not return empty -
     * they THROW SecurityError - and fetch/XHR/sendBeacon are refused by the
     * frame's own `default-src 'none'`. A page whose first line reads
     * localStorage.getItem() dies on that line with no error the agent can see,
     * so it looks like the import broke. This warning is the difference between
     * a usable escape hatch and a mysterious one.
     *
     * A byte scan, deliberately, not a parse: the string may be in an inline
     * <script>, in an attribute, or in a file the page loads, and all we are
     * doing is telling a human what to look at.
     */
    private function scanForCagedApis(string $utf8, string $mode): void
    {
        if ($mode !== self::MODE_EMBED) {
            return;
        }

        $caged = [];
        foreach ([
            'document.cookie' => 'document.cookie',
            'localStorage'    => 'localStorage',
            'sessionStorage'  => 'sessionStorage',
            'indexedDB'       => 'indexedDB',
            'fetch('          => 'fetch()',
            'XMLHttpRequest'  => 'XMLHttpRequest',
            'sendBeacon'      => 'navigator.sendBeacon()',
            'WebSocket'       => 'WebSocket',
        ] as $needle => $label) {
            if (stripos($utf8, $needle) !== false) {
                $caged[] = $label;
            }
        }

        if ($caged !== []) {
            $this->warn(
                'That page uses ' . implode(', ', $caged) . '. An embedded tool runs with no origin, '
                . 'no storage and no network, so those throw or are refused - the tool will not work until they are removed.'
            );
        }

        if (preg_match('/<(?:script|link|img|iframe)[^>]+(?:src|href)\s*=\s*["\']?(?:https?:)?\/\//i', $utf8) === 1) {
            $this->warn('That page loads files from other websites. An embedded tool cannot reach the network, so anything it does not carry inline will be missing.');
        }
    }

    // =====================================================================
    // Flow rendering, and the block recognisers
    // =====================================================================

    /**
     * Render a list of sibling nodes in BLOCK context.
     *
     * This is where the interactive recognisers live, which is what makes them
     * work at every level rather than only at the top: a <details> run inside a
     * <div> inside a <main> is found because every one of those unwraps into
     * another renderFlow() over its own children.
     *
     * Loose inline content between blocks is collected in $pending and wrapped
     * in a <p> - or, when $wrapLoose is false (inside an <li> or a <td>, where
     * a <p> would change the shape of the thing), emitted as it stands.
     */
    private function renderFlow(array $nodes, int $depth, bool $wrapLoose = true): string
    {
        if ($depth > self::MAX_DEPTH) {
            $this->countDropped('deeply nested sections');
            return '';
        }

        $out     = '';
        $pending = '';
        $n       = count($nodes);
        $i       = 0;

        while ($i < $n) {
            $node = $nodes[$i];

            if (!$this->isBlock($node)) {
                $pending .= $this->renderInlineNode($node, $depth);
                $i++;
                continue;
            }

            $out .= $this->flushPending($pending, $wrapLoose);

            /* A heading immediately followed by a recognisable run is consumed
               AS that block's title - both the heading and the run - rather
               than being emitted as a heading of its own with an untitled block
               under it. A "Step 1" heading is excluded because it is the start
               of a run itself (rule F), not the title of one. */
            $run = null;
            if ($this->mapBlocks && $this->isHeading($node) && !$this->looksLikeStepHeading($node)) {
                $j     = $this->nextContentIndex($nodes, $i + 1);
                $title = $this->text($node);
                if ($j !== null && $title !== '') {
                    $run = $this->detectRun($nodes, $j, $title, $depth);
                }
            }

            if ($run === null && $this->mapBlocks) {
                $run = $this->detectRun($nodes, $i, null, $depth);
            }

            if ($run !== null) {
                $out .= $run['html'];
                $i = $run['end'];
                continue;
            }

            $out .= $this->renderBlock($node, $depth);
            $i++;
        }

        return $out . $this->flushPending($pending, $wrapLoose);
    }

    /**
     * Try every recogniser, in priority order, at position $i.
     *
     * Returns ['html' => string, 'end' => int] where 'end' is the index of the
     * first sibling NOT consumed, or null when nothing matched. $title is the
     * heading the caller is offering as the block's title, or null.
     */
    private function detectRun(array $nodes, int $i, ?string $title, int $depth): ?array
    {
        if ($depth > self::MAX_BLOCK_DEPTH || $this->blockCount >= self::MAX_BLOCKS) {
            if ($this->blockCount === self::MAX_BLOCKS) {
                $this->blockCount++;   // warn exactly once
                $this->warn('This page had more than ' . self::MAX_BLOCKS . ' interactive sections; the rest were imported as ordinary content.');
            }
            return null;
        }

        $node = $nodes[$i] ?? null;
        if (!$node instanceof \DOMElement) {
            return null;
        }

        return $this->detectDetailsRun($nodes, $i, $title, $depth)
            ?? $this->detectTabs($nodes, $i, $title, $depth)
            ?? $this->detectTaskList($nodes, $i, $title, $depth)
            ?? $this->detectSectionRun($nodes, $i, $title, $depth)
            ?? $this->detectStepHeadings($nodes, $i, $title, $depth)
            ?? $this->detectHeadingLedList($nodes, $i, $title, $depth);
    }

    /**
     * RULE A - a run of <details> becomes an accordion.
     *
     * The single highest-value mapping in this importer: <details>/<summary> is
     * what MkDocs, Docusaurus, GitHub and most wiki exporters emit for
     * collapsible content, and HTMLPurifier 4.15.0 UNWRAPS <details> - so
     * without this rule every collapsible section in an imported page silently
     * becomes a flat run of prose with its summary as a stray line of text.
     */
    private function detectDetailsRun(array $nodes, int $i, ?string $title, int $depth): ?array
    {
        if ($nodes[$i]->nodeName !== 'details') {
            return null;
        }

        $parts = [];
        $j     = $i;
        while ($j < count($nodes)) {
            $node = $nodes[$j];
            if ($node instanceof \DOMText && trim($node->textContent) === '') {
                $j++;
                continue;
            }
            if (!($node instanceof \DOMElement) || $node->nodeName !== 'details') {
                break;
            }
            $parts[] = $node;
            $j++;
        }

        if ($parts === []) {
            return null;
        }

        $rendered = [];
        foreach ($parts as $details) {
            $label   = null;
            $content = [];
            foreach ($this->childArray($details) as $child) {
                if ($label === null && $child instanceof \DOMElement && $child->nodeName === 'summary') {
                    $label = $this->renderLabel($this->childArray($child), $depth + 1);
                    continue;
                }
                $content[] = $child;
            }
            $rendered[] = [
                'label' => $label ?? '',
                'body'  => $this->renderFlow($content, $depth + 1),
            ];
        }

        $this->stats['accordions'] = ($this->stats['accordions'] ?? 0) + 1;
        $this->warn('Collapsible sections became an accordion block you can expand in the article.');

        return ['html' => $this->emitSequence('accordion', $title, $rendered, $depth), 'end' => $j];
    }

    /**
     * RULE B - a Bootstrap or ARIA tab widget becomes a tabs block.
     *
     * Both shapes, because half the world writes `ul.nav-tabs > li > a[href=#x]`
     * plus `div.tab-pane#x` and the other half writes `[role=tablist]` with
     * `[role=tab][aria-controls=x]` plus `[role=tabpanel]#x`. Neither survives
     * an import as anything but a stack of duplicated content otherwise: the
     * tab captions become a bullet list and every pane is shown at once.
     */
    private function detectTabs(array $nodes, int $i, ?string $title, int $depth): ?array
    {
        $start = $nodes[$i];
        if (!$start instanceof \DOMElement) {
            return null;
        }

        $tablist = $this->findTablist($start);
        if ($tablist === null) {
            return null;
        }

        // Caption + target id for every tab, in document order, capped at
        // MAX_TABS (see its comment for the measured reason). A pane that
        // contains - or IS - the tab strip itself is refused outright: that
        // shape is not a real tab widget, and walking into it would mean
        // rendering the very markup this loop is reading.
        $tabs          = [];
        $tabsSeen      = 0;
        $tabsOverCap   = false;
        foreach ($tablist->getElementsByTagName('*') as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $isTab = strtolower($el->getAttribute('role')) === 'tab'
                || ($el->nodeName === 'a' && str_starts_with($el->getAttribute('href'), '#'));
            if (!$isTab) {
                continue;
            }
            $target = $el->getAttribute('aria-controls');
            if ($target === '' && str_starts_with($el->getAttribute('href'), '#')) {
                $target = substr($el->getAttribute('href'), 1);
            }
            $caption = $this->text($el);
            if ($target === '' || $caption === '' || !isset($this->idMap[$target])) {
                continue;
            }
            $pane = $this->idMap[$target];
            if ($pane === $tablist || $this->isAncestorOrSelf($pane, $tablist)) {
                continue;
            }

            $tabsSeen++;
            if ($tabsSeen > self::MAX_TABS) {
                $tabsOverCap = true;
                continue;
            }
            $tabs[] = ['caption' => $caption, 'pane' => $pane];
        }

        if (count($tabs) < 2) {
            return null;
        }

        // Every pane must live inside the sibling run we are about to consume,
        // or we would render the same content twice - once here and once when
        // the walk reaches wherever the pane actually is. This pass also
        // dedupes by PANE IDENTITY: a pane referenced by more than one tab is
        // rendered once, with every referencing caption folded into that one
        // part's label - never once per tab. Without this, N tabs pointing at
        // one pane render that pane N times; see MAX_TABS above for what that
        // measured.
        $index = [];
        foreach ($nodes as $k => $node) {
            if ($node instanceof \DOMElement) {
                $index[spl_object_id($node)] = $k;
            }
        }

        $end          = $i;
        $paneCaptions = [];   // spl_object_id(pane) => captions, in first-seen order
        $paneElements = [];   // spl_object_id(pane) => the \DOMElement, in first-seen order
        foreach ($tabs as $tab) {
            $owner = $this->siblingIndexOf($index, $tab['pane']);
            if ($owner === null || $owner < $i) {
                return null;
            }
            $end = max($end, $owner);

            $paneId = spl_object_id($tab['pane']);
            if (!isset($paneElements[$paneId])) {
                $paneElements[$paneId] = $tab['pane'];
                $paneCaptions[$paneId] = [];
            }
            $paneCaptions[$paneId][] = $tab['caption'];
        }

        if (count($paneElements) < 2) {
            // Every tab that survived the cap named the same pane - not a real
            // tab widget, whatever it is.
            return null;
        }

        // Nothing between the tab strip and the last pane may carry content of
        // its own that is not part of the widget. Each unique pane's text is
        // measured once here, not once per tab that points at it.
        $widgetText = mb_strlen($this->text($tablist));
        foreach ($paneElements as $pane) {
            $widgetText += mb_strlen($this->text($pane));
        }
        $runText = 0;
        for ($k = $i; $k <= $end; $k++) {
            $runText += mb_strlen($this->text($nodes[$k]));
        }
        $strayChars = max(0, $runText - $widgetText);
        if ($strayChars > 80) {
            return null;
        }
        if ($strayChars > 0) {
            // Recognised anyway (the design's 80-character slack), but the
            // agent must be told: this text is NOT in any rendered pane and is
            // not coming back. Previously this branch dropped it with no
            // warning at all - see the finding this fixes.
            $this->warn('A tabbed section had some text between its tabs that was not inside any tab pane; that text was left out of the tabs block.');
        }

        $rendered = [];
        foreach ($paneElements as $paneId => $pane) {
            $rendered[] = [
                'label' => $this->esc(implode(' / ', array_unique($paneCaptions[$paneId]))),
                'body'  => $this->renderFlow($this->childArray($pane), $depth + 1),
            ];
        }

        $this->stats['tabs'] = ($this->stats['tabs'] ?? 0) + 1;
        $this->warn('A tabbed section became a tabs block.');
        if ($tabsOverCap) {
            $this->warn('A tabbed section had more than ' . self::MAX_TABS . ' tabs; the rest were left out of the tabs block.');
        }

        return ['html' => $this->emitSequence('tabs', $title, $rendered, $depth), 'end' => $end + 1];
    }

    /**
     * RULE C - a task list becomes a checklist with saved progress.
     *
     * Both the GitHub-flavoured-Markdown rendering (`<li><input type=checkbox>`)
     * and the plain-text convention (`[ ]` / `[x]` at the start of the item).
     * The <input> is dropped and its checked state is DISCARDED on purpose: a
     * tick belongs to a reader, not to a document, and the checklist block
     * stores each reader's own ticks in kb_article_progress.
     */
    private function detectTaskList(array $nodes, int $i, ?string $title, int $depth): ?array
    {
        $list = $nodes[$i];
        if (!$list instanceof \DOMElement || ($list->nodeName !== 'ul' && $list->nodeName !== 'ol')) {
            return null;
        }

        $items = $this->listItems($list);
        if (count($items) < 2) {
            return null;
        }

        $ticky = 0;
        foreach ($items as $item) {
            if ($this->taskItemMarker($item) !== null) {
                $ticky++;
            }
        }

        // A majority, and at least two: one stray "[x]" in a prose list is not a
        // task list, and turning a reference list into a checklist would be a
        // worse article than leaving it alone.
        if ($ticky < 2 || $ticky * 2 < count($items)) {
            return null;
        }

        $rendered = [];
        foreach ($items as $item) {
            $rendered[] = $this->partFromListItem($item, $depth);
        }

        $this->stats['checklists'] = ($this->stats['checklists'] ?? 0) + 1;
        $this->warn('A task list became a checklist block that remembers each reader\'s ticks.');

        return ['html' => $this->emitSequence('checklist', $title, $rendered, $depth), 'end' => $i + 1];
    }

    /**
     * RULE E - a run of <section>s that each open with a heading becomes steps.
     *
     * Deliberately narrow. "A heading-plus-section run" could be read as "any
     * run of headings", and implementing it that way would turn every ordinary
     * document on earth into a wizard - which is why this rule requires the
     * author to have said "these are sections" in the markup itself, and why
     * rule F below requires the headings to literally say "Step 1".
     */
    private function detectSectionRun(array $nodes, int $i, ?string $title, int $depth): ?array
    {
        $isSection = static function ($node): bool {
            if (!$node instanceof \DOMElement) {
                return false;
            }
            if ($node->nodeName !== 'section' && $node->nodeName !== 'article') {
                return false;
            }
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMText && trim($child->textContent) === '') {
                    continue;
                }
                return $child instanceof \DOMElement
                    && in_array($child->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);
            }
            return false;
        };

        if (!$isSection($nodes[$i])) {
            return null;
        }

        $parts = [];
        $j     = $i;
        while ($j < count($nodes)) {
            $node = $nodes[$j];
            if ($node instanceof \DOMText && trim($node->textContent) === '') {
                $j++;
                continue;
            }
            if (!$isSection($node)) {
                break;
            }
            $parts[] = $node;
            $j++;
        }

        if (count($parts) < 2) {
            return null;
        }

        $rendered = [];
        foreach ($parts as $section) {
            $children = $this->childArray($section);
            $heading  = null;
            $rest     = [];
            foreach ($children as $child) {
                if ($heading === null && $child instanceof \DOMElement && $this->isHeading($child)) {
                    $heading = $child;
                    continue;
                }
                $rest[] = $child;
            }
            $rendered[] = [
                'label' => $heading === null ? '' : $this->renderLabel($this->childArray($heading), $depth + 1),
                'body'  => $this->renderFlow($rest, $depth + 1),
            ];
        }

        $this->stats['steps'] = ($this->stats['steps'] ?? 0) + 1;
        $this->warn(count($parts) . ' page sections became a guided-steps block; switch it to an accordion or tabs from the editor if that reads better.');

        return ['html' => $this->emitSequence('steps', $title, $rendered, $depth), 'end' => $j];
    }

    /**
     * RULE F - "Step 1 ... Step 2 ..." headings become steps.
     *
     * The headings have to SAY step (or phase, or stage) and carry a number.
     * Anything looser guesses, and a guess that turns a reference page into a
     * wizard hides content behind a Next button.
     */
    private function detectStepHeadings(array $nodes, int $i, ?string $title, int $depth): ?array
    {
        $first = $nodes[$i];
        if (!$first instanceof \DOMElement || !$this->looksLikeStepHeading($first)) {
            return null;
        }

        /* 'h2' < 'h3' as strings and the six tag names sort exactly as their
           levels do, so a plain comparison answers "is this heading the same
           level or a more important one" without a lookup table. */
        $level = $first->nodeName;

        $parts = [];   // [['heading' => DOMElement, 'body' => DOMNode[]], ...]
        $j     = $i;
        $open  = -1;   // index in $parts of the step currently collecting a body

        while ($j < count($nodes)) {
            $node = $nodes[$j];

            if ($node instanceof \DOMElement && $this->isHeading($node)) {
                if ($node->nodeName === $level && $this->looksLikeStepHeading($node)) {
                    $parts[] = ['heading' => $node, 'body' => []];
                    $open    = count($parts) - 1;
                    $j++;
                    continue;
                }
                if ($node->nodeName <= $level) {
                    break;   // a new section of the document - the run is over
                }
            }

            if ($open < 0) {
                break;
            }
            $parts[$open]['body'][] = $node;
            $j++;
        }

        if (count($parts) < 2) {
            return null;
        }

        $rendered = [];
        foreach ($parts as $part) {
            $rendered[] = [
                'label' => $this->renderLabel($this->childArray($part['heading']), $depth + 1),
                'body'  => $this->renderFlow($part['body'], $depth + 1),
            ];
        }

        $this->stats['steps'] = ($this->stats['steps'] ?? 0) + 1;
        $this->warn(count($parts) . ' numbered step headings became a guided-steps block.');

        return ['html' => $this->emitSequence('steps', $title, $rendered, $depth), 'end' => $j];
    }

    /**
     * RULE D - a short list under a "checklist / steps / procedure / runbook /
     * how to" heading becomes a checklist, or guided steps when the heading
     * says "step".
     *
     * THIS IS THE ONE MAPPING THAT GUESSES, and it is the reason the import
     * modal carries a "leave lists and headings alone" checkbox. It fires only
     * with a heading in hand, so it can name that heading in the warning and
     * the agent can see exactly what it did.
     */
    private function detectHeadingLedList(array $nodes, int $i, ?string $title, int $depth): ?array
    {
        if ($title === null) {
            return null;
        }
        if (preg_match('/checklist|steps?|procedure|runbook|how ?to/i', $title) !== 1) {
            return null;
        }

        $list = $nodes[$i];
        if (!$list instanceof \DOMElement || ($list->nodeName !== 'ul' && $list->nodeName !== 'ol')) {
            return null;
        }

        $items = $this->listItems($list);
        if (count($items) < 2) {
            return null;
        }
        foreach ($items as $item) {
            if (mb_strlen($this->text($item)) > self::SHORT_ITEM_CHARS) {
                return null;   // prose in a list, not a procedure
            }
        }

        $mode = preg_match('/\bsteps?\b/i', $title) === 1 ? 'steps' : 'checklist';

        $rendered = [];
        foreach ($items as $item) {
            $rendered[] = $this->partFromListItem($item, $depth);
        }

        $this->stats[$mode === 'steps' ? 'steps' : 'checklists'] = ($this->stats[$mode === 'steps' ? 'steps' : 'checklists'] ?? 0) + 1;
        $this->warn('Turned the list under "' . $this->clip($title, 60) . '" into a ' . ($mode === 'steps' ? 'guided-steps' : 'checklist') . ' block.');

        return ['html' => $this->emitSequence($mode, $title, $rendered, $depth), 'end' => $i + 1];
    }

    // =====================================================================
    // Block emission
    // =====================================================================

    /**
     * Emit one sequence block: the shape every mode shares.
     *
     * The stored markup is ORDINARY SEMANTIC HTML - a heading and a list of
     * labelled sections. With JavaScript off it reads correctly, prints
     * correctly and lands whole in the FULLTEXT index; the interactivity is
     * js/kb_interactive.js's rendering OF this, not a thing stored instead of
     * it. Which is also why every human-readable string here is a TEXT NODE and
     * never an attribute value: kb_article_content_raw is built with
     * strip_tags(), so a label in an attribute would be invisible to search.
     *
     * @param array<int,array{label:string,body:string}> $parts already-escaped HTML
     */
    private function emitSequence(string $mode, ?string $title, array $parts, int $depth): string
    {
        $this->blockCount++;

        $html = $this->account(
            '<div class="ikb" data-ikb="sequence" data-ikb-mode="' . $mode . '"'
            . ' data-ikb-key="' . InteractiveBlocks::mintKey() . '">' . "\n"
        );

        if ($title !== null && trim($title) !== '') {
            $html .= $this->budget('<h4 class="ikb-title">' . $this->esc($this->clip($title, 255)) . "</h4>\n");
        }

        $unlabelled = 0;
        foreach ($parts as $part) {
            $label = trim($part['label']);
            if ($label === '' || trim(strip_tags($label)) === '') {
                $unlabelled++;
                $label = 'Section ' . ($unlabelled + 0);
            }

            $html .= $this->account('<div class="ikb-part" data-ikb-part="' . InteractiveBlocks::mintKey() . '">');
            $html .= $this->budget('<h5 class="ikb-label">' . $label . '</h5>');
            if (trim($part['body']) !== '') {
                $html .= $this->account('<div class="ikb-body">') . $part['body'] . $this->account('</div>');
            }
            $html .= $this->account("</div>\n");
        }

        if ($unlabelled > 0) {
            $this->warn($unlabelled . ' section' . ($unlabelled === 1 ? ' had' : 's had') . ' no title in the page and ' . ($unlabelled === 1 ? 'was' : 'were') . ' numbered instead - give ' . ($unlabelled === 1 ? 'it' : 'them') . ' a real one.');
        }

        return $html . $this->account("</div>\n");
    }

    /**
     * THE EMBED BLOCK - the escape hatch's article-side markup.
     *
     * Built here rather than in the importer's return value because two of its
     * three numbers do not exist until the caller has written rows: the embed id
     * comes from kb_article_embeds, and the fallback link needs it. The <p> is
     * load-bearing three times over - it is the no-JavaScript fallback, it is
     * what the Android app shows (a frame cannot work there), and it is the ONLY
     * text about the embed that FULLTEXT will ever see - so the name and the
     * description are TEXT NODES in it, never attributes.
     *
     * THE HREF IS DELIBERATELY RELATIVE, NOT ROOT-RELATIVE. This is the one
     * place this class departs from the kb_media convention (an absolute
     * /agent/... canonical URL, rewritten to /client/... at render time by
     * src/KB/MediaUrlRewriter::toPortal()) - deliberately, because that
     * rewriter has no entry for kb_embed and this file may not add one (out of
     * this lane's owned files). A hard-coded '/agent/kb_embed.php?id=N' sends
     * every department-portal reader who clicks it to the agent login page,
     * which was exactly the defect this comment used to just document.
     *
     * js/kb_interactive.js's bindEmbed() already solves the identical problem
     * for the iframe it builds, the SAME markup this <a> sits beside, with a
     * plain relative 'kb_embed.php?id=' + id: on agent/kb_article.php that
     * resolves to /agent/kb_embed.php, and on client/kb_article.php - same
     * stored HTML, same page family, no per-lane branch anywhere - it resolves
     * to /client/kb_embed.php instead, because both pages serve from a path
     * exactly one segment above their matching kb_embed.php and neither emits
     * a <base> tag (grepped; neither does). This fallback link uses the same
     * relative form for the same reason, and now resolves correctly with
     * JavaScript on OR off, on both the agent view and the portal.
     *
     * THE GAP THIS WOULD HAVE OPENED, NOT MERELY LEFT CLOSED: api/v1/kb.php,
     * which the Android app reads. A relative href has no defined resolution
     * there - the app loads article HTML into a WebView via
     * loadDataWithBaseURL() with a base that is neither '/agent/' nor
     * '/client/', rather than fetching either page directly - and that is
     * NOT equivalent to the old unrewritten-absolute-URL problem this
     * comment used to describe: an absolute '/agent/kb_embed.php?id=N' at
     * least resolved to a real, reachable path (merely login-gated for a
     * portal reader); a relative href resolving against the API's own base
     * can land on an entirely wrong, likely 404 path instead - worse, not
     * the same. api/v1/kb.php closes this itself with its own small
     * rewrite immediately after purifying (see the comment there) rather
     * than through this class: MediaUrlRewriter's engine is purpose-built
     * for the very different kb_media src=/href=/url() shapes with signed
     * tokens, and this is one static link shape needing no signature.
     *
     * @param int    $embedId     kb_article_embeds.kb_article_embed_id
     * @param string $name        what the tool is, typed by the agent
     * @param string $description one line about it, typed by the agent
     * @param int    $height      declared frame height; clamped to the vocabulary's bounds
     */
    public static function embedBlock(int $embedId, string $name, string $description, int $height): string
    {
        $height = InteractiveBlocks::clampHeight($height);
        $escape = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $name = trim($name) === '' ? 'Interactive tool' : trim($name);

        $html = '<div class="ikb" data-ikb="embed" data-ikb-embed="' . $embedId . '"'
            . ' data-ikb-key="' . InteractiveBlocks::mintKey() . '"'
            . ' data-ikb-height="' . $height . '">' . "\n"
            . '<p><a href="kb_embed.php?id=' . $embedId . '">Interactive: '
            . $escape(mb_substr($name, 0, 200)) . '</a>';

        if (trim($description) !== '') {
            $html .= ' &mdash; ' . $escape(mb_substr(trim($description), 0, 500));
        }

        return $html . "</p>\n</div>\n";
    }

    // =====================================================================
    // Ordinary rendering
    // =====================================================================

    private function renderBlock(\DOMNode $node, int $depth): string
    {
        if (!$node instanceof \DOMElement) {
            return '';
        }

        $name = $node->nodeName;

        // Already counted document-wide by countDroppableElements().
        if (array_key_exists($name, self::DROP_ELEMENTS)) {
            return '';
        }

        switch ($name) {
            case 'p':
                $inner = $this->renderInline($this->childArray($node), $depth + 1);
                return $this->hasVisibleContent($inner) ? $this->account('<p>') . $inner . $this->account('</p>') : '';

            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $inner = $this->renderInline($this->childArray($node), $depth + 1);
                return $this->hasVisibleContent($inner) ? $this->account("<$name>") . $inner . $this->account("</$name>") : '';

            case 'ul': case 'ol':
                return $this->renderList($node, $depth);

            case 'table':
                return $this->renderTable($node, $depth);

            case 'pre':
                return $this->renderPre($node);

            case 'hr':
                // Kept, not dropped: HTMLPurifier permits it (no HTML.Allowed
                // restriction narrows the default set in any of the four KB
                // purifier configs) and this application's own TinyMCE editor
                // produces it, so an imported page that used a rule to
                // separate sections is entitled to keep doing so.
                $this->stats['rules'] = ($this->stats['rules'] ?? 0) + 1;
                return $this->account('<hr>');

            case 'blockquote':
                // Kept as a real <blockquote>, not unwrapped to a plain
                // paragraph: HTMLPurifier permits it for the same reason as
                // <hr> above, and a quoted policy statement or vendor warning
                // deserves to stay visually distinguishable from surrounding
                // prose, which unwrapping silently threw away.
                $inner = $this->renderFlow($this->childArray($node), $depth + 1);
                return trim($inner) === ''
                    ? ''
                    : $this->account('<blockquote>') . $inner . $this->account('</blockquote>');

            case 'details':
                // Reached only when the run recogniser is switched off or the
                // element is alone in a context that could not host a block.
                // Summary first, then the content, so nothing is lost.
                return $this->renderDetailsFlat($node, $depth);

            case 'dl':
                return $this->renderFlow($this->childArray($node), $depth + 1);

            case 'dt':
                $inner = $this->renderInline($this->childArray($node), $depth + 1);
                return $this->hasVisibleContent($inner) ? $this->account('<p><strong>') . $inner . $this->account('</strong></p>') : '';

            case 'dd':
            case 'summary':
            case 'caption':
            case 'figcaption':
                $inner = $this->renderInline($this->childArray($node), $depth + 1);
                return $this->hasVisibleContent($inner) ? $this->account('<p>') . $inner . $this->account('</p>') : '';

            default:
                // Every other block element is a container: unwrap it and keep
                // its children. That is what flattens layout - columns, grids,
                // absolutely-positioned panels - into document order.
                return $this->renderFlow($this->childArray($node), $depth + 1);
        }
    }

    private function renderList(\DOMElement $list, int $depth): string
    {
        $tag   = $list->nodeName === 'ol' ? 'ol' : 'ul';
        $items = $this->listItems($list);
        if ($items === []) {
            return '';
        }

        $out = $this->account("<$tag>");
        foreach ($items as $item) {
            $marker = $this->taskItemMarker($item);

            /* A task list that did NOT become a checklist block - because the
               agent asked for lists to be left alone, or because too few of its
               items were ticky - still must not carry its <input> into the
               article. Dropping it HERE rather than letting it fall through to
               DROP_ELEMENTS keeps it out of the "removed N forms" count, which
               would otherwise report a checkbox list as a form. */
            $children = [];
            foreach ($this->childArray($item) as $child) {
                if ($child instanceof \DOMElement
                    && $child->nodeName === 'input'
                    && strcasecmp($child->getAttribute('type'), 'checkbox') === 0) {
                    continue;
                }
                $children[] = $child;
            }

            $inner = $this->renderFlow($children, $depth + 1, false);

            if ($marker !== null && $marker !== '') {
                // Drop the literal "[ ]" so it does not read as punctuation.
                $inner = preg_replace('/\A\s*' . preg_quote($this->esc($marker), '/') . '\s*/u', '', $inner, 1) ?? $inner;
            }

            $out .= $this->account('<li>') . $inner . $this->account('</li>');
        }

        return $out . $this->account("</$tag>");
    }

    private function renderTable(\DOMElement $table, int $depth): string
    {
        /* THIS TABLE'S OWN ROWS ONLY. getElementsByTagName('tr') would return
           the rows of every nested table too, and emitting those here would
           hoist an inner table's rows into the outer one - the inner table's
           content would appear twice and in the wrong place. Direct children,
           plus the direct children of a thead/tbody/tfoot, is the whole of it;
           a nested table is reached the normal way, through its cell. */
        $rows = [];
        foreach ($table->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->nodeName === 'tr') {
                $rows[] = $child;
                continue;
            }
            if (in_array($child->nodeName, ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($child->childNodes as $inner) {
                    if ($inner instanceof \DOMElement && $inner->nodeName === 'tr') {
                        $rows[] = $inner;
                    }
                }
            }
        }
        if ($rows === []) {
            return '';
        }

        $out = $this->account('<table>');
        foreach ($rows as $tr) {
            $out .= $this->account('<tr>');
            foreach ($this->childArray($tr) as $cell) {
                if (!$cell instanceof \DOMElement || ($cell->nodeName !== 'td' && $cell->nodeName !== 'th')) {
                    continue;
                }
                $tag   = $cell->nodeName;
                $attrs = $this->spanAttribute($cell, 'colspan') . $this->spanAttribute($cell, 'rowspan');
                $out  .= $this->account("<$tag$attrs>")
                    . $this->renderFlow($this->childArray($cell), $depth + 1, false)
                    . $this->account("</$tag>");
            }
            $out .= $this->account('</tr>');
        }

        return $out . $this->account('</table>');
    }

    /**
     * A bounded colspan/rowspan, or nothing.
     *
     * 64 because a table wider or deeper than that is a spreadsheet, and an
     * unbounded span from author-controlled markup is a rendering denial of
     * service - the same objection the design review raised against the two
     * unbounded integers in the purifier attribute definitions.
     */
    private function spanAttribute(\DOMElement $cell, string $name): string
    {
        $value = trim($cell->getAttribute($name));
        if ($value === '' || preg_match('/\A[0-9]{1,3}\z/', $value) !== 1) {
            return '';
        }
        $span = (int) $value;
        if ($span < 2 || $span > 64) {
            return '';
        }
        return ' ' . $name . '="' . $span . '"';
    }

    /**
     * <pre> becomes the copy marker. Unconditional - a code block a reader can
     * copy in one click is always better than one they have to select, and with
     * JavaScript off it is exactly the <pre><code> the KB already renders today.
     */
    private function renderPre(\DOMElement $pre): string
    {
        $text = $pre->textContent;
        if (trim($text) === '') {
            return '';
        }

        $this->stats['copy'] = ($this->stats['copy'] ?? 0) + 1;

        return $this->account('<pre class="ikb-copy" data-ikb="copy"><code>')
            . $this->budget($this->esc($text))
            . $this->account('</code></pre>');
    }

    private function renderDetailsFlat(\DOMElement $details, int $depth): string
    {
        $out  = '';
        $rest = [];
        foreach ($this->childArray($details) as $child) {
            if ($child instanceof \DOMElement && $child->nodeName === 'summary') {
                $inner = $this->renderInline($this->childArray($child), $depth + 1);
                if ($this->hasVisibleContent($inner)) {
                    $out .= $this->account('<p><strong>') . $inner . $this->account('</strong></p>');
                }
                continue;
            }
            $rest[] = $child;
        }
        return $out . $this->renderFlow($rest, $depth + 1);
    }

    // =====================================================================
    // Inline rendering
    // =====================================================================

    private function renderInline(array $nodes, int $depth, bool $labelOnly = false): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= $this->renderInlineNode($node, $depth, $labelOnly);
        }
        return $out;
    }

    private function renderInlineNode(\DOMNode $node, int $depth, bool $labelOnly = false): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }

        if ($node instanceof \DOMText) {
            return $this->budget($this->esc($node->textContent));
        }

        if ($node instanceof \DOMEntityReference) {
            /* Entity references are left UNEXPANDED by the parser (see parse()),
             * so they arrive as nodes rather than text. Only the five HTML
             * entities every parser knows are re-emitted; anything else would be
             * a reference to a declaration we refused to allow. */
            $known = ['amp' => '&amp;', 'lt' => '&lt;', 'gt' => '&gt;', 'quot' => '&quot;', 'apos' => '&#039;', 'nbsp' => '&nbsp;'];
            return $this->budget($known[strtolower($node->nodeName)] ?? '');
        }

        if (!$node instanceof \DOMElement) {
            return '';   // comments, CDATA, processing instructions
        }

        $name = $node->nodeName;

        // Already counted document-wide by countDroppableElements().
        if (array_key_exists($name, self::DROP_ELEMENTS)) {
            return '';
        }

        if ($this->isBlock($node)) {
            if ($labelOnly) {
                // renderLabel()'s whole reason to exist: a label is written
                // straight into <h5 class="ikb-label">...</h5> by emitSequence(),
                // and HTMLPurifier does not allow block children inside a
                // heading - it hoists them OUT, leaving an EMPTY label with the
                // real words orphaned as loose siblings, and normalise() sees a
                // present (if empty) .ikb-label element so it never repairs it.
                // Flatten to the block's own text instead of recursing into
                // renderBlock(), and flag it once so run() can warn - this must
                // never be a silent content loss.
                $this->labelLostStructure = true;
                // visibleText(), not text(): text() collapses via
                // $node->textContent directly, which inserts NO separator at
                // element boundaries - "<p>Hello</p><p>World</p>" flattens to
                // "HelloWorld", one run-together word, whenever the source
                // markup had no whitespace text node between the two <p>s.
                // visibleText() (already used by plainText() for the same
                // reason) adds a space after every child as it recurses, so
                // adjacent block children always end up separated.
                $text = $this->normaliseText($this->visibleText($node, 0));
                return $text === '' ? '' : $this->budget($this->esc($text) . ' ');
            }
            // A block element in inline context (a <div> inside a <p>, which
            // libxml keeps where it found it) still has to render.
            return $this->renderBlock($node, $depth);
        }

        switch ($name) {
            case 'strong': case 'b':
                return $this->wrapInline('strong', $node, $depth, $labelOnly);
            case 'em': case 'i': case 'cite': case 'var': case 'dfn':
                return $this->wrapInline('em', $node, $depth, $labelOnly);
            case 'u': case 'ins':
                return $this->wrapInline('u', $node, $depth, $labelOnly);
            case 's': case 'strike': case 'del':
                return $this->wrapInline('s', $node, $depth, $labelOnly);
            case 'code': case 'kbd': case 'samp': case 'tt':
                return $this->wrapInline('code', $node, $depth, $labelOnly);
            case 'br':
                return $this->account('<br>');
            case 'img':
                return $this->renderImage($node);
            case 'a':
                return $this->renderAnchor($node, $depth, $labelOnly);
            default:
                // span, small, mark, sup, sub, font, abbr, time, bdi, ...
                return $this->renderInline($this->childArray($node), $depth + 1, $labelOnly);
        }
    }

    private function wrapInline(string $tag, \DOMElement $node, int $depth, bool $labelOnly = false): string
    {
        $inner = $this->renderInline($this->childArray($node), $depth + 1, $labelOnly);
        if (!$this->hasVisibleContent($inner)) {
            return '';
        }
        return $this->account("<$tag>") . $inner . $this->account("</$tag>");
    }

    private function renderAnchor(\DOMElement $anchor, int $depth, bool $labelOnly = false): string
    {
        $inner = $this->renderInline($this->childArray($anchor), $depth + 1, $labelOnly);
        if (!$this->hasVisibleContent($inner)) {
            return '';
        }

        $href = $this->safeUrl($anchor->getAttribute('href'));
        if ($href === null) {
            // An in-page anchor, a javascript: URL, a relative path that would
            // resolve against /agent/ - the words stay, the link does not.
            return $inner;
        }

        return $this->account('<a href="' . $this->esc($href) . '">') . $inner . $this->account('</a>');
    }

    /**
     * Render a label: INLINE CONTENT ONLY, ever. Every call site that builds a
     * part's or a section's label - never a body - must go through this, not
     * renderInline() directly. See renderInlineNode()'s $labelOnly branch for
     * why: a label is written straight into <h5 class="ikb-label"> and a block
     * child there is worse than useless once HTMLPurifier is done with it.
     */
    private function renderLabel(array $nodes, int $depth): string
    {
        return trim($this->renderInline($nodes, $depth, true));
    }

    /**
     * http and https only, and nothing that could smuggle a scheme past.
     *
     * The control-character refusal is DocxConverter::safeUrl()'s, and for its
     * reason: control bytes are how a scheme filter gets fooled ("java\0script:").
     *
     * NARROWER THAN ITS SIBLINGS BY ONE SCHEME, deliberately. DocxConverter and
     * PdfConverter also emit mailto:, but NO KB renderer in this application
     * allows it - all four purifier configs set
     *     URI.AllowedSchemes = ['data','src','http','https']
     * (agent/kb_article.php:16 and the portal's is narrower still,
     * client/kb_article.php:21, which allows only http and https). Measured on
     * the bundled 4.15.0 with agent/kb_article.php's config:
     *     <a href="mailto:it@example.com">it@example.com</a>
     *   becomes
     *     <a>it@example.com</a>
     * - a dead anchor. Emitting a link this application is guaranteed to strip
     * would put markup in storage that no reader can ever use; the address
     * itself is a text node either way and stays fully searchable. If mailto is
     * ever added to those configs, add it back here in the same commit.
     */
    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }
        return $url;
    }

    // =====================================================================
    // Images
    // =====================================================================

    /**
     * The one place bytes come out of the document.
     *
     * data: URIs are imported. Everything else - a relative path, a remote
     * http(s) URL, a file: URL, a blob: - is DROPPED, and reported once with a
     * count.
     *
     * Remote http(s) images used to be kept, pointing at their original
     * address, with a warning that they "will only show for readers who can
     * reach that site". Measured false on both renderers: client/kb_article.php
     * sends `Content-Security-Policy: default-src 'self'; img-src 'self'
     * data:` and includes/header.php sends `img-src 'self' data: blob:
     * https://*.foleyit.com https://tile.openstreetmap.org
     * https://*.tile.openstreetmap.org` - neither origin list has room for an
     * arbitrary third-party host, so a browser refuses the request under the
     * page's OWN policy, unconditionally, for every reader regardless of their
     * network. This importer fetches nothing (see threat model 2), so there is
     * no way to make that URL actually display without downloading the bytes
     * at import time - out of scope here - and keeping a link that can never
     * resolve is worse than dropping it the same way a same-directory relative
     * path already is.
     */
    private function renderImage(\DOMElement $img): string
    {
        $src = trim($img->getAttribute('src'));
        $alt = $this->clip($img->getAttribute('alt'), 200);

        if ($src === '') {
            return '';
        }

        if (stripos($src, 'data:') === 0) {
            $token = $this->extractDataUri($src);
            if ($token === null) {
                return '';
            }
            return $this->budget('<img src="' . $this->esc($token) . '" alt="' . $this->esc($alt) . '">');
        }

        if (preg_match('#^https?://#i', $src) === 1) {
            $this->warn('Pictures hosted on other websites could not be imported: this application only ever loads images from its own server, so a picture at another address would never display for any reader. Add it to the article from the editor.');
            $this->countDropped('images');
            return '';
        }

        $this->warn('Pictures stored beside the HTML file could not be imported (only the page itself was uploaded). Add them to the article from the editor.');
        $this->countDropped('images');
        return '';
    }

    /**
     * Decode one data: URI into media bytes, with the SAME two-sniff rule
     * DocxConverter uses: finfo and getimagesizefromstring must agree that these
     * bytes are one of five raster types, and the extension comes from that
     * verdict rather than from anything the document said.
     */
    private function extractDataUri(string $src): ?string
    {
        if (preg_match('#\Adata:([a-z0-9.+/-]*)\s*;\s*base64\s*,#i', $src, $m) !== 1) {
            // A non-base64 data: URI is percent-encoded text - in practice an
            // SVG, which is script-bearing markup and not an image we accept.
            $this->countDropped('images');
            return null;
        }

        $payload = substr($src, strlen($m[0]));
        // Whitespace is legal inside a base64 payload in an attribute value.
        $payload = preg_replace('/\s+/', '', $payload) ?? '';

        // A base64 string is 4/3 the size of its bytes: refuse before decoding.
        if (strlen($payload) > (int) (self::MAX_MEDIA_BYTES_EACH * 4 / 3) + 4) {
            $this->warn('An oversized embedded picture was skipped.');
            return null;
        }

        $bytes = base64_decode($payload, true);
        if ($bytes === false || $bytes === '') {
            $this->countDropped('images');
            return null;
        }

        $hash = sha1($bytes);
        if (isset($this->mediaByHash[$hash])) {
            return $this->mediaByHash[$hash];   // the same picture twice
        }

        if (count($this->media) >= self::MAX_IMAGES) {
            $this->warn('This page contains more than ' . self::MAX_IMAGES . ' pictures; the extras were skipped.');
            return null;
        }

        if (strlen($bytes) > self::MAX_MEDIA_BYTES_EACH) {
            $this->warn('An oversized embedded picture was skipped.');
            return null;
        }

        if ($this->mediaBytesUsed + strlen($bytes) > self::MAX_MEDIA_BYTES_TOTAL) {
            $this->warn('This page carries more than ' . round(self::MAX_MEDIA_BYTES_TOTAL / 1048576) . ' MB of pictures; the extras were skipped.');
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = strtolower(trim(explode(';', (string) $finfo->buffer($bytes))[0]));

        if (!isset(self::IMAGE_MIME_EXT[$mime])) {
            $this->warn('An embedded file that is not a picture was skipped.');
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset($info[2]) || !in_array($info[2], self::IMAGE_TYPES, true)) {
            $this->warn('An embedded file that claimed to be a picture but does not decode was skipped.');
            return null;
        }
        if (strtolower(image_type_to_mime_type($info[2])) !== $mime) {
            $this->warn('An embedded picture with a mismatched type was skipped.');
            return null;
        }

        $this->mediaBytesUsed += strlen($bytes);

        $token = $this->tokenPrefix . count($this->media);
        $this->media[] = [
            'token'     => $token,
            'extension' => self::IMAGE_MIME_EXT[$mime],
            'mime'      => $mime,
            'bytes'     => $bytes,
        ];
        $this->mediaByHash[$hash] = $token;

        return $token;
    }

    // =====================================================================
    // Small helpers
    // =====================================================================

    /** @return array<int,\DOMNode> a snapshot, so the caller may index into it */
    private function childArray(\DOMNode $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            $out[] = $child;
        }
        return $out;
    }

    private function isBlock(\DOMNode $node): bool
    {
        return $node instanceof \DOMElement && in_array($node->nodeName, self::BLOCK_ELEMENTS, true);
    }

    private function isHeading(\DOMNode $node): bool
    {
        return $node instanceof \DOMElement
            && in_array($node->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);
    }

    private function looksLikeStepHeading(\DOMNode $node): bool
    {
        return $this->isHeading($node)
            && preg_match('/\A(step|phase|stage)\s*#?\s*[0-9]+\b/i', $this->text($node)) === 1;
    }

    /** Index of the next node that is not whitespace, or null. */
    private function nextContentIndex(array $nodes, int $from): ?int
    {
        for ($i = $from; $i < count($nodes); $i++) {
            $node = $nodes[$i];
            if ($node instanceof \DOMText && trim($node->textContent) === '') {
                continue;
            }
            return $i;
        }
        return null;
    }

    /** Direct <li> children only, so a nested list stays inside its item. */
    private function listItems(\DOMElement $list): array
    {
        $out = [];
        foreach ($list->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->nodeName === 'li') {
                $out[] = $child;
            }
        }
        return $out;
    }

    /**
     * The children taskItemMarker() and partFromListItem() should actually
     * look at: the item's own children, except that a LEADING wrapping <p> -
     * if the item opens with one - is replaced by ITS children in place.
     *
     * GitHub, Pandoc and MkDocs all render a LOOSE task-list item (GFM's term
     * for one with a blank line around it in the source Markdown) as
     * <li><p><input type=checkbox> text</p></li>, never the tight
     * <li><input type=checkbox> text</li> this class was written against - and
     * a loose item can legitimately carry a nested sub-list right after that
     * paragraph, e.g. <li><p><input ...> text</p><ul>...</ul></li>, the normal
     * rendering of a task item that itself has sub-bullets in the source.
     * Without this, both callers see the <p> as one opaque child: taskItemMarker()
     * never finds the checkbox inside it (so the item does not count as
     * "ticky" and detectTaskList() can miss the whole list), and
     * partFromListItem() files the entire <p> - marker text included - as
     * body content, leaving the label empty. Splicing only the leading <p>'s
     * children into its place, and leaving any later sibling (the sub-list)
     * untouched, fixes both without disturbing content that was never the
     * label to begin with.
     */
    private function taskListChildren(\DOMElement $item): array
    {
        $out    = [];
        $sawReal = false;
        foreach ($item->childNodes as $child) {
            if (!$sawReal && $child instanceof \DOMText && trim($child->textContent) === '') {
                $out[] = $child;
                continue;
            }
            if (!$sawReal && $child instanceof \DOMElement && $child->nodeName === 'p') {
                // Splice the wrapping <p>'s own children in its place, and
                // keep walking the REST of the item unchanged - a loose task
                // item can carry a genuine nested sub-list right after its
                // marker paragraph (the standard rendering of a task item
                // that itself has sub-bullets in the source Markdown), and
                // that sibling is real block content, not part of the label.
                foreach ($child->childNodes as $inner) {
                    $out[] = $inner;
                }
                $sawReal = true;
                continue;
            }
            $sawReal = true;
            $out[]   = $child;
        }
        return $out;
    }

    /**
     * The literal task marker at the start of a list item, or null.
     *
     * Returns '' for the <input type=checkbox> form (nothing to strip from the
     * text), or the literal marker string for the textual forms.
     */
    private function taskItemMarker(\DOMElement $item): ?string
    {
        foreach ($this->taskListChildren($item) as $child) {
            if ($child instanceof \DOMText && trim($child->textContent) === '') {
                continue;
            }
            if ($child instanceof \DOMElement
                && $child->nodeName === 'input'
                && strcasecmp($child->getAttribute('type'), 'checkbox') === 0) {
                return '';
            }
            break;
        }

        // textContent recurses through a loose item's wrapping <p> on its own,
        // so this half needed no change to see through it.
        $text = $this->text($item);
        if (preg_match('/\A(\[\s*[xX\x{2713}\x{2714}]?\s*\]|\x{2610}|\x{2611}|\x{2612}|\x{25A1}|\x{2705})/u', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * One sequence part out of one <li>: the item's own text becomes the label,
     * anything block-level inside it becomes the body.
     *
     * @return array{label:string,body:string}
     */
    private function partFromListItem(\DOMElement $item, int $depth): array
    {
        $marker = $this->taskItemMarker($item);

        $inline = [];
        $blocks = [];
        foreach ($this->taskListChildren($item) as $child) {
            if ($child instanceof \DOMElement
                && $child->nodeName === 'input'
                && strcasecmp($child->getAttribute('type'), 'checkbox') === 0) {
                continue;   // the control is created by JS at render time
            }
            if ($this->isBlock($child)) {
                $blocks[] = $child;
                continue;
            }
            $inline[] = $child;
        }

        $label = $this->renderLabel($inline, $depth + 1);
        if ($marker !== null && $marker !== '') {
            $label = preg_replace('/\A\s*' . preg_quote($this->esc($marker), '/') . '\s*/u', '', $label, 1) ?? $label;
        }
        $label = trim($label);

        if ($label === '' && $blocks !== []) {
            // Nothing inline survived - the item opened straight into block
            // content with no leading <p> for taskListChildren() to splice
            // (e.g. <li><ul>...</ul></li>), so there is no text anywhere in
            // the item outside a block. Promote the FIRST block to the label
            // rather than leaving an empty <h5 class="ikb-label"> for
            // emitSequence()'s "Section N" placeholder to swallow the real
            // words into the body - the same failure this method exists to
            // avoid for the loose-list shape.
            $first = array_shift($blocks);
            $label = trim($this->renderLabel([$first], $depth + 1));
            if ($label === '') {
                $label = trim($this->text($first));
            }
        }

        return [
            'label' => $label,
            'body'  => $this->renderFlow($blocks, $depth + 1),
        ];
    }

    /**
     * The first tab strip inside an element, Bootstrap or ARIA.
     *
     * Answered from the list built ONCE by indexIds(), not by walking the
     * element's subtree. detectRun() is called for every block element in the
     * document, so a subtree scan here would be O(elements^2) - on a page at
     * the 20,000 node cap that is 4x10^8 node visits for a page with no tabs
     * in it at all. Documents carry a handful of tab strips at most, so
     * "is one of these inside this element" is the cheap direction to ask.
     */
    private function findTablist(\DOMElement $scope): ?\DOMElement
    {
        foreach ($this->tablists as $tablist) {
            $node = $tablist;
            $hops = 0;
            while ($node !== null && $hops++ < self::MAX_DEPTH) {
                if ($node === $scope) {
                    return $tablist;
                }
                $node = $node->parentNode;
            }
        }

        return null;
    }

    /**
     * Is $ancestor the same node as $node, or does it contain $node? Same
     * MAX_DEPTH-bounded parent walk as siblingIndexOf() and findTablist(), for
     * the same reason: a pane that turns out to BE (or wrap) the tab strip
     * that names it is not a real tab widget, and detectTabs() must refuse it
     * before trying to render it.
     */
    private function isAncestorOrSelf(\DOMNode $ancestor, \DOMNode $node): bool
    {
        $hops = 0;
        while ($node !== null && $hops++ < self::MAX_DEPTH) {
            if ($node === $ancestor) {
                return true;
            }
            $node = $node->parentNode;
        }
        return false;
    }

    /** Which of $index's siblings contains $target, or null if none does. */
    private function siblingIndexOf(array $index, \DOMNode $target): ?int
    {
        $node = $target;
        $hops = 0;
        while ($node !== null && $hops++ < self::MAX_DEPTH) {
            $id = spl_object_id($node);
            if (isset($index[$id])) {
                return $index[$id];
            }
            $node = $node->parentNode;
        }
        return null;
    }

    /**
     * ONE pass over the document, feeding both halves of the tab recogniser:
     * id => element so href="#pane" and aria-controls="pane" can be resolved,
     * and the list of tab strips so findTablist() never walks a subtree.
     *
     * Attr.EnableID is off in every KB purifier config, so these ids are read
     * here and never written - the block that comes out carries minted keys
     * instead, which is what the stored vocabulary allows.
     */
    private function indexIds(\DOMDocument $doc): void
    {
        foreach ($doc->getElementsByTagName('*') as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }

            $id = $el->getAttribute('id');
            if ($id !== '' && !isset($this->idMap[$id])) {
                $this->idMap[$id] = $el;
            }

            if (count($this->tablists) >= 64) {
                continue;
            }
            if (strtolower($el->getAttribute('role')) === 'tablist') {
                $this->tablists[] = $el;
                continue;
            }
            if (in_array($el->nodeName, ['ul', 'ol', 'nav', 'div'], true)
                && preg_match('/(?:\A|\s)(nav-tabs|nav-pills|tabs)(?:\s|\z)/i', $el->getAttribute('class')) === 1) {
                $this->tablists[] = $el;
            }
        }
    }

    /**
     * Count what the emitter will refuse to carry, ACROSS THE WHOLE DOCUMENT.
     *
     * Document-wide rather than counted as the walk meets them, because the
     * walk starts at <body> and a page's <script> and <style> usually live in
     * <head> - counted at the walk, "this page had scripts in it" would be
     * reported only when the scripts were in the part we were already reading.
     * The agent is being told what was in the FILE.
     *
     * The one exclusion is <input type="checkbox">, which is not a form: it is
     * how GitHub-flavoured Markdown renders a task list, and rule C turns those
     * into a checklist block. Counting them would report a runbook's tick boxes
     * as "4 forms removed", which is true of the markup and false about the
     * page.
     */
    private function countDroppableElements(\DOMDocument $doc): void
    {
        foreach ($doc->getElementsByTagName('*') as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $kind = self::DROP_ELEMENTS[$el->nodeName] ?? false;
            if ($kind === false || $kind === null) {
                continue;
            }
            if ($el->nodeName === 'input' && strcasecmp($el->getAttribute('type'), 'checkbox') === 0) {
                continue;
            }
            // Same reasoning for the ARIA tab strip: <button role="tab"> is a
            // tab caption that rule B turns into a label, not a form control.
            if ($el->nodeName === 'button' && strtolower($el->getAttribute('role')) === 'tab') {
                continue;
            }
            $this->countDropped($kind);
        }
    }

    private function documentTitle(\DOMDocument $doc): ?string
    {
        $title = $doc->getElementsByTagName('title')->item(0);
        if ($title === null) {
            return null;
        }
        $text = $this->normaliseText($title->textContent);
        return $text === '' ? null : $this->clip($text, 255);
    }

    /**
     * Plain text for the FULLTEXT feed, whitespace-collapsed and capped.
     *
     * textContent would include the source of every <script> and <style> in the
     * page - measured on the escape-hatch fixture, 300 characters of the
     * article's search text were JavaScript - so those subtrees are skipped.
     * This matters most in embed mode, where this string is the ONLY thing
     * about the embedded tool that ever reaches kb_article_content_raw.
     */
    private function plainText(\DOMElement $body, int $cap): string
    {
        $text = $this->normaliseText($this->visibleText($body, 0));
        // mb_strcut, not substr: the cap is in BYTES (it bounds what goes into
        // a MEDIUMTEXT column) but it must not fall inside a character.
        return strlen($text) > $cap ? mb_strcut($text, 0, $cap, 'UTF-8') : $text;
    }

    /** textContent, minus the subtrees whose text is code rather than words. */
    private function visibleText(\DOMNode $node, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '';
        }
        if ($node instanceof \DOMText) {
            return $node->textContent;
        }
        if ($node instanceof \DOMElement
            && in_array($node->nodeName, ['script', 'style', 'template', 'noscript'], true)) {
            return '';
        }

        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->visibleText($child, $depth + 1) . ' ';
        }
        return $out;
    }

    private function text(\DOMNode $node): string
    {
        return $this->normaliseText($node->textContent);
    }

    private function normaliseText(string $text): string
    {
        $text = str_replace("\xc2\xa0", ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function clip(string $text, int $max): string
    {
        $text = $this->normaliseText($text);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    /** Does this rendered fragment carry anything a reader would see? */
    private function hasVisibleContent(string $html): bool
    {
        if (str_contains($html, '<img')) {
            return true;
        }
        // U+00A0 is what &nbsp; decodes to, and trim() does not strip it - a
        // <p>&nbsp;</p> is furniture, not content.
        $text = str_replace("\xc2\xa0", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
        return trim($text) !== '';
    }

    private function flushPending(string &$pending, bool $wrapLoose): string
    {
        $buffer  = $pending;
        $pending = '';

        if (!$this->hasVisibleContent($buffer)) {
            return '';
        }

        return $wrapLoose
            ? $this->account('<p>') . trim($buffer) . $this->account('</p>')
            : $buffer;
    }

    private function esc(string $text): string
    {
        if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Charge CONTENT against the output budget and drop it once spent. Same
     * split as DocxConverter: content can be dropped, structure cannot, because
     * dropping a closing tag would emit broken HTML.
     */
    private function budget(string $fragment): string
    {
        if ($this->truncated) {
            return '';
        }
        $this->htmlBytesUsed += strlen($fragment);
        if ($this->htmlBytesUsed > self::MAX_HTML_BYTES) {
            $this->truncated = true;
            return '';
        }
        return $fragment;
    }

    private function account(string $markup): string
    {
        $this->htmlBytesUsed += strlen($markup);
        if ($this->htmlBytesUsed > self::MAX_HTML_BYTES) {
            $this->truncated = true;
        }
        return $markup;
    }

    private function countDropped(?string $kind): void
    {
        if ($kind === null) {
            return;
        }
        $this->dropped[$kind] = ($this->dropped[$kind] ?? 0) + 1;
    }

    /**
     * Say what was thrown away, once, with counts.
     *
     * The modal states the policy up front; this states what the policy actually
     * did to THIS page. An importer that silently dropped a form would leave an
     * agent looking at an article that is missing the only part that mattered.
     */
    private function reportDropped(): void
    {
        // Singular and plural, in the order they matter to the reader.
        $labels = [
            'scripts'                => ['script', 'scripts'],
            'stylesheets'            => ['stylesheet', 'stylesheets'],
            'forms'                  => ['form element', 'form elements'],
            'frames'                 => ['embedded frame', 'embedded frames'],
            'drawings'               => ['drawing', 'drawings'],
            'media players'          => ['media player', 'media players'],
            'images'                 => ['picture', 'pictures'],
            'deeply nested sections' => ['deeply nested section', 'deeply nested sections'],
        ];

        $parts = [];
        foreach ($labels as $kind => $word) {
            $count = $this->dropped[$kind] ?? 0;
            if ($count > 0) {
                $parts[] = $count . ' ' . ($count === 1 ? $word[0] : $word[1]);
            }
        }
        if ($parts !== []) {
            $this->warn('Removed ' . implode(', ', $parts) . ' - none of those can run or be stored in an article.');
        }
        $this->stats['dropped'] = array_sum($this->dropped);
        $this->stats['blocks']  = min($this->blockCount, self::MAX_BLOCKS);
        $this->stats['images']  = count($this->media);
    }

    private function megabytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? rtrim(rtrim(sprintf('%.1f', $bytes / 1048576), '0'), '.') . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private function warn(string $message): void
    {
        if (!in_array($message, $this->warnings, true) && count($this->warnings) < 25) {
            $this->warnings[] = $message;
        }
    }
}

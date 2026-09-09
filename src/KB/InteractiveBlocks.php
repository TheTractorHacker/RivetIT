<?php

namespace ITFlow\KB;

/**
 * THE INTERACTIVE KB VOCABULARY - one definition, four renderers, one grammar.
 *
 * Interactive KB blocks are stored as ORDINARY SEMANTIC HTML inside
 * kb_articles.kb_article_content. There is no placeholder token and no side
 * table for block content. With JavaScript off the markup still reads as a
 * heading plus a list of labelled sections, prints correctly, and lands whole
 * in the FULLTEXT index; the interactivity is a RENDERING of that markup,
 * applied by js/kb_interactive.js.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE GRAMMAR (this is the whole of it)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   SEQUENCE - a titled, ordered set of labelled parts. One shape, four displays.
 *
 *     <div class="ikb" data-ikb="sequence" data-ikb-mode="checklist|steps|tabs|accordion"
 *          data-ikb-key="a3f19c22">
 *       <h4 class="ikb-title">Firewall swap runbook</h4>
 *       <div class="ikb-part" data-ikb-part="7b1e0d44">
 *         <h5 class="ikb-label">Take a config backup</h5>
 *         <div class="ikb-body"><p>Export the running config.</p></div>
 *       </div>
 *     </div>
 *
 *   TREE - a real branching graph, flat node list so two branches can converge.
 *
 *     <div class="ikb" data-ikb="tree" data-ikb-key="9d0e1f2a" data-ikb-start="n1">
 *       <h4 class="ikb-title">Laptop will not boot</h4>
 *       <div class="ikb-node" data-ikb-node="n1"><p>Is the power LED lit?</p>
 *         <ul><li><a href="#ikb-9d0e1f2a-n2" data-ikb-go="n2">Yes</a></li>
 *             <li><a href="#ikb-9d0e1f2a-n3" data-ikb-go="n3">No</a></li></ul></div>
 *       <div class="ikb-node" data-ikb-node="n3"><p><strong>Answer:</strong> replace the PSU.</p></div>
 *     </div>
 *
 *   COPY - a marker on a <pre>, not a block.
 *
 *     <pre class="ikb-copy" data-ikb="copy"><code>gpupdate /force</code></pre>
 *
 *   EMBED - the sandboxed escape hatch. The <p> is load-bearing: it is the
 *   no-JS fallback, the Android fallback, the print fallback, and the ONLY text
 *   about the embed that reaches the FULLTEXT index. The href is RELATIVE
 *   (see HtmlImporter::embedBlock()'s docblock for why) and resolves
 *   correctly with no rewriting on agent/kb_article.php, client/kb_article.php
 *   AND the API response the Android app reads (api/v1/kb.php rewrites it to
 *   absolute there, since neither /agent/ nor /client/ is that response's base).
 *
 *     <div class="ikb" data-ikb="embed" data-ikb-embed="17" data-ikb-height="640">
 *       <p><a href="kb_embed.php?id=17">Interactive: VLAN subnet calculator</a>
 *          &mdash; sizes VLAN allocations.</p>
 *     </div>
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FOUR INVARIANTS, EACH FORCED BY A MEASUREMENT, NONE OF THEM NEGOTIABLE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. EVERY HUMAN-READABLE STRING IS A TEXT NODE, NEVER AN ATTRIBUTE VALUE.
 *    agent/post/kb_article.php builds the search column as
 *      sanitizeInput($title . " " . str_replace("<", " <", $content))
 *    and sanitizeInput() is strip_tags() (functions.php:2537, the strip_tags
 *    call is at :2549). Attribute values are DROPPED by strip_tags, so a label
 *    carried in data-ikb-label="..." would be completely invisible to search.
 *    That single fact is what shapes the entire vocabulary. Re-measured for
 *    this file: proof 2 below.
 *
 * 2. NO STORED CONTROL ELEMENTS AND NO STORED id. Measured on the bundled
 *    HTMLPurifier 4.15.0 (plugins/htmlpurifier/HTMLPurifier.standalone.php,
 *    VERSION = '4.15.0' at line 86): <button> and <details> are UNWRAPPED,
 *    <input> is DELETED outright, and id is STRIPPED because Attr.EnableID
 *    defaults to false. So every checkbox, every Next button and every tab
 *    strip is created by JavaScript at render time and never stored, and no
 *    block may ever need an id. Attr.EnableID stays OFF for a second reason:
 *    includes/footer.php:114 puts window.csrfToken on the window object, and
 *    author-controlled ids are a DOM-clobbering primitive against exactly that
 *    kind of global.
 *
 * 3. ATTRIBUTES CARRY MACHINE IDENTITY ONLY - an enum, a key matching
 *    /\A[a-z0-9][a-z0-9-]{0,23}\z/, or a BOUNDED integer. Never free text.
 *
 * 4. EVERY NUMERIC ATTRIBUTE IS EXPLICITLY BOUNDED AT BOTH ENDS.
 *    HTMLPurifier_AttrDef_Integer(false, false, true) accepts an integer of any
 *    LENGTH - a 20-digit data-ikb-height reaches the render layer intact. An
 *    unbounded integer out of author-controlled markup is a denial of service
 *    on whatever consumes it, so InteractiveUintAttrDef caps the digit count
 *    first and the range second. See proof 4.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PUBLIC API - what the other work streams call
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   apply(\HTMLPurifier_Config $c): void
 *       Registers the vocabulary on a purifier config. MUST be called BEFORE
 *       `new HTMLPurifier($c)`. Idempotent per config object. Called by the four
 *       KB renderers; nothing else in the tree should call it, because the
 *       vocabulary is KB-only.
 *
 *   contains(string $html): bool                Cheap "does this article have blocks".
 *   isKey($v): bool                             Content key grammar.
 *   isStorableKey($v): bool                     Content key OR the reserved position key.
 *   isBlockType($v) / isSequenceMode($v): bool  Enum membership.
 *   mintKey(): string                           A fresh 8-hex-character key.
 *   clampHeight(int $h): int                    Second bound on the embed height.
 *   clampState(int $s): int                     Bound on a stored progress value.
 *   partHash(string $label, string $body)       16 hex of the stale-tick hash.
 *   partHashes(string $html): array             ['blockKey' => ['partKey' => hash]]
 *   progressAttribute(array $progress): string  The JSON for data-ikb-progress.
 *                                               Takes kbProgressLoad()'s map
 *                                               (agent/includes/kb_progress_store.php).
 *   hashesAttribute(string $html): string       The JSON for data-ikb-hashes.
 *   normalise(string $html): array              ['html' => ..., 'warnings' => [...]]
 *
 * WHERE THE PIECES LIVE
 *   js/kb_interactive.js  the render layer (the extension seam is documented there)
 *   css/itflow_kb.css     the styling
 *   Four RENDER sites: agent/kb_article.php, client/kb_article.php,
 *   api/v1/kb.php and agent/modals/kb_article/kb_article_version_view.php.
 *   Those four are the complete set of places that turn STORED article HTML
 *   into a page a reader sees. Miss one and that renderer silently flattens
 *   every block into prose - which is a readable document, not a broken one
 *   (proof 5), but it is not the feature.
 *
 *   A FIFTH call site exists and is not one of the four above:
 *   agent/post/kb_article.php's HTML-import path purifies once at STORE time
 *   (grep -rn "purify(" over the tree, excluding plugins/, finds all five).
 *   It must call apply() too - a config that forgot it would let this same
 *   importer's own interactive blocks be stripped back out of the markup it
 *   just built, before any of the four render sites ever saw them - but
 *   missing it there is a different failure (an import that silently cannot
 *   produce blocks) from missing it at a render site (a render that silently
 *   cannot show blocks that ARE stored), which is why the two are counted
 *   separately rather than folded into one "five call sites" line.
 */
final class InteractiveBlocks
{
    /**
     * Bump when the stored grammar changes in a way the render layer must know
     * about. Emitted as data-ikb-version on the render root so a cached
     * js/kb_interactive.js can tell it is older than the markup it is looking at.
     */
    public const VERSION = 1;

    /**
     * THE KEY GRAMMAR. 1-24 characters of lowercase alphanumeric plus hyphen,
     * first character alphanumeric. Nothing else may ever reach an attribute
     * value in this vocabulary.
     *
     * Deliberately excludes '_' so that POSITION_KEY below cannot collide with
     * a content key BY CONSTRUCTION rather than by convention. The design this
     * was built from used the literal key 'at' and defended it with "minted
     * keys are always 8 hex characters" - an invariant living in one function
     * and enforced by nothing. One character of charset closes it permanently.
     */
    public const KEY_REGEX = '/\A[a-z0-9][a-z0-9-]{0,23}\z/';

    /** Longest a key may be; also the varchar width the progress table needs. */
    public const KEY_MAX_LENGTH = 24;

    /**
     * RESERVED. A wizard's saved position is stored in the progress table under
     * this part key, holding the 0-based index of the step the reader reached.
     * The leading underscore is outside KEY_REGEX, so no authored part can ever
     * be called this. Storage layers must accept it (isStorableKey) while
     * refusing it as content (isKey).
     */
    public const POSITION_KEY = '_at';

    public const BLOCK_TYPES = ['sequence', 'tree', 'embed'];
    public const SEQUENCE_MODES = ['checklist', 'steps', 'tabs', 'accordion'];
    public const DEFAULT_MODE = 'checklist';

    /**
     * NUMERIC BOUNDS - invariant 4. Both ends of both integers, stated once
     * here and enforced twice: in the purifier AttrDef (so hostile markup never
     * survives the parse) and again in PHP/JS before the value is used (so a
     * row written before these bounds existed cannot reach a renderer either).
     *
     * EMBED_ID_MAX is 7 digits because kb_article_embeds.kb_article_embed_id is
     * an int(11) AUTO_INCREMENT and 9,999,999 embeds is four orders of
     * magnitude past anything this install will ever hold; the point of the cap
     * is to bound the DIGIT COUNT, not to predict the row count.
     *
     * EMBED_HEIGHT_MIN is 120 because a frame shorter than that cannot show a
     * heading plus one control, and EMBED_HEIGHT_MAX is 4000 because that is
     * roughly four 1080p viewports - past it the frame is a scroll trap.
     */
    public const EMBED_ID_MIN = 1;
    public const EMBED_ID_MAX = 9999999;
    public const EMBED_HEIGHT_MIN = 120;
    public const EMBED_HEIGHT_MAX = 4000;
    public const EMBED_HEIGHT_DEFAULT = 480;

    /**
     * A stored progress value is either a checklist tick (0/1) or a wizard step
     * index under POSITION_KEY. Bounded because it is written to a smallint(6)
     * column under STRICT_TRANS_TABLES, where an out-of-range value is a
     * mysqli ERROR, not a truncation.
     */
    public const STATE_MIN = 0;
    public const STATE_MAX = 9999;

    /** Longest label+body text that feeds partHash(). Bounds the hash cost. */
    private const HASH_TEXT_MAX = 8192;

    /**
     * Longest text normalise() will lift out of a part's body to serve as a
     * recovered label. A tab caption is a few words; past this it is a
     * paragraph, and turning a paragraph into a heading is a worse repair than
     * reporting the missing title.
     */
    private const LABEL_PROMOTE_MAX = 160;

    /**
     * Configs that have already had the vocabulary registered. A WeakMap so a
     * finished request's config objects are still collectable.
     *
     * WHY THIS EXISTS: HTMLPurifier_Config::getDefinition() THROWS
     * "Cannot retrieve raw definition after it has already been setup" if it is
     * asked for a raw definition twice with the second call landing after the
     * definition was used. Two render sites sharing one config object - or a
     * defensive second apply() by a caller who cannot tell whether the first
     * one happened - would otherwise take the page down. Measured on 4.15.0:
     * the throw is at HTMLPurifier.standalone.php:2348.
     */
    private static ?\WeakMap $applied = null;

    /**
     * REGISTER THE VOCABULARY ON A PURIFIER CONFIG.
     *
     * Call immediately BEFORE `new HTMLPurifier($config)`. Idempotent per
     * config object.
     *
     * HOW, AND WHY THIS EXACT FORM. 4.15.0 has no wildcard switch for data-*
     * attributes - there is no %HTML.AllowedAttributes shortcut that takes
     * "data-*" - so the mechanism is a RAW HTMLDefinition.
     *
     * NO HTML.DefinitionID AND NO HTML.DefinitionRev, deliberately. All four KB
     * configs set Cache.DefinitionImpl = null, and with the cache off 4.15.0
     * emits
     *     "Due to a documentation error in previous version of HTML Purifier,
     *      your definitions are not being cached."
     * from HTMLPurifier.standalone.php:2399 on EVERY request if those two are
     * set. getHTMLDefinition(true) with neither of them is warning-free, which
     * is the form used here. maybeGetRawHTMLDefinition() is NOT an alternative:
     * it passes $optimized = true, which throws without a DefinitionID
     * (:2337). Do not "fix" this.
     *
     * PER ELEMENT, NEVER GLOBALLY. Every attribute is registered against the
     * specific elements that may carry it. $def->info_global_attr is
     * deliberately untouched: registering there would let <span data-ikb>,
     * <img data-ikb> and 20 other elements declare themselves blocks. Measured
     * refusals in proof 3.
     *
     * COST: 0.2 ms to build the raw definition, paid on every article render
     * including the ones with no blocks, because Cache.DefinitionImpl is null.
     * Measured in proof 6.
     */
    public static function apply(\HTMLPurifier_Config $config): void
    {
        if (self::$applied === null) {
            self::$applied = new \WeakMap();
        }
        if (isset(self::$applied[$config])) {
            return;
        }
        self::$applied[$config] = true;

        try {
            $def = $config->getHTMLDefinition(true);
        } catch (\HTMLPurifier_Exception $e) {
            /* The only way to get here is calling apply() AFTER the config has
             * already been handed to `new HTMLPurifier()` and used. Re-thrown
             * rather than swallowed: silently skipping registration would make
             * every interactive block on that renderer flatten into prose with
             * no error anywhere, which is precisely the invisible failure this
             * class exists to prevent. */
            throw new \LogicException(
                'InteractiveBlocks::apply() must be called BEFORE new HTMLPurifier($config). '
                . 'Move the call above the constructor. (' . $e->getMessage() . ')',
                0,
                $e
            );
        }

        if ($def === null) {
            return; // Unreachable with $optimized = false; kept as a contract guard.
        }

        $key    = new InteractiveKeyAttrDef();
        $block  = new \HTMLPurifier_AttrDef_Enum(self::BLOCK_TYPES, false);
        $mode   = new \HTMLPurifier_AttrDef_Enum(self::SEQUENCE_MODES, false);
        $embed  = new InteractiveUintAttrDef(self::EMBED_ID_MIN, self::EMBED_ID_MAX);
        $height = new InteractiveUintAttrDef(self::EMBED_HEIGHT_MIN, self::EMBED_HEIGHT_MAX);

        // The block wrapper and every structural element inside it.
        $def->addAttribute('div', 'data-ikb', $block);
        $def->addAttribute('div', 'data-ikb-mode', $mode);
        $def->addAttribute('div', 'data-ikb-key', $key);
        $def->addAttribute('div', 'data-ikb-part', $key);
        $def->addAttribute('div', 'data-ikb-node', $key);
        $def->addAttribute('div', 'data-ikb-start', $key);
        $def->addAttribute('div', 'data-ikb-embed', $embed);
        $def->addAttribute('div', 'data-ikb-height', $height);

        // A decision-tree choice. <a>, not <li>, so the no-JS reader still sees
        // something that looks and copies like a link.
        $def->addAttribute('a', 'data-ikb-go', $key);

        // The copy marker. Its own one-value enum rather than $block, so
        // <div data-ikb="copy"> and <pre data-ikb="sequence"> are both refused.
        $def->addAttribute('pre', 'data-ikb', new \HTMLPurifier_AttrDef_Enum(['copy'], false));

        /* NOT REGISTERED, ON PURPOSE:
         *   - class. 4.15.0 already allows it on every element under the
         *     default HTML 4.01 doctype; registering it again would be a no-op
         *     at best. It is the CSS hook and the TinyMCE guard's hook.
         *   - id, and every element that would need one (label[for],
         *     aria-controls, a real fragment link). See invariant 2.
         *   - <details>, <summary>, <input>, <button>, <iframe>. Every control
         *     is created by JavaScript at render time. Adding <iframe> to the
         *     schema so the embed could be stored would put an author-controlled
         *     frame src in the database; the render layer builds that element
         *     instead, from a bounded integer. */
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GRAMMAR PREDICATES - the storage layers validate request values with these
    // ─────────────────────────────────────────────────────────────────────────

    /** True for a value that may appear as a content key in stored markup. */
    public static function isKey($value): bool
    {
        return is_string($value) && preg_match(self::KEY_REGEX, $value) === 1;
    }

    /**
     * True for a value a progress row may legally use as its part key: a content
     * key, or the reserved wizard position key. Write endpoints should validate
     * with THIS, and read nothing else into the table.
     */
    public static function isStorableKey($value): bool
    {
        return $value === self::POSITION_KEY || self::isKey($value);
    }

    public static function isBlockType($value): bool
    {
        return is_string($value) && in_array($value, self::BLOCK_TYPES, true);
    }

    public static function isSequenceMode($value): bool
    {
        return is_string($value) && in_array($value, self::SEQUENCE_MODES, true);
    }

    /**
     * A fresh key: exactly 8 lowercase hex characters, so it always satisfies
     * KEY_REGEX and always sorts as an opaque identifier rather than looking
     * like something an agent may edit.
     *
     * random_bytes(), not mt_rand(): a guessable key is not a security problem
     * here (keys are public in the markup) but a COLLIDING key is a correctness
     * problem - two parts sharing a key share a reader's tick.
     */
    public static function mintKey(): string
    {
        return bin2hex(random_bytes(4));
    }

    /** Second bound on the embed height - invariant 4, the "twice" half. */
    public static function clampHeight(int $height): int
    {
        if ($height < self::EMBED_HEIGHT_MIN || $height > self::EMBED_HEIGHT_MAX) {
            return self::EMBED_HEIGHT_DEFAULT;
        }
        return $height;
    }

    /** Bound on a progress value before it reaches a smallint under STRICT mode. */
    public static function clampState(int $state): int
    {
        if ($state < self::STATE_MIN) {
            return self::STATE_MIN;
        }
        if ($state > self::STATE_MAX) {
            return self::STATE_MAX;
        }
        return $state;
    }

    /**
     * Cheap test used to decide whether an article needs any of this at all -
     * partHashes() and normalise() both skip the DOM parse entirely when this
     * is false.
     *
     * A REGEX ON THE ATTRIBUTE=VALUE SHAPE, not a bare substring test. A plain
     * `stripos($html, 'data-ikb') !== false` matched an article that only
     * MENTIONS the bare word in prose or names an unrelated attribute
     * (data-ikb-part, data-ikb-node, "the data-ikb attribute...") - measured:
     * this file's own vocabulary documentation is exactly that kind of
     * article - and normalise() would then run a full DOMDocument parse and
     * re-serialise on every save of a page with no block at all, decoding
     * named entities and auto-closing tags along the way. Not unsafe
     * (normalise() never deletes authored text) but pointless churn of the
     * stored bytes, and it makes every future safelist mistake here look
     * "cheap" when it silently is not. Requiring the =value shape closes that
     * for the common case.
     *
     * WHAT THIS DOES NOT AND CANNOT CLOSE: a code sample that spells the exact
     * characters `data-ikb="sequence"` - inside a <code> or <pre>, as prose
     * about the feature written character-for-character - is byte-identical
     * to a real attribute at this point in the pipeline, and no substring or
     * regex test over raw HTML text can tell the two apart; only a real parse
     * can, which is the cost this function exists to skip. That residual
     * false positive costs one DOM round trip, is idempotent, and (per this
     * class's own guarantee above) deletes nothing - so it is accepted rather
     * than chased with a parser this function was built specifically to avoid
     * running. */
    public static function contains(string $html): bool
    {
        return preg_match(
            '/data-ikb\s*=\s*["\']?(?:' . implode('|', self::BLOCK_TYPES) . '|copy)\b/i',
            $html
        ) === 1;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STALE-TICK HASHING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * THE STALE-TICK HASH, over the label AND the body.
     *
     * A reader ticks step 4. An agent later edits the article. Keys are minted
     * once and never rewritten, so the tick correctly follows its step through
     * a reorder or a rename - but "correctly follows" is wrong when the step's
     * MEANING changed. This hash is how the renderer can say so.
     *
     * label AND body, deliberately, and this is a change from the design this
     * was built from. That design hashed the LABEL ONLY and listed the
     * consequence as a known weakness: rewriting a step's body from "reboot the
     * switch" to "do NOT reboot the switch" leaves every existing tick green
     * with no warning at all - the more dangerous edit is the unflagged one.
     * Hashing both closes it. The cost is noise on a body-only typo fix, and a
     * stale mark is a note beside a tick, not a lost tick, so noise is the
     * cheaper failure.
     *
     * Text only, normalised: markup churn (a <b> becoming <strong>, TinyMCE
     * reflowing whitespace) must not fire it. Case IS significant, because
     * "MUST" and "must" can be the whole of the change.
     *
     * 16 hex characters of sha1. Not a security primitive - it answers "did
     * these words change", and a reader who can edit the article can already
     * change the words.
     */
    public static function partHash(string $label, string $body): string
    {
        $text = self::normaliseText($label) . "\x1f" . self::normaliseText($body);
        if (strlen($text) > self::HASH_TEXT_MAX) {
            $text = substr($text, 0, self::HASH_TEXT_MAX);
        }
        return substr(sha1($text), 0, 16);
    }

    /**
     * Every sequence part's current hash, keyed by block then part:
     *   ['a3f19c22' => ['7b1e0d44' => 'c0ffee...', ...], ...]
     *
     * The renderer compares this against the hashes stored with each tick;
     * the write endpoint stores the value from here for the part being ticked.
     * Both sides derive it from the SAME function over the SAME stored HTML,
     * which is what keeps the two from drifting.
     */
    public static function partHashes(string $html): array
    {
        if (!self::contains($html)) {
            return [];
        }

        $doc = self::parse($html);
        if ($doc === null) {
            return [];
        }

        $out = [];
        foreach (self::blockElements($doc) as $block) {
            if ($block->getAttribute('data-ikb') !== 'sequence') {
                continue;
            }
            $blockKey = $block->getAttribute('data-ikb-key');
            if (!self::isKey($blockKey)) {
                continue;
            }
            $parts = [];
            foreach (self::childrenWithAttribute($block, 'data-ikb-part') as $part) {
                $partKey = $part->getAttribute('data-ikb-part');
                if (!self::isKey($partKey)) {
                    continue;
                }
                $label = self::firstByClass($part, 'ikb-label', $block);
                $body  = self::firstByClass($part, 'ikb-body', $block);
                $parts[$partKey] = self::partHash(
                    $label === null ? '' : $label->textContent,
                    $body === null ? '' : $body->textContent
                );
            }
            if ($parts !== []) {
                $out[$blockKey] = $parts;
            }
        }

        return $out;
    }

    /**
     * BUILD THE data-ikb-progress ATTRIBUTE VALUE.
     *
     * Takes the map kbProgressLoad() returns
     * (agent/includes/kb_progress_store.php) and re-validates it on the way
     * out:
     *
     *   {"<blockKey>": {"<partKey>": {"s": <int>, "h": "<16 hex>"}, ...}, ...}
     *
     *   s  0/1 for a checklist tick; the 0-based step index when the part key
     *      is POSITION_KEY ("_at").
     *   h  the part hash recorded AT TICK TIME, absent when none was stored.
     *      js/kb_interactive.js compares it against the CURRENT hash from
     *      data-ikb-hashes and marks the tick stale when they differ. The
     *      comparison is on the client because both values are already there
     *      and neither is a secret; the browser cannot compute the hash itself
     *      (crypto.subtle needs a secure context and this app is reached over
     *      plain HTTP), which is why the current values are shipped rather than
     *      derived.
     *
     * SHAPE, NOT PROVENANCE, IS WHAT IS CHECKED HERE. A row is not a trust
     * boundary: these values are written through kbProgressSave(), which
     * validates, and re-checking is what keeps a hypothetically poisoned column
     * from reaching an HTML attribute. Same reasoning, and the same grammar,
     * as kbProgressLoad()'s own re-check.
     *
     * A 200-item runbook is about 6 KB of attribute; below that it is noise.
     *
     * PRIVACY NOTE FOR THE CALLER: this embeds ONE reader's state in the page
     * body. That is safe only because both article pages are sent
     * "Cache-Control: no-store" - measured on the live site, the portal sends
     * "no-store, no-cache, must-revalidate". The day a shared cache appears in
     * front of an article page, one contact's progress would be served to
     * another and nothing in this code would notice.
     */
    public static function progressAttribute(array $progress): string
    {
        $out = [];

        foreach ($progress as $blockKey => $parts) {
            if (!self::isKey((string) $blockKey) || !is_array($parts)) {
                continue;
            }
            foreach ($parts as $partKey => $entry) {
                if (!self::isStorableKey((string) $partKey)) {
                    continue;
                }
                if (is_int($entry) || is_string($entry)) {
                    $entry = ['s' => (int) $entry];
                }
                if (!is_array($entry)) {
                    continue;
                }
                $row = ['s' => self::clampState((int) ($entry['s'] ?? 0))];
                $hash = (string) ($entry['h'] ?? '');
                if ($partKey !== self::POSITION_KEY && preg_match('/\A[0-9a-f]{16}\z/', $hash) === 1) {
                    $row['h'] = $hash;
                }
                $out[(string) $blockKey][(string) $partKey] = $row;
            }
        }

        return self::encodeAttribute($out);
    }

    /**
     * BUILD THE data-ikb-hashes ATTRIBUTE VALUE - every sequence part's hash as
     * the article stands RIGHT NOW: {"<blockKey>": {"<partKey>": "<16 hex>"}}.
     *
     * Paired with data-ikb-progress above. Returns "{}" for an article with no
     * blocks without parsing anything.
     */
    public static function hashesAttribute(string $html): string
    {
        return self::encodeAttribute(self::partHashes($html));
    }

    /**
     * JSON_FORCE_OBJECT, same reason kb_progress_store.php's kbProgressJson()
     * needs it for the HTTP response: block/part keys are strings by
     * grammar, but a sequence's part keys are commonly the numeric strings
     * "0", "1", "2"... and PHP's json_encode() treats an array whose keys
     * are exactly a 0-indexed numeric sequence as a JSON ARRAY, not an
     * OBJECT, with no way to tell it apart from a real list after the fact.
     * data-ikb-progress/data-ikb-hashes are documented (and js/kb_interactive.js
     * parses them) as JSON OBJECTS keyed by part - without this, the exact
     * sequences most likely to trigger it (a 3+ part sequence numbered from
     * zero) would silently render as an array attribute instead.
     * JSON_UNESCAPED_SLASHES otherwise: the value is emitted through
     * htmlspecialchars(..., ENT_QUOTES) at the render site, which is what makes
     * it safe inside an attribute; every key has already been through
     * isKey()/isStorableKey() and every value is an int or 16 hex characters, so
     * nothing here can carry a quote, an angle bracket or a non-ASCII byte in
     * the first place.
     */
    private static function encodeAttribute(array $value): string
    {
        if ($value === []) {
            return '{}';
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);

        return $json === false ? '{}' : $json;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SAVE-TIME REPAIR
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * REPAIR A BLOCK'S STRUCTURE ON SAVE. Guard first, repair second, tell the
     * agent third.
     *
     * WHY A SERVER-SIDE PASS EXISTS AT ALL when js/tinymce_ikb.js already makes
     * the structure non-editable furniture: because TinyMCE is not the only
     * editor. js/app.js's initDocBuilder() wraps that same editor in Rich Text
     * / Markdown / HTML tabs. The HTML tab is a raw textarea with no schema and
     * no contenteditable, and the Markdown tab round-trips the content through
     * Turndown, which has never heard of .ikb-part. Neither respects the guard.
     * So every path that writes kb_article_content runs this instead of trusting
     * any editor: add, edit, the DOCX import, the PDF import and the HTML import.
     *
     * WHAT IT FIXES
     *   - mints a missing block or part or node key, and re-mints a DUPLICATE
     *     key (copy-paste is how duplicates happen, and two parts sharing a key
     *     share a reader's tick)
     *   - restores the class hooks (ikb / ikb-part / ikb-node / ikb-body)
     *   - promotes a part's first text-bearing child back to .ikb-label when the
     *     label element was lost - a single Backspace at the start of a label
     *     does exactly that in an unguarded editor
     *   - wraps a part's non-label content in .ikb-body
     *   - drops the <p>&nbsp;</p> a stray Enter leaves inside a part
     *   - repairs or replaces a data-ikb-start naming no node
     *   - drops a data-ikb-go naming no node (the link and its text stay)
     *   - re-points each choice's href at its own block, so a re-minted block
     *     key does not leave a stale fragment behind
     *   - clamps data-ikb-height and drops an out-of-range data-ikb-embed
     *   - STRIPS contenteditable everywhere. Measured: contenteditable="false"
     *     SURVIVES the stock KB purifier (the ContentEditable AttrDef is
     *     registered on div under the default doctype), so this pass is the only
     *     thing that can guarantee the editor's own marker never reaches
     *     storage.
     *
     * WHAT IT DOES NOT DO: it never deletes a part, a node or any authored text.
     * A block it cannot understand is left exactly as it is and reported. The
     * failure it is guarding against is a lost tick, and destroying content to
     * tidy structure would be worse than the thing being repaired.
     *
     * @return array{html: string, warnings: string[]}
     */
    public static function normalise(string $html): array
    {
        if (!self::contains($html)) {
            return ['html' => $html, 'warnings' => []];
        }

        $doc = self::parse($html);
        if ($doc === null) {
            return ['html' => $html, 'warnings' => ['Interactive blocks could not be checked: the article HTML would not parse.']];
        }

        $warnings = [];
        $seenBlockKeys = [];

        // contenteditable is an editor-session marker and must never be stored.
        $stripped = 0;
        foreach (iterator_to_array($doc->getElementsByTagName('*')) as $el) {
            if ($el instanceof \DOMElement && $el->hasAttribute('contenteditable')) {
                $el->removeAttribute('contenteditable');
                $stripped++;
            }
        }
        if ($stripped > 0) {
            $warnings[] = "Removed $stripped editor-only contenteditable marker" . ($stripped === 1 ? '' : 's') . '.';
        }

        foreach (self::blockElements($doc) as $block) {
            $type = $block->getAttribute('data-ikb');

            if ($block->nodeName === 'pre') {
                // The copy marker. Nothing structural to repair; just the hook.
                self::addClass($block, 'ikb-copy');
                continue;
            }

            if (!self::isBlockType($type)) {
                $block->removeAttribute('data-ikb');
                $warnings[] = 'One block declared an unknown type and was left as ordinary content.';
                continue;
            }

            self::addClass($block, 'ikb');

            $blockKey = $block->getAttribute('data-ikb-key');
            if (!self::isKey($blockKey) || isset($seenBlockKeys[$blockKey])) {
                $wasDuplicate = self::isKey($blockKey) && isset($seenBlockKeys[$blockKey]);
                $blockKey = self::mintKey();
                $block->setAttribute('data-ikb-key', $blockKey);
                if ($wasDuplicate) {
                    $warnings[] = 'Two blocks shared an identifier; one was given a new one, and its saved progress starts fresh.';
                }
            }
            $seenBlockKeys[$blockKey] = true;

            if ($type === 'sequence') {
                self::normaliseSequence($doc, $block, $warnings);
            } elseif ($type === 'tree') {
                self::normaliseTree($block, $blockKey, $warnings);
            } else { // embed
                self::normaliseEmbed($block, $warnings);
            }
        }

        return ['html' => self::serialise($doc), 'warnings' => $warnings];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // normalise() internals
    // ─────────────────────────────────────────────────────────────────────────

    private static function normaliseSequence(\DOMDocument $doc, \DOMElement $block, array &$warnings): void
    {
        $mode = $block->getAttribute('data-ikb-mode');
        if (!self::isSequenceMode($mode)) {
            $block->setAttribute('data-ikb-mode', self::DEFAULT_MODE);
            $warnings[] = 'A block had no valid display mode and is showing as a checklist.';
        }

        $seen = [];
        $repairedLabels = 0;
        $unlabelled = 0;

        foreach (self::sequenceParts($block) as $part) {
            self::addClass($part, 'ikb-part');

            $partKey = $part->getAttribute('data-ikb-part');
            if (!self::isKey($partKey) || isset($seen[$partKey])) {
                $partKey = self::mintKey();
                $part->setAttribute('data-ikb-part', $partKey);
            }
            $seen[$partKey] = true;

            self::dropEmptyParagraphs($part);

            $label = self::firstByClass($part, 'ikb-label', $block);
            if ($label === null) {
                $label = self::promoteLabel($doc, $part, $block);
                if ($label !== null) {
                    $repairedLabels++;
                } else {
                    $unlabelled++;
                }
            }

            if ($label !== null) {
                self::wrapBody($doc, $part, $label, $block);
            }

            /* A .ikb-body left holding nothing - which is what promoting its
             * only paragraph up to be the label produces - is furniture the
             * render layer would otherwise draw an empty panel for. */
            $body = self::firstByClass($part, 'ikb-body', $block);
            if ($body !== null
                && $body->getElementsByTagName('*')->length === 0
                && trim(str_replace("\xc2\xa0", ' ', $body->textContent)) === '') {
                $body->parentNode->removeChild($body);
            }
        }

        if ($repairedLabels > 0) {
            $warnings[] = $repairedLabels . ' step' . ($repairedLabels === 1 ? '' : 's')
                . ' lost ' . ($repairedLabels === 1 ? 'its title' : 'their titles') . ' and had it restored from the first line.';
        }
        if ($unlabelled > 0) {
            /* Not repairable without inventing or moving words, so it is
             * reported instead. A part with no title still renders - it is a
             * body with no heading - but it is unusable as a tab caption or an
             * accordion header, so the agent has to be told. */
            $warnings[] = $unlabelled . ' step' . ($unlabelled === 1 ? ' has' : 's have')
                . ' no title. Give ' . ($unlabelled === 1 ? 'it' : 'them') . ' one, or the tab and accordion displays will show a blank heading.';
        }
    }

    private static function normaliseTree(\DOMElement $block, string $blockKey, array &$warnings): void
    {
        $seen = [];
        $nodes = [];

        foreach (self::childrenWithAttribute($block, 'data-ikb-node') as $node) {
            self::addClass($node, 'ikb-node');
            $nodeKey = $node->getAttribute('data-ikb-node');
            if (!self::isKey($nodeKey) || isset($seen[$nodeKey])) {
                $nodeKey = self::mintKey();
                $node->setAttribute('data-ikb-node', $nodeKey);
            }
            $seen[$nodeKey] = true;
            $nodes[] = $nodeKey;
        }

        $start = $block->getAttribute('data-ikb-start');
        if (!isset($seen[$start])) {
            if ($nodes === []) {
                $block->removeAttribute('data-ikb-start');
                $warnings[] = 'A decision tree has no questions left in it.';
            } else {
                $block->setAttribute('data-ikb-start', $nodes[0]);
                if ($start !== '') {
                    $warnings[] = 'A decision tree started at a question that no longer exists; it now starts at the first one.';
                }
            }
        }

        /* Choices. Dangling targets lose the binding, never the text.
         *
         * getElementsByTagName('a') is a full DESCENDANT walk, so an anchor
         * belonging to a tree NESTED inside this one (a troubleshooter step
         * that itself contains a smaller decision tree) would otherwise be
         * rewritten here, against THIS block's $seen map, before its own
         * normaliseTree() pass ever runs - stripping data-ikb-go from every
         * one of its choices because none of its node keys are in scope yet.
         * blockElements() visits blocks in document order, so the OUTER tree
         * is always processed first; without this filter every inner tree
         * would lose its branches on the very first save. Mirrors
         * childrenWithAttribute()'s direct-children rule and js/kb_interactive.js
         * bindTree()'s own `choice.closest('[data-ikb]') !== block` guard on
         * its click handler - an anchor only belongs to THIS block if walking
         * up from it hits no [data-ikb] element before reaching $block. */
        $dangling = 0;
        foreach ($block->getElementsByTagName('a') as $anchor) {
            if (!$anchor->hasAttribute('data-ikb-go') || self::nearestIkbAncestor($anchor) !== $block) {
                continue;
            }
            $target = $anchor->getAttribute('data-ikb-go');
            if (!isset($seen[$target])) {
                $anchor->removeAttribute('data-ikb-go');
                $dangling++;
                continue;
            }
            /* The href is the no-JS fallback and is regenerated here so a
             * re-minted block key cannot leave a stale fragment behind. It
             * cannot actually jump - Attr.EnableID is off, so no node carries
             * the matching id - and that is accepted: it exists so the choice
             * looks and copies like a link. data-ikb-go is what the render
             * layer binds. */
            $anchor->setAttribute('href', '#ikb-' . $blockKey . '-' . $target);
        }
        if ($dangling > 0) {
            $warnings[] = $dangling . ' decision-tree choice' . ($dangling === 1 ? '' : 's')
                . ' pointed at a question that no longer exists and no longer branch' . ($dangling === 1 ? 'es' : '') . '.';
        }
    }

    private static function normaliseEmbed(\DOMElement $block, array &$warnings): void
    {
        $id = $block->getAttribute('data-ikb-embed');
        if (preg_match('/\A[0-9]{1,7}\z/', $id) !== 1
            || (int) $id < self::EMBED_ID_MIN
            || (int) $id > self::EMBED_ID_MAX) {
            $block->removeAttribute('data-ikb-embed');
            $warnings[] = 'An embedded tool had no valid identifier; its description is still in the article.';
        }

        if ($block->hasAttribute('data-ikb-height')) {
            $height = $block->getAttribute('data-ikb-height');
            $clamped = self::clampHeight(preg_match('/\A[0-9]{1,7}\z/', $height) === 1 ? (int) $height : 0);
            if ((string) $clamped !== $height) {
                $block->setAttribute('data-ikb-height', (string) $clamped);
            }
        }
    }

    /**
     * Move everything after the label into a .ikb-body wrapper, so the render
     * layer has one element to show, hide, collapse or put in a tab pane. A
     * part whose only content is its label keeps no body at all.
     */
    private static function wrapBody(\DOMDocument $doc, \DOMElement $part, \DOMElement $label, \DOMElement $block): void
    {
        $existing = self::firstByClass($part, 'ikb-body', $block);
        if ($existing !== null) {
            return;
        }

        $after = [];
        $found = false;
        foreach (iterator_to_array($part->childNodes) as $child) {
            if ($child === $label) {
                $found = true;
                continue;
            }
            if (!$found) {
                continue;
            }
            if ($child instanceof \DOMText && trim($child->textContent) === '') {
                continue;
            }
            $after[] = $child;
        }

        if ($after === []) {
            return;
        }

        $body = $doc->createElement('div');
        $body->setAttribute('class', 'ikb-body');
        $part->appendChild($body);
        foreach ($after as $child) {
            $body->appendChild($child);
        }
    }

    /**
     * A part with no .ikb-label. Restore one WITHOUT inventing or deleting a
     * single word, in three escalating attempts:
     *
     *   1. the first child element outside .ikb-body that carries text - mark
     *      it as the label where it stands;
     *   2. bare text sitting directly in the part - wrap it in an <h5>;
     *   3. the first heading or paragraph INSIDE .ikb-body - lift it out and
     *      mark it. This is the case a single Backspace at the start of a label
     *      actually produces (measured: the <h5 class="ikb-label"> element is
     *      deleted outright and only the .ikb-body survives), so without step 3
     *      the commonest damage would be reported and never repaired.
     *
     * Step 3 refuses anything that is not a heading or a paragraph, and refuses
     * text longer than LABEL_PROMOTE_MAX: promoting a table, an image or a
     * three-paragraph preamble into a tab caption produces a worse article than
     * leaving the part untitled and saying so.
     */
    private static function promoteLabel(\DOMDocument $doc, \DOMElement $part, \DOMElement $block): ?\DOMElement
    {
        foreach ($part->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                if (self::hasClass($child, 'ikb-body')) {
                    continue;
                }
                // A part whose entire content is one nested interactive block
                // (no separate label, no .ikb-body at this level) must not have
                // THAT block's own container promoted as the outer part's
                // label: render-time firstIn() (see nearestIkbAncestor() calls
                // elsewhere in this class) correctly refuses to recognise a
                // nested block's container as an ancestor part's label - its
                // nearest [data-ikb] ancestor is itself, not $block - so doing
                // it here would only add a stray ikb-label class onto the
                // nested block while leaving the outer part exactly as
                // unlabelled as before. Fall through to steps 2/3 instead.
                if ($child->hasAttribute('data-ikb')) {
                    continue;
                }
                if (trim($child->textContent) === '') {
                    continue;
                }
                self::addClass($child, 'ikb-label');
                return $child;
            }
            if ($child instanceof \DOMText && trim($child->textContent) !== '') {
                $label = $doc->createElement('h5');
                $label->setAttribute('class', 'ikb-label');
                $part->insertBefore($label, $child);
                $label->appendChild($child);
                return $label;
            }
        }

        $body = self::firstByClass($part, 'ikb-body', $block);
        if ($body === null) {
            return null;
        }
        foreach ($body->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            $text = self::normaliseText($child->textContent);
            if ($text === '') {
                continue;
            }
            if (!in_array($child->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'], true)) {
                return null;
            }
            if (mb_strlen($text) > self::LABEL_PROMOTE_MAX) {
                return null;
            }
            self::addClass($child, 'ikb-label');
            $part->insertBefore($child, $body);
            return $child;
        }

        return null;
    }

    /** The <p>&nbsp;</p> a stray Enter leaves behind. Direct children only. */
    private static function dropEmptyParagraphs(\DOMElement $part): void
    {
        foreach (iterator_to_array($part->childNodes) as $child) {
            if (!($child instanceof \DOMElement) || $child->nodeName !== 'p') {
                continue;
            }
            if ($child->getElementsByTagName('img')->length > 0) {
                continue;
            }
            // U+00A0 is what &nbsp; became at parse time.
            if (trim(str_replace("\xc2\xa0", ' ', $child->textContent)) === '') {
                $part->removeChild($child);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DOM plumbing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse an article-content FRAGMENT.
     *
     * DOMDocument rather than PHP 8.4's Dom\HTMLDocument, to match
     * src/KB/DocxConverter.php and src/KB/PdfConverter.php, which are the two
     * other places in this namespace that parse markup.
     *
     * The <meta charset> preamble is load-bearing: without it libxml treats the
     * bytes as ISO-8859-1 and every non-ASCII character in an article comes back
     * mojibaked. LIBXML_NONET because a document must never fetch anything, and
     * LIBXML_NOERROR/NOWARNING because article HTML is routinely "invalid" in
     * ways libxml complains about and repairs correctly anyway.
     *
     * NEITHER FLAG SILENCES A FATAL, and that is why a fatal is checked for
     * explicitly below rather than trusted to $ok. LIBXML_NOERROR/NOWARNING
     * suppress libxml's ERROR and WARNING severities (levels 2 and 1) - the
     * routine "invalid but repairable" noise the docblock above is about - but
     * libxml issues a level-3 FATAL when a document exceeds its own
     * undocumented nesting-depth limit, and past that point it does not
     * repair the tree, it TRUNCATES it: the parse still reports success ($ok
     * stays true) but everything past the limit is silently gone. Measured on
     * the libxml bundled with this box (2.9.14): a fragment 300 levels of
     * <div> deep round-trips through loadHTML() with $ok === true and ZERO
     * entries from libxml_get_errors() at 250 levels, but at 256 the parse
     * yields exactly one error - level 3 (LIBXML_ERR_FATAL), "Excessive depth
     * in document: 256 use XML_PARSE_HUGE option" - and the serialised output
     * is missing the innermost content, four elements short at 256 levels and
     * unchanged in size at every depth beyond that (libxml stops at the same
     * 256-level cutoff regardless of how much deeper the input goes). Left
     * unchecked, normalise() would then serialise($doc) and hand back fewer
     * elements than it was given with an empty warnings array - a silent
     * deletion of authored content, exactly what this class's own contract
     * ("WHAT IT DOES NOT DO... it never deletes a part, a node or any
     * authored text") says cannot happen. Treating a fatal as a parse
     * failure - the same outcome $ok === false already produces - is what
     * keeps that promise: normalise() falls back to the ORIGINAL bytes with a
     * warning instead of storing the truncated form, and partHashes() falls
     * back to reporting no hashes rather than hashes computed over a mutilated
     * tree.
     */
    private static function parse(string $html): ?\DOMDocument
    {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;

        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
            . $html
            . '</body></html>';

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $doc->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok) {
            return null;
        }
        foreach ($errors as $error) {
            if ($error->level >= LIBXML_ERR_FATAL) {
                return null;
            }
        }

        return $doc;
    }

    /** Serialise the body's children back to a fragment. */
    private static function serialise(\DOMDocument $doc): string
    {
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /** Every element declaring itself part of the vocabulary, in document order. */
    private static function blockElements(\DOMDocument $doc): array
    {
        $out = [];
        foreach ($doc->getElementsByTagName('*') as $el) {
            if ($el instanceof \DOMElement && $el->hasAttribute('data-ikb')) {
                $out[] = $el;
            }
        }
        return $out;
    }

    /**
     * Direct children carrying an attribute.
     *
     * DIRECT children, so a block nested inside another block cannot steal its
     * parent's parts. The render layer uses the same rule (":scope > ...").
     */
    private static function childrenWithAttribute(\DOMElement $parent, string $attribute): array
    {
        $out = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->hasAttribute($attribute)) {
                $out[] = $child;
            }
        }
        return $out;
    }

    /**
     * A sequence's parts: direct children carrying data-ikb-part, plus direct
     * children that only carry the class (an editor can strip an attribute and
     * leave the class, and that part still deserves its key back).
     */
    private static function sequenceParts(\DOMElement $block): array
    {
        $out = [];
        foreach ($block->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            if ($child->hasAttribute('data-ikb-part') || self::hasClass($child, 'ikb-part')) {
                $out[] = $child;
            }
        }
        return $out;
    }

    /**
     * First descendant of $scope carrying $class, but never one belonging to a
     * NESTED block - mirrors js/kb_interactive.js's firstIn(), which requires
     * `found[i].closest('[data-ikb]') === block`. Before this fix this method
     * was a plain descendant search: a sequence part containing a nested block
     * (a tabs-inside-a-wizard-step article) could have its .ikb-label / .ikb-body
     * satisfied by the NESTED block's own label/body, so normaliseSequence()
     * concluded the outer part was already labelled and left it exactly as
     * un-tickable as firstIn() (correctly) sees it in the browser.
     *
     * $block is the [data-ikb] element a match must resolve back to - pass the
     * part's own containing block, the same argument firstIn() takes.
     */
    private static function firstByClass(\DOMElement $scope, string $class, \DOMElement $block): ?\DOMElement
    {
        foreach ($scope->getElementsByTagName('*') as $el) {
            if ($el instanceof \DOMElement
                && self::hasClass($el, $class)
                && self::nearestIkbAncestor($el) === $block) {
                return $el;
            }
        }
        return null;
    }

    /**
     * Walks up from $el (inclusive) to the nearest ancestor carrying data-ikb -
     * the DOM equivalent of `element.closest('[data-ikb]')`, which DOMElement
     * has no built-in method for.
     */
    private static function nearestIkbAncestor(\DOMElement $el): ?\DOMElement
    {
        $node = $el;
        while ($node instanceof \DOMElement) {
            if ($node->hasAttribute('data-ikb')) {
                return $node;
            }
            $node = $node->parentNode instanceof \DOMElement ? $node->parentNode : null;
        }
        return null;
    }

    private static function hasClass(\DOMElement $el, string $class): bool
    {
        $value = $el->getAttribute('class');
        if ($value === '') {
            return false;
        }
        return in_array($class, preg_split('/\s+/', trim($value)) ?: [], true);
    }

    private static function addClass(\DOMElement $el, string $class): void
    {
        if (self::hasClass($el, $class)) {
            return;
        }
        $value = trim($el->getAttribute('class'));
        $el->setAttribute('class', $value === '' ? $class : $value . ' ' . $class);
    }

    /** Collapse every run of whitespace (U+00A0 included) to one space, trim. */
    private static function normaliseText(string $text): string
    {
        $text = str_replace("\xc2\xa0", ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}

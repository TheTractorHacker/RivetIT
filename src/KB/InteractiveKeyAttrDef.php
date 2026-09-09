<?php

namespace ITFlow\KB;

/**
 * THE KEY ATTRIBUTE for the interactive-KB vocabulary: 1-24 characters of
 * [a-z0-9-] beginning with an alphanumeric, lowercased.
 *
 * Used by InteractiveBlocks::apply() for data-ikb-key, data-ikb-part,
 * data-ikb-node, data-ikb-start and data-ikb-go. The grammar itself lives in
 * InteractiveBlocks::KEY_REGEX so the purifier, the normaliser, the render
 * layer and the progress endpoints all state it once.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ITS OWN FILE, AND THAT IS NOT COSMETIC
 * ─────────────────────────────────────────────────────────────────────────────
 * This class extends \HTMLPurifier_AttrDef, which does not exist until
 * plugins/htmlpurifier/HTMLPurifier.standalone.php has been required - and that
 * file is required only by the four KB RENDERERS. While this class lived
 * alongside InteractiveBlocks, merely autoloading InteractiveBlocks (to call
 * isKey(), partHashes() or normalise()) was a FATAL ERROR on every code path
 * that had not loaded the purifier - which is every save path, the one place
 * normalise() is supposed to run. Measured, not theorised:
 *
 *     PHP Fatal error: Uncaught Error: Class "HTMLPurifier_AttrDef" not found
 *     in src/KB/InteractiveBlocks.php:1085
 *
 * Split out, InteractiveBlocks.php loads with no dependency at all, and this
 * file is only ever autoloaded from inside apply() - which by definition runs
 * after the purifier is loaded.
 *
 * So: never reference this class from anywhere except
 * InteractiveBlocks::apply().
 */
final class InteractiveKeyAttrDef extends \HTMLPurifier_AttrDef
{
    public function validate($string, $config, $context)
    {
        $value = strtolower(trim((string) $string));

        return preg_match(InteractiveBlocks::KEY_REGEX, $value) === 1 ? $value : false;
    }
}

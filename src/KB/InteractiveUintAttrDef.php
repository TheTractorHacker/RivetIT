<?php

namespace ITFlow\KB;

/**
 * A BOUNDED UNSIGNED INTEGER attribute for the interactive-KB vocabulary.
 *
 * WHY NOT HTMLPurifier_AttrDef_Integer. Its (false, false, true) form rejects a
 * negative and a zero but accepts an integer of any LENGTH: measured on the
 * bundled 4.15.0, data-ikb-height="99999999999999999999" survives it intact and
 * reaches the render layer. An unbounded integer out of author-controlled markup
 * is a denial of service on whatever consumes it - a frame 10^20 pixels tall, or
 * an embed id that is no longer an int at all once PHP has widened it.
 *
 * Two gates, in this order:
 *   1. at most 7 digits, so an over-long value is never converted to an int;
 *   2. the declared inclusive range.
 * The value is returned CANONICALISED, so "0640" and "640" cannot be two
 * different stored attributes.
 *
 * The ranges themselves are InteractiveBlocks::EMBED_ID_MIN/MAX and
 * EMBED_HEIGHT_MIN/MAX, and each is enforced a second time in PHP
 * (InteractiveBlocks::clampHeight) and a third in js/kb_interactive.js before
 * the number reaches an element.
 *
 * ITS OWN FILE for the same measured reason as InteractiveKeyAttrDef - see that
 * file's header. Never reference it outside InteractiveBlocks::apply().
 */
final class InteractiveUintAttrDef extends \HTMLPurifier_AttrDef
{
    private int $min;
    private int $max;

    public function __construct(int $min, int $max)
    {
        $this->min = $min;
        $this->max = $max;
    }

    public function validate($string, $config, $context)
    {
        $value = trim((string) $string);
        if (preg_match('/\A[0-9]{1,7}\z/', $value) !== 1) {
            return false;
        }
        $number = (int) $value;
        if ($number < $this->min || $number > $this->max) {
            return false;
        }

        return (string) $number;
    }
}

<?php

namespace ITFlow\Training\Core;

/**
 * The one icon allowlist for training categories and achievements (Font Awesome 5 solid
 * names, without the "fa-" prefix). Includes every icon the 2.6.91 migration seeds for
 * the default categories. Icons are rendered as `fas fa-<name>` and checked server-side,
 * so a stored value can never smuggle extra classes into the markup.
 */
final class Icons
{
    public const ALLOWED = ['award', 'medal', 'trophy', 'star', 'crown', 'certificate', 'graduation-cap', 'shield-alt',
        'hard-hat', 'fire-extinguisher', 'truck-loading', 'hand-paper', 'eye', 'bolt', 'check-double', 'user-shield', 'hands-helping', 'medkit',
        'tools', 'cog', 'lock', 'life-ring', 'thumbs-up', 'rocket', 'laptop', 'industry', 'clipboard-check', 'exclamation-triangle', 'biohazard', 'wrench'];

    public static function valid(string $icon): bool
    {
        return in_array($icon, self::ALLOWED, true);
    }
}

<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Core\Ctx;

/**
 * Finds the online component source when Phase 3 has shipped it (§1.5: the implementation
 * class is exactly \ITFlow\Training\Kiosk\RunComponentSource, constructed with the Ctx).
 */
final class Components
{
    public const ONLINE_CLASS = '\\ITFlow\\Training\\Kiosk\\RunComponentSource';

    public static function online(Ctx $c): ?OnlineComponentSource
    {
        $class = ltrim(self::ONLINE_CLASS, '\\');
        if (!class_exists($class)) {
            return null;
        }
        $src = new $class($c);
        return $src instanceof OnlineComponentSource ? $src : null;
    }
}

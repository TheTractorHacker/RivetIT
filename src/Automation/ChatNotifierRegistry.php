<?php

namespace ITFlow\Automation;

/** Holds the one registered ChatNotifier (see ChatNotifier). */
final class ChatNotifierRegistry
{
    private static ?ChatNotifier $notifier = null;

    public static function set(?ChatNotifier $notifier): void
    {
        self::$notifier = $notifier;
    }

    public static function get(): ChatNotifier
    {
        return self::$notifier ?? new UnavailableChatNotifier();
    }
}

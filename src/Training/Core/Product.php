<?php

namespace ITFlow\Training\Core;

/**
 * The product name for Training text (Odoo notes, PDF metadata, the certificate check, outgoing
 * User-Agent). It is APP_NAME from includes/branding.php, never a literal here.
 *
 * Every web page, kiosk page and cron runner loads functions.php, which loads branding.php first.
 * A CLI script or test that only loads the autoloader still gets the constant: branding.php is
 * required on first use when APP_NAME is not defined yet (it only defines constants).
 */
final class Product
{
    public static function name(): string
    {
        if (!\defined('APP_NAME')) {
            require_once \dirname(__DIR__, 3) . '/includes/branding.php';
        }
        return (string) \constant('APP_NAME');
    }
}

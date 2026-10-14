<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * The ManageEngine MDM Cloud data centers RivetIT will talk to. The host a request goes to is ALWAYS looked up here by a short key
 * (us, eu, ...); it is never built from free text, so an administrator (or a tampered form) cannot point the sync at an internal
 * address (SSRF). Both hosts per data center are https and fixed.
 *
 * mdm      the API host: "Base API URI" table on https://www.manageengine.com/mobile-device-management/api/oauth/
 * accounts the Zoho Accounts host that issues tokens (https://www.zoho.com/accounts/protocol/oauth/multi-dc.html)
 * China (.cn) is deliberately absent: ManageEngine documents an MDM host for it but no Zoho accounts host could be confirmed.
 * See docs/MANAGEENGINE_MDM.md.
 */
final class DataCenters
{
    public const DEFAULT = 'us';

    /** @var array<string,array{label:string,mdm:string,accounts:string}> */
    private const MAP = [
        'us' => ['label' => 'United States (.com)', 'mdm' => 'mdm.manageengine.com', 'accounts' => 'accounts.zoho.com'],
        'eu' => ['label' => 'Europe (.eu)', 'mdm' => 'mdm.manageengine.eu', 'accounts' => 'accounts.zoho.eu'],
        'in' => ['label' => 'India (.in)', 'mdm' => 'mdm.manageengine.in', 'accounts' => 'accounts.zoho.in'],
        'au' => ['label' => 'Australia (.com.au)', 'mdm' => 'mdm.manageengine.com.au', 'accounts' => 'accounts.zoho.com.au'],
        'jp' => ['label' => 'Japan (.jp)', 'mdm' => 'mdm.manageengine.jp', 'accounts' => 'accounts.zoho.jp'],
        'ca' => ['label' => 'Canada (.ca)', 'mdm' => 'mdm.manageengine.ca', 'accounts' => 'accounts.zohocloud.ca'],
        'sa' => ['label' => 'Saudi Arabia (.sa)', 'mdm' => 'mdm.manageengine.sa', 'accounts' => 'accounts.zoho.sa'],
        'uk' => ['label' => 'United Kingdom (.uk)', 'mdm' => 'mdm.manageengine.uk', 'accounts' => 'accounts.zoho.uk'],
    ];

    /** @return array<string,string> key => label, for the settings select */
    public static function options(): array
    {
        return array_map(static fn (array $d): string => $d['label'], self::MAP);
    }

    public static function isValid(string $key): bool
    {
        return isset(self::MAP[$key]);
    }

    /** An unknown key falls back to the default rather than to anything attacker-controlled. */
    public static function normalize(?string $key): string
    {
        $key = strtolower(trim((string) $key));

        return isset(self::MAP[$key]) ? $key : self::DEFAULT;
    }

    public static function mdmBase(string $key): string
    {
        return 'https://' . self::MAP[self::normalize($key)]['mdm'];
    }

    public static function accountsBase(string $key): string
    {
        return 'https://' . self::MAP[self::normalize($key)]['accounts'];
    }
}

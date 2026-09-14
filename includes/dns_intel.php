<?php
/*
 * "Who's who" lookups for a domain's DNS/MX records - purely presentational,
 * pattern-matched against the NS/MX hostname text already stored on the
 * domains row (functions.php's getDomainRecords(), refreshed by
 * cron/domain_refresher.php). No new columns, no new fetch: this only parses
 * text that's already there, so it's safe to call from any page that already
 * has a domain row loaded.
 *
 * Fingerprint lists are intentionally conservative (well-known hostname
 * substrings only) - an unmatched provider returns null/empty rather than a
 * guess, since a wrong "who's who" answer is worse than none.
 */

// Ordered [needle => label] - first match wins, so a more specific needle
// (e.g. a spam-filter vendor's own domain) should come before anything that
// could also coincidentally substring-match a generic one.
const DNS_PROVIDER_FINGERPRINTS = [
    'cloudflare.com'          => 'Cloudflare',
    'awsdns'                  => 'Amazon Route 53',
    'azure-dns'                => 'Microsoft Azure DNS',
    'domaincontrol.com'       => 'GoDaddy',
    'googledomains.com'       => 'Google Domains',
    'google.com'              => 'Google Cloud DNS',
    'ns-cloud'                 => 'Google Cloud DNS',
    'dnsmadeeasy.com'         => 'DNS Made Easy',
    'registrar-servers.com'   => 'Namecheap',
    'digitalocean.com'        => 'DigitalOcean',
    'dynect.net'               => 'Oracle Dyn',
    'ultradns'                 => 'Neustar UltraDNS',
    'worldnic.com'            => 'Network Solutions',
    'bluehost.com'            => 'Bluehost',
    'hostgator.com'           => 'HostGator',
    'wixdns.net'              => 'Wix',
    'squarespacedns.com'      => 'Squarespace',
    'name.com'                 => 'Name.com',
    'dreamhost.com'           => 'DreamHost',
    'siteground.net'          => 'SiteGround',
    'network-solutions.com'   => 'Network Solutions',
    'nsone.net'                => 'NS1',
    'he.net'                   => 'Hurricane Electric',
];

// Dedicated email-security / spam-filtering gateways. Checked FIRST against MX
// hostnames - a domain routing through one of these often has no other
// visible mailbox-provider hostname in its MX records at all (the real
// mailbox sits downstream, unreachable to a plain MX lookup), so this list
// intentionally takes priority over EMAIL_PROVIDER_FINGERPRINTS below.
const SPAM_FILTER_FINGERPRINTS = [
    'mxthunder.com'           => 'SpamTitan (TitanHQ)',
    'mxthunder.net'           => 'SpamTitan (TitanHQ)',
    'mimecast.com'            => 'Mimecast',
    'pphosted.com'            => 'Proofpoint',
    'proofpoint.com'          => 'Proofpoint',
    'barracudanetworks.com'   => 'Barracuda',
    'barracuda.com'           => 'Barracuda',
    'iphmx.com'                => 'Cisco Secure Email (IronPort)',
    'ironport.com'            => 'Cisco Secure Email (IronPort)',
    'messagelabs.com'         => 'Broadcom (Symantec) Email Security',
    'symanteccloud.com'       => 'Broadcom (Symantec) Email Security',
    'trendmicro.com'          => 'Trend Micro Email Security',
    'sophos.com'              => 'Sophos Email',
    'forcepoint.net'          => 'Forcepoint Email Security',
    'appriver.com'            => 'Zix / AppRiver',
    'zix.com'                  => 'Zix / AppRiver',
    'mailroute.net'           => 'MailRoute',
    'reflexion.net'           => 'Reflexion (Sophos)',
    'hornetsecurity.com'      => 'Hornetsecurity',
    'antispamcloud.com'       => 'Hornetsecurity',
    'vadesecure.com'          => 'Vade Secure',
    'perception-point.io'     => 'Perception Point',
    'ppe-hosted.com'          => 'Proofpoint Essentials',
    'messagestream.io'        => 'Cisco Secure Email',
    'smtpout.com'             => 'SolarWinds/N-able Mail Assure',
];

// Direct mailbox providers. Checked after SPAM_FILTER_FINGERPRINTS so a
// domain that filters through, say, Mimecast and ALSO exposes a Microsoft 365
// hostname in a secondary MX record shows both, correctly labeled.
const EMAIL_PROVIDER_FINGERPRINTS = [
    'google.com'              => 'Google Workspace',
    'googlemail.com'          => 'Google Workspace',
    'protection.outlook.com'  => 'Microsoft 365',
    'outlook.com'             => 'Microsoft 365',
    'secureserver.net'        => 'GoDaddy Email',
    'zoho.com'                => 'Zoho Mail',
    'zohomail.com'            => 'Zoho Mail',
    'yahoodns.net'            => 'Yahoo Mail',
    'icloud.com'              => 'iCloud Mail',
    'emailsrvr.com'           => 'Rackspace Email',
    'rackspace.com'           => 'Rackspace Email',
    'fastmail.com'            => 'FastMail',
    'protonmail.ch'           => 'ProtonMail',
    'mail.ru'                  => 'Mail.ru',
    'yandex.net'              => 'Yandex Mail',
    'bluehost.com'            => 'Bluehost Email',
    'hostgator.com'           => 'HostGator Email',
];

/**
 * Match a block of newline-separated hostnames (NS or MX record text, as
 * stored on domains.domain_name_servers/domain_mail_servers) against a
 * [needle => label] fingerprint list. Returns the distinct matched labels,
 * in fingerprint-list order, or [] when nothing matched / input was empty.
 */
function dnsIntelMatch(?string $record_text, array $fingerprints): array {
    if (empty($record_text)) return [];
    $haystack = strtolower($record_text);
    $found = [];
    foreach ($fingerprints as $needle => $label) {
        if (strpos($haystack, $needle) !== false && !in_array($label, $found, true)) {
            $found[] = $label;
        }
    }
    return $found;
}

/** DNS provider(s) serving this domain's nameservers, or [] if unrecognized. */
function dnsIntelDnsProviders(?string $name_servers): array {
    return dnsIntelMatch($name_servers, DNS_PROVIDER_FINGERPRINTS);
}

/**
 * Email intel from MX records: which spam-filter gateway(s) are in the MX
 * chain, and which direct mailbox provider(s) are visible. Either/both may be
 * empty - a domain behind a filter commonly shows only the filter, since the
 * real mailbox destination isn't exposed to a plain MX lookup once a gateway
 * is the sole first hop.
 */
function dnsIntelEmailProviders(?string $mail_servers): array {
    return [
        'spam_filter' => dnsIntelMatch($mail_servers, SPAM_FILTER_FINGERPRINTS),
        'mailbox'     => dnsIntelMatch($mail_servers, EMAIL_PROVIDER_FINGERPRINTS),
    ];
}

<?php

namespace ITFlow\Chat;

/**
 * Builds the Microsoft Teams app package (a .zip with manifest.json, color.png 192x192 and outline.png 32x32) for the RivetIT bot, ready to upload
 * in Teams ("Apps > Manage your apps > Upload an app") or in the Teams admin center. The bot id is the Microsoft App ID of the Azure Bot registration.
 * Icons are generated here (no image library needed). UNVERIFIED LIVE: the manifest follows schema v1.17 and the structure is checked locally
 * (tests/chat_teams.php), but it has not been sideloaded into a real tenant.
 */
final class TeamsPackage
{
    public const SCHEMA = 'https://developer.microsoft.com/en-us/json-schemas/teams/v1.17/MicrosoftTeams.schema.json';

    /** @return array<string,mixed> */
    public static function manifest(string $baseUrl, string $appName, string $botAppId, string $version = '1.0.0'): array
    {
        $base = rtrim($baseUrl, '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        if (!preg_match('#^https://#i', $base) || $host === '') {
            throw new \InvalidArgumentException('A public https base URL is required for the Teams app.');
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $botAppId)) {
            throw new \InvalidArgumentException('The Microsoft App ID must be a GUID (the Azure Bot registration\'s App ID).');
        }
        $name = mb_substr(trim(preg_replace('/[^\p{L}\p{N} ._-]/u', '', $appName) ?? '') ?: (defined('APP_NAME') ? APP_NAME : 'RivetIT'), 0, 30);
        $scopes = ['personal', 'team', 'groupChat'];

        return [
            '$schema' => self::SCHEMA,
            'manifestVersion' => '1.17',
            'version' => preg_match('/^\d+\.\d+\.\d+$/', $version) ? $version : '1.0.0',
            'id' => strtolower($botAppId),
            'developer' => ['name' => $name, 'websiteUrl' => $base, 'privacyUrl' => $base, 'termsOfUseUrl' => $base],
            'name' => ['short' => $name, 'full' => $name . ' service desk'],
            'description' => ['short' => 'Tickets, approvals and answers from your service desk.', 'full' => 'Create and follow tickets, approve requests and search the knowledge base and service catalog without leaving Teams.'],
            'icons' => ['outline' => 'outline.png', 'color' => 'color.png'],
            'accentColor' => '#0F766E',
            'bots' => [[
                'botId' => strtolower($botAppId),
                'scopes' => $scopes,
                'supportsFiles' => false,
                'isNotificationOnly' => false,
                'commandLists' => [[
                    'scopes' => $scopes,
                    'commands' => [
                        ['title' => 'help', 'description' => 'What I can do'],
                        ['title' => 'new', 'description' => 'new <subject> - create a ticket'],
                        ['title' => 'status', 'description' => 'status <ticket> - where a ticket is'],
                        ['title' => 'approvals', 'description' => 'What is waiting for your approval'],
                    ],
                ]],
            ]],
            'permissions' => ['identity', 'messageTeamMembers'],
            'validDomains' => [$host],
        ];
    }

    /** The package bytes (a zip). @throws \RuntimeException when the zip extension is missing */
    public static function zip(string $baseUrl, string $appName, string $botAppId, string $version = '1.0.0'): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to build the Teams app package.');
        }
        $manifest = (string) json_encode(self::manifest($baseUrl, $appName, $botAppId, $version), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = tempnam(sys_get_temp_dir(), 'teamsapp');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary file.');
        }
        try {
            $z = new \ZipArchive();
            if ($z->open($tmp, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the zip.');
            }
            $z->addFromString('manifest.json', $manifest);
            $z->addFromString('color.png', self::colorIcon());
            $z->addFromString('outline.png', self::outlineIcon());
            $z->close();

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** 192x192: teal tile with a white rounded block (a plain mark; replace with your logo in Teams admin if you like). */
    public static function colorIcon(): string
    {
        return self::png(192, 192, static function (int $x, int $y): array {
            $in = $x >= 48 && $x < 144 && $y >= 48 && $y < 144;
            $notch = $x >= 84 && $x < 144 && $y >= 84 && $y < 108;

            return $in && !$notch ? [255, 255, 255, 255] : [15, 118, 110, 255];
        });
    }

    /** 32x32: transparent with a white outline (Teams requires a white-on-transparent outline icon). */
    public static function outlineIcon(): string
    {
        return self::png(32, 32, static function (int $x, int $y): array {
            $edge = ($x >= 6 && $x < 26 && $y >= 6 && $y < 26) && !($x >= 8 && $x < 24 && $y >= 8 && $y < 24);

            return $edge ? [255, 255, 255, 255] : [0, 0, 0, 0];
        });
    }

    /** Minimal RGBA PNG encoder. @param callable(int,int): array{0:int,1:int,2:int,3:int} $pixel */
    public static function png(int $w, int $h, callable $pixel): string
    {
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\0";
            for ($x = 0; $x < $w; $x++) {
                $raw .= pack('C4', ...$pixel($x, $y));
            }
        }
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress($raw, 9)) . $chunk('IEND', '');
    }
}
